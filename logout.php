<?php
require_once __DIR__ . '/includes/auth.php';
start_app_session();
$_SESSION = [];
session_destroy();
redirect_masked('/login.php');
exit;
