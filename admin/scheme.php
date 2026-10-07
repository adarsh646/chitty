<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$scheme = get_scheme($id);
if (!$scheme) { http_response_code(404); render_error('Scheme not found.'); exit; }

// Download sample enrollment CSV template for this scheme
if (isset($_GET['download_enroll_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="scheme_' . $id . '_bulk_enroll_sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Full name', 'Chittal N0.']);

    $existingSubs = get_subscriptions($id);
    $taken = array_map(fn($s) => (int)$s['ticket_number'], $existingSubs);
    $avail = array_values(array_diff(range(1, (int)$scheme['duration_months']), $taken));

    $allMembersSample = list_members();
    if (!empty($allMembersSample)) {
        foreach (array_slice($allMembersSample, 0, 3) as $k => $m) {
            $t = $avail[$k] ?? ($k + 1);
            fputcsv($out, [$m['name'], $t]);
        }
    } else {
        fputcsv($out, ['Rahul Sharma', 1]);
        fputcsv($out, ['Priya Nair', 2]);
    }
    fclose($out);
    exit;
}

$bulkEnrollErrors = [];

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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_enroll'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the scheme and try again.');
        exit;
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $bulkEnrollErrors[] = 'Please select a valid CSV file to upload.';
    } else {
        $tmpName = $_FILES['csv_file']['tmp_name'];
        $originalName = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'], true)) {
            $bulkEnrollErrors[] = 'Invalid file format. Please upload a .csv file.';
        } elseif (($handle = fopen($tmpName, 'r')) === false) {
            $bulkEnrollErrors[] = 'Could not read the uploaded CSV file.';
        } else {
            // Find existing subscriptions for this scheme
            $existingSubs = get_subscriptions($id);
            $existingTickets = [];
            foreach ($existingSubs as $s) {
                $existingTickets[(int)$s['ticket_number']] = $s['member_name'];
            }
            $remainingSlots = (int)$scheme['duration_months'] - count($existingTickets);

            $ticketIdx = null;
            $nameIdx = null;
            $phoneIdx = null;

            $firstRow = fgetcsv($handle);
            if ($firstRow === false) {
                $bulkEnrollErrors[] = 'The uploaded CSV file is empty.';
            } else {
                // Strip UTF-8 BOM if present
                if (isset($firstRow[0])) {
                    $firstRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $firstRow[0]);
                }

                $isHeader = false;
                foreach ($firstRow as $idx => $col) {
                    $colNorm = strtolower(trim((string)$col));
                    if (str_contains($colNorm, 'ticket') || str_contains($colNorm, 'chittal')) {
                        $ticketIdx = $idx;
                        $isHeader = true;
                    } elseif (str_contains($colNorm, 'name')) {
                        $nameIdx = $idx;
                        $isHeader = true;
                    } elseif (str_contains($colNorm, 'phone') || str_contains($colNorm, 'mobile')) {
                        $phoneIdx = $idx;
                    }
                }

                if (!$isHeader || ($nameIdx === null && $ticketIdx === null)) {
                    // Positional: check which column is numeric
                    $col0 = trim((string)($firstRow[0] ?? ''));
                    $col1 = trim((string)($firstRow[1] ?? ''));
                    if (ctype_digit($col0) && !ctype_digit($col1)) {
                        $ticketIdx = 0;
                        $nameIdx = 1;
                    } else {
                        $nameIdx = 0;
                        $ticketIdx = 1;
                    }
                    $rowsToProcess = [$firstRow];
                    $startLine = 1;
                } else {
                    if ($nameIdx === null) $nameIdx = 0;
                    if ($ticketIdx === null) $ticketIdx = 1;
                    $rowsToProcess = [];
                    $startLine = 2;
                }

                while (($row = fgetcsv($handle)) !== false) {
                    $rowsToProcess[] = $row;
                }
                fclose($handle);

                $seenTicketsInCsv = [];
                $validEnrollments = [];

                foreach ($rowsToProcess as $k => $row) {
                    $lineNum = $startLine + $k;
                    // Skip completely empty rows
                    $hasContent = false;
                    foreach ($row as $cell) {
                        if (trim((string)$cell) !== '') {
                            $hasContent = true;
                            break;
                        }
                    }
                    if (!$hasContent) {
                        continue;
                    }

                    $nameRaw = trim((string)($row[$nameIdx] ?? ''));
                    $ticketRaw = trim((string)($row[$ticketIdx] ?? ''));
                    $phoneRaw = $phoneIdx !== null ? trim((string)($row[$phoneIdx] ?? '')) : '';

                    $rowIssues = [];
                    $ticketNum = 0;

                    // Validate Chittal N0.
                    if ($ticketRaw === '' || !ctype_digit($ticketRaw)) {
                        $rowIssues[] = 'Valid numeric Chittal N0. is required';
                    } else {
                        $ticketNum = (int)$ticketRaw;
                        if ($ticketNum < 1 || $ticketNum > (int)$scheme['duration_months']) {
                            $rowIssues[] = "Chittal N0. #{$ticketNum} is out of range (Must be 1 to {$scheme['duration_months']})";
                        } elseif (isset($existingTickets[$ticketNum])) {
                            $rowIssues[] = "Chittal N0. #{$ticketNum} is already assigned to " . $existingTickets[$ticketNum];
                        } elseif (isset($seenTicketsInCsv[$ticketNum])) {
                            $rowIssues[] = "Duplicate Chittal N0. #{$ticketNum} in this CSV";
                        } else {
                            $seenTicketsInCsv[$ticketNum] = true;
                        }
                    }

                    // Validate member by Full Name
                    $memberUser = null;
                    if ($nameRaw === '') {
                        $rowIssues[] = 'Full name is required';
                    } else {
                        $stmt = db()->prepare("SELECT * FROM users WHERE role = 'member' AND LOWER(TRIM(name)) = LOWER(TRIM(?))");
                        $stmt->execute([$nameRaw]);
                        $matches = $stmt->fetchAll();

                        if (count($matches) === 0) {
                            // Check if member phone was entered instead of name
                            $phoneFallback = find_user_by_phone($nameRaw);
                            if ($phoneFallback && $phoneFallback['role'] === 'member') {
                                $memberUser = $phoneFallback;
                            } else {
                                $rowIssues[] = "No registered member found with name '{$nameRaw}'";
                            }
                        } elseif (count($matches) === 1) {
                            $memberUser = $matches[0];
                        } else {
                            // Multiple members with same name: use phone if available
                            if ($phoneRaw !== '') {
                                foreach ($matches as $m) {
                                    if ($m['phone'] === $phoneRaw) {
                                        $memberUser = $m;
                                        break;
                                    }
                                }
                                if (!$memberUser) {
                                    $rowIssues[] = "Multiple members named '{$nameRaw}', but none match phone '{$phoneRaw}'";
                                }
                            } else {
                                $rowIssues[] = "Multiple members found with name '{$nameRaw}'. Please include phone number to distinguish";
                            }
                        }
                    }

                    if (!empty($rowIssues)) {
                        $label = $nameRaw !== '' ? " (" . h($nameRaw) . ")" : ($ticketNum > 0 ? " (Chittal N0. #{$ticketNum})" : "");
                        $bulkEnrollErrors[] = "Row {$lineNum}{$label}: " . implode(', ', $rowIssues);
                    } else {
                        $validEnrollments[] = [
                            'user_id' => (int)$memberUser['id'],
                            'ticket_number' => $ticketNum,
                            'name' => $memberUser['name'],
                        ];
                    }
                }

                if (empty($bulkEnrollErrors)) {
                    if (empty($validEnrollments)) {
                        $bulkEnrollErrors[] = 'No valid enrollment rows found in the CSV file.';
                    } elseif (count($validEnrollments) > $remainingSlots) {
                        $bulkEnrollErrors[] = "Cannot enroll " . count($validEnrollments) . " Chittals. Only {$remainingSlots} Chittal(s) remaining in this scheme.";
                    } else {
                        $pdo = db();
                        $pdo->beginTransaction();
                        try {
                            $stmt = $pdo->prepare('INSERT INTO subscriptions (scheme_id, user_id, ticket_number) VALUES (?, ?, ?)');
                            foreach ($validEnrollments as $ve) {
                                $stmt->execute([$id, $ve['user_id'], $ve['ticket_number']]);
                            }
                            $pdo->commit();
                            header('Location: /admin/scheme.php?id=' . $id . '&bulk_enrolled=' . count($validEnrollments));
                            exit;
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $bulkEnrollErrors[] = 'Database error during bulk enroll: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
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
    <div class="stat"><div class="label">Chittals filled</div><div class="value"><?= $summary['totalTickets'] ?> / <?= (int)$scheme['duration_months'] ?></div></div>
    <div class="stat"><div class="label">Auctions held</div><div class="value"><?= $summary['auctionsHeld'] ?> / <?= (int)$scheme['duration_months'] ?></div></div>
    <div class="stat"><div class="label">Collected</div><div class="value">₹<?= money($summary['collected']) ?></div></div>
    <div class="stat"><div class="label">Outstanding</div><div class="value">₹<?= money($summary['outstanding']) ?></div></div>
  </div>

  <?php if (isset($_GET['enrolled'])): ?>
    <div class="success-msg">Member enrolled successfully.</div>
  <?php elseif (isset($_GET['bulk_enrolled'])): ?>
    <div class="success-msg">Successfully enrolled <?= (int)$_GET['bulk_enrolled'] ?> Chittal(s) via bulk CSV upload.</div>
  <?php elseif (isset($_GET['enrollment_error'])): ?>
    <div class="error-msg">Unable to enroll that Chittal N0. Choose an available Chittal N0. and a registered member.</div>
  <?php elseif (isset($_GET['auction_locked'])): ?>
    <div class="error-msg">Fill all <?= (int)$scheme['duration_months'] ?> Chittals before starting an auction.</div>
  <?php endif; ?>

  <?php if (!empty($bulkEnrollErrors)): ?>
    <div class="error-msg">
      <strong>Bulk Enrollment Failed:</strong> Please fix the following errors in your CSV file:
      <ul style="margin: 8px 0 0 18px; padding: 0;">
        <?php foreach (array_slice($bulkEnrollErrors, 0, 10) as $err): ?>
          <li><?= h($err) ?></li>
        <?php endforeach; ?>
        <?php if (count($bulkEnrollErrors) > 10): ?>
          <li><em>...and <?= count($bulkEnrollErrors) - 10 ?> more errors.</em></li>
        <?php endif; ?>
      </ul>
    </div>
  <?php endif; ?>

  <h2>Monthly auctions</h2>
  <p class="section-note">
    <?= $ticketsSoldOut
      ? 'All Chittals are sold out. You can now enter auction results.'
      : 'Auctions unlock after all ' . (int)$scheme['duration_months'] . ' Chittals are filled.' ?>
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

  <h2>Chittals & members</h2>
  <?php if (empty($subs)): ?>
    <div class="empty">No members enrolled yet.</div>
  <?php else: ?>
    <table class="responsive-card-table tickets-table">
      <thead><tr><th class="num">Chittal N0.</th><th>Member</th><th>Phone</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($subs as $s): ?>
          <tr>
            <td class="num font-mono" data-label="Chittal N0.">#<?= (int)$s['ticket_number'] ?></td>
            <td data-label="Member"><?= h($s['member_name']) ?></td>
            <td class="font-mono" data-label="Phone"><?= h($s['member_phone']) ?></td>
            <td data-label="Status"><span class="badge <?= $s['has_won'] ? 'paid' : 'active' ?>"><?= $s['has_won'] ? 'Won (month ' . (int)$s['won_month'] . ')' : 'Active' ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Enroll Section: Single Enrollment & Bulk CSV Upload -->
  <div class="members-add-grid">
    <!-- Single Member Enrollment Card -->
    <div class="card" style="margin-bottom: 0;">
      <h3>Enroll a member</h3>
      <div class="sub" style="margin-bottom: 16px;">Assign a single Chittal N0. to a registered member</div>

      <?php if ($ticketsSoldOut): ?>
        <div class="success-msg">All Chittals sold out — <?= (int)$scheme['duration_months'] ?> of <?= (int)$scheme['duration_months'] ?> Chittals are filled.</div>
      <?php elseif (empty($allMembers)): ?>
        <p class="section-note">No members registered yet. <a href="/admin/members.php">Add a member first</a>.</p>
      <?php else: ?>
        <form class="inline" method="POST" action="/admin/scheme.php?id=<?= $id ?>">
          <input type="hidden" name="enroll" value="1">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="field" style="flex:2">
            <label for="enroll_user_id">Member <span style="color:var(--bad)">*</span></label>
            <select id="enroll_user_id" name="user_id" required>
              <?php foreach ($allMembers as $m): ?>
                <option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?> (<?= h($m['phone']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="flex:1">
            <label for="enroll_ticket_number">Chittal N0. <span style="color:var(--bad)">*</span></label>
            <input type="number" id="enroll_ticket_number" name="ticket_number" min="1" max="<?= (int)$scheme['duration_months'] ?>" required placeholder="1-<?= (int)$scheme['duration_months'] ?>">
          </div>
          <button type="submit" style="align-self:flex-end">Enroll</button>
        </form>
        <p class="section-note" style="margin-top: 10px;">Chittal N0.s must be unique, from 1 to <?= (int)$scheme['duration_months'] ?>.</p>
      <?php endif; ?>
    </div>

    <!-- Bulk Upload Enrollment Card -->
    <div class="card" style="margin-bottom: 0;">
      <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px;">
        <div>
          <h3 style="margin: 0;">Bulk Enroll via CSV</h3>
          <div class="sub">Assign multiple Chittals at once using a CSV file</div>
        </div>
        <?php if (!$ticketsSoldOut): ?>
          <a href="/admin/scheme.php?id=<?= $id ?>&download_enroll_sample=1" class="btn secondary small" title="Download sample enrollment CSV template" style="white-space: nowrap;">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Sample CSV
          </a>
        <?php endif; ?>
      </div>

      <?php if ($ticketsSoldOut): ?>
        <div class="success-msg">All Chittals sold out — <?= (int)$scheme['duration_months'] ?> of <?= (int)$scheme['duration_months'] ?> Chittals are filled.</div>
      <?php elseif (empty($allMembers)): ?>
        <p class="section-note">No members registered yet. <a href="/admin/members.php">Add a member first</a>.</p>
      <?php else: ?>
        <div style="background: var(--paper); border: 1px dashed var(--rule); border-radius: 4px; padding: 10px 14px; margin: 10px 0 16px; font-size: 0.85rem; line-height: 1.5;">
          <div><strong>Required columns:</strong> <span style="color:var(--bad)">Full name</span>, <span style="color:var(--bad)">Chittal N0.</span></div>
          <div style="color: var(--ink-soft); margin-top: 3px;"><strong>Available Chittals remaining:</strong> <?= (int)$scheme['duration_months'] - $summary['totalTickets'] ?> of <?= (int)$scheme['duration_months'] ?></div>
        </div>

        <form method="POST" action="/admin/scheme.php?id=<?= $id ?>" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="bulk_enroll" value="1">

          <div class="field">
            <label for="enroll_csv_file">Select CSV File <span style="color:var(--bad)">*</span></label>
            <input type="file" id="enroll_csv_file" name="csv_file" accept=".csv,text/csv,text/plain" required style="padding: 8px 10px; background: #fff; width: 100%;">
            <span class="field-hint">Upload a <code>.csv</code> file with <strong>Full name</strong> and <strong>Chittal N0.</strong>.</span>
          </div>

          <div style="margin-top: 16px;">
            <button type="submit" class="btn">Upload &amp; Enroll Members</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
