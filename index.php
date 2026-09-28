<?php
require_once __DIR__ . '/includes/auth.php';
$user = current_user();
if (!$user) {
    header('Location: /login.php');
} elseif ($user['role'] === 'admin') {
    header('Location: /admin/index.php');
} else {
    header('Location: /member/index.php');
}
exit;
