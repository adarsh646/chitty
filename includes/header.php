<?php
require_once __DIR__ . '/auth.php';
$currentUser = current_user();
$pageTitle = $title ?? 'Chitty Register';

$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$isAdmin = $currentUser && ($currentUser['role'] ?? '') === 'admin';
$isSchemesActive = $isAdmin && (
    strpos($currentUri, '/admin/scheme') !== false ||
    strpos($currentUri, '/admin/month') !== false ||
    strpos($currentUri, '/admin/auction') !== false ||
    strpos($currentUri, '/admin/reports') !== false ||
    strpos($currentUri, '/admin/index.php') !== false ||
    $currentUri === '/admin/' ||
    $currentUri === '/admin'
);
$isMembersActive = $isAdmin && (strpos($currentUri, '/admin/members.php') !== false);
$isNotificationsActive = $isAdmin && (strpos($currentUri, '/admin/notifications.php') !== false);
$isMemberChittiesActive = !$isAdmin && (strpos($currentUri, '/member/') !== false || strpos($currentUri, '/member') !== false);
$isProfileActive = (strpos($currentUri, '/profile.php') !== false);

require_once __DIR__ . '/upi.php';
$pendingNotifCount = $isAdmin ? get_pending_notifications_count() : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= h($pageTitle) ?> — Chitty Register</title>
  <link rel="stylesheet" href="/css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
</head>
<body>
<div class="app-shell" id="appShell">
  <!-- Mobile Topbar for screens <= 768px -->
  <header class="mobile-topbar" id="mobileTopbar">
    <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Open navigation menu" aria-expanded="false">
      <span class="hamburger-bar"></span>
      <span class="hamburger-bar"></span>
      <span class="hamburger-bar"></span>
    </button>
    <div class="mobile-brand">
      <span class="mobile-brand-title">Chitty Register</span>
    </div>
    <?php if ($currentUser): ?>
      <a href="/profile.php" class="mobile-avatar <?= $isProfileActive ? 'active' : '' ?>" title="View Profile" aria-label="View Profile">
        <?= h(strtoupper(mb_substr($currentUser['name'] ?? 'U', 0, 1))) ?>
      </a>
    <?php else: ?>
      <div style="width: 32px;"></div>
    <?php endif; ?>
  </header>

  <!-- Mobile Backdrop Overlay -->
  <div class="mobile-nav-backdrop" id="mobileNavBackdrop" aria-hidden="true"></div>

  <aside class="sidebar" id="appSidebar" aria-label="Main Navigation">
    <div class="sidebar-header">
      <div class="sidebar-brand-block">
        <div class="brand">Chitty Register</div>
        <div class="brand-sub">Cooperative Society Chit Fund</div>
      </div>
      <button type="button" class="mobile-drawer-close" id="mobileDrawerClose" aria-label="Close menu">&times;</button>
    </div>
    <nav class="sidebar-nav">
      <?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
        <a href="/admin/index.php" class="<?= $isSchemesActive ? 'active' : '' ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
          <span>Schemes</span>
        </a>
        <a href="/admin/members.php" class="<?= $isMembersActive ? 'active' : '' ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          <span>Members</span>
        </a>
        <a href="/admin/notifications.php" class="<?= $isNotificationsActive ? 'active' : '' ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
          <span>Notifications</span>
          <?php if ($pendingNotifCount > 0): ?>
            <span class="nav-badge-pill"><?= $pendingNotifCount ?></span>
          <?php endif; ?>
        </a>
      <?php elseif ($currentUser): ?>
        <a href="/member/index.php" class="<?= $isMemberChittiesActive ? 'active' : '' ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
          <span>My Chitties</span>
        </a>
      <?php endif; ?>
    </nav>
    <?php if ($currentUser): ?>
      <div class="sidebar-footer who">
        <div class="user-profile">
          <a href="/profile.php" class="user-avatar <?= $isProfileActive ? 'active' : '' ?>" title="View Profile" aria-label="View Profile">
            <?= h(strtoupper(mb_substr($currentUser['name'] ?? 'U', 0, 1))) ?>
          </a>
          <div class="user-meta">
            <span class="user-role-badge"><?= h(ucfirst($currentUser['role'] ?? 'user')) ?></span>
            <a href="/profile.php" class="user-profile-link" title="View Profile">
              <div class="user-name" title="<?= h($currentUser['name'] ?? '') ?>"><?= h($currentUser['name'] ?? '') ?></div>
            </a>
            <?php if (!empty($currentUser['phone'])): ?>
              <div class="user-phone"><?= h($currentUser['phone']) ?></div>
            <?php endif; ?>
            <?php if (!empty($currentUser['email'])): ?>
              <div class="user-email" style="font-size: .72rem; color: #8E9CA8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= h($currentUser['email']) ?>"><?= h($currentUser['email']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <form method="POST" action="/logout.php" class="logout-form">
          <button class="logout-btn" type="submit" title="Sign out of your account">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            <span>Log out</span>
          </button>
        </form>
      </div>
    <?php endif; ?>
  </aside>
  <main class="main">
    <div class="main-inner">
