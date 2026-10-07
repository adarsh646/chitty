<?php
// Copy this file's values to match your MySQL setup, or set them as environment
// variables (DB_HOST, DB_NAME, DB_USER, DB_PASS, RAZORPAY_KEY_ID, etc.).

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists(\Dotenv\Dotenv::class) && file_exists(__DIR__ . '/.env')) {
        static $dotenvLoaded = false;
        if (!$dotenvLoaded) {
            $dotenv = \Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
            $dotenv->safeLoad();
            $dotenvLoaded = true;
        }
    }
}

return [
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'localhost'),
        'name' => $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'chitty'),
        'user' => $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root'),
        'pass' => $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'Admin@iiiTk'),
        'charset' => 'utf8mb4',
    ],
    'razorpay' => [
        'key_id' => $_ENV['RAZORPAY_KEY_ID'] ?? (getenv('RAZORPAY_KEY_ID') ?: ''),
        'key_secret' => $_ENV['RAZORPAY_KEY_SECRET'] ?? (getenv('RAZORPAY_KEY_SECRET') ?: ''),
        'webhook_secret' => $_ENV['RAZORPAY_WEBHOOK_SECRET'] ?? (getenv('RAZORPAY_WEBHOOK_SECRET') ?: ''),
    ],
    'upi' => [
        'id' => $_ENV['UPI_ID'] ?? (getenv('UPI_ID') ?: 'chitty@okaxis'),
        'payee_name' => $_ENV['UPI_PAYEE_NAME'] ?? (getenv('UPI_PAYEE_NAME') ?: 'Chitty Society'),
    ],
    // Change this before going live. Used only to namespace PHP session cookies.
    'session_name' => 'chitty_session',
];

