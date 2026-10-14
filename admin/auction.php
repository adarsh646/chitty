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
    redirect_masked('/admin/scheme.php?id=' . $id . '&auction_locked=1');
}

// Ensure dues exist for this month
ensure_month_dues_generated($id, $month_number);

$isFinalMonth = ($month_number === (int)$scheme['duration_months']);
$canStart = can_start_auction($id, $month_number);
$existing = get_auction_for_month($id, $month_number);
$commission_amount = round($scheme['chit_value'] * ($scheme['commission_percent'] / 100), 2);
$finalPrizeAmount = round($scheme['chit_value'] - $commission_amount, 2);

$auctionError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the auction and try again.');
        exit;
    }
    $auction_date = $_POST['auction_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '') ?: null;

    if ($isFinalMonth) {
        $lastSub = get_last_remaining_subscription($id);
        $winning_subscription_id = $lastSub ? (int)$lastSub['id'] : (int)($existing['winning_subscription_id'] ?? 0);
        $bid_amount = 0.00;
    } else {
        $winning_subscription_id = !empty($_POST['winning_subscription_id']) ? (int)$_POST['winning_subscription_id'] : null;
        $bid_amount = (float) ($_POST['bid_amount'] ?? 0);
    }

    try {
        settle_auction($id, $month_number, $auction_date, $winning_subscription_id, $bid_amount, $notes);
        redirect_masked('/admin/month.php?id=' . $id . '&month=' . $month_number);
    } catch (RuntimeException $e) {
        $auctionError = $e->getMessage();
    }
}

$subs = get_subscriptions($id);
$eligible = array_values(array_filter($subs, fn($s) => !$s['has_won']));
$lastRemainingSub = $isFinalMonth ? get_last_remaining_subscription($id) : null;

$title = 'Month ' . $month_number . ' auction';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head">
    <div>
      <h1><?= h($scheme['name']) ?> — Month <?= $month_number ?> <?= $isFinalMonth ? 'Final Settlement' : 'Auction' ?></h1>
      <div class="sub">
        Monthly subscription: ₹<?= money($scheme['monthly_subscription']) ?> · Commission: <?= h($scheme['commission_percent']) ?>%
        <?= $isFinalMonth ? ' · <strong>Final Month (No Bidding)</strong>' : '' ?>
      </div>
    </div>
    <a class="btn secondary" href="/admin/scheme.php?id=<?= $id ?>">Back to scheme</a>
  </div>

  <?php if ($auctionError): ?>
    <div class="error-msg"><?= h($auctionError) ?></div>
  <?php endif; ?>

  <!-- Collection Status Notice -->
  <?php if (!$canStart['allowed'] && !$existing): ?>
    <div class="info-msg" style="border-left: 4px solid var(--accent); margin-bottom: 20px;">
      <div style="font-weight: 700; font-size: 1rem; margin-bottom: 4px;">
        Payment Collection In Progress (<?= (int)$canStart['collection']['paid_count'] ?> of <?= (int)$canStart['collection']['total_tickets'] ?> Paid)
      </div>
      <p style="margin: 0 0 10px 0;">
        <?= h($canStart['reason']) ?>
      </p>
      <?php if (!empty($canStart['collection']['pending_upi_count'])): ?>
        <p style="margin: 0 0 10px 0; color: #B45309; font-weight: 600;">
          ⚠️ <?= (int)$canStart['collection']['pending_upi_count'] ?> payment submission(s) are awaiting verification in <a href="/admin/notifications.php" style="text-decoration: underline;">Payment Notifications</a>.
        </p>
      <?php endif; ?>
      <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a class="btn small" href="/admin/month.php?id=<?= $id ?>&month=<?= $month_number ?>">
          View & Collect Month <?= $month_number ?> Dues &rarr;
        </a>
        <?php if (!empty($canStart['collection']['pending_upi_count'])): ?>
          <a class="btn small secondary" href="/admin/notifications.php">
            Go to Notifications &rarr;
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($canStart['collection']['unpaid_installments'])): ?>
      <div class="card" style="max-width: 680px; margin-bottom: 24px;">
        <h3 style="margin-top:0;">Pending Installments for Month <?= $month_number ?></h3>
        <p class="section-note">These members must complete their installment payment before the auction can be conducted.</p>
        <table class="responsive-card-table" style="font-size: 0.88rem;">
          <thead>
            <tr>
              <th class="num">Chittal N0.</th>
              <th>Member</th>
              <th class="num">Remaining Due</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($canStart['collection']['unpaid_installments'] as $unp): ?>
              <tr>
                <td class="num font-mono" data-label="Chittal N0.">#<?= (int)$unp['ticket_number'] ?></td>
                <td data-label="Member">
                  <strong><?= h($unp['member_name']) ?></strong>
                  <div class="sub"><?= h($unp['member_phone']) ?></div>
                </td>
                <td class="num font-mono" data-label="Remaining Due" style="color: var(--bad); font-weight: 600;">
                  ₹<?= money($unp['remaining']) ?>
                </td>
                <td data-label="Status">
                  <?php if (!empty($unp['has_pending_upi'])): ?>
                    <span class="badge processing" style="background: #FFF4DE; color: #B45309; border: 1px solid #FDE68A;">
                      UPI Verification Pending
                    </span>
                    <div class="sub font-mono" style="font-size: 0.72rem; margin-top: 2px;">
                      Txn: <?= h($unp['pending_txn']) ?>
                    </div>
                  <?php else: ?>
                    <span class="badge <?= h($unp['status']) ?>"><?= h($unp['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td data-label="Action">
                  <?php if (!empty($unp['has_pending_upi'])): ?>
                    <a class="btn small success" href="/admin/notifications.php" style="padding: 4px 10px; font-size: 0.78rem;">
                      Verify in Notifications &rarr;
                    </a>
                  <?php else: ?>
                    <a class="btn small secondary" href="/admin/month.php?id=<?= $id ?>&month=<?= $month_number ?>" style="padding: 4px 10px; font-size: 0.78rem;">
                      Record Payment &rarr;
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <?php if (!$existing): ?>
      <div class="success-msg" style="margin-bottom: 20px;">
        <strong>✓ All <?= (int)$canStart['collection']['total_tickets'] ?> members have paid Month <?= $month_number ?> installments.</strong>
        You can now record the auction result.
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($isFinalMonth): ?>
    <!-- FINAL MONTH SETTLEMENT (NO BIDDING REQUIRED) -->
    <div class="card" style="max-width: 620px;">
      <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
        <span class="badge paid" style="font-size: 0.85rem; padding: 4px 10px;">Final Month</span>
        <h2 style="margin: 0; font-size: 1.25rem;">Final Month Payout Settlement (No Bidding)</h2>
      </div>

      <p style="color: var(--ink-soft); font-size: 0.92rem; line-height: 1.5; margin-bottom: 16px;">
        In the final month, only one Chittal N0. remains that has not won. Therefore, no auction bidding is conducted.
        The final beneficiary receives the full chit value minus only the society commission.
      </p>

      <?php
        $finalWinner = $lastRemainingSub ?: ($existing ? ['ticket_number' => $existing['winner_ticket'], 'member_name' => $existing['winner_name']] : null);
      ?>

      <div class="payment-summary-box" style="margin-bottom: 18px;">
        <div class="summary-row">
          <span class="summary-label">Final Beneficiary (Last Remaining)</span>
          <span class="summary-val font-mono" style="color: var(--accent);">
            #<?= (int)($finalWinner['ticket_number'] ?? 0) ?> — <?= h($finalWinner['member_name'] ?? '—') ?>
          </span>
        </div>
        <div class="summary-row">
          <span class="summary-label">Total Chit Value</span>
          <span class="summary-val font-mono">₹<?= money($scheme['chit_value']) ?></span>
        </div>
        <div class="summary-row">
          <span class="summary-label">Society Commission (<?= h($scheme['commission_percent']) ?>%)</span>
          <span class="summary-val font-mono">- ₹<?= money($commission_amount) ?></span>
        </div>
        <div class="summary-row" style="border-top: 2px solid var(--rule); padding-top: 8px; margin-top: 6px;">
          <strong style="color: var(--ink); font-size: 1rem;">Full Prize Paid to Winner</strong>
          <strong class="font-mono due-val" style="color: var(--good); font-size: 1.2rem;">
            ₹<?= money($finalPrizeAmount) ?>
          </strong>
        </div>
      </div>

      <form method="POST" action="/admin/auction.php?id=<?= $id ?>&month=<?= $month_number ?>">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="field">
          <label>Settlement Date <span style="color:var(--bad)">*</span></label>
          <input type="date" name="auction_date" value="<?= h($existing['auction_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="field">
          <label>Notes (optional)</label>
          <input type="text" name="notes" value="<?= h($existing['notes'] ?? 'Final month settlement: full chit pot paid to the last remaining Chittal with only society commission deducted.') ?>">
        </div>

        <?php if ($canStart['allowed'] || $existing): ?>
          <button type="submit" class="btn success" style="margin-top: 10px; width: 100%; padding: 12px; font-weight: 600;">
            Confirm & Record Final Month Payout (₹<?= money($finalPrizeAmount) ?>)
          </button>
        <?php else: ?>
          <button type="button" class="btn secondary" disabled style="margin-top: 10px; width: 100%; padding: 12px;">
            Payment Collection In Progress — Final Payout Locked
          </button>
        <?php endif; ?>
      </form>
    </div>

  <?php else: ?>
    <!-- REGULAR MONTH AUCTION (WITH BIDDING) -->
    <div class="card" style="max-width:560px">
      <?php if (empty($eligible) && !$existing): ?>
        <p class="section-note">Every Chittal N0. has already won. A winning Chittal N0. is required to record an auction result.</p>
      <?php endif; ?>

      <form method="POST" action="/admin/auction.php?id=<?= $id ?>&month=<?= $month_number ?>">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="field-row">
          <div class="field">
            <label>Auction date <span style="color:var(--bad)">*</span></label>
            <input type="date" name="auction_date" value="<?= h($existing['auction_date'] ?? date('Y-m-d')) ?>" required>
          </div>
          <div class="field">
            <label>Winning bid / discount (₹) <span style="color:var(--bad)">*</span></label>
            <input type="number" step="1" inputmode="numeric" name="bid_amount" value="<?= h($existing['bid_amount'] ?? 0) ?>" required>
          </div>
        </div>
        <div class="field">
          <label>Winning Chittal N0. <span style="color:var(--bad)">*</span></label>
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
          everyone's installment for the next month (Month <?= $month_number + 1 ?>).
        </p>

        <?php if ($canStart['allowed'] || $existing): ?>
          <button type="submit">Save auction result & generate Month <?= $month_number + 1 ?> dues</button>
        <?php else: ?>
          <button type="button" class="btn secondary" disabled>
            Payment Collection In Progress — Auction Locked
          </button>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($existing): ?>
    <div class="card" style="max-width:560px; margin-top: 20px;">
      <h3>This month's settlement</h3>
      <table>
        <tr><td>Winning Chittal N0.</td><td class="num font-mono">#<?= (int)$existing['winner_ticket'] ?> — <?= h($existing['winner_name']) ?></td></tr>
        <tr><td>Prize paid to winner</td><td class="num font-mono" style="color: var(--good); font-weight:700;">₹<?= money($existing['prize_amount']) ?></td></tr>
        <tr><td>Society commission</td><td class="num font-mono">₹<?= money($existing['commission_amount']) ?></td></tr>
        <tr><td>Dividend pool shared</td><td class="num font-mono">₹<?= money($existing['dividend_pool']) ?></td></tr>
        <tr><td>Dividend per Chittal N0.</td><td class="num font-mono">₹<?= money($existing['dividend_per_ticket']) ?></td></tr>
        <tr><td><strong>Net installment</strong></td><td class="num font-mono"><strong>₹<?= money($existing['net_installment']) ?></strong></td></tr>
      </table>
      <div style="margin-top: 14px;">
        <a class="btn small secondary" href="/admin/month.php?id=<?= $id ?>&month=<?= $month_number ?>">View Month <?= $month_number ?> dues &rarr;</a>
        <?php if (!$isFinalMonth && $month_number < (int)$scheme['duration_months']): ?>
          <a class="btn small" href="/admin/month.php?id=<?= $id ?>&month=<?= $month_number + 1 ?>" style="margin-left: 6px;">View Month <?= $month_number + 1 ?> generated dues &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
