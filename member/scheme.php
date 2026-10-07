<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_once __DIR__ . '/../includes/razorpay.php';
require_once __DIR__ . '/../includes/upi.php';

$user = require_member();
$upiConfig = get_upi_config();

$id = (int) ($_GET['id'] ?? 0);
$scheme = get_scheme($id);
if (!$scheme) { http_response_code(404); render_error('Scheme not found.'); exit; }

$mySubs = get_subscriptions_for_user_in_scheme($id, $user['id']);
if (empty($mySubs)) { http_response_code(403); render_error("You are not enrolled in this chitty."); exit; }

$ledgers = array_map(fn($s) => ['sub' => $s, 'ledger' => get_ledger_for_subscription($s['id'])], $mySubs);
$auctions = get_auctions($id);
$pendingPayments = get_user_scheme_pending_payments($id, (int)$user['id']);

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

  <?php if (isset($_GET['payment_submitted'])): ?>
    <div class="info-msg processing-banner">
      <strong>Payment Under Processing!</strong> Your UPI transaction details have been submitted. It is awaiting administrator verification and will be marked as successful once approved.
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['payment_success'])): ?>
    <div class="success-msg">
      <strong>Payment Successful!</strong> Your installment payment has been verified by the administrator and recorded in the ledger.
    </div>
  <?php endif; ?>

  <?php foreach ($ledgers as $entry): $sub = $entry['sub']; $ledger = $entry['ledger']; ?>
    <h2>Chittal N0. #<?= (int)$sub['ticket_number'] ?><?= $sub['has_won'] ? ' — won in month ' . (int)$sub['won_month'] : '' ?></h2>
    <?php if (empty($ledger)): ?>
      <p class="empty">No dues have been generated for this Chittal N0. yet.</p>
    <?php else: ?>
      <div class="history-table-region">
        <div class="history-table-scroll" tabindex="0" role="region" aria-label="Chittal N0. #<?= (int)$sub['ticket_number'] ?> payment history">
          <table class="responsive-card-table ledger-table">
            <thead><tr><th class="num">Month</th><th class="num">Due balance</th><th class="num">Paid</th><th>Status</th><th>Paid on</th></tr></thead>
            <tbody>
              <?php foreach ($ledger as $i): ?>
                <?php
                  $isPendingVerification = isset($pendingPayments[$i['id']]);
                  $pendingInfo = $isPendingVerification ? $pendingPayments[$i['id']] : null;
                ?>
                <tr>
                  <td class="num" data-label="Month">#<?= (int)$i['month_number'] ?></td>
                  <td class="num font-mono" data-label="Due balance">₹<?= money(remaining_amount_due($i)) ?></td>
                  <td class="num font-mono" data-label="Amount paid">₹<?= money($i['amount_paid']) ?></td>
                  <td data-label="Status & Action">
                    <div class="status-cell-wrap">
                      <?php if ($isPendingVerification): ?>
                        <span class="badge processing">under processing</span>
                        <div class="sub-pending-pill" title="Payment submitted and awaiting administrator verification">
                          <span class="pulse-dot"></span>
                          <span>Txn: <?= h($pendingInfo['transaction_id']) ?></span>
                        </div>
                      <?php else: ?>
                        <span class="badge <?= h($i['status']) ?>"><?= h($i['status']) ?></span>
                        <?php if (($i['status'] === 'pending' || $i['status'] === 'partial') && remaining_amount_due($i) > 0): ?>
                          <button type="button"
                                  class="pay-now-btn"
                                  data-installment-id="<?= (int)$i['id'] ?>"
                                  data-month="<?= (int)$i['month_number'] ?>"
                                  data-ticket="<?= (int)$sub['ticket_number'] ?>"
                                  data-due="<?= number_format(remaining_amount_due($i), 2, '.', '') ?>"
                                  data-scheme-name="<?= h($scheme['name']) ?>">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                              <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                              <line x1="1" y1="10" x2="23" y2="10"></line>
                            </svg>
                            <span>Pay Now</span>
                          </button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td data-label="Paid on"><?= h($i['paid_date'] ?: '—') ?></td>
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
        <table class="responsive-card-table auction-table">
          <thead><tr><th class="num">Month</th><th>Date</th><th>Winner</th><th class="num">Bid</th><th class="num">Prize paid</th></tr></thead>
          <tbody>
            <?php foreach ($auctions as $a): ?>
              <tr>
                <td class="num" data-label="Month">#<?= (int)$a['month_number'] ?></td>
                <td data-label="Auction date"><?= h($a['auction_date']) ?></td>
                <td data-label="Winner"><?= $a['winner_name'] ? ('#' . (int)$a['winner_ticket'] . ' — ' . h($a['winner_name'])) : '—' ?></td>
                <td class="num font-mono" data-label="Winning bid">₹<?= money($a['bid_amount'] ?? 0) ?></td>
                <td class="num font-mono" data-label="Prize paid">₹<?= money($a['prize_amount'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- UPI QR & Google Pay Payment Modal -->
  <div id="paymentModal" class="modal-backdrop" style="display: none;" aria-hidden="true" role="dialog" aria-labelledby="modalTitle">
    <div class="modal-card" style="max-width: 480px;">
      <div class="modal-header">
        <div>
          <h2 id="modalTitle" style="margin: 0; font-size: 1.25rem;">Pay Installment via Google Pay / UPI</h2>
          <div class="sub" id="modalSub">Month #— · Chittal N0. #—</div>
        </div>
        <button type="button" class="modal-close" id="modalCloseBtn" aria-label="Close modal">&times;</button>
      </div>

      <div class="modal-body" style="padding-top: 14px;">
        <div id="modalAlert" class="error-msg" style="display: none;"></div>

        <!-- Step Indicator -->
        <div class="step-indicator" id="stepIndicator">
          <div class="step-item active" id="stepIndicator1">
            <span class="step-num">1</span>
            <span>Scan QR / Pay</span>
          </div>
          <span class="step-sep">&rarr;</span>
          <div class="step-item" id="stepIndicator2">
            <span class="step-num">2</span>
            <span>Enter Transaction ID</span>
          </div>
        </div>

        <!-- Payment Summary Box -->
        <div class="payment-summary-box">
          <div class="summary-row">
            <span class="summary-label">Chit Scheme</span>
            <span class="summary-val" id="modalScheme">—</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Chittal N0.</span>
            <span class="summary-val font-mono" id="modalTicket">—</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Month Due</span>
            <span class="summary-val font-mono" id="modalMonth">—</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Outstanding Due</span>
            <span class="summary-val due-val font-mono" id="modalDue">₹0.00</span>
          </div>
        </div>

        <!-- STEP 1: Scan QR Code & Open in Google Pay -->
        <div id="stepView1" class="upi-step-container">
          <div class="field" style="margin-bottom: 12px;">
            <label for="payAmount">Payment Amount (₹) <span style="color:var(--bad)">*</span></label>
            <div class="input-with-prefix">
              <span class="prefix">₹</span>
              <input type="number" id="payAmount" step="0.01" min="1.00" inputmode="decimal" placeholder="0.00" required>
            </div>
            <div class="quick-amount-buttons" style="margin-top: 6px;">
              <button type="button" class="btn secondary small" id="payFullBtn">Pay Full Due</button>
            </div>
          </div>

          <!-- QR Card -->
          <div class="upi-qr-card">
            <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 3px; color: var(--ink);">
              Scan QR to Pay with Google Pay / UPI
            </div>
            <div style="font-size: 0.8rem; color: var(--ink-soft); margin-bottom: 12px;">
              Scan using Google Pay or any UPI app to transfer to society account
            </div>

            <div class="upi-qr-box" id="qrContainer">
              <img id="upiQrImg" class="upi-qr-img" src="" alt="UPI Payment QR Code" style="display: none;" />
              <div id="qrLoadingSpinner" class="spinner" style="width: 28px; height: 28px; border-width: 3px; display: inline-block;"></div>
            </div>

            <!-- Society Bank Account / UPI ID -->
            <div class="upi-meta-info">
              <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="color: var(--ink-soft);">Payee Name:</span>
                <strong id="upiPayeeName"><?= h($upiConfig['payee_name']) ?></strong>
              </div>
              <div class="upi-id-row">
                <span style="color: var(--ink-soft);">UPI ID:</span>
                <span class="font-mono" id="upiIdDisplay" style="font-weight: 600; color: var(--ink);"><?= h($upiConfig['upi_id']) ?></span>
                <button type="button" class="btn-copy-code" id="btnCopyUpiId">Copy</button>
              </div>
            </div>

            <!-- Open in Google Pay / UPI Deep Link -->
            <a id="gpayDeepLinkBtn" href="#" class="btn gpay-btn">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                <line x1="2" y1="10" x2="22" y2="10"></line>
              </svg>
              <span>Open in Google Pay / UPI App</span>
            </a>
          </div>

          <!-- Step transition trigger button -->
          <button type="button" class="btn proceed-step-btn" id="btnScannedProceed">
            <span>✓ I Have Scanned / Paid &rarr; Enter Transaction ID</span>
          </button>
        </div>

        <!-- STEP 2: Enter Transaction ID -->
        <div id="stepView2" class="upi-step-container" style="display: none;">
          <div style="background: #faf7f0; border: 1px solid var(--rule); border-radius: 6px; padding: 12px 14px; margin-bottom: 14px;">
            <div style="font-weight: 600; color: var(--ink); margin-bottom: 2px;">Step 2: Enter Payment Reference</div>
            <div style="font-size: 0.82rem; color: var(--ink-soft);">
              After completing the payment in Google Pay or your UPI app, enter the 12-digit UPI Transaction ID / UTR below to complete the transaction.
            </div>
            <div style="margin-top: 8px; font-size: 0.88rem;">
              Amount to Verify: <strong class="font-mono" id="confirmAmountDisplay" style="color: var(--text);">₹0.00</strong>
            </div>
          </div>

          <div class="field">
            <label for="upiTxnIdInput">
              UPI Transaction ID / UTR / Reference ID <span style="color:var(--bad)">*</span>
            </label>
            <input type="text" id="upiTxnIdInput" class="font-mono" placeholder="e.g. 428190382910 or Google Pay Ref No" maxlength="60" required autocomplete="off" style="font-size: 1.05rem; letter-spacing: 0.5px;">
            <span class="field-hint">
              You can find the 12-digit UTR or UPI Transaction ID in Google Pay under payment details.
            </span>
          </div>

          <div style="margin-top: 16px;">
            <button type="button" class="btn success" id="btnSubmitTxnProof" style="width: 100%; padding: 12px; font-size: 1rem; font-weight: 600;">
              <span id="submitProofText">Submit Payment for Verification</span>
              <span id="submitProofSpinner" class="spinner" style="display: none;"></span>
            </button>
            <button type="button" class="btn secondary" id="btnBackToQr" style="width: 100%; margin-top: 8px; padding: 8px;">
              &larr; View QR Code Again
            </button>
          </div>
        </div>

        <!-- STEP 3: Under Processing Confirmation -->
        <div id="stepView3" class="upi-step-container" style="display: none;">
          <div class="processing-state-box">
            <div class="processing-icon-wrap">
              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
              </svg>
            </div>
            <h3 style="margin: 0 0 8px 0; color: #92400e; font-size: 1.2rem;">Payment Under Processing</h3>
            <p style="margin: 0 0 12px 0; color: var(--ink-soft); font-size: 0.9rem;">
              Your payment has been submitted and is currently awaiting administrator verification.
            </p>
            <div style="background: #ffffff; border: 1px solid var(--rule); border-radius: 6px; padding: 12px; margin-bottom: 16px; text-align: left;">
              <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                <span style="color: var(--ink-soft); font-size: 0.84rem;">Transaction ID:</span>
                <span class="font-mono" id="successTxnId" style="font-weight: 700; color: var(--ink);">—</span>
              </div>
              <div style="display: flex; justify-content: space-between;">
                <span style="color: var(--ink-soft); font-size: 0.84rem;">Amount Submitted:</span>
                <span class="font-mono" id="successAmount" style="font-weight: 700; color: var(--good);">₹0.00</span>
              </div>
            </div>
            <div style="font-size: 0.84rem; color: #78350f; background: #fffbeb; border: 1px solid #fde68a; border-radius: 4px; padding: 8px 12px; margin-bottom: 16px; text-align: left;">
              A notification has been sent to the administrator. Once verified, your status will update to <strong>Payment Successful</strong> in your dashboard.
            </div>
            <button type="button" class="btn" id="btnFinishAndReload" style="width: 100%; padding: 11px;">
              Done & Return to Ledger
            </button>
          </div>
        </div>

      </div>

      <div class="modal-footer" id="modalFooter">
        <button type="button" class="btn secondary" id="modalCancelBtn">Close</button>
      </div>
    </div>
  </div>

  <script>
  (function () {
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const schemeId = <?= (int)$scheme['id'] ?>;

    const modal = document.getElementById('paymentModal');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    const modalCancelBtn = document.getElementById('modalCancelBtn');
    const modalAlert = document.getElementById('modalAlert');

    const modalSub = document.getElementById('modalSub');
    const modalScheme = document.getElementById('modalScheme');
    const modalTicket = document.getElementById('modalTicket');
    const modalMonth = document.getElementById('modalMonth');
    const modalDue = document.getElementById('modalDue');
    const payAmount = document.getElementById('payAmount');
    const payFullBtn = document.getElementById('payFullBtn');

    // Steps
    const stepView1 = document.getElementById('stepView1');
    const stepView2 = document.getElementById('stepView2');
    const stepView3 = document.getElementById('stepView3');
    const stepIndicator = document.getElementById('stepIndicator');
    const stepIndicator1 = document.getElementById('stepIndicator1');
    const stepIndicator2 = document.getElementById('stepIndicator2');

    // Step 1 Elements
    const upiQrImg = document.getElementById('upiQrImg');
    const qrLoadingSpinner = document.getElementById('qrLoadingSpinner');
    const upiPayeeName = document.getElementById('upiPayeeName');
    const upiIdDisplay = document.getElementById('upiIdDisplay');
    const btnCopyUpiId = document.getElementById('btnCopyUpiId');
    const gpayDeepLinkBtn = document.getElementById('gpayDeepLinkBtn');
    const btnScannedProceed = document.getElementById('btnScannedProceed');

    // Step 2 Elements
    const confirmAmountDisplay = document.getElementById('confirmAmountDisplay');
    const upiTxnIdInput = document.getElementById('upiTxnIdInput');
    const btnSubmitTxnProof = document.getElementById('btnSubmitTxnProof');
    const submitProofText = document.getElementById('submitProofText');
    const submitProofSpinner = document.getElementById('submitProofSpinner');
    const btnBackToQr = document.getElementById('btnBackToQr');

    // Step 3 Elements
    const successTxnId = document.getElementById('successTxnId');
    const successAmount = document.getElementById('successAmount');
    const btnFinishAndReload = document.getElementById('btnFinishAndReload');

    let currentInstallmentId = null;
    let currentDue = 0;
    let currentTicket = null;
    let currentMonth = null;
    let currentSchemeName = '';
    let currentQrTimeout = null;

    function formatRupee(amount) {
      return '₹' + Number(amount).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function showError(msg) {
      modalAlert.textContent = msg;
      modalAlert.style.display = 'block';
    }

    function hideError() {
      modalAlert.textContent = '';
      modalAlert.style.display = 'none';
    }

    function switchStep(step) {
      hideError();
      if (step === 1) {
        stepView1.style.display = 'flex';
        stepView2.style.display = 'none';
        stepView3.style.display = 'none';
        stepIndicator.style.display = 'flex';
        stepIndicator1.classList.add('active');
        stepIndicator2.classList.remove('active');
      } else if (step === 2) {
        stepView1.style.display = 'none';
        stepView2.style.display = 'flex';
        stepView3.style.display = 'none';
        stepIndicator.style.display = 'flex';
        stepIndicator1.classList.remove('active');
        stepIndicator2.classList.add('active');
        confirmAmountDisplay.textContent = formatRupee(parseFloat(payAmount.value) || currentDue);
        setTimeout(() => upiTxnIdInput.focus(), 150);
      } else if (step === 3) {
        stepView1.style.display = 'none';
        stepView2.style.display = 'none';
        stepView3.style.display = 'block';
        stepIndicator.style.display = 'none';
      }
    }

    async function loadUpiQr() {
      const amount = parseFloat(payAmount.value);
      if (isNaN(amount) || amount < 1.00 || amount > currentDue + 0.005) {
        return;
      }

      upiQrImg.style.display = 'none';
      qrLoadingSpinner.style.display = 'inline-block';

      try {
        const res = await fetch('/member/payment-upi-init.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            installment_id: currentInstallmentId,
            amount: amount
          })
        });

        const data = await res.json();
        if (!data.success) {
          showError(data.error || 'Failed to initialize payment.');
          qrLoadingSpinner.style.display = 'none';
          return;
        }

        if (data.qr_data_uri) {
          upiQrImg.src = data.qr_data_uri;
          upiQrImg.style.display = 'block';
        }
        qrLoadingSpinner.style.display = 'none';

        if (data.upi_url) {
          gpayDeepLinkBtn.href = data.upi_url;
        }
        if (data.upi_id) {
          upiIdDisplay.textContent = data.upi_id;
        }
        if (data.payee_name) {
          upiPayeeName.textContent = data.payee_name;
        }
      } catch (err) {
        qrLoadingSpinner.style.display = 'none';
        showError('Network error loading QR code.');
      }
    }

    function openPaymentModal(btn) {
      currentInstallmentId = parseInt(btn.dataset.installmentId, 10);
      currentDue = parseFloat(btn.dataset.due);
      currentTicket = btn.dataset.ticket;
      currentMonth = btn.dataset.month;
      currentSchemeName = btn.dataset.schemeName;

      hideError();
      modalSub.textContent = currentSchemeName + ' · Chittal N0. #' + currentTicket;
      modalScheme.textContent = currentSchemeName;
      modalTicket.textContent = '#' + currentTicket;
      modalMonth.textContent = 'Month #' + currentMonth;
      modalDue.textContent = formatRupee(currentDue);

      payAmount.value = currentDue.toFixed(2);
      payAmount.max = currentDue.toFixed(2);
      upiTxnIdInput.value = '';

      switchStep(1);
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');

      loadUpiQr();
    }

    function closePaymentModal() {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      hideError();
    }

    // Attach click listeners to all "Pay Now" buttons
    document.querySelectorAll('.pay-now-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        openPaymentModal(this);
      });
    });

    payFullBtn.addEventListener('click', function () {
      payAmount.value = currentDue.toFixed(2);
      hideError();
      loadUpiQr();
    });

    payAmount.addEventListener('input', function () {
      if (currentQrTimeout) clearTimeout(currentQrTimeout);
      currentQrTimeout = setTimeout(() => {
        loadUpiQr();
      }, 500);
    });

    btnCopyUpiId.addEventListener('click', function () {
      const upiId = upiIdDisplay.textContent.trim();
      navigator.clipboard.writeText(upiId).then(() => {
        btnCopyUpiId.textContent = 'Copied!';
        btnCopyUpiId.classList.add('copied');
        setTimeout(() => {
          btnCopyUpiId.textContent = 'Copy';
          btnCopyUpiId.classList.remove('copied');
        }, 1800);
      });
    });

    // Advance to Step 2 when user clicks "I Have Scanned / Paid" or "Open in Google Pay"
    btnScannedProceed.addEventListener('click', function () {
      switchStep(2);
    });

    gpayDeepLinkBtn.addEventListener('click', function () {
      // Allow link to trigger Google Pay / UPI intent, then prompt to enter txn id
      setTimeout(() => {
        switchStep(2);
      }, 800);
    });

    btnBackToQr.addEventListener('click', function () {
      switchStep(1);
    });

    // Step 2: Submit Transaction ID
    btnSubmitTxnProof.addEventListener('click', async function () {
      hideError();
      const enteredAmount = parseFloat(payAmount.value);
      const txnId = upiTxnIdInput.value.trim();

      if (isNaN(enteredAmount) || enteredAmount < 1.00) {
        showError('Please enter a valid payment amount of at least ₹1.00.');
        return;
      }

      if (enteredAmount > currentDue + 0.005) {
        showError('Payment amount cannot exceed the remaining due balance of ' + formatRupee(currentDue) + '.');
        return;
      }

      if (!txnId || txnId.length < 4) {
        showError('Please enter a valid UPI Transaction ID / UTR number from Google Pay (at least 4 characters).');
        upiTxnIdInput.focus();
        return;
      }

      // Set loading
      btnSubmitTxnProof.disabled = true;
      submitProofText.textContent = 'Submitting...';
      submitProofSpinner.style.display = 'inline-block';

      try {
        const res = await fetch('/member/payment-submit-upi.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            installment_id: currentInstallmentId,
            amount: enteredAmount,
            transaction_id: txnId
          })
        });

        const data = await res.json();
        btnSubmitTxnProof.disabled = false;
        submitProofText.textContent = 'Submit Payment for Verification';
        submitProofSpinner.style.display = 'none';

        if (!data.success) {
          showError(data.error || 'Failed to submit payment details.');
          return;
        }

        // Show Success / Under Processing step
        successTxnId.textContent = txnId;
        successAmount.textContent = formatRupee(enteredAmount);
        switchStep(3);
      } catch (err) {
        btnSubmitTxnProof.disabled = false;
        submitProofText.textContent = 'Submit Payment for Verification';
        submitProofSpinner.style.display = 'none';
        showError('Network error submitting payment. Please try again.');
      }
    });

    btnFinishAndReload.addEventListener('click', function () {
      window.location.href = '/member/scheme.php?id=' + schemeId + '&payment_submitted=1';
    });

    modalCloseBtn.addEventListener('click', closePaymentModal);
    modalCancelBtn.addEventListener('click', closePaymentModal);

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closePaymentModal();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.style.display === 'flex') {
        closePaymentModal();
      }
    });

  })();
  </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
