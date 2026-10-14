<?php
// Admin Email Action Endpoint: Direct 1-Click Verification from Email.
// Validates cryptographic HMAC token, immediately approves payment, marks installment ledger as Paid,
// and displays a verified confirmation receipt without requiring dashboard authentication.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chitty.php';
require_once __DIR__ . '/../includes/upi.php';
require_once __DIR__ . '/../includes/mail.php';

start_url_mask_buffer();

$notificationId = (int) ($_GET['id'] ?? 0);
$token = trim((string) ($_GET['token'] ?? ''));

$error = null;
$verifiedNow = false;
$alreadyVerified = false;
$notif = null;

if ($notificationId <= 0 || empty($token)) {
    $error = 'Invalid verification link. Missing notification ID or security token.';
} else {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT n.*, u.name AS member_name, u.phone AS member_phone, u.email AS member_email,
               cs.id AS chit_scheme_id, cs.name AS scheme_name, cs.monthly_subscription, 
               s.ticket_number, i.month_number, i.amount_due, i.amount_paid, i.status AS installment_status,
               i.excess_amount, i.deducted_from_excess
        FROM notifications n
        JOIN users u ON u.id = n.user_id
        JOIN chit_schemes cs ON cs.id = n.scheme_id
        JOIN installments i ON i.id = n.installment_id
        JOIN subscriptions s ON s.id = i.subscription_id
        WHERE n.id = ?
    ');
    $stmt->execute([$notificationId]);
    $notif = $stmt->fetch();

    if (!$notif) {
        $error = 'Payment notification record not found.';
    } elseif (!validate_verify_mail_token((int)$notif['id'], $notif['transaction_id'], (float)$notif['amount'], $token)) {
        $error = 'Security check failed. The verification token is invalid or has expired.';
    } elseif ($notif['status'] === 'rejected') {
        $error = 'This transaction has already been rejected as invalid by an administrator.';
    } elseif ($notif['status'] === 'verified') {
        $alreadyVerified = true;
    } else {
        // Status is pending: execute direct 1-click verification immediately!
        $currentUser = current_user();
        $adminId = ($currentUser && ($currentUser['role'] ?? '') === 'admin')
            ? (int)$currentUser['id']
            : 0;

        if ($adminId <= 0) {
            $stmtAdmin = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
            $adminId = (int) ($stmtAdmin->fetchColumn() ?: 14);
        }

        try {
            $res = verify_upi_payment($notificationId, $adminId);
            $verifiedNow = true;

            // Re-fetch updated record to display paid status & excess details
            $stmt->execute([$notificationId]);
            $notif = $stmt->fetch();
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'already been verified')) {
                $alreadyVerified = true;
                $stmt->execute([$notificationId]);
                $notif = $stmt->fetch();
            } else {
                $error = 'Failed to verify payment: ' . $e->getMessage();
            }
        }
    }
}

$currentUser = current_user();
$isAdminLoggedIn = ($currentUser && ($currentUser['role'] ?? '') === 'admin');

if ($isAdminLoggedIn) {
    $title = 'Payment Verification Receipt';
    require_once __DIR__ . '/../includes/header.php';
} else {
    // Standalone clean receipt view for guest / mobile email clicks
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
      <title>Payment Verification Receipt — MSS Chitty</title>
      <link rel="stylesheet" href="/css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
      <style>
        .standalone-verify-wrap {
          min-height: 100vh;
          background: #F1F5F9;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 24px 16px;
        }
        .receipt-card {
          width: 100%;
          max-width: 580px;
          background: #ffffff;
          border-radius: 14px;
          border: 1px solid #E2E8F0;
          box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
          overflow: hidden;
        }
      </style>
    </head>
    <body>
    <div class="standalone-verify-wrap">
    <?php
}
?>

<div class="receipt-card" style="max-width: 600px; margin: <?= $isAdminLoggedIn ? '20px auto 40px auto' : '0 auto' ?>; background: #fff; border-radius: 12px; border: 1px solid #E2E8F0; box-shadow: 0 4px 16px rgba(0,0,0,0.06); overflow: hidden;">
  
  <!-- Header Banner -->
  <div style="background: #0F172A; padding: 22px 28px; color: #fff;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
      <div>
        <div style="color: #10B981; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 2px;">MSS Chitty Platform</div>
        <h2 style="margin: 0; color: #ffffff; font-size: 1.35rem; font-weight: 700;">Payment Verification</h2>
      </div>
      <div style="background: rgba(255,255,255,0.08); padding: 8px 12px; border-radius: 6px; font-size: 12px; color: #94A3B8;">
        Email 1-Click
      </div>
    </div>
  </div>

  <div style="padding: 28px;">
    <?php if ($error): ?>
      <div style="background: #FEF2F2; border-left: 4px solid #EF4444; border-radius: 8px; padding: 18px; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="15" y1="9" x2="9" y2="15"></line>
            <line x1="9" y1="9" x2="15" y2="15"></line>
          </svg>
          <strong style="color: #991B1B; font-size: 15px;">Verification Unsuccessful</strong>
        </div>
        <div style="margin-top: 8px; color: #B91C1C; font-size: 14px; line-height: 1.5;">
          <?= h($error) ?>
        </div>
      </div>
    <?php else: ?>
      
      <!-- Success / Already Verified Banner -->
      <div style="background: <?= $verifiedNow ? '#ECFDF5' : '#F0F9FF' ?>; border-left: 4px solid <?= $verifiedNow ? '#10B981' : '#0EA5E9' ?>; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="background: <?= $verifiedNow ? '#10B981' : '#0EA5E9' ?>; color: #ffffff; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
          </div>
          <div>
            <h3 style="margin: 0; color: <?= $verifiedNow ? '#065F46' : '#075985' ?>; font-size: 1.15rem; font-weight: 700;">
              <?= $verifiedNow ? 'Payment Verified & Approved!' : 'Payment Already Verified & Approved' ?>
            </h3>
            <div style="font-size: 13px; color: <?= $verifiedNow ? '#047857' : '#0369A1' ?>; margin-top: 3px;">
              <?= $verifiedNow ? 'The installment ledger has been credited and updated to <strong>Paid</strong> in the database.' : 'This installment payment was already confirmed and marked as Paid.' ?>
            </div>
            <div style="font-size: 12px; color: <?= $verifiedNow ? '#065F46' : '#0369A1' ?>; margin-top: 6px;">
              ✉ The action button in your notification email has transitioned to <strong>Verified</strong>.
            </div>
          </div>
        </div>
      </div>

      <!-- Amount Highlight Card -->
      <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 18px 22px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
        <div>
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748B;">Amount Credited</div>
          <div style="font-size: 1.85rem; font-weight: 800; color: #0F172A; margin-top: 2px;">
            ₹<?= money($notif['amount']) ?>
          </div>
        </div>
        <div>
          <span style="display: inline-block; background: #10B981; color: #ffffff; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 12px; letter-spacing: 0.05em; text-transform: uppercase;">
            ✓ Status: Paid
          </span>
        </div>
      </div>

      <!-- Transaction Details -->
      <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #475569; border-bottom: 1px solid #E2E8F0; padding-bottom: 8px;">
        Transaction & Member Details
      </h4>

      <table style="width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 24px;">
        <tr style="border-bottom: 1px solid #F1F5F9;">
          <td style="padding: 10px 0; color: #64748B; width: 150px;">UPI Transaction ID:</td>
          <td style="padding: 10px 0; font-family: monospace; font-size: 14px; font-weight: 700; color: #0F172A;">
            <?= h($notif['transaction_id']) ?>
          </td>
        </tr>
        <tr style="border-bottom: 1px solid #F1F5F9;">
          <td style="padding: 10px 0; color: #64748B;">Member Name:</td>
          <td style="padding: 10px 0; font-weight: 600; color: #0F172A;">
            <?= h($notif['member_name']) ?>
          </td>
        </tr>
        <tr style="border-bottom: 1px solid #F1F5F9;">
          <td style="padding: 10px 0; color: #64748B;">Phone Number:</td>
          <td style="padding: 10px 0; color: #334155;">
            <?= h($notif['member_phone']) ?>
          </td>
        </tr>
        <tr style="border-bottom: 1px solid #F1F5F9;">
          <td style="padding: 10px 0; color: #64748B;">Chit Scheme:</td>
          <td style="padding: 10px 0; font-weight: 600; color: #0F172A;">
            <?= h($notif['scheme_name']) ?> (Chittal #<?= (int)$notif['ticket_number'] ?>)
          </td>
        </tr>
        <tr style="border-bottom: 1px solid #F1F5F9;">
          <td style="padding: 10px 0; color: #64748B;">Installment Month:</td>
          <td style="padding: 10px 0; font-weight: 600; color: #0F172A;">
            Month #<?= (int)$notif['month_number'] ?>
          </td>
        </tr>
        <?php if (!empty($notif['excess_amount']) && (float)$notif['excess_amount'] > 0): ?>
          <tr style="border-bottom: 1px solid #F1F5F9; background: #FEFCE8;">
            <td style="padding: 10px 8px; color: #854D0E; font-weight: 600;">Excess Credited:</td>
            <td style="padding: 10px 8px; font-weight: 700; color: #854D0E;">
              ₹<?= money($notif['excess_amount']) ?> (Will auto-deduct from next month)
            </td>
          </tr>
        <?php endif; ?>
        <tr>
          <td style="padding: 10px 0; color: #64748B;">Verified On:</td>
          <td style="padding: 10px 0; color: #475569;">
            <?= !empty($notif['verified_at']) ? date('d M Y, h:i A', strtotime($notif['verified_at'])) : date('d M Y, h:i A') ?>
          </td>
        </tr>
      </table>

    <?php endif; ?>

    <!-- Navigation Actions -->
    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; padding-top: 16px; border-top: 1px solid #E2E8F0;">
      <?php if ($isAdminLoggedIn): ?>
        <a href="/admin/notifications.php" class="btn primary" style="flex: 1; text-align: center; padding: 12px; background: #0F172A; color: #fff; border-radius: 6px; text-decoration: none; font-weight: 600;">
          &larr; Admin Notifications Dashboard
        </a>
        <?php if (!empty($notif['chit_scheme_id']) && !empty($notif['month_number'])): ?>
          <a href="/admin/month.php?scheme_id=<?= (int)$notif['chit_scheme_id'] ?>&month=<?= (int)$notif['month_number'] ?>" class="btn secondary" style="padding: 12px 18px; border: 1px solid #CBD5E1; color: #334155; border-radius: 6px; text-decoration: none; font-weight: 600;">
            View Scheme Month
          </a>
        <?php endif; ?>
      <?php else: ?>
        <a href="/login.php" class="btn primary" style="flex: 1; text-align: center; padding: 12px; background: #0F172A; color: #fff; border-radius: 6px; text-decoration: none; font-weight: 600;">
          Sign In to Administrator Dashboard &rarr;
        </a>
      <?php endif; ?>
    </div>

    <?php if (!$isAdminLoggedIn): ?>
      <div style="margin-top: 14px; text-align: center; font-size: 12px; color: #94A3B8;">
        The transaction is already processed and committed to the database ledger. No further sign-in is required.
      </div>
    <?php endif; ?>

  </div>
</div>

<?php
if ($isAdminLoggedIn) {
    require_once __DIR__ . '/../includes/footer.php';
} else {
    ?>
    </div>
    </body>
    </html>
    <?php
}
