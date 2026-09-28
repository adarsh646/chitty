<?php
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
if ($user) {
    header('Location: ' . ($user['role'] === 'admin' ? '/admin/index.php' : '/member/index.php'));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $found = find_user_by_phone($phone);
    if (!$found || !password_verify($password, $found['password_hash'])) {
        $error = 'Phone number or password is incorrect.';
    } else {
        start_app_session();
        $_SESSION['user'] = ['id' => $found['id'], 'name' => $found['name'], 'role' => $found['role'], 'phone' => $found['phone']];
        header('Location: ' . ($found['role'] === 'admin' ? '/admin/index.php' : '/member/index.php'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in — Chitty Register</title>
  <link rel="stylesheet" href="/css/style.css">
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="brand">Chitty Register</div>
      <div class="brand-sub">Cooperative Society Chit Fund</div>
      <?php if ($error): ?><div class="error-msg"><?= h($error) ?></div><?php endif; ?>
      <form method="POST" action="/login.php">
        <div class="field">
          <label for="phone">Phone number</label>
          <input type="text" id="phone" name="phone" required autofocus>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>
        <button type="submit">Sign in</button>
      </form>
    </div>
  </div>
</body>
</html>
