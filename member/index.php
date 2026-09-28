<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
$user = require_member();

$schemes = schemes_for_user($user['id']);
$title = 'My Chitties';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head"><div><h1>My Chitties</h1><div class="sub">Chit schemes you're enrolled in</div></div></div>

  <?php if (empty($schemes)): ?>
    <div class="empty">You're not enrolled in any chit scheme yet. Contact the society office.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Scheme</th><th class="num">Chit value</th><th class="num">Monthly subscription</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($schemes as $s): ?>
          <tr>
            <td><a href="/member/scheme.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a></td>
            <td class="num">₹<?= money($s['chit_value']) ?></td>
            <td class="num">₹<?= money($s['monthly_subscription']) ?></td>
            <td><span class="badge <?= h($s['status']) ?>"><?= h($s['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
