<?php
require_once __DIR__ . '/auth.php';
$currentUser = current_user();
$pageTitle = $title ?? 'Chitty Register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> — Chitty Register</title>
  <link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">Chitty Register</div>
    <div class="brand-sub">Cooperative Society Chit Fund</div>
    <nav>
      <?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
        <a href="/admin/index.php">Schemes</a>
        <a href="/admin/members.php">Members</a>
      <?php elseif ($currentUser): ?>
        <a href="/member/index.php">My Chitties</a>
      <?php endif; ?>
    </nav>
    <?php if ($currentUser): ?>
      <div class="who">
        Signed in as<br><strong style="color:#fff"><?= h($currentUser['name']) ?></strong> (<?= h($currentUser['role']) ?>)
        <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Log out</button></form>
      </div>
    <?php endif; ?>
  </aside>
  <main class="main">
