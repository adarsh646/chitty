<?php
// Administrator Payment Notifications & Verification Page
// Allows admins to review member UPI payment submissions, view transaction IDs & member details,
// and verify transactions to mark them as successful.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_once __DIR__ . '/../includes/upi.php';

$currentUser = require_admin();

$actionError = null;
$actionSuccess = null;

// Handle Verification or Rejection POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Security token expired. Please refresh the page and try again.');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $notificationId = (int) ($_POST['notification_id'] ?? 0);

    if ($notificationId > 0 && $action === 'verify') {
        try {
            $res = verify_upi_payment($notificationId, (int) $currentUser['id']);
            header('Location: /admin/notifications.php?status=verified_success&txn=' . urlencode($res['transaction_id']));
            exit;
        } catch (\Throwable $e) {
            $actionError = $e->getMessage();
        }
    } elseif ($notificationId > 0 && $action === 'reject') {
        try {
            $reason = trim((string) ($_POST['reject_reason'] ?? ''));
            reject_upi_payment($notificationId, (int) $currentUser['id'], $reason);
            header('Location: /admin/notifications.php?status=rejected_success');
            exit;
        } catch (\Throwable $e) {
            $actionError = $e->getMessage();
        }
    }
}

$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending', 'verified', 'all'], true)) {
    $tab = 'pending';
}

$statusFilter = ($tab === 'all') ? null : $tab;
$notifications = get_payment_notifications($statusFilter);

// Calculate metric totals
$allNotifs = get_payment_notifications(null);
$pendingCount = 0;
$verifiedCount = 0;
$totalVerifiedAmount = 0.0;

foreach ($allNotifs as $n) {
    if ($n['status'] === 'pending') {
        $pendingCount++;
    } elseif ($n['status'] === 'verified') {
        $verifiedCount++;
        $totalVerifiedAmount += (float) $n['amount'];
    }
}

$title = 'Payment Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

  <div class="page-head">
    <div>
      <h1>Payment Notifications</h1>
      <div class="sub">Review member UPI transactions and verify submitted payments</div>
    </div>
    <div class="page-head-actions">
      <a class="btn secondary" href="/admin/index.php">Back to Schemes</a>
    </div>
  </div>

  <?php if (($_GET['status'] ?? '') === 'verified_success'): ?>
    <div class="success-msg">
      <strong>Payment Verified!</strong> Transaction ID <code><?= h($_GET['txn'] ?? '') ?></code> was marked verified. The member ledger is updated to Paid.
    </div>
  <?php elseif (($_GET['status'] ?? '') === 'rejected_success'): ?>
    <div class="error-msg">
      The payment submission has been rejected and marked as invalid.
    </div>
  <?php elseif ($actionError): ?>
    <div class="error-msg">
      <?= h($actionError) ?>
    </div>
  <?php endif; ?>

  <!-- Summary Metric Cards -->
  <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 24px;">
    <div class="stat-card <?= $pendingCount > 0 ? 'highlight-amber' : '' ?>">
      <div class="stat-label">Pending Verification</div>
      <div class="stat-value font-mono" style="<?= $pendingCount > 0 ? 'color: var(--accent);' : '' ?>"><?= $pendingCount ?></div>
      <div class="stat-sub"><?= $pendingCount === 1 ? '1 payment awaiting review' : $pendingCount . ' payments awaiting review' ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Verified</div>
      <div class="stat-value font-mono" style="color: var(--good);"><?= $verifiedCount ?></div>
      <div class="stat-sub">Completed transactions</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Verified Collections</div>
      <div class="stat-value font-mono">₹<?= money($totalVerifiedAmount) ?></div>
      <div class="stat-sub">Approved UPI payments</div>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div class="notif-tab-bar">
    <a href="/admin/notifications.php?tab=pending" class="notif-tab <?= $tab === 'pending' ? 'active' : '' ?>">
      Pending Verifications
      <?php if ($pendingCount > 0): ?>
        <span class="tab-badge"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
    <a href="/admin/notifications.php?tab=verified" class="notif-tab <?= $tab === 'verified' ? 'active' : '' ?>">
      Verified History
      <span class="tab-badge secondary"><?= $verifiedCount ?></span>
    </a>
    <a href="/admin/notifications.php?tab=all" class="notif-tab <?= $tab === 'all' ? 'active' : '' ?>">
      All Submissions
      <span class="tab-badge secondary"><?= count($allNotifs) ?></span>
    </a>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="empty" style="padding: 48px 24px; text-align: center;">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-muted); margin-bottom: 12px;">
        <rect x="2" y="5" width="20" height="14" rx="2"></rect>
        <line x1="2" y1="10" x2="22" y2="10"></line>
      </svg>
      <div>No payment notifications found in this tab.</div>
      <?php if ($tab === 'pending'): ?>
        <div class="sub" style="margin-top: 6px;">All member UPI submissions have been verified.</div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="history-table-region">
      <div class="history-table-scroll" tabindex="0" role="region" aria-label="Payment notifications list">
        <table class="responsive-card-table notif-table">
          <thead>
            <tr>
              <th>Transaction ID</th>
              <th>Member Details</th>
              <th>Chit Scheme / Chittal</th>
              <th class="num">Amount</th>
              <th>Submitted Date</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($notifications as $n): ?>
              <tr>
                <td data-label="Transaction ID">
                  <div class="txn-cell">
                    <span class="txn-id font-mono"><?= h($n['transaction_id']) ?></span>
                    <button type="button" class="btn-copy-sm" onclick="copyText('<?= h($n['transaction_id']) ?>', this)" title="Copy Transaction ID">
                      Copy
                    </button>
                  </div>
                </td>
                <td data-label="Member Details">
                  <div class="member-cell">
                    <strong><?= h($n['member_name']) ?></strong>
                    <div class="sub-contact">
                      <a href="tel:<?= h($n['member_phone']) ?>" class="phone-link"><?= h($n['member_phone']) ?></a>
                      <?php if (!empty($n['member_email'])): ?>
                        · <span class="email-text"><?= h($n['member_email']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td data-label="Chit Scheme / Chittal">
                  <div>
                    <a href="/admin/scheme.php?id=<?= (int)$n['scheme_id'] ?>" style="font-weight: 600;">
                      <?= h($n['scheme_name']) ?>
                    </a>
                    <div class="sub" style="font-size: 0.8rem; margin-top: 2px;">
                      Month #<?= (int)$n['month_number'] ?> · Chittal N0. #<?= (int)$n['ticket_number'] ?>
                    </div>
                  </div>
                </td>
                <td class="num font-mono" data-label="Amount">
                  <strong style="color: var(--text);">₹<?= money($n['amount']) ?></strong>
                </td>
                <td data-label="Submitted Date">
                  <div style="font-size: 0.88rem;">
                    <?= date('d M Y, h:i A', strtotime($n['created_at'])) ?>
                  </div>
                </td>
                <td data-label="Status">
                  <?php if ($n['status'] === 'pending'): ?>
                    <span class="badge processing">Under Processing</span>
                  <?php elseif ($n['status'] === 'verified'): ?>
                    <span class="badge paid">Verified</span>
                    <?php if (!empty($n['verified_by_name'])): ?>
                      <div class="sub" style="font-size: 0.72rem; margin-top: 3px;">
                        by <?= h($n['verified_by_name']) ?>
                      </div>
                    <?php endif; ?>
                  <?php elseif ($n['status'] === 'rejected'): ?>
                    <span class="badge danger">Rejected</span>
                  <?php endif; ?>
                </td>
                <td data-label="Action">
                  <?php if ($n['status'] === 'pending'): ?>
                    <div class="notif-actions">
                      <form method="POST" action="/admin/notifications.php" onsubmit="return confirm('Verify this payment of ₹<?= money($n['amount']) ?> for <?= h(addslashes($n['member_name'])) ?> (Txn: <?= h(addslashes($n['transaction_id'])) ?>)?');">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                        <input type="hidden" name="action" value="verify">
                        <button type="submit" class="btn success small" title="Verify payment and mark installment paid">
                          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                            <polyline points="20 6 9 17 4 12"></polyline>
                          </svg>
                          <span>Verified</span>
                        </button>
                      </form>
                      <form method="POST" action="/admin/notifications.php" onsubmit="return confirm('Are you sure you want to reject this payment submission as invalid?');" style="margin-top: 4px;">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn secondary danger small" title="Mark payment as rejected/invalid">
                          Reject
                        </button>
                      </form>
                    </div>
                  <?php elseif ($n['status'] === 'verified'): ?>
                    <div class="verified-check">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                      </svg>
                      <span style="color: var(--good); font-size: 0.82rem; font-weight: 500;">Approved</span>
                    </div>
                  <?php else: ?>
                    <span style="color: var(--text-muted); font-size: 0.82rem;">Rejected</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <script>
  function copyText(text, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function() {
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(function() {
          btn.textContent = orig;
          btn.classList.remove('copied');
        }, 1800);
      });
    } else {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      const orig = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function() { btn.textContent = orig; }, 1800);
    }
  }
  </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
