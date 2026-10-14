<?php
// Administrator Payment Notifications & Verification Page
// Allows admins to review member UPI payment submissions, view transaction IDs & member details,
// and verify transactions to mark them as successful.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_once __DIR__ . '/../includes/upi.php';

$currentUser = require_admin();

$actionError = null;
$actionSuccess = null;

// Handle Verification or Rejection POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Security token expired. Please refresh the page and try again.');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    $tabParam = (isset($_POST['tab']) && in_array($_POST['tab'], ['pending', 'verified', 'all'], true)) ? $_POST['tab'] : 'pending';

    if ($notificationId > 0 && $action === 'verify') {
        try {
            $res = verify_upi_payment($notificationId, (int) $currentUser['id']);
            redirect_masked('/admin/notifications.php?tab=' . urlencode($tabParam) . '&status=verified_success&txn=' . urlencode($res['transaction_id']));
        } catch (\Throwable $e) {
            $actionError = $e->getMessage();
        }
    } elseif ($notificationId > 0 && $action === 'reject') {
        try {
            $reason = trim((string) ($_POST['reject_reason'] ?? ''));
            reject_upi_payment($notificationId, (int) $currentUser['id'], $reason);
            redirect_masked('/admin/notifications.php?tab=' . urlencode($tabParam) . '&status=rejected_success');
        } catch (\Throwable $e) {
            $actionError = $e->getMessage();
        }
    }
}

$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending', 'verified', 'all'], true)) {
    $tab = 'pending';
}

$statusFilter = ($tab === 'all') ? null : $tab;
$notifications = get_payment_notifications($statusFilter);

// Calculate metric totals
$allNotifs = get_payment_notifications(null);
$pendingCount = 0;
$verifiedCount = 0;
$totalVerifiedAmount = 0.0;

foreach ($allNotifs as $n) {
    if ($n['status'] === 'pending') {
        $pendingCount++;
    } elseif ($n['status'] === 'verified') {
        $verifiedCount++;
        $totalVerifiedAmount += (float) $n['amount'];
    }
}

$isOptimizedTab = in_array($tab, ['verified', 'all'], true);

$title = 'Payment Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

  <div class="page-head">
    <div>
      <h1>Payment Notifications</h1>
      <div class="sub">Review member UPI transactions and verify submitted payments</div>
    </div>
    <div class="page-head-actions">
      <a class="btn secondary" href="/admin/index.php">Back to Schemes</a>
    </div>
  </div>

  <?php if (($_GET['status'] ?? '') === 'verified_success'): ?>
    <div class="success-msg">
      <strong>Payment Verified<?= ($_GET['via'] ?? '') === 'email' ? ' via Email Link' : '' ?>!</strong> Transaction ID <code><?= h($_GET['txn'] ?? '') ?></code> was marked verified. The member ledger is updated to Paid.
    </div>
  <?php elseif (($_GET['status'] ?? '') === 'already_verified'): ?>
    <div class="info-msg" style="border-left: 4px solid var(--good); margin-bottom: 20px;">
      <strong>Already Verified:</strong> Transaction ID <code><?= h($_GET['txn'] ?? '') ?></code> was already verified and approved.
    </div>
  <?php elseif (($_GET['status'] ?? '') === 'rejected_success'): ?>
    <div class="error-msg">
      The payment submission has been rejected and marked as invalid.
    </div>
  <?php elseif ($actionError): ?>
    <div class="error-msg">
      <?= h($actionError) ?>
    </div>
  <?php endif; ?>

  <!-- Summary Metric Cards -->
  <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 24px;">
    <div class="stat-card <?= $pendingCount > 0 ? 'highlight-amber' : '' ?>">
      <div class="stat-label">Pending Verification</div>
      <div class="stat-value font-mono" style="<?= $pendingCount > 0 ? 'color: var(--accent);' : '' ?>"><?= $pendingCount ?></div>
      <div class="stat-sub"><?= $pendingCount === 1 ? '1 payment awaiting review' : $pendingCount . ' payments awaiting review' ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Verified</div>
      <div class="stat-value font-mono" style="color: var(--good);"><?= $verifiedCount ?></div>
      <div class="stat-sub">Completed transactions</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Verified Collections</div>
      <div class="stat-value font-mono">₹<?= money($totalVerifiedAmount) ?></div>
      <div class="stat-sub">Approved UPI payments</div>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div class="notif-tab-bar">
    <a href="/admin/notifications.php?tab=pending" class="notif-tab <?= $tab === 'pending' ? 'active' : '' ?>">
      Pending Verifications
      <?php if ($pendingCount > 0): ?>
        <span class="tab-badge"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
    <a href="/admin/notifications.php?tab=verified" class="notif-tab <?= $tab === 'verified' ? 'active' : '' ?>">
      Verified History
      <span class="tab-badge secondary"><?= $verifiedCount ?></span>
    </a>
    <a href="/admin/notifications.php?tab=all" class="notif-tab <?= $tab === 'all' ? 'active' : '' ?>">
      All Submissions
      <span class="tab-badge secondary"><?= count($allNotifs) ?></span>
    </a>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="empty" style="padding: 48px 24px; text-align: center;">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-muted); margin-bottom: 12px;">
        <rect x="2" y="5" width="20" height="14" rx="2"></rect>
        <line x1="2" y1="10" x2="22" y2="10"></line>
      </svg>
      <div>No payment notifications found in this tab.</div>
      <?php if ($tab === 'pending'): ?>
        <div class="sub" style="margin-top: 6px;">All member UPI submissions have been verified.</div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php if ($isOptimizedTab): ?>
      <!-- Search & Page Size Toolbar for Optimization -->
      <div class="table-toolbar notif-toolbar">
        <div class="search-input-wrap">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" id="notifSearchInput" placeholder="Search by Txn ID, member, phone, scheme, amount, status..." autocomplete="off" spellcheck="false" aria-label="Search notifications">
          <button type="button" id="clearNotifSearchBtn" class="search-clear-btn" aria-label="Clear search" title="Clear search" style="display:none;">&times;</button>
        </div>
        <div class="table-toolbar-right">
          <div class="page-size-selector">
            <label for="notifPageSize">Show:</label>
            <select id="notifPageSize" aria-label="Entries per page">
              <option value="10" selected>10</option>
              <option value="25">25</option>
              <option value="50">50</option>
              <option value="100">100</option>
              <option value="all">All</option>
            </select>
            <span class="sub" style="font-size: 0.85rem;">entries</span>
          </div>
          <div id="notifToolbarCount" class="notif-count-text">
            Showing <span id="notifCountStart">1</span>–<span id="notifCountEnd"><?= min(10, count($notifications)) ?></span> of <span id="notifTotalCount"><?= count($notifications) ?></span>
          </div>
        </div>
      </div>

      <!-- Empty Filter Results Message -->
      <div id="noNotifResults" class="empty" style="display:none; padding: 40px 20px; text-align: center; margin-bottom: 20px;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-muted); margin-bottom: 10px;">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <div>No payment notifications found matching "<strong id="notifSearchQueryDisplay"></strong>".</div>
        <div class="sub" style="margin-top: 6px;">Try adjusting your keywords or clearing the search.</div>
        <button type="button" class="btn secondary small" id="resetNotifSearchBtn" style="margin-top: 12px;">Clear search</button>
      </div>
    <?php endif; ?>

    <div class="history-table-region">
      <div class="history-table-scroll" tabindex="0" role="region" aria-label="Payment notifications list">
        <table class="responsive-card-table notif-table" id="notifTable">
          <thead>
            <tr>
              <th>Transaction ID</th>
              <th>Member Details</th>
              <th>Chit Scheme / Chittal</th>
              <th class="num">Amount</th>
              <th>Submitted Date</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($notifications as $n): ?>
              <?php
                $searchBlob = mb_strtolower(implode(' ', array_filter([
                    $n['transaction_id'] ?? '',
                    $n['member_name'] ?? '',
                    $n['member_phone'] ?? '',
                    $n['member_email'] ?? '',
                    $n['scheme_name'] ?? '',
                    'month #' . ($n['month_number'] ?? ''),
                    'month ' . ($n['month_number'] ?? ''),
                    'chittal #' . ($n['ticket_number'] ?? ''),
                    'chittal ' . ($n['ticket_number'] ?? ''),
                    'ticket #' . ($n['ticket_number'] ?? ''),
                    'ticket ' . ($n['ticket_number'] ?? ''),
                    (string)($n['amount'] ?? ''),
                    number_format((float)($n['amount'] ?? 0), 2, '.', ''),
                    money($n['amount'] ?? 0),
                    $n['status'] ?? '',
                    $n['status'] === 'verified' ? 'approved verified' : '',
                    $n['status'] === 'pending' ? 'under processing pending' : '',
                    $n['status'] === 'rejected' ? 'rejected failed invalid' : '',
                    $n['verified_by_name'] ?? '',
                    date('d M Y', strtotime($n['created_at'] ?? 'now')),
                ])));
              ?>
              <tr class="notif-row" data-search="<?= h($searchBlob) ?>">
                <td data-label="Transaction ID">
                  <div class="txn-cell">
                    <span class="txn-id font-mono"><?= h($n['transaction_id']) ?></span>
                    <button type="button" class="btn-copy-sm" onclick="copyText('<?= h($n['transaction_id']) ?>', this)" title="Copy Transaction ID">
                      Copy
                    </button>
                  </div>
                </td>
                <td data-label="Member Details">
                  <div class="member-cell">
                    <strong><?= h($n['member_name']) ?></strong>
                    <div class="sub-contact">
                      <a href="tel:<?= h($n['member_phone']) ?>" class="phone-link"><?= h($n['member_phone']) ?></a>
                      <?php if (!empty($n['member_email'])): ?>
                        · <span class="email-text"><?= h($n['member_email']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td data-label="Chit Scheme / Chittal">
                  <div>
                    <a href="/admin/scheme.php?id=<?= (int)$n['scheme_id'] ?>" style="font-weight: 600;">
                      <?= h($n['scheme_name']) ?>
                    </a>
                    <div class="sub" style="font-size: 0.8rem; margin-top: 2px;">
                      Month #<?= (int)$n['month_number'] ?> · Chittal N0. #<?= (int)$n['ticket_number'] ?>
                    </div>
                  </div>
                </td>
                <td class="num font-mono" data-label="Amount">
                  <strong style="color: var(--text);">₹<?= money($n['amount']) ?></strong>
                </td>
                <td data-label="Submitted Date">
                  <div style="font-size: 0.88rem;">
                    <?= date('d M Y, h:i A', strtotime($n['created_at'])) ?>
                  </div>
                </td>
                <td data-label="Status">
                  <?php if ($n['status'] === 'pending'): ?>
                    <span class="badge processing">Under Processing</span>
                  <?php elseif ($n['status'] === 'verified'): ?>
                    <span class="badge paid">Verified</span>
                    <?php if (!empty($n['verified_by_name'])): ?>
                      <div class="sub" style="font-size: 0.72rem; margin-top: 3px;">
                        by <?= h($n['verified_by_name']) ?>
                      </div>
                    <?php endif; ?>
                  <?php elseif ($n['status'] === 'rejected'): ?>
                    <span class="badge danger">Rejected</span>
                  <?php endif; ?>
                </td>
                <td data-label="Action">
                  <?php if ($n['status'] === 'pending'): ?>
                    <div class="notif-actions">
                      <form method="POST" action="/admin/notifications.php" class="verify-notif-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="tab" value="<?= h($tab) ?>">
                        <button type="submit" class="btn success small btn-verify" title="Click to verify payment and mark installment as paid">
                          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                            <polyline points="20 6 9 17 4 12"></polyline>
                          </svg>
                          <span>Verify</span>
                        </button>
                      </form>
                      <form method="POST" action="/admin/notifications.php" onsubmit="return confirm('Are you sure you want to reject this payment submission as invalid?');" style="margin-top: 4px;">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="tab" value="<?= h($tab) ?>">
                        <button type="submit" class="btn secondary danger small" title="Mark payment as rejected/invalid">
                          Reject
                        </button>
                      </form>
                    </div>
                  <?php elseif ($n['status'] === 'verified'): ?>
                    <div class="verified-check">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                      </svg>
                      <span style="color: var(--good); font-size: 0.82rem; font-weight: 500;">Approved</span>
                    </div>
                  <?php else: ?>
                    <span style="color: var(--text-muted); font-size: 0.82rem;">Rejected</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($isOptimizedTab): ?>
      <!-- Table Pagination Bar -->
      <div id="notifPaginationBar" class="table-pagination-bar">
        <div class="pagination-info" id="notifPaginationInfo">
          Showing <strong id="pagInfoStart">1</strong> to <strong id="pagInfoEnd"><?= min(10, count($notifications)) ?></strong> of <strong id="pagInfoTotal"><?= count($notifications) ?></strong> entries<span id="pagFilteredText" style="display:none;"></span>
        </div>
        <div class="pagination-controls" id="notifPaginationControls" role="navigation" aria-label="Table pagination navigation">
          <button type="button" class="btn-pag btn-pag-nav" id="pagFirstBtn" title="First page" aria-label="First page">&laquo; First</button>
          <button type="button" class="btn-pag btn-pag-nav" id="pagPrevBtn" title="Previous page" aria-label="Previous page">&lsaquo; Prev</button>
          <div class="pagination-pages" id="pagNumberButtons"></div>
          <button type="button" class="btn-pag btn-pag-nav" id="pagNextBtn" title="Next page" aria-label="Next page">Next &rsaquo;</button>
          <button type="button" class="btn-pag btn-pag-nav" id="pagLastBtn" title="Last page" aria-label="Last page">Last &raquo;</button>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <script>
  document.querySelectorAll('.verify-notif-form').forEach(function(form) {
    form.addEventListener('submit', function() {
      const btn = form.querySelector('.btn-verify');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>Verifying...</span>';
      }
    });
  });

  function copyText(text, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function() {
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(function() {
          btn.textContent = orig;
          btn.classList.remove('copied');
        }, 1800);
      });
    } else {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      const orig = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function() { btn.textContent = orig; }, 1800);
    }
  }

  // Interactive Live Search & Table Pagination Controller
  (function() {
    const isOptimizedTab = <?= $isOptimizedTab ? 'true' : 'false' ?>;
    if (!isOptimizedTab) return;

    const notifTable = document.getElementById('notifTable');
    if (!notifTable) return;

    const allRows = Array.from(notifTable.querySelectorAll('tbody tr.notif-row'));
    const totalRecords = allRows.length;
    if (totalRecords === 0) return;

    const searchInput = document.getElementById('notifSearchInput');
    const clearBtn = document.getElementById('clearNotifSearchBtn');
    const resetBtn = document.getElementById('resetNotifSearchBtn');
    const noResults = document.getElementById('noNotifResults');
    const searchQueryDisplay = document.getElementById('notifSearchQueryDisplay');
    const pageSizeSelect = document.getElementById('notifPageSize');
    const paginationBar = document.getElementById('notifPaginationBar');

    const countStart = document.getElementById('notifCountStart');
    const countEnd = document.getElementById('notifCountEnd');
    const countTotal = document.getElementById('notifTotalCount');

    const pagInfoStart = document.getElementById('pagInfoStart');
    const pagInfoEnd = document.getElementById('pagInfoEnd');
    const pagInfoTotal = document.getElementById('pagInfoTotal');
    const pagFilteredText = document.getElementById('pagFilteredText');

    const pagFirstBtn = document.getElementById('pagFirstBtn');
    const pagPrevBtn = document.getElementById('pagPrevBtn');
    const pagNextBtn = document.getElementById('pagNextBtn');
    const pagLastBtn = document.getElementById('pagLastBtn');
    const pagNumberButtons = document.getElementById('pagNumberButtons');

    let matchingRows = allRows.slice();
    let currentPage = 1;
    let pageSize = parseInt(pageSizeSelect ? pageSizeSelect.value : '10', 10) || 10;

    function scrollToTable() {
      const region = document.querySelector('.history-table-region');
      if (region) {
        const rect = region.getBoundingClientRect();
        if (rect.top < 0 || rect.top > window.innerHeight) {
          region.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    }

    function renderPaginationButtons(totalPages) {
      if (!pagNumberButtons) return;
      pagNumberButtons.innerHTML = '';

      if (totalPages <= 1) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-pag active';
        btn.textContent = '1';
        btn.setAttribute('aria-current', 'page');
        pagNumberButtons.appendChild(btn);
        return;
      }

      function createPageBtn(p) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-pag' + (p === currentPage ? ' active' : '');
        btn.textContent = p;
        btn.setAttribute('aria-label', 'Go to page ' + p);
        if (p === currentPage) {
          btn.setAttribute('aria-current', 'page');
        } else {
          btn.addEventListener('click', function() {
            currentPage = p;
            renderView();
            scrollToTable();
          });
        }
        return btn;
      }

      function createEllipsis() {
        const span = document.createElement('span');
        span.className = 'pag-ellipsis';
        span.textContent = '…';
        span.setAttribute('aria-hidden', 'true');
        return span;
      }

      if (totalPages <= 7) {
        for (let p = 1; p <= totalPages; p++) {
          pagNumberButtons.appendChild(createPageBtn(p));
        }
      } else {
        // Smart pagination with ellipsis for large numbers of pages
        pagNumberButtons.appendChild(createPageBtn(1));

        let startRange = Math.max(2, currentPage - 1);
        let endRange = Math.min(totalPages - 1, currentPage + 1);

        if (currentPage <= 4) {
          startRange = 2;
          endRange = 5;
        } else if (currentPage >= totalPages - 3) {
          startRange = totalPages - 4;
          endRange = totalPages - 1;
        }

        if (startRange > 2) {
          pagNumberButtons.appendChild(createEllipsis());
        }

        for (let p = startRange; p <= endRange; p++) {
          pagNumberButtons.appendChild(createPageBtn(p));
        }

        if (endRange < totalPages - 1) {
          pagNumberButtons.appendChild(createEllipsis());
        }

        pagNumberButtons.appendChild(createPageBtn(totalPages));
      }
    }

    function renderView() {
      const query = (searchInput ? searchInput.value : '').trim();
      if (clearBtn) {
        clearBtn.style.display = query ? '' : 'none';
      }

      const totalMatching = matchingRows.length;

      // Handle 0 matching results
      if (totalMatching === 0) {
        notifTable.style.display = 'none';
        if (noResults) {
          noResults.style.display = '';
          if (searchQueryDisplay) searchQueryDisplay.textContent = query;
        }
        if (paginationBar) paginationBar.style.display = 'none';
        if (countStart) countStart.textContent = '0';
        if (countEnd) countEnd.textContent = '0';
        if (countTotal) countTotal.textContent = '0';
        return;
      }

      // Show table, hide empty search alert
      notifTable.style.display = '';
      if (noResults) noResults.style.display = 'none';
      if (paginationBar) paginationBar.style.display = 'flex';

      const totalPages = (pageSize === Infinity) ? 1 : Math.max(1, Math.ceil(totalMatching / pageSize));
      if (currentPage > totalPages) currentPage = totalPages;
      if (currentPage < 1) currentPage = 1;

      const startIndex = (pageSize === Infinity) ? 0 : (currentPage - 1) * pageSize;
      const endIndex = (pageSize === Infinity) ? totalMatching : Math.min(totalMatching, startIndex + pageSize);

      // Create set of currently visible rows for fast lookup
      const visibleSet = new Set(matchingRows.slice(startIndex, endIndex));
      allRows.forEach(function(row) {
        row.style.display = visibleSet.has(row) ? '' : 'none';
      });

      // Update counters
      const displayStart = startIndex + 1;
      const displayEnd = endIndex;

      if (countStart) countStart.textContent = displayStart;
      if (countEnd) countEnd.textContent = displayEnd;
      if (countTotal) countTotal.textContent = totalMatching;

      if (pagInfoStart) pagInfoStart.textContent = displayStart;
      if (pagInfoEnd) pagInfoEnd.textContent = displayEnd;
      if (pagInfoTotal) pagInfoTotal.textContent = totalMatching;

      if (pagFilteredText) {
        if (totalMatching < totalRecords) {
          pagFilteredText.style.display = '';
          pagFilteredText.textContent = ' (filtered from ' + totalRecords + ' total)';
        } else {
          pagFilteredText.style.display = 'none';
        }
      }

      // Render pagination buttons & nav states
      renderPaginationButtons(totalPages);

      const isFirst = (currentPage <= 1 || totalPages <= 1);
      const isLast = (currentPage >= totalPages || totalPages <= 1);

      if (pagFirstBtn) pagFirstBtn.disabled = isFirst;
      if (pagPrevBtn) pagPrevBtn.disabled = isFirst;
      if (pagNextBtn) pagNextBtn.disabled = isLast;
      if (pagLastBtn) pagLastBtn.disabled = isLast;
    }

    function performSearch(query) {
      const q = (query || '').trim().toLowerCase();
      if (!q) {
        matchingRows = allRows.slice();
      } else {
        const terms = q.split(/\s+/).filter(Boolean);
        matchingRows = allRows.filter(function(row) {
          const text = row.getAttribute('data-search') || row.textContent.toLowerCase();
          return terms.every(function(term) {
            return text.indexOf(term) !== -1;
          });
        });
      }
      currentPage = 1;
      renderView();
    }

    // Attach Search Event Listeners
    if (searchInput) {
      searchInput.addEventListener('input', function() {
        performSearch(this.value);
      });

      searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          this.value = '';
          performSearch('');
        }
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        if (searchInput) {
          searchInput.value = '';
          searchInput.focus();
        }
        performSearch('');
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', function() {
        if (searchInput) {
          searchInput.value = '';
          searchInput.focus();
        }
        performSearch('');
      });
    }

    // Page Size Selector
    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', function() {
        const val = this.value;
        pageSize = (val === 'all') ? Infinity : (parseInt(val, 10) || 10);
        currentPage = 1;
        renderView();
      });
    }

    // Pagination Navigation Buttons
    if (pagFirstBtn) {
      pagFirstBtn.addEventListener('click', function() {
        if (currentPage > 1) {
          currentPage = 1;
          renderView();
          scrollToTable();
        }
      });
    }

    if (pagPrevBtn) {
      pagPrevBtn.addEventListener('click', function() {
        if (currentPage > 1) {
          currentPage--;
          renderView();
          scrollToTable();
        }
      });
    }

    if (pagNextBtn) {
      pagNextBtn.addEventListener('click', function() {
        const totalPages = (pageSize === Infinity) ? 1 : Math.ceil(matchingRows.length / pageSize);
        if (currentPage < totalPages) {
          currentPage++;
          renderView();
          scrollToTable();
        }
      });
    }

    if (pagLastBtn) {
      pagLastBtn.addEventListener('click', function() {
        const totalPages = (pageSize === Infinity) ? 1 : Math.ceil(matchingRows.length / pageSize);
        if (currentPage < totalPages) {
          currentPage = totalPages;
          renderView();
          scrollToTable();
        }
      });
    }

    // Initial render
    renderView();
  })();
  </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
