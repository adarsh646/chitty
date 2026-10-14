<?php
// Copy this file's values to match your MySQL setup, or set them as environment
// variables (DB_HOST, DB_NAME, DB_USER, DB_PASS, RAZORPAY_KEY_ID, etc.).

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (file_exists(__DIR__ . '/.env')) {
        static $dotenvLoaded = false;
        if (!$dotenvLoaded) {
            $loadedSuccessfully = false;
            if (class_exists(\Dotenv\Dotenv::class)) {
                try {
                    $dotenv = \Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
                    $dotenv->safeLoad();
                    $loadedSuccessfully = true;
                } catch (\Throwable $e) {
                    // Fallback to manual parser below if .env contains unquoted spaces or syntax issues
                }
            }

            if (!$loadedSuccessfully) {
                $envContent = (string)@file_get_contents(__DIR__ . '/.env');
                $lines = explode("\n", $envContent);
                $modified = false;
                $newLines = [];
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        $newLines[] = $line;
                        continue;
                    }
                    if (str_contains($trimmed, '=')) {
                        [$name, $value] = explode('=', $trimmed, 2);
                        $name = trim($name);
                        $value = trim($value);

                        // If unquoted and contains spaces, quote it to prevent future dotenv errors
                        if (str_contains($value, ' ') && !str_starts_with($value, '"') && !str_starts_with($value, "'")) {
                            $cleanValue = $value;
                            $newLines[] = "{$name}=\"" . addcslashes($value, '"\\') . "\"";
                            $modified = true;
                        } else {
                            $newLines[] = $line;
                            $cleanValue = $value;
                            if ((str_starts_with($cleanValue, '"') && str_ends_with($cleanValue, '"')) ||
                                (str_starts_with($cleanValue, "'") && str_ends_with($cleanValue, "'"))) {
                                $cleanValue = substr($cleanValue, 1, -1);
                            }
                        }

                        if ($name !== '') {
                            $_ENV[$name] = $cleanValue;
                            $_SERVER[$name] = $cleanValue;
                            putenv("{$name}={$cleanValue}");
                        }
                    } else {
                        $newLines[] = $line;
                    }
                }

                if ($modified) {
                    @file_put_contents(__DIR__ . '/.env', implode("\n", $newLines));
                }
            }
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
    'mail' => [
        'host' => $_ENV['MAIL_HOST'] ?? (getenv('MAIL_HOST') ?: 'smtp.gmail.com'),
        'port' => (int)($_ENV['MAIL_PORT'] ?? (getenv('MAIL_PORT') ?: 587)),
        'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? (getenv('MAIL_ENCRYPTION') ?: 'tls'),
        'username' => $_ENV['MAIL_USERNAME'] ?? (getenv('MAIL_USERNAME') ?: ''),
        'password' => $_ENV['MAIL_PASSWORD'] ?? (getenv('MAIL_PASSWORD') ?: ''),
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? (getenv('MAIL_FROM_ADDRESS') ?: ''),
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? (getenv('MAIL_FROM_NAME') ?: 'MSS Chitty'),
        'to_address' => $_ENV['MAIL_TO_ADDRESS'] ?? (getenv('MAIL_TO_ADDRESS') ?: ''),
        'app_url' => $_ENV['APP_URL'] ?? (getenv('APP_URL') ?: 'http://localhost:8000'),
    ],
    // Change this before going live. Used only to namespace PHP session cookies.
    'session_name' => 'chitty_session',
];

