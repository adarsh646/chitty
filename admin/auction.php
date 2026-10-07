<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$month_number = (int) ($_GET['month'] ?? 0);
$scheme = get_scheme($id);
if (!$scheme) { http_response_code(404); render_error('Scheme not found.'); exit; }
if ($month_number < 1 || $month_number > (int) $scheme['duration_months']) {
    http_response_code(404);
    render_error('Auction month not found.');
    exit;
}
if (!is_scheme_fully_enrolled($id)) {
    header('Location: /admin/scheme.php?id=' . $id . '&auction_locked=1');
    exit;
}

$auctionError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the auction and try again.');
        exit;
    }
    $auction_date = $_POST['auction_date'] ?? '';
    $winning_subscription_id = !empty($_POST['winning_subscription_id']) ? (int)$_POST['winning_subscription_id'] : null;
    $bid_amount = (float) ($_POST['bid_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '') ?: null;

    try {
        settle_auction($id, $month_number, $auction_date, $winning_subscription_id, $bid_amount, $notes);
        header('Location: /admin/month.php?id=' . $id . '&month=' . $month_number);
        exit;
    } catch (RuntimeException $e) {
        $auctionError = $e->getMessage();
    }
}

$subs = get_subscriptions($id);
$existing = get_auction_for_month($id, $month_number);
$eligible = array_values(array_filter($subs, fn($s) => !$s['has_won']));

$title = 'Month ' . $month_number . ' auction';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head">
    <div>
      <h1><?= h($scheme['name']) ?> — Month <?= $month_number ?> auction</h1>
      <div class="sub">Monthly subscription: ₹<?= money($scheme['monthly_subscription']) ?> · Commission: <?= h($scheme['commission_percent']) ?>%</div>
    </div>
    <a class="btn secondary" href="/admin/scheme.php?id=<?= $id ?>">Back to scheme</a>
  </div>

  <div class="card" style="max-width:560px">
    <?php if ($auctionError): ?>
      <div class="error-msg"><?= h($auctionError) ?></div>
    <?php endif; ?>
    <?php if (empty($eligible) && !$existing): ?>
      <p class="section-note">Every Chittal N0. has already won. A winning Chittal N0. is required to record an auction result.</p>
    <?php endif; ?>
    <form method="POST" action="/admin/auction.php?id=<?= $id ?>&month=<?= $month_number ?>">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="field-row">
        <div class="field">
          <label>Auction date</label>
          <input type="date" name="auction_date" value="<?= h($existing['auction_date'] ?? '') ?>" required>
        </div>
        <div class="field">
          <label>Winning bid / discount (₹)</label>
          <input type="number" step="1" inputmode="numeric" name="bid_amount" value="<?= h($existing['bid_amount'] ?? 0) ?>" required>
        </div>
      </div>
      <div class="field">
        <label>Winning Chittal N0.</label>
        <select name="winning_subscription_id" required>
          <option value="" disabled <?= !$existing ? 'selected' : '' ?>>— Select winning Chittal N0. —</option>
          <?php foreach ($subs as $s): ?>
            <option value="<?= (int)$s['id'] ?>"
              <?= ($existing && (int)$existing['winning_subscription_id'] === (int)$s['id']) ? 'selected' : '' ?>
              <?= $s['has_won'] && (!$existing || (int)$existing['winning_subscription_id'] !== (int)$s['id']) ? 'disabled' : '' ?>>
              #<?= (int)$s['ticket_number'] ?> — <?= h($s['member_name']) ?><?= $s['has_won'] ? ' (already won)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Notes (optional)</label>
        <input type="text" name="notes" value="<?= h($existing['notes'] ?? '') ?>">
      </div>
      <p class="section-note">
        Commission is <?= h($scheme['commission_percent']) ?>% of the chit value, taken from the winning bid.
        What's left of the bid is split across all <?= (int)$scheme['duration_months'] ?> Chittals and reduces
        everyone's installment for this month. This recalculates and overwrites this month's dues if
        you submit again.
      </p>
      <button type="submit">Save auction result & generate dues</button>
    </form>
  </div>

  <?php if ($existing): ?>
    <div class="card" style="max-width:560px">
      <h3>This month's settlement</h3>
      <table>
        <tr><td>Winning Chittal N0.</td><td class="num">#<?= (int)$existing['winner_ticket'] ?> — <?= h($existing['winner_name']) ?></td></tr>
        <tr><td>Prize paid to winner</td><td class="num">₹<?= money($existing['prize_amount']) ?></td></tr>
        <tr><td>Society commission</td><td class="num">₹<?= money($existing['commission_amount']) ?></td></tr>
        <tr><td>Dividend pool shared</td><td class="num">₹<?= money($existing['dividend_pool']) ?></td></tr>
        <tr><td>Dividend per Chittal N0.</td><td class="num">₹<?= money($existing['dividend_per_ticket']) ?></td></tr>
        <tr><td><strong>Net installment this month</strong></td><td class="num"><strong>₹<?= money($existing['net_installment']) ?></strong></td></tr>
      </table>
      <a class="btn small" href="/admin/month.php?id=<?= $id ?>&month=<?= $month_number ?>">View & collect this month's dues →</a>
    </div>
  <?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
