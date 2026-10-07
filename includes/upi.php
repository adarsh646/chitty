<?php
// UPI Payment and Notification Helper for Chitty Platform.
// Handles UPI URI generation, QR code generation, payment submission, and administrator verification.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/chitty.php';
require_once __DIR__ . '/razorpay.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Returns UPI configuration specified in .env / config.php
 */
function get_upi_config(): array {
    $config = require __DIR__ . '/../config.php';
    $upi = $config['upi'] ?? [];

    $upiId = trim((string)($upi['id'] ?? ''));
    if (empty($upiId)) {
        $upiId = 'chitty@okaxis';
    }

    $payeeName = trim((string)($upi['payee_name'] ?? ''));
    if (empty($payeeName)) {
        $payeeName = 'Chitty Society';
    }

    return [
        'upi_id' => $upiId,
        'payee_name' => $payeeName,
    ];
}

/**
 * Builds a standard UPI URI string.
 * Example: upi://pay?pa=recipient@okaxis&pn=Chitty+Society&am=4050.00&cu=INR&tn=Installment+Month+2
 */
function build_upi_url(string $upiId, string $payeeName, float $amount, string $note): string {
    $params = [
        'pa' => $upiId,
        'pn' => $payeeName,
        'am' => number_format($amount, 2, '.', ''),
        'cu' => 'INR',
        'tn' => $note,
    ];

    return 'upi://pay?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Generates an SVG Data-URI QR code for the given text/URL.
 */
function generate_upi_qr_data_uri(string $data): string {
    try {
        $qrcode = new QRCode();
        return $qrcode->render($data);
    } catch (\Throwable $e) {
        // Fallback simple base64 placeholder or rethrow
        error_log('QR code generation failed: ' . $e->getMessage());
        return '';
    }
}

/**
 * Submits a member's UPI transaction ID for verification.
 * Creates a payment with status 'under_processing' and a notification for the administrator.
 */
function submit_upi_payment(int $installment_id, int $user_id, string $transaction_id, float $amount): array {
    $transaction_id = trim($transaction_id);
    if (empty($transaction_id)) {
        throw new RuntimeException('Please enter a valid UPI Transaction ID / UTR.');
    }
    if (strlen($transaction_id) < 4 || strlen($transaction_id) > 100) {
        throw new RuntimeException('Transaction ID must be between 4 and 100 characters.');
    }

    // Validate installment belongs to user
    $inst = get_member_installment($installment_id, $user_id);
    if (!$inst) {
        throw new RuntimeException('Installment not found or unauthorized access.');
    }

    if ($inst['status'] === 'paid') {
        throw new RuntimeException('This installment has already been fully paid.');
    }

    $remaining = remaining_amount_due($inst);
    if ($remaining <= 0.005) {
        throw new RuntimeException('This installment has no remaining due balance.');
    }

    $amount = round($amount, 2);
    if ($amount < 1.00) {
        throw new RuntimeException('Payment amount must be at least ₹1.00.');
    }
    if ($amount > $remaining + 0.005) {
        throw new RuntimeException('Payment amount cannot exceed the due balance of ₹' . number_format($remaining, 2) . '.');
    }

    $pdo = db();

    // Check if there is already a pending verification for this installment
    $stmtCheck = $pdo->prepare('
        SELECT p.id, p.transaction_id, p.status
        FROM payments p
        WHERE p.installment_id = ? AND p.status = "under_processing"
    ');
    $stmtCheck->execute([$installment_id]);
    $existingPending = $stmtCheck->fetch();
    if ($existingPending) {
        throw new RuntimeException('A payment verification (Txn #' . $existingPending['transaction_id'] . ') is already under processing for this installment.');
    }

    // Check if this exact transaction ID is already in use
    $stmtTxn = $pdo->prepare('
        SELECT id FROM payments
        WHERE transaction_id = ? AND status IN ("under_processing", "captured")
    ');
    $stmtTxn->execute([$transaction_id]);
    if ($stmtTxn->fetch()) {
        throw new RuntimeException('This Transaction ID has already been submitted for another payment. Please verify your reference number.');
    }

    $pdo->beginTransaction();
    try {
        // Insert into payments table
        $stmtPay = $pdo->prepare('
            INSERT INTO payments (user_id, scheme_id, installment_id, amount, currency, payment_method, transaction_id, status)
            VALUES (?, ?, ?, ?, "INR", "upi", ?, "under_processing")
        ');
        $stmtPay->execute([
            $user_id,
            $inst['scheme_id'],
            $installment_id,
            $amount,
            $transaction_id,
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        // Insert into notifications table
        $stmtNotif = $pdo->prepare('
            INSERT INTO notifications (payment_id, user_id, scheme_id, installment_id, transaction_id, amount, status)
            VALUES (?, ?, ?, ?, ?, ?, "pending")
        ');
        $stmtNotif->execute([
            $paymentId,
            $user_id,
            $inst['scheme_id'],
            $installment_id,
            $transaction_id,
            $amount,
        ]);
        $notificationId = (int) $pdo->lastInsertId();

        $pdo->commit();

        return [
            'payment_id' => $paymentId,
            'notification_id' => $notificationId,
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'status' => 'under_processing',
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Administrator verifies an approved payment.
 * Updates payments record, records payment into installments ledger, and marks notification verified.
 */
function verify_upi_payment(int $notification_id, int $admin_id): array {
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT n.*, p.payment_method
        FROM notifications n
        JOIN payments p ON p.id = n.payment_id
        WHERE n.id = ? FOR UPDATE
    ');
    $stmt->execute([$notification_id]);
    $notif = $stmt->fetch();
    if (!$notif) {
        throw new RuntimeException('Notification not found.');
    }
    if ($notif['status'] === 'verified') {
        throw new RuntimeException('This payment has already been verified.');
    }

    $pdo->beginTransaction();
    try {
        // 1. Mark notification as verified
        $stmtUpNotif = $pdo->prepare('
            UPDATE notifications
            SET status = "verified",
                is_read = 1,
                verified_at = CURRENT_TIMESTAMP,
                verified_by = ?
            WHERE id = ?
        ');
        $stmtUpNotif->execute([$admin_id, $notification_id]);

        // 2. Mark payment as captured in payments table
        $stmtUpPay = $pdo->prepare('
            UPDATE payments
            SET status = "captured"
            WHERE id = ?
        ');
        $stmtUpPay->execute([$notif['payment_id']]);

        // 3. Record payment into installments ledger
        $updatedInstallment = record_payment(
            (int) $notif['installment_id'],
            (float) $notif['amount'],
            date('Y-m-d')
        );

        $pdo->commit();

        return [
            'notification_id' => $notification_id,
            'payment_id' => $notif['payment_id'],
            'transaction_id' => $notif['transaction_id'],
            'amount' => (float) $notif['amount'],
            'installment' => $updatedInstallment,
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Rejects an invalid or fraudulent payment submission.
 */
function reject_upi_payment(int $notification_id, int $admin_id, ?string $reason = null): array {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE id = ? FOR UPDATE');
    $stmt->execute([$notification_id]);
    $notif = $stmt->fetch();
    if (!$notif) {
        throw new RuntimeException('Notification not found.');
    }
    if ($notif['status'] === 'verified') {
        throw new RuntimeException('A verified payment cannot be rejected.');
    }

    $pdo->beginTransaction();
    try {
        $stmtUpNotif = $pdo->prepare('
            UPDATE notifications
            SET status = "rejected",
                is_read = 1,
                verified_at = CURRENT_TIMESTAMP,
                verified_by = ?
            WHERE id = ?
        ');
        $stmtUpNotif->execute([$admin_id, $notification_id]);

        $stmtUpPay = $pdo->prepare('
            UPDATE payments
            SET status = "failed",
                error_description = ?
            WHERE id = ?
        ');
        $stmtUpPay->execute([$reason ?: 'Rejected by administrator', $notif['payment_id']]);

        $pdo->commit();
        return $notif;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Returns count of pending payment notifications for the administrator badge.
 */
function get_pending_notifications_count(): int {
    try {
        $stmt = db()->query('SELECT COUNT(*) FROM notifications WHERE status = "pending"');
        return (int) $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Returns list of notifications with full member, scheme, and installment details.
 */
function get_payment_notifications(?string $statusFilter = null): array {
    $sql = '
        SELECT n.*,
               u.name AS member_name,
               u.phone AS member_phone,
               u.email AS member_email,
               cs.name AS scheme_name,
               s.ticket_number,
               i.month_number,
               i.amount_due,
               i.amount_paid,
               i.status AS installment_status,
               admin_u.name AS verified_by_name
        FROM notifications n
        JOIN installments i ON i.id = n.installment_id
        JOIN subscriptions s ON s.id = i.subscription_id
        JOIN users u ON u.id = n.user_id
        JOIN chit_schemes cs ON cs.id = n.scheme_id
        LEFT JOIN users admin_u ON admin_u.id = n.verified_by
    ';

    $params = [];
    if ($statusFilter !== null && in_array($statusFilter, ['pending', 'verified', 'rejected'], true)) {
        $sql .= ' WHERE n.status = ? ';
        $params[] = $statusFilter;
    }

    $sql .= ' ORDER BY n.created_at DESC ';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Returns the pending payment for a specific installment if one exists.
 */
function get_installment_pending_payment(int $installment_id): ?array {
    $stmt = db()->prepare('
        SELECT p.*, n.id AS notification_id
        FROM payments p
        LEFT JOIN notifications n ON n.payment_id = p.id
        WHERE p.installment_id = ? AND p.status = "under_processing"
        ORDER BY p.id DESC
        LIMIT 1
    ');
    $stmt->execute([$installment_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Returns a map of installment_id => pending_payment array for all pending payments in a scheme for a user.
 */
function get_user_scheme_pending_payments(int $scheme_id, int $user_id): array {
    $stmt = db()->prepare('
        SELECT p.*, n.id AS notification_id
        FROM payments p
        LEFT JOIN notifications n ON n.payment_id = p.id
        WHERE p.scheme_id = ? AND p.user_id = ? AND p.status = "under_processing"
    ');
    $stmt->execute([$scheme_id, $user_id]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['installment_id']] = $r;
    }
    return $map;
}
