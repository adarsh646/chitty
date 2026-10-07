<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_scheme'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the schemes page and try again.');
        exit;
    }

    $schemeId = (int) ($_POST['scheme_id'] ?? 0);
    $result = $schemeId > 0 ? delete_scheme($schemeId) : 'not_found';
    header('Location: /admin/index.php?delete=' . urlencode($result));
    exit;
}

$schemes = list_schemes();
$summaries = array_map(fn($s) => scheme_summary($s['id']), $schemes);

$title = 'Schemes';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head">
    <div>
      <h1>Chit Schemes</h1>
      <div class="sub">All chitties run by the society</div>
    </div>
    <a class="btn" href="/admin/scheme-new.php">+ New scheme</a>
  </div>

  <?php if (($_GET['delete'] ?? '') === 'deleted'): ?>
    <div class="success-msg">The chit scheme and its related records were deleted.</div>
  <?php elseif (($_GET['delete'] ?? '') === 'active'): ?>
    <div class="error-msg">An active chit scheme cannot be deleted. Close it before deleting it.</div>
  <?php elseif (($_GET['delete'] ?? '') === 'not_found'): ?>
    <div class="error-msg">That chit scheme could not be found.</div>
  <?php endif; ?>

  <?php if (empty($summaries)): ?>
    <div class="empty">No chit schemes yet. Start by creating one.</div>
  <?php else: ?>
    <table class="responsive-card-table admin-schemes-table">
      <thead>
        <tr>
          <th>Scheme</th>
          <th class="num">Chit value</th>
          <th class="num">Duration</th>
          <th class="num">Chittals filled</th>
          <th class="num">Collected</th>
          <th class="num">Outstanding</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($summaries as $s): ?>
          <tr>
            <td data-label="Scheme"><a href="/admin/scheme.php?id=<?= (int)$s['scheme']['id'] ?>"><?= h($s['scheme']['name']) ?></a></td>
            <td class="num font-mono" data-label="Chit value">₹<?= money($s['scheme']['chit_value']) ?></td>
            <td class="num font-mono" data-label="Duration"><?= (int)$s['scheme']['duration_months'] ?> mo</td>
            <td class="num font-mono" data-label="Chittals filled"><?= $s['totalTickets'] ?> / <?= (int)$s['scheme']['duration_months'] ?></td>
            <td class="num font-mono" data-label="Collected">₹<?= money($s['collected']) ?></td>
            <td class="num font-mono" data-label="Outstanding">₹<?= money($s['outstanding']) ?></td>
            <td data-label="Status">
              <div class="scheme-status-actions">
                <span class="badge <?= h($s['scheme']['status']) ?>"><?= h($s['scheme']['status']) ?></span>
                <?php if ($s['scheme']['status'] === 'closed'): ?>
                  <form method="POST" action="/admin/index.php" onsubmit="return confirm('Delete this closed chit scheme and all its auctions, enrollments, and payments? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="scheme_id" value="<?= (int)$s['scheme']['id'] ?>">
                    <button class="btn danger small" type="submit" name="delete_scheme" value="1">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
