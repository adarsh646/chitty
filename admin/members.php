<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

// Download sample CSV template
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members_bulk_upload_sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Full name', 'Phone', 'Temporary password', 'Email', 'Address']);
    fputcsv($out, ['John Doe', '9876543210', 'Pass@1234', 'john@example.com', '123 MG Road, Kochi']);
    fputcsv($out, ['Jane Smith', '9876543211', 'Pass@5678', 'jane@example.com', 'Flat 4B, Baker Hill']);
    fputcsv($out, ['Robert Paul', '9876543212', 'Pass@9999', '', '']);
    fclose($out);
    exit;
}

$error = null;
$editError = null;
$bulkErrors = [];
$form = [];
$editMember = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_member'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the members page and try again.');
        exit;
    }

    $result = delete_member((int) ($_POST['member_id'] ?? 0));
    header('Location: /admin/members.php?delete=' . urlencode($result));
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_member'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the members page and try again.');
        exit;
    }

    $editId = (int) ($_POST['member_id'] ?? 0);
    $editName = trim($_POST['name'] ?? '');
    $editPhone = trim($_POST['phone'] ?? '');
    $editEmail = trim($_POST['email'] ?? '') ?: null;
    $editAddress = trim($_POST['address'] ?? '') ?: null;
    $editPassword = (string) ($_POST['password'] ?? '');

    $target = find_user_by_id($editId);
    if (!$target || $target['role'] !== 'member') {
        $editError = 'That member could not be found.';
    } elseif ($editName === '' || $editPhone === '') {
        $editError = 'Name and phone are required.';
    } else {
        $existingPhoneUser = find_user_by_phone($editPhone);
        if ($existingPhoneUser && (int) $existingPhoneUser['id'] !== $editId) {
            $editError = 'A user with that phone number already exists.';
        } elseif ($editPassword !== '' && strlen($editPassword) < 6) {
            $editError = 'Password must be at least 6 characters long.';
        } else {
            update_member_details(
                $editId,
                $editName,
                $editPhone,
                $editEmail,
                $editAddress,
                $editPassword !== '' ? $editPassword : null
            );
            header('Location: /admin/members.php?updated=1');
            exit;
        }
    }

    if ($editError) {
        $editMember = [
            'id' => $editId,
            'name' => $editName,
            'phone' => $editPhone,
            'email' => $editEmail,
            'address' => $editAddress,
        ];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the members page and try again.');
        exit;
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $bulkErrors[] = 'Please select a valid CSV file to upload.';
    } else {
        $tmpName = $_FILES['csv_file']['tmp_name'];
        $originalName = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'], true)) {
            $bulkErrors[] = 'Invalid file format. Please upload a .csv file.';
        } elseif (($handle = fopen($tmpName, 'r')) === false) {
            $bulkErrors[] = 'Could not read the uploaded CSV file.';
        } else {
            $nameIdx = null;
            $phoneIdx = null;
            $passwordIdx = null;
            $emailIdx = null;
            $addressIdx = null;

            $firstRow = fgetcsv($handle);
            if ($firstRow === false) {
                $bulkErrors[] = 'The uploaded CSV file is empty.';
            } else {
                // Strip UTF-8 BOM if present
                if (isset($firstRow[0])) {
                    $firstRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $firstRow[0]);
                }

                $isHeader = false;
                foreach ($firstRow as $idx => $col) {
                    $colNorm = strtolower(trim((string)$col));
                    if (str_contains($colNorm, 'name')) {
                        $nameIdx = $idx;
                        $isHeader = true;
                    } elseif (str_contains($colNorm, 'phone') || str_contains($colNorm, 'mobile')) {
                        $phoneIdx = $idx;
                        $isHeader = true;
                    } elseif (str_contains($colNorm, 'pass')) {
                        $passwordIdx = $idx;
                        $isHeader = true;
                    } elseif (str_contains($colNorm, 'email')) {
                        $emailIdx = $idx;
                    } elseif (str_contains($colNorm, 'address')) {
                        $addressIdx = $idx;
                    }
                }

                if (!$isHeader || $nameIdx === null || $phoneIdx === null || $passwordIdx === null) {
                    $nameIdx = 0;
                    $phoneIdx = 1;
                    $passwordIdx = 2;
                    $emailIdx = 3;
                    $addressIdx = 4;
                    $rowsToProcess = [$firstRow];
                    $startLine = 1;
                } else {
                    $rowsToProcess = [];
                    $startLine = 2;
                }

                while (($row = fgetcsv($handle)) !== false) {
                    $rowsToProcess[] = $row;
                }
                fclose($handle);

                $seenPhones = [];
                $validMembers = [];

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

                    $rowName = trim((string)($row[$nameIdx] ?? ''));
                    $rowPhone = trim((string)($row[$phoneIdx] ?? ''));
                    $rowPassword = (string)($row[$passwordIdx] ?? '');
                    $rowEmail = isset($emailIdx) && isset($row[$emailIdx]) ? trim((string)$row[$emailIdx]) : '';
                    $rowAddress = isset($addressIdx) && isset($row[$addressIdx]) ? trim((string)$row[$addressIdx]) : '';

                    $rowIssues = [];
                    if ($rowName === '') {
                        $rowIssues[] = 'Full name is required';
                    }
                    if ($rowPhone === '') {
                        $rowIssues[] = 'Phone is required';
                    } elseif (isset($seenPhones[$rowPhone])) {
                        $rowIssues[] = "Duplicate phone '{$rowPhone}' in CSV";
                    } elseif (find_user_by_phone($rowPhone)) {
                        $rowIssues[] = "Phone '{$rowPhone}' already exists in database";
                    } else {
                        $seenPhones[$rowPhone] = true;
                    }

                    if ($rowPassword === '') {
                        $rowIssues[] = 'Temporary password is required';
                    } elseif (strlen($rowPassword) < 6) {
                        $rowIssues[] = 'Temporary password must be at least 6 characters';
                    }

                    if (!empty($rowIssues)) {
                        $label = $rowName !== '' ? " (" . h($rowName) . ")" : '';
                        $bulkErrors[] = "Row {$lineNum}{$label}: " . implode(', ', $rowIssues);
                    } else {
                        $validMembers[] = [
                            'name' => $rowName,
                            'phone' => $rowPhone,
                            'password' => $rowPassword,
                            'email' => $rowEmail !== '' ? $rowEmail : null,
                            'address' => $rowAddress !== '' ? $rowAddress : null,
                        ];
                    }
                }

                if (empty($bulkErrors)) {
                    if (empty($validMembers)) {
                        $bulkErrors[] = 'No member records found in the CSV file.';
                    } else {
                        $pdo = db();
                        $pdo->beginTransaction();
                        try {
                            foreach ($validMembers as $vm) {
                                create_user($vm['name'], $vm['phone'], $vm['email'], $vm['address'], $vm['password'], 'member');
                            }
                            $pdo->commit();
                            header('Location: /admin/members.php?bulk_added=' . count($validMembers));
                            exit;
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $bulkErrors[] = 'Database error during bulk insert: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the members page and try again.');
        exit;
    }

    $form = $_POST;
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '') ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;

    if ($name === '' || $phone === '' || $password === '') {
        $error = 'Name, phone and temporary password are required.';
    } elseif (strlen($password) < 6) {
        $error = 'Temporary password must be at least 6 characters.';
    } elseif (find_user_by_phone($phone)) {
        $error = 'A user with that phone number already exists.';
    } else {
        create_user($name, $phone, $email, $address, $password, 'member');
        header('Location: /admin/members.php?added=1');
        exit;
    }
}

if (isset($_GET['edit_id']) && !$editMember) {
    $found = find_user_by_id((int) $_GET['edit_id']);
    if ($found && $found['role'] === 'member') {
        $editMember = $found;
    }
}

$searchQuery = trim($_GET['q'] ?? '');
$allMembers = list_members();
if ($searchQuery !== '') {
    $qLower = mb_strtolower($searchQuery);
    $members = array_values(array_filter($allMembers, function($m) use ($qLower) {
        return str_contains(mb_strtolower($m['name'] ?? ''), $qLower)
            || str_contains(mb_strtolower($m['phone'] ?? ''), $qLower)
            || str_contains(mb_strtolower($m['email'] ?? ''), $qLower)
            || str_contains(mb_strtolower($m['address'] ?? ''), $qLower);
    }));
} else {
    $members = $allMembers;
}

$title = 'Members';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head"><div><h1>Members</h1><div class="sub">Everyone registered with the society (<?= count($allMembers) ?> total)</div></div></div>

  <?php if (($_GET['delete'] ?? '') === 'deleted'): ?>
    <div class="success-msg">The member was deleted.</div>
  <?php elseif (($_GET['delete'] ?? '') === 'enrolled'): ?>
    <div class="error-msg">You can't delete this member because they are participating in a chit scheme. Their scheme and payment records must be kept.</div>
  <?php elseif (($_GET['delete'] ?? '') === 'not_found'): ?>
    <div class="error-msg">That member could not be found.</div>
  <?php elseif (($_GET['updated'] ?? '') === '1'): ?>
    <div class="success-msg">Member details updated successfully.</div>
  <?php elseif (($_GET['added'] ?? '') === '1'): ?>
    <div class="success-msg">Member added successfully.</div>
  <?php elseif (isset($_GET['bulk_added'])): ?>
    <div class="success-msg">Successfully added <?= (int)$_GET['bulk_added'] ?> member(s) via bulk CSV upload.</div>
  <?php endif; ?>

  <?php if (!empty($bulkErrors)): ?>
    <div class="error-msg">
      <strong>Bulk Upload Failed:</strong> Please fix the following errors in your CSV file:
      <ul style="margin: 8px 0 0 18px; padding: 0;">
        <?php foreach (array_slice($bulkErrors, 0, 10) as $err): ?>
          <li><?= h($err) ?></li>
        <?php endforeach; ?>
        <?php if (count($bulkErrors) > 10): ?>
          <li><em>...and <?= count($bulkErrors) - 10 ?> more errors.</em></li>
        <?php endif; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($editError && !$editMember): ?>
    <div class="error-msg"><?= h($editError) ?></div>
  <?php endif; ?>

  <?php if (empty($allMembers)): ?>
    <div class="empty">No members yet.</div>
  <?php else: ?>
    <div class="table-toolbar">
      <div class="search-input-wrap">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" id="memberSearchInput" placeholder="Search members by name, phone, email..." value="<?= h($searchQuery) ?>" autocomplete="off" spellcheck="false">
        <button type="button" id="clearSearchBtn" class="search-clear-btn" aria-label="Clear search" title="Clear search" style="<?= $searchQuery !== '' ? '' : 'display:none;' ?>">&times;</button>
      </div>
      <div id="memberSearchCount" style="font-size: 0.88rem; color: var(--ink-soft);">
        Showing <span id="visibleMemberCount"><?= count($members) ?></span> of <?= count($allMembers) ?> members
      </div>
    </div>

    <div id="noSearchResults" class="empty" style="<?= (empty($members) && $searchQuery !== '') ? '' : 'display:none;' ?> margin-bottom: 20px;">
      No members found matching "<span id="searchQueryDisplay"><?= h($searchQuery) ?></span>".
      <button type="button" class="btn secondary small" id="resetSearchBtn" style="margin-left: 8px;">Clear search</button>
    </div>

    <table class="responsive-card-table members-table" id="membersTable" style="<?= (empty($members) && $searchQuery !== '') ? 'display:none;' : '' ?>">
      <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Address</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($allMembers as $m): ?>
          <?php
            $isRowVisible = true;
            if ($searchQuery !== '') {
                $qLower = mb_strtolower($searchQuery);
                $isRowVisible = str_contains(mb_strtolower($m['name'] ?? ''), $qLower)
                    || str_contains(mb_strtolower($m['phone'] ?? ''), $qLower)
                    || str_contains(mb_strtolower($m['email'] ?? ''), $qLower)
                    || str_contains(mb_strtolower($m['address'] ?? ''), $qLower);
            }
          ?>
          <tr class="member-row" style="<?= $isRowVisible ? '' : 'display:none;' ?>" data-search="<?= h(mb_strtolower($m['name'] . ' ' . $m['phone'] . ' ' . ($m['email'] ?? '') . ' ' . ($m['address'] ?? ''))) ?>">
            <td data-label="Name"><?= h($m['name']) ?></td>
            <td data-label="Phone" class="font-mono"><?= h($m['phone']) ?></td>
            <td data-label="Email"><?= h($m['email'] ?: '—') ?></td>
            <td data-label="Address"><?= h($m['address'] ?: '—') ?></td>
            <td data-label="Action">
              <div class="table-actions">
                <form method="POST" action="/admin/members.php" onsubmit="return confirm('Delete this member? This cannot be undone.');" style="display:inline; margin:0;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                  <button class="btn danger small" type="submit" name="delete_member" value="1">Delete</button>
                </form>
                <button type="button" class="btn secondary small edit-member-btn"
                  data-id="<?= (int)$m['id'] ?>"
                  data-name="<?= h($m['name']) ?>"
                  data-phone="<?= h($m['phone']) ?>"
                  data-email="<?= h($m['email'] ?? '') ?>"
                  data-address="<?= h($m['address'] ?? '') ?>"
                >Edit</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Edit Member Modal -->
  <div id="editMemberModal" class="modal-backdrop" style="display: <?= $editMember ? 'flex' : 'none' ?>;" aria-hidden="<?= $editMember ? 'false' : 'true' ?>" role="dialog" aria-labelledby="editModalTitle">
    <div class="modal-card">
      <div class="modal-header">
        <div>
          <h2 id="editModalTitle" style="margin: 0; font-size: 1.25rem;">Edit Member</h2>
          <div class="sub" id="editModalSub"><?= $editMember ? ('Editing ' . h($editMember['name'])) : 'Update member details and credentials' ?></div>
        </div>
        <button type="button" class="modal-close" id="editModalCloseBtn" aria-label="Close modal">&times;</button>
      </div>

      <div class="modal-body" style="padding: 20px;">
        <?php if ($editError): ?>
          <div class="error-msg" style="margin-bottom: 16px;"><?= h($editError) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/members.php" id="editMemberForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="edit_member" value="1">
          <input type="hidden" name="member_id" id="edit_member_id" value="<?= (int)($editMember['id'] ?? 0) ?>">

          <div class="field">
            <label for="edit_name">Full name <span style="color:var(--bad)">*</span></label>
            <input type="text" id="edit_name" name="name" value="<?= h($editMember['name'] ?? '') ?>" required>
          </div>

          <div class="field">
            <label for="edit_phone">Phone (used to log in) <span style="color:var(--bad)">*</span></label>
            <input type="tel" id="edit_phone" inputmode="tel" autocomplete="tel" name="phone" value="<?= h($editMember['phone'] ?? '') ?>" required>
          </div>

          <div class="field-row">
            <div class="field">
              <label for="edit_email">Email (optional)</label>
              <input type="email" id="edit_email" name="email" value="<?= h($editMember['email'] ?? '') ?>">
            </div>
            <div class="field">
              <label for="edit_address">Address (optional)</label>
              <input type="text" id="edit_address" name="address" value="<?= h($editMember['address'] ?? '') ?>">
            </div>
          </div>

          <div class="field">
            <label for="edit_password">New password (optional)</label>
            <input type="password" id="edit_password" name="password" minlength="6" placeholder="Leave blank to keep unchanged" autocomplete="new-password">
            <span class="field-hint">Leave blank unless you wish to change this member's password.</span>
          </div>

          <div class="form-actions" style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" class="btn secondary" id="editModalCancelBtn">Cancel</button>
            <button type="submit" class="btn">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Add Member Section: Single Add & Bulk Upload -->
  <div class="members-add-grid">
    <!-- Single Member Card -->
    <div class="card" style="margin-bottom: 0;">
      <h3>Add a member</h3>
      <div class="sub" style="margin-bottom: 16px;">Create a single member account manually</div>
      <?php if ($error): ?><div class="error-msg"><?= h($error) ?></div><?php endif; ?>
      <form method="POST" action="/admin/members.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="add_single_member" value="1">

        <div class="field">
          <label for="single_name">Full name <span style="color:var(--bad)">*</span></label>
          <input type="text" id="single_name" name="name" value="<?= h($form['name'] ?? '') ?>" required placeholder="e.g. Rahul Sharma">
        </div>

        <div class="field-row">
          <div class="field">
            <label for="single_phone">Phone (used to log in) <span style="color:var(--bad)">*</span></label>
            <input type="tel" id="single_phone" inputmode="tel" autocomplete="tel" name="phone" value="<?= h($form['phone'] ?? '') ?>" required placeholder="e.g. 9876543210">
          </div>
          <div class="field">
            <label for="single_password">Temporary password <span style="color:var(--bad)">*</span></label>
            <input type="text" id="single_password" name="password" required minlength="6" placeholder="Min. 6 characters">
          </div>
        </div>

        <div class="field-row">
          <div class="field">
            <label for="single_email">Email (optional)</label>
            <input type="email" id="single_email" name="email" value="<?= h($form['email'] ?? '') ?>" placeholder="name@example.com">
          </div>
          <div class="field">
            <label for="single_address">Address (optional)</label>
            <input type="text" id="single_address" name="address" value="<?= h($form['address'] ?? '') ?>" placeholder="Residential address">
          </div>
        </div>

        <button type="submit" class="btn">Add Member</button>
      </form>
    </div>

    <!-- Bulk Upload Card -->
    <div class="card" style="margin-bottom: 0;">
      <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px;">
        <div>
          <h3 style="margin: 0;">Bulk Upload via CSV</h3>
          <div class="sub">Import multiple members at once using a CSV file</div>
        </div>
        <a href="/admin/members.php?download_sample=1" class="btn secondary small" title="Download sample CSV template" style="white-space: nowrap;">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Sample CSV
        </a>
      </div>

      <div style="background: var(--paper); border: 1px dashed var(--rule); border-radius: 4px; padding: 12px 14px; margin: 12px 0 18px; font-size: 0.85rem; line-height: 1.5;">
        <div><strong>Required (not null):</strong> <span style="color:var(--bad)">Full name</span>, <span style="color:var(--bad)">Phone</span>, <span style="color:var(--bad)">Temporary password</span></div>
        <div style="color: var(--ink-soft); margin-top: 4px;"><strong>Optional:</strong> Email, Address</div>
      </div>

      <form method="POST" action="/admin/members.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="bulk_upload" value="1">

        <div class="field">
          <label for="csv_file">Select CSV File <span style="color:var(--bad)">*</span></label>
          <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv,text/plain" required style="padding: 8px 10px; background: #fff; width: 100%;">
          <span class="field-hint">Upload a <code>.csv</code> file matching the column format.</span>
        </div>

        <div style="margin-top: 24px;">
          <button type="submit" class="btn">Upload &amp; Import Members</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('editMemberModal');
    const closeBtn = document.getElementById('editModalCloseBtn');
    const cancelBtn = document.getElementById('editModalCancelBtn');
    const idInput = document.getElementById('edit_member_id');
    const nameInput = document.getElementById('edit_name');
    const phoneInput = document.getElementById('edit_phone');
    const emailInput = document.getElementById('edit_email');
    const addressInput = document.getElementById('edit_address');
    const passwordInput = document.getElementById('edit_password');
    const titleSub = document.getElementById('editModalSub');

    function openEditModal(btn) {
      idInput.value = btn.dataset.id || '';
      nameInput.value = btn.dataset.name || '';
      phoneInput.value = btn.dataset.phone || '';
      emailInput.value = btn.dataset.email || '';
      addressInput.value = btn.dataset.address || '';
      passwordInput.value = '';
      if (titleSub) {
        titleSub.textContent = 'Editing ' + (btn.dataset.name || 'Member');
      }
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      nameInput.focus();
    }

    function closeEditModal() {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.edit-member-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        openEditModal(btn);
      });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeEditModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeEditModal);

    modal.addEventListener('click', function(e) {
      if (e.target === modal) {
        closeEditModal();
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && modal.style.display === 'flex') {
        closeEditModal();
      }
    });

    // Live Search Functionality
    const searchInput = document.getElementById('memberSearchInput');
    const clearBtn = document.getElementById('clearSearchBtn');
    const resetBtn = document.getElementById('resetSearchBtn');
    const visibleCountSpan = document.getElementById('visibleMemberCount');
    const noResults = document.getElementById('noSearchResults');
    const searchQueryDisplay = document.getElementById('searchQueryDisplay');
    const membersTable = document.getElementById('membersTable');
    const memberRows = document.querySelectorAll('.member-row');
    const totalMemberCount = memberRows.length;

    function performSearch(query) {
      const q = (query || '').trim().toLowerCase();
      let visible = 0;

      memberRows.forEach(function(row) {
        const text = row.getAttribute('data-search') || row.textContent.toLowerCase();
        if (!q || text.indexOf(q) !== -1) {
          row.style.display = '';
          visible++;
        } else {
          row.style.display = 'none';
        }
      });

      if (visibleCountSpan) {
        visibleCountSpan.textContent = visible;
      }

      if (clearBtn) {
        clearBtn.style.display = q ? '' : 'none';
      }

      if (visible === 0 && totalMemberCount > 0) {
        if (membersTable) membersTable.style.display = 'none';
        if (noResults) {
          noResults.style.display = '';
          if (searchQueryDisplay) searchQueryDisplay.textContent = query;
        }
      } else {
        if (membersTable) membersTable.style.display = '';
        if (noResults) noResults.style.display = 'none';
      }
    }

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
  });
  </script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
