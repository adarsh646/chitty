<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$month_number = (int) ($_GET['month'] ?? 0);
$scheme = get_scheme($id);
if (!$scheme) { http_response_code(404); render_error('Scheme not found.'); exit; }

$paymentError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['installment_id'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the monthly dues page and try again.');
        exit;
    }
    $installment_id = (int) $_POST['installment_id'];
    $amount_paid = (float) ($_POST['amount_paid'] ?? 0);
    $paid_date = ($_POST['paid_date'] ?? '') ?: null;
    try {
        record_payment($installment_id, $amount_paid, $paid_date);
        header('Location: /admin/month.php?id=' . $id . '&month=' . $month_number . '&payment_recorded=1');
        exit;
    } catch (RuntimeException $e) {
        $paymentError = $e->getMessage();
    }
}

$auction = get_auction_for_month($id, $month_number);
$installments = get_installments($id, $month_number);

$title = 'Month ' . $month_number . ' dues';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head">
    <div>
      <h1><?= h($scheme['name']) ?> — Month <?= $month_number ?> dues</h1>
      <?php if ($auction): ?><div class="sub">Net installment: ₹<?= money($auction['net_installment']) ?> per Chittal N0.</div><?php endif; ?>
    </div>
    <a class="btn secondary" href="/admin/auction.php?id=<?= $id ?>&month=<?= $month_number ?>">Edit auction</a>
  </div>

  <?php if (isset($_GET['payment_recorded'])): ?>
    <div class="success-msg">Payment recorded successfully.</div>
  <?php elseif ($paymentError): ?>
    <div class="error-msg"><?= h($paymentError) ?></div>
  <?php endif; ?>

  <?php if (!$auction): ?>
    <div class="empty">No auction has been recorded for this month yet. <a href="/admin/auction.php?id=<?= $id ?>&month=<?= $month_number ?>">Enter it first</a> to generate dues.</div>
  <?php elseif (empty($installments)): ?>
    <div class="empty">No installments generated. Save the auction form again to generate them.</div>
  <?php else: ?>
    <table class="responsive-card-table admin-month-table">
      <thead>
        <tr><th class="num">Chittal N0.</th><th>Member</th><th class="num">Due balance</th><th class="num">Paid</th><th>Status</th><th>Record payment</th></tr>
      </thead>
      <tbody>
        <?php foreach ($installments as $i): ?>
          <tr>
            <td class="num font-mono" data-label="Chittal N0.">#<?= (int)$i['ticket_number'] ?></td>
            <td data-label="Member"><?= h($i['member_name']) ?></td>
            <td class="num font-mono" data-label="Due balance">₹<?= money(remaining_amount_due($i)) ?></td>
            <td class="num font-mono" data-label="Paid">₹<?= money($i['amount_paid']) ?></td>
            <td data-label="Status"><span class="badge <?= h($i['status']) ?>"><?= h($i['status']) ?></span></td>
            <td data-label="Record payment">
              <?php if ($i['status'] !== 'paid'): ?>
                <form class="inline" method="POST" action="/admin/month.php?id=<?= $id ?>&month=<?= $month_number ?>">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="installment_id" value="<?= (int)$i['id'] ?>">
                  <input type="number" step="0.01" min="0.01" max="<?= h(remaining_amount_due($i)) ?>" name="amount_paid" placeholder="₹" style="width:90px" inputmode="decimal" required>
                  <input type="date" name="paid_date" style="width:140px">
                  <button class="small" type="submit">Record</button>
                </form>
              <?php else: ?>
                <span class="section-note" style="margin:0">Paid on <?= h($i['paid_date']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
