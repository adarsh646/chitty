<?php
// Endpoint: Create a Razorpay order for an installment due payment.
// Only accessible to logged-in members for their own installments.

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

// Support both JSON body and standard POST form data
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
$amount = isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null;

if ($installmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid installment ID.']);
    exit;
}

try {
    $orderData = create_installment_order($installmentId, (int) $user['id'], $amount);
    echo json_encode([
        'success' => true,
        'order' => $orderData,
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
