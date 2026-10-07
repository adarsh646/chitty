<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_once __DIR__ . '/../includes/upi.php';
$user = require_member();

$schemes = schemes_for_user($user['id']);
$totalDue = get_user_total_due($user['id']);

$stmtPending = db()->prepare('
    SELECT p.*, cs.name AS scheme_name
    FROM payments p
    JOIN chit_schemes cs ON cs.id = p.scheme_id
    WHERE p.user_id = ? AND p.status = "under_processing"
    ORDER BY p.created_at DESC
');
$stmtPending->execute([$user['id']]);
$pendingList = $stmtPending->fetchAll();

$title = 'My Chitties';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head">
    <div>
      <h1>My Chitties</h1>
      <div class="sub">Chit schemes you're enrolled in</div>
    </div>
    <div class="page-head-actions">
      <div style="text-align: right;">
        <span style="font-size: 0.82rem; color: var(--ink-soft);">Total Outstanding Due</span>
        <div class="font-mono" style="font-size: 1.25rem; font-weight: 700; color: <?= $totalDue > 0 ? 'var(--bad)' : 'var(--good)' ?>;">
          ₹<?= money($totalDue) ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($pendingList)): ?>
    <div class="info-msg processing-banner" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
      <div>
        <strong>Payment Under Processing:</strong> You have <?= count($pendingList) ?> payment submission(s) currently being verified by the administrator.
      </div>
      <div style="font-size: 0.85rem;">
        <?php foreach ($pendingList as $p): ?>
          <span class="sub-pending-pill" style="margin-left: 6px;">Txn: <?= h($p['transaction_id']) ?> (₹<?= money($p['amount']) ?>)</span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (empty($schemes)): ?>
    <div class="empty">You're not enrolled in any chit scheme yet. Contact the society office.</div>
  <?php else: ?>
    <table class="responsive-card-table schemes-table">
      <thead><tr><th>Scheme</th><th class="num">Chit value</th><th class="num">Monthly subscription</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($schemes as $s): ?>
          <tr>
            <td data-label="Scheme"><a href="/member/scheme.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a></td>
            <td class="num" data-label="Chit value">₹<?= money($s['chit_value']) ?></td>
            <td class="num" data-label="Monthly subscription">₹<?= money($s['monthly_subscription']) ?></td>
            <td data-label="Status"><span class="badge <?= h($s['status']) ?>"><?= h($s['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
