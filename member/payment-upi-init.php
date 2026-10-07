<?php
// Endpoint: Initialize UPI Payment details and generate QR code for installment due.
// Only accessible to logged-in members for their own installments.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_once __DIR__ . '/../includes/razorpay.php';
require_once __DIR__ . '/../includes/upi.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_member();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$csrfToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!valid_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token expired. Please refresh the page.']);
    exit;
}

$installmentId = (int) ($data['installment_id'] ?? 0);
$customAmount = isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null;

if ($installmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid installment ID.']);
    exit;
}

try {
    $inst = get_member_installment($installmentId, (int) $user['id']);
    if (!$inst) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Installment not found or unauthorized.']);
        exit;
    }

    $remaining = remaining_amount_due($inst);
    if ($remaining <= 0.005) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This installment has already been fully paid.']);
        exit;
    }

    // Check if there is already a payment under processing
    $pendingPayment = get_installment_pending_payment($installmentId);
    if ($pendingPayment) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'is_under_processing' => true,
            'error' => 'A payment submission with Transaction ID #' . $pendingPayment['transaction_id'] . ' is already under processing for this installment.',
            'pending_payment' => $pendingPayment,
        ]);
        exit;
    }

    $amount = ($customAmount !== null && $customAmount > 0) ? round($customAmount, 2) : $remaining;
    if ($amount < 1.00) {
        throw new RuntimeException('Payment amount must be at least ₹1.00.');
    }
    if ($amount > $remaining + 0.005) {
        throw new RuntimeException('Payment amount cannot exceed the due balance of ₹' . number_format($remaining, 2) . '.');
    }

    $upiConfig = get_upi_config();
    $note = substr("Chitty M#{$inst['month_number']} T#{$inst['ticket_number']} - {$user['name']}", 0, 45);

    $upiUrl = build_upi_url($upiConfig['upi_id'], $upiConfig['payee_name'], $amount, $note);
    $qrDataUri = generate_upi_qr_data_uri($upiUrl);

    echo json_encode([
        'success' => true,
        'installment_id' => $installmentId,
        'scheme_name' => $inst['scheme_name'],
        'ticket_number' => (int) $inst['ticket_number'],
        'month_number' => (int) $inst['month_number'],
        'due_balance' => $remaining,
        'amount' => $amount,
        'upi_id' => $upiConfig['upi_id'],
        'payee_name' => $upiConfig['payee_name'],
        'upi_url' => $upiUrl,
        'qr_data_uri' => $qrDataUri,
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
