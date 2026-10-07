<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/chitty.php';

$sessionUser = require_login();
$dbUser = find_user_by_id((int) $sessionUser['id']);
if (!$dbUser) {
    header('Location: /login.php');
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please refresh the page and try again.');
        exit;
    }

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all password fields.';
    } elseif (!password_verify($currentPassword, $dbUser['password_hash'])) {
        $error = 'The current password you entered is incorrect.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'The new password must be at least 6 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new password and re-entered password do not match.';
    } elseif ($currentPassword === $newPassword) {
        $error = 'The new password must be different from your current password.';
    } else {
        update_user_password((int) $dbUser['id'], $newPassword);
        $success = 'Your password has been changed successfully.';
        $dbUser = find_user_by_id((int) $dbUser['id']);
    }
}

$totalDue = get_user_total_due((int) $dbUser['id']);
$breakdown = get_user_due_breakdown((int) $dbUser['id']);

$isAdmin = ($dbUser['role'] === 'admin');
$backUrl = $isAdmin ? '/admin/index.php' : '/member/index.php';
$backLabel = $isAdmin ? 'Back to Schemes' : 'Back to My Chitties';

$title = 'My Profile';
require_once __DIR__ . '/includes/header.php';
?>
  <div class="page-head">
    <div>
      <h1>User Profile</h1>
      <div class="sub">Account details, payment dues, and security credentials</div>
    </div>
    <a class="btn secondary" href="<?= h($backUrl) ?>"><?= h($backLabel) ?></a>
  </div>

  <?php if ($success): ?>
    <div class="success-msg"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="error-msg"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="profile-grid">
    <!-- Profile & Account Information -->
    <div class="card profile-info-card">
      <div class="profile-header-strip">
        <div class="profile-large-avatar" aria-hidden="true">
          <?= h(strtoupper(mb_substr($dbUser['name'], 0, 1))) ?>
        </div>
        <div class="profile-title-block">
          <h2><?= h($dbUser['name']) ?></h2>
          <div class="profile-badges">
            <span class="badge <?= $isAdmin ? 'active' : 'paid' ?>">
              <?= h(ucfirst($dbUser['role'])) ?> Account
            </span>
          </div>
        </div>
      </div>

      <div class="profile-detail-list">
        <div class="profile-detail-item">
          <div class="detail-label">Username / Full Name</div>
          <div class="detail-value"><?= h($dbUser['name']) ?></div>
        </div>

        <div class="profile-detail-item">
          <div class="detail-label">Mobile Number</div>
          <div class="detail-value font-mono"><?= h($dbUser['phone']) ?></div>
        </div>

        <div class="profile-detail-item">
          <div class="detail-label">Email Address</div>
          <div class="detail-value"><?= h($dbUser['email'] ?: '—') ?></div>
        </div>

        <div class="profile-detail-item">
          <div class="detail-label">Account Role</div>
          <div class="detail-value"><?= h(ucfirst($dbUser['role'])) ?></div>
        </div>

        <?php if (!empty($dbUser['address'])): ?>
          <div class="profile-detail-item">
            <div class="detail-label">Residential Address</div>
            <div class="detail-value"><?= nl2br(h($dbUser['address'])) ?></div>
          </div>
        <?php endif; ?>

        <div class="profile-detail-item">
          <div class="detail-label">Member Since</div>
          <div class="detail-value"><?= h(date('F j, Y', strtotime($dbUser['created_at']))) ?></div>
        </div>
      </div>
    </div>

    <!-- Total Due Amount Card -->
    <div class="card profile-due-card">
      <h2>Financial Dues Summary</h2>
      <div class="due-banner <?= $totalDue > 0 ? 'due-pending' : 'due-clear' ?>">
        <div class="due-label">Total Outstanding Due Amount</div>
        <div class="due-amount font-mono">₹<?= money($totalDue) ?></div>
        <div class="due-note">
          <?php if ($totalDue > 0): ?>
            <span class="badge pending">Payment Pending</span>
            Pending installment payments recorded across your enrolled chitty Chittals.
          <?php else: ?>
            <span class="badge paid">All Dues Cleared</span>
            You have no outstanding installment dues at this time.
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($breakdown)): ?>
        <h3 style="margin-top: 18px; margin-bottom: 8px;">Breakdown by Chittal N0.</h3>
        <table class="responsive-card-table profile-dues-table">
          <thead>
            <tr>
              <th>Chit Scheme</th>
              <th class="num">Chittal N0.</th>
              <th class="num">Pending Months</th>
              <th class="num">Outstanding Due</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($breakdown as $row): ?>
              <tr>
                <td data-label="Chit Scheme">
                  <?php if ($isAdmin): ?>
                    <a href="/admin/scheme.php?id=<?= (int)$row['scheme_id'] ?>"><?= h($row['scheme_name']) ?></a>
                  <?php else: ?>
                    <a href="/member/scheme.php?id=<?= (int)$row['scheme_id'] ?>"><?= h($row['scheme_name']) ?></a>
                  <?php endif; ?>
                </td>
                <td class="num font-mono" data-label="Chittal N0.">#<?= (int)$row['ticket_number'] ?></td>
                <td class="num font-mono" data-label="Pending Months"><?= (int)$row['pending_months'] ?></td>
                <td class="num font-mono" data-label="Outstanding Due">₹<?= money($row['ticket_due']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php elseif ($isAdmin): ?>
        <p class="section-note" style="margin-top: 14px;">
          Society administrative account. Financial dues apply to member enrollments in chit schemes.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Change Password Section -->
  <div class="card change-password-card">
    <div class="card-head-line">
      <h2>Change Password</h2>
      <div class="sub">Verify your existing password and choose a secure new password</div>
    </div>

    <form method="POST" action="/profile.php" class="password-change-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="change_password" value="1">

      <div class="field">
        <label for="current_password">Current Password <span style="color:var(--bad)">*</span></label>
        <input
          type="password"
          id="current_password"
          name="current_password"
          required
          autocomplete="current-password"
          placeholder="Enter your old/current password"
        >
        <span class="field-hint">We verify your existing password before making any changes.</span>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="new_password">New Password <span style="color:var(--bad)">*</span></label>
          <input
            type="password"
            id="new_password"
            name="new_password"
            required
            minlength="6"
            autocomplete="new-password"
            placeholder="Enter new password (min. 6 characters)"
          >
          <span class="field-hint">Must be at least 6 characters long.</span>
        </div>

        <div class="field">
          <label for="confirm_password">Re-enter New Password <span style="color:var(--bad)">*</span></label>
          <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            required
            minlength="6"
            autocomplete="new-password"
            placeholder="Re-enter new password"
          >
          <span class="field-hint">Must match the new password above.</span>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn">Update Password</button>
        <a class="btn secondary" href="<?= h($backUrl) ?>">Cancel</a>
      </div>
    </form>
  </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
