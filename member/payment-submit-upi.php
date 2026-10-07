<?php
// Endpoint: Submit UPI Transaction ID for installment payment.
// Puts transaction into 'under_processing' and sends notification to administrator.

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
$amount = (float) ($data['amount'] ?? 0);
$transactionId = trim((string) ($data['transaction_id'] ?? ''));

if ($installmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid installment ID.']);
    exit;
}

if (empty($transactionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter the UPI Transaction ID / UTR.']);
    exit;
}

if ($amount < 1.00) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payment amount must be at least ₹1.00.']);
    exit;
}

try {
    $result = submit_upi_payment($installmentId, (int) $user['id'], $transactionId, $amount);

    echo json_encode([
        'success' => true,
        'message' => 'Payment submitted successfully and is currently under processing.',
        'payment' => $result,
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
