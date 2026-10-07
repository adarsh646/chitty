<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_admin();

$error = null;
$form = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST;
    $name = trim($_POST['name'] ?? '');
    $chit_value = $_POST['chit_value'] ?? '';
    $duration_months = $_POST['duration_months'] ?? '';
    $commission_percent = $_POST['commission_percent'] ?? '5';
    $start_date = $_POST['start_date'] ?? '';

    if ($name === '' || $chit_value === '' || $duration_months === '' || $start_date === '') {
        $error = 'Please fill in all required fields.';
    } else {
        $id = create_scheme($name, (float)$chit_value, (int)$duration_months, (float)$commission_percent, $start_date);
        header('Location: /admin/scheme.php?id=' . $id);
        exit;
    }
}

$title = 'New scheme';
require_once __DIR__ . '/../includes/header.php';
?>
  <div class="page-head"><div><h1>New chit scheme</h1></div></div>

  <div class="card" style="max-width:520px">
    <?php if ($error): ?><div class="error-msg"><?= h($error) ?></div><?php endif; ?>
    <form method="POST" action="/admin/scheme-new.php">
      <div class="field">
        <label for="name">Scheme name</label>
        <input type="text" id="name" name="name" placeholder="e.g. Chitty No. 42" value="<?= h($form['name'] ?? '') ?>" required>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="chit_value">Chit value (₹)</label>
          <input type="number" step="1" inputmode="numeric" id="chit_value" name="chit_value" value="<?= h($form['chit_value'] ?? '') ?>" required>
        </div>
        <div class="field">
          <label for="duration_months">Duration (months / Chittals)</label>
          <input type="number" step="1" inputmode="numeric" id="duration_months" name="duration_months" value="<?= h($form['duration_months'] ?? '') ?>" required>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="commission_percent">Society commission (%)</label>
          <input type="number" step="0.1" inputmode="decimal" id="commission_percent" name="commission_percent" value="<?= h($form['commission_percent'] ?? '5') ?>">
        </div>
        <div class="field">
          <label for="start_date">Month 1 auction date</label>
          <input type="date" id="start_date" name="start_date" value="<?= h($form['start_date'] ?? '') ?>" required>
        </div>
      </div>
      <p class="section-note">Monthly subscription is calculated automatically as chit value ÷ duration.</p>
      <button type="submit">Create scheme</button>
    </form>
  </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
