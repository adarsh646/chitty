<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
$user = require_member();

$id = (int) ($_GET['id'] ?? 0);
$scheme = get_scheme($id);
if (!$scheme) { http_response_code(404); render_error('Scheme not found.'); exit; }

$mySubs = get_subscriptions_for_user_in_scheme($id, $user['id']);
if (empty($mySubs)) { http_response_code(403); render_error("You are not enrolled in this chitty."); exit; }

$ledgers = array_map(fn($s) => ['sub' => $s, 'ledger' => get_ledger_for_subscription($s['id'])], $mySubs);
$auctions = get_auctions($id);

$title = $scheme['name'];
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head">
    <div>
      <h1><?= h($scheme['name']) ?></h1>
      <div class="sub">₹<?= money($scheme['chit_value']) ?> chitty · <?= (int)$scheme['duration_months'] ?> months · ₹<?= money($scheme['monthly_subscription']) ?>/month base subscription</div>
    </div>
    <a class="btn secondary" href="/member/index.php">Back to my chitties</a>
  </div>

  <?php foreach ($ledgers as $entry): $sub = $entry['sub']; $ledger = $entry['ledger']; ?>
    <h2>Ticket #<?= (int)$sub['ticket_number'] ?><?= $sub['has_won'] ? ' — won in month ' . (int)$sub['won_month'] : '' ?></h2>
    <?php if (empty($ledger)): ?>
      <p class="empty">No dues have been generated for this ticket yet.</p>
    <?php else: ?>
      <div class="history-table-region">
        <div class="history-table-scroll" tabindex="0" role="region" aria-label="Ticket #<?= (int)$sub['ticket_number'] ?> payment history">
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
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <h2>Auction history</h2>
  <?php if (empty($auctions)): ?>
    <p class="empty">No auctions have been held yet.</p>
  <?php else: ?>
    <div class="history-table-region">
      <div class="history-table-scroll" tabindex="0" role="region" aria-label="Auction history">
        <table>
          <thead><tr><th class="num">Month</th><th>Date</th><th>Winner</th><th class="num">Bid</th><th class="num">Prize paid</th></tr></thead>
          <tbody>
            <?php foreach ($auctions as $a): ?>
              <tr>
                <td class="num">#<?= (int)$a['month_number'] ?></td>
                <td><?= h($a['auction_date']) ?></td>
                <td><?= $a['winner_name'] ? ('#' . (int)$a['winner_ticket'] . ' — ' . h($a['winner_name'])) : '—' ?></td>
                <td class="num">₹<?= money($a['bid_amount'] ?? 0) ?></td>
                <td class="num">₹<?= money($a['prize_amount'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
