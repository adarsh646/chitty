<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$scheme = get_scheme($id);
if (!$scheme) { http_response_code(404); render_error('Scheme not found.'); exit; }

$summary = scheme_summary($id);
$subs = get_subscriptions($id);
$ledgers = array_map(fn($s) => ['sub' => $s, 'ledger' => get_ledger_for_subscription($s['id'])], $subs);

$title = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head">
    <div><h1><?= h($scheme['name']) ?> — Ledger</h1><div class="sub">Full month-by-month record for every ticket</div></div>
    <a class="btn secondary" href="/admin/scheme.php?id=<?= $id ?>">Back to scheme</a>
  </div>

  <div class="stat-row">
    <div class="stat"><div class="label">Total due so far</div><div class="value">₹<?= money($summary['due']) ?></div></div>
    <div class="stat"><div class="label">Total collected</div><div class="value">₹<?= money($summary['collected']) ?></div></div>
    <div class="stat"><div class="label">Outstanding</div><div class="value">₹<?= money($summary['outstanding']) ?></div></div>
  </div>

  <?php foreach ($ledgers as $entry): $sub = $entry['sub']; $ledger = $entry['ledger']; ?>
    <h3>Ticket #<?= (int)$sub['ticket_number'] ?> — <?= h($sub['member_name']) ?><?= $sub['has_won'] ? ' (won month ' . (int)$sub['won_month'] . ')' : '' ?></h3>
    <?php if (empty($ledger)): ?>
      <p class="empty">No installments recorded yet for this ticket.</p>
    <?php else: ?>
      <table>
        <thead><tr><th class="num">Month</th><th class="num">Due balance</th><th class="num">Paid</th><th>Status</th><th>Paid on</th></tr></thead>
        <tbody>
          <?php foreach ($ledger as $i): ?>
            <tr>
              <td class="num">#<?= (int)$i['month_number'] ?></td>
              <td class="num">₹<?= money(remaining_amount_due($i)) ?></td>
              <td class="num">₹<?= money($i['amount_paid']) ?></td>
              <td><span class="badge <?= h($i['status']) ?>"><?= h($i['status']) ?></span></td>
              <td><?= h($i['paid_date'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endforeach; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
