<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$error = null;
$form = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_member'])) {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        render_error('Your form session has expired. Please return to the members page and try again.');
        exit;
    }

    $result = delete_member((int) ($_POST['member_id'] ?? 0));
    header('Location: /admin/members.php?delete=' . urlencode($result));
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST;
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '') ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;

    if ($name === '' || $phone === '' || $password === '') {
        $error = 'Name, phone and password are required.';
    } elseif (find_user_by_phone($phone)) {
        $error = 'A user with that phone number already exists.';
    } else {
        create_user($name, $phone, $email, $address, $password, 'member');
        header('Location: /admin/members.php');
        exit;
    }
}

$members = list_members();
$title = 'Members';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head"><div><h1>Members</h1><div class="sub">Everyone registered with the society</div></div></div>

  <?php if (($_GET['delete'] ?? '') === 'deleted'): ?>
    <div class="success-msg">The member was deleted.</div>
  <?php elseif (($_GET['delete'] ?? '') === 'enrolled'): ?>
    <div class="error-msg">You can't delete this member because they are participating in a chit scheme. Their scheme and payment records must be kept.</div>
  <?php elseif (($_GET['delete'] ?? '') === 'not_found'): ?>
    <div class="error-msg">That member could not be found.</div>
  <?php endif; ?>

  <?php if (empty($members)): ?>
    <div class="empty">No members yet.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Address</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($members as $m): ?>
          <tr>
            <td><?= h($m['name']) ?></td>
            <td><?= h($m['phone']) ?></td>
            <td><?= h($m['email'] ?: '—') ?></td>
            <td><?= h($m['address'] ?: '—') ?></td>
            <td>
              <form method="POST" action="/admin/members.php" onsubmit="return confirm('Delete this member? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                <button class="btn danger small" type="submit" name="delete_member" value="1">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="card" style="max-width:480px">
    <h3>Add a member</h3>
    <?php if ($error): ?><div class="error-msg"><?= h($error) ?></div><?php endif; ?>
    <form method="POST" action="/admin/members.php">
      <div class="field"><label>Full name</label><input type="text" name="name" value="<?= h($form['name'] ?? '') ?>" required></div>
      <div class="field-row">
        <div class="field"><label>Phone (used to log in)</label><input type="text" name="phone" value="<?= h($form['phone'] ?? '') ?>" required></div>
        <div class="field"><label>Temporary password</label><input type="text" name="password" required></div>
      </div>
      <div class="field-row">
        <div class="field"><label>Email (optional)</label><input type="email" name="email" value="<?= h($form['email'] ?? '') ?>"></div>
        <div class="field"><label>Address (optional)</label><input type="text" name="address" value="<?= h($form['address'] ?? '') ?>"></div>
      </div>
      <button type="submit">Add member</button>
    </form>
  </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
