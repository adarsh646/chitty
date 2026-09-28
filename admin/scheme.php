<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$scheme = get_scheme($id);
if (!$scheme) { http_response_code(404); render_error('Scheme not found.'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the scheme and try again.');
        exit;
    }
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $ticket_number = (int) ($_POST['ticket_number'] ?? 0);
    $enrolled = $user_id && $ticket_number && add_subscription($id, $user_id, $ticket_number);
    header('Location: /admin/scheme.php?id=' . $id . ($enrolled ? '&enrolled=1' : '&enrollment_error=1'));
    exit;
}

$subs = get_subscriptions($id);
$auctions = get_auctions($id);
$summary = scheme_summary($id);
$allMembers = list_members();
$ticketsSoldOut = $summary['totalTickets'] >= (int) $scheme['duration_months'];

$title = $scheme['name'];
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head">
    <div>
      <h1><?= h($scheme['name']) ?></h1>
      <div class="sub">₹<?= money($scheme['chit_value']) ?> chitty · <?= (int)$scheme['duration_months'] ?> months · ₹<?= money($scheme['monthly_subscription']) ?>/month subscription · <?= h($scheme['commission_percent']) ?>% commission</div>
    </div>
    <a class="btn secondary" href="/admin/reports.php?id=<?= $id ?>">View reports</a>
  </div>

  <div class="stat-row">
    <div class="stat"><div class="label">Tickets filled</div><div class="value"><?= $summary['totalTickets'] ?> / <?= (int)$scheme['duration_months'] ?></div></div>
    <div class="stat"><div class="label">Auctions held</div><div class="value"><?= $summary['auctionsHeld'] ?> / <?= (int)$scheme['duration_months'] ?></div></div>
    <div class="stat"><div class="label">Collected</div><div class="value">₹<?= money($summary['collected']) ?></div></div>
    <div class="stat"><div class="label">Outstanding</div><div class="value">₹<?= money($summary['outstanding']) ?></div></div>
  </div>

  <?php if (isset($_GET['enrolled'])): ?>
    <div class="success-msg">Member enrolled successfully.</div>
  <?php elseif (isset($_GET['enrollment_error'])): ?>
    <div class="error-msg">Unable to enroll that ticket. Choose an available ticket number and a registered member.</div>
  <?php elseif (isset($_GET['auction_locked'])): ?>
    <div class="error-msg">Fill all <?= (int)$scheme['duration_months'] ?> tickets before starting an auction.</div>
  <?php endif; ?>

  <h2>Monthly auctions</h2>
  <p class="section-note">
    <?= $ticketsSoldOut
      ? 'All tickets are sold out. You can now enter auction results.'
      : 'Auctions unlock after all ' . (int)$scheme['duration_months'] . ' tickets are filled.' ?>
  </p>
  <div class="month-nav">
    <?php
    $heldMonths = [];
    foreach ($auctions as $a) { $heldMonths[$a['month_number']] = true; }
    for ($m = 1; $m <= $scheme['duration_months']; $m++):
        $held = $heldMonths[$m] ?? false;
    ?>
      <?php if ($ticketsSoldOut): ?>
        <a class="<?= $held ? 'won' : '' ?>" href="/admin/auction.php?id=<?= $id ?>&month=<?= $m ?>">
          Month <?= $m ?><?= $held ? ' ✓' : '' ?>
        </a>
      <?php else: ?>
        <span class="disabled">Month <?= $m ?></span>
      <?php endif; ?>
    <?php endfor; ?>
  </div>

  <h2>Tickets & members</h2>
  <?php if (empty($subs)): ?>
    <div class="empty">No members enrolled yet.</div>
  <?php else: ?>
    <table>
      <thead><tr><th class="num">Ticket</th><th>Member</th><th>Phone</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($subs as $s): ?>
          <tr>
            <td class="num">#<?= (int)$s['ticket_number'] ?></td>
            <td><?= h($s['member_name']) ?></td>
            <td><?= h($s['member_phone']) ?></td>
            <td><?= $s['has_won'] ? 'Won (month ' . (int)$s['won_month'] . ')' : 'Active' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="card" style="max-width:480px">
    <h3>Enroll a member</h3>
    <?php if ($ticketsSoldOut): ?>
      <div class="success-msg">All tickets sold out — <?= (int)$scheme['duration_months'] ?> of <?= (int)$scheme['duration_months'] ?> tickets are filled.</div>
    <?php elseif (empty($allMembers)): ?>
      <p class="section-note">No members registered yet. <a href="/admin/members.php">Add a member first</a>.</p>
    <?php else: ?>
      <form class="inline" method="POST" action="/admin/scheme.php?id=<?= $id ?>">
        <input type="hidden" name="enroll" value="1">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="field" style="flex:2">
          <label>Member</label>
          <select name="user_id" required>
            <?php foreach ($allMembers as $m): ?>
              <option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?> (<?= h($m['phone']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1">
          <label>Ticket #</label>
          <input type="number" name="ticket_number" min="1" max="<?= (int)$scheme['duration_months'] ?>" required>
        </div>
        <button type="submit" style="align-self:flex-end">Enroll</button>
      </form>
      <p class="section-note">Ticket numbers must be unique, from 1 to <?= (int)$scheme['duration_months'] ?>.</p>
    <?php endif; ?>
  </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
