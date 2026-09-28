<?php
// Run once from the command line: php seed.php
// Or set env vars ADMIN_PHONE / ADMIN_PASSWORD / ADMIN_NAME first.

require_once __DIR__ . '/includes/auth.php';

$phone = getenv('ADMIN_PHONE') ?: '9999999999';
$password = getenv('ADMIN_PASSWORD') ?: 'admin123';
$name = getenv('ADMIN_NAME') ?: 'Society Admin';

$existing = find_user_by_phone($phone);
if ($existing) {
    echo "Admin with phone {$phone} already exists (id {$existing['id']}). Nothing to do.\n";
} else {
    $id = create_user($name, $phone, null, null, $password, 'admin');
    echo "Created admin user \"{$name}\" (id {$id})\n";
    echo "Login phone: {$phone}\n";
    echo "Login password: {$password}\n";
    echo "IMPORTANT: log in and change this password, or re-run with ADMIN_PASSWORD set, before going live.\n";
}
