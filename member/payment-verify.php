<?php
// Endpoint: Verify Razorpay payment signature and record payment in database.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_once __DIR__ . '/../includes/razorpay.php';

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
$orderId = trim((string) ($data['razorpay_order_id'] ?? ''));
$paymentId = trim((string) ($data['razorpay_payment_id'] ?? ''));
$signature = trim((string) ($data['razorpay_signature'] ?? ''));
$amount = (float) ($data['amount'] ?? 0);

if ($installmentId <= 0 || empty($orderId) || empty($paymentId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required payment parameters.']);
    exit;
}

try {
    $updatedInstallment = verify_and_capture_installment_payment(
        $installmentId,
        (int) $user['id'],
        $orderId,
        $paymentId,
        $signature,
        $amount
    );

    echo json_encode([
        'success' => true,
        'message' => 'Payment recorded successfully!',
        'installment' => [
            'id' => $updatedInstallment['id'],
            'status' => $updatedInstallment['status'],
            'amount_paid' => $updatedInstallment['amount_paid'],
            'amount_due' => $updatedInstallment['amount_due'],
            'remaining' => remaining_amount_due($updatedInstallment),
            'paid_date' => $updatedInstallment['paid_date'],
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
