<?php
// Razorpay Payment Gateway integration helper for Chitty Platform.
// Handles configuration, order creation, signature verification, and transaction logging.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/chitty.php';

function get_razorpay_config(): array {
    $config = require __DIR__ . '/../config.php';
    $rzp = $config['razorpay'] ?? [];

    $keyId = trim((string)($rzp['key_id'] ?? ''));
    $keySecret = trim((string)($rzp['key_secret'] ?? ''));
    $webhookSecret = trim((string)($rzp['webhook_secret'] ?? ''));

    // Check whether valid, non-placeholder keys are supplied
    $isPlaceholder = empty($keyId)
        || $keyId === 'rzp_test_yourKeyHere'
        || $keySecret === 'yourSecretKeyHere'
        || empty($keySecret);

    $isTestMode = str_starts_with($keyId, 'rzp_test_') || $isPlaceholder;

    return [
        'key_id' => $keyId,
        'key_secret' => $keySecret,
        'webhook_secret' => $webhookSecret,
        'is_configured' => !$isPlaceholder,
        'is_test_mode' => $isTestMode,
        'is_placeholder' => $isPlaceholder,
    ];
}

/**
 * Returns an instance of Razorpay\Api\Api if valid keys are configured.
 */
function get_razorpay_api(): \Razorpay\Api\Api {
    $cfg = get_razorpay_config();
    if (!$cfg['is_configured']) {
        throw new RuntimeException(
            'Razorpay API credentials are not configured or still have placeholder values. ' .
            'Please update RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET in your .env file.'
        );
    }
    return new \Razorpay\Api\Api($cfg['key_id'], $cfg['key_secret']);
}

/**
 * Fetches installment details along with ownership validation for a member.
 */
function get_member_installment(int $installment_id, int $user_id): ?array {
    $stmt = db()->prepare('
        SELECT i.*,
               s.user_id,
               s.scheme_id,
               s.ticket_number,
               cs.name AS scheme_name,
               u.name AS member_name,
               u.phone AS member_phone,
               u.email AS member_email
        FROM installments i
        JOIN subscriptions s ON s.id = i.subscription_id
        JOIN chit_schemes cs ON cs.id = s.scheme_id
        JOIN users u ON u.id = s.user_id
        WHERE i.id = ? AND s.user_id = ?
    ');
    $stmt->execute([$installment_id, $user_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Creates a Razorpay Order for an installment payment.
 */
function create_installment_order(int $installment_id, int $user_id, ?float $custom_amount = null): array {
    $inst = get_member_installment($installment_id, $user_id);
    if (!$inst) {
        throw new RuntimeException('Installment not found or unauthorized access.');
    }

    $remaining = remaining_amount_due($inst);
    if ($remaining <= 0.005) {
        throw new RuntimeException('This installment has already been fully paid.');
    }

    $amount = ($custom_amount !== null && $custom_amount > 0) ? round($custom_amount, 2) : $remaining;

    if ($amount < 1.00) {
        throw new RuntimeException('Payment amount must be at least ₹1.00.');
    }
    if ($amount > $remaining + 0.005) {
        throw new RuntimeException('Payment amount cannot exceed the remaining due balance of ₹' . number_format($remaining, 2) . '.');
    }

    $cfg = get_razorpay_config();
    $amountInPaise = (int) round($amount * 100);
    $receipt = 'inst_' . $installment_id . '_' . time();
    $description = "Chitty Month #{$inst['month_number']} (Chittal N0. #{$inst['ticket_number']})";

    $orderId = null;

    if ($cfg['is_configured']) {
        // Real Razorpay API Order Creation
        $api = get_razorpay_api();
        try {
            $order = $api->order->create([
                'receipt' => $receipt,
                'amount' => $amountInPaise,
                'currency' => 'INR',
                'notes' => [
                    'installment_id' => (string) $installment_id,
                    'scheme_id' => (string) $inst['scheme_id'],
                    'scheme_name' => (string) $inst['scheme_name'],
                    'ticket_number' => (string) $inst['ticket_number'],
                    'month_number' => (string) $inst['month_number'],
                    'user_id' => (string) $user_id,
                ],
            ]);
            $orderId = $order['id'];
        } catch (\Exception $e) {
            throw new RuntimeException('Razorpay error creating order: ' . $e->getMessage());
        }
    } else {
        // In local development / test mode with placeholder keys, generate a mock order ID
        $orderId = 'order_test_' . bin2hex(random_bytes(8));
    }

    // Record the initiated payment in payments table
    $stmt = db()->prepare('
        INSERT INTO payments (user_id, scheme_id, installment_id, amount, currency, razorpay_order_id, status)
        VALUES (?, ?, ?, ?, "INR", ?, "created")
    ');
    $stmt->execute([
        $user_id,
        $inst['scheme_id'],
        $installment_id,
        $amount,
        $orderId,
    ]);
    $paymentRecordId = (int) db()->lastInsertId();

    return [
        'payment_record_id' => $paymentRecordId,
        'order_id' => $orderId,
        'amount' => $amountInPaise,
        'amount_display' => $amount,
        'currency' => 'INR',
        'key_id' => $cfg['key_id'],
        'name' => 'Chitty Society',
        'description' => $description,
        'is_configured' => $cfg['is_configured'],
        'is_placeholder' => $cfg['is_placeholder'],
        'prefill' => [
            'name' => $inst['member_name'] ?? '',
            'contact' => $inst['member_phone'] ?? '',
            'email' => $inst['member_email'] ?? '',
        ],
        'notes' => [
            'ticket_number' => $inst['ticket_number'],
            'month_number' => $inst['month_number'],
            'scheme_name' => $inst['scheme_name'],
        ],
    ];
}

/**
 * Verifies Razorpay payment signature, updates payment record, and logs into installments ledger.
 */
function verify_and_capture_installment_payment(
    int $installment_id,
    int $user_id,
    string $order_id,
    string $payment_id,
    string $signature,
    float $amount
): array {
    $inst = get_member_installment($installment_id, $user_id);
    if (!$inst) {
        throw new RuntimeException('Installment not found or unauthorized.');
    }

    $cfg = get_razorpay_config();

    // Verify signature
    if ($cfg['is_configured']) {
        $api = get_razorpay_api();
        try {
            $api->utility->verifyPaymentSignature([
                'razorpay_order_id' => $order_id,
                'razorpay_payment_id' => $payment_id,
                'razorpay_signature' => $signature,
            ]);
        } catch (\Exception $e) {
            // Update payment record to failed
            $stmt = db()->prepare('
                UPDATE payments
                SET status = "failed",
                    razorpay_payment_id = ?,
                    razorpay_signature = ?,
                    error_description = ?
                WHERE razorpay_order_id = ? AND user_id = ?
            ');
            $stmt->execute([$payment_id, $signature, $e->getMessage(), $order_id, $user_id]);

            throw new RuntimeException('Payment signature verification failed: ' . $e->getMessage());
        }
    } else {
        // Test mode simulation verification check
        if (!str_starts_with($order_id, 'order_test_') && !str_starts_with($order_id, 'order_')) {
            throw new RuntimeException('Invalid test order reference.');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Update payment table record to captured
        $stmt = $pdo->prepare('
            UPDATE payments
            SET status = "captured",
                razorpay_payment_id = ?,
                razorpay_signature = ?
            WHERE razorpay_order_id = ? AND user_id = ?
        ');
        $stmt->execute([$payment_id, $signature, $order_id, $user_id]);

        // Record the payment in installments ledger
        $updatedInstallment = record_payment($installment_id, $amount, date('Y-m-d'));

        $pdo->commit();
        return $updatedInstallment;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
