<?php
// Centralized Mail & SMTP Dispatcher for MSS Chitty Platform.
// Reads all SMTP and email settings directly from the .env environment file.
// Sends payment submission alerts to administrators and validates 1-click verification tokens.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/chitty.php';
require_once __DIR__ . '/upi.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Ensures environment variables from .env are loaded into $_ENV and getenv().
 */
function load_mail_dotenv(): void {
    require_once __DIR__ . '/../config.php';
}

/**
 * Returns active mail configuration loaded directly from the .env file.
 */
function get_mail_config(): array {
    load_mail_dotenv();

    $fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? (getenv('MAIL_FROM_ADDRESS') ?: '');
    $fromName = trim($_ENV['MAIL_FROM_NAME'] ?? (getenv('MAIL_FROM_NAME') ?: 'MSS Chitty'), '"\'');
    $smtpHost = $_ENV['MAIL_HOST'] ?? (getenv('MAIL_HOST') ?: 'smtp.gmail.com');
    $smtpPort = (int) ($_ENV['MAIL_PORT'] ?? (getenv('MAIL_PORT') ?: 587));
    $smtpEnc = strtolower($_ENV['MAIL_ENCRYPTION'] ?? (getenv('MAIL_ENCRYPTION') ?: 'tls'));
    $smtpUser = $_ENV['MAIL_USERNAME'] ?? (getenv('MAIL_USERNAME') ?: '');
    $smtpPass = $_ENV['MAIL_PASSWORD'] ?? (getenv('MAIL_PASSWORD') ?: '');
    $toEmail = $_ENV['MAIL_TO_ADDRESS'] ?? (getenv('MAIL_TO_ADDRESS') ?: '');
    $appUrl = $_ENV['APP_URL'] ?? (getenv('APP_URL') ?: get_default_app_url());

    return [
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'smtp_host' => $smtpHost,
        'smtp_port' => $smtpPort,
        'smtp_encryption' => $smtpEnc,
        'smtp_username' => $smtpUser,
        'smtp_password' => $smtpPass,
        'to_email' => $toEmail,
        'app_url' => $appUrl,
        'secret_key' => 'mss_chitty_secure_token_secret_2026',
    ];
}

/**
 * Returns application base URL (e.g. http://localhost:8000).
 */
function get_default_app_url(): string {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    return 'http://localhost:8000';
}

/**
 * Generates a secure HMAC signature for the payment verification email link.
 */
function generate_verify_mail_token(int $notification_id, string $txn_id, float $amount): string {
    $config = get_mail_config();
    $data = "verify_payment:{$notification_id}:{$txn_id}:" . number_format($amount, 2, '.', '');
    return hash_hmac('sha256', $data, $config['secret_key']);
}

/**
 * Validates the HMAC signature from the verification email link.
 */
function validate_verify_mail_token(int $notification_id, string $txn_id, float $amount, ?string $token): bool {
    if (empty($token) || !is_string($token)) {
        return false;
    }
    $expected = generate_verify_mail_token($notification_id, $txn_id, $amount);
    return hash_equals($expected, $token);
}

/**
 * Logs mail delivery results to the database.
 */
function log_mail_dispatch(?int $notification_id, string $recipient, string $subject, string $status, ?string $errorMessage = null): void {
    try {
        $stmt = db()->prepare('
            INSERT INTO mail_logs (notification_id, recipient, subject, status, error_message)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $notification_id,
            $recipient,
            $subject,
            $status,
            $errorMessage,
        ]);
    } catch (\Throwable $e) {
        error_log('Failed to log mail dispatch: ' . $e->getMessage());
    }
}

/**
 * Sends a payment submission notification email to the administrator with a one-click verification link.
 */
function send_upi_payment_admin_email(int $notification_id): array {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT n.*, u.name AS member_name, u.phone AS member_phone, u.email AS member_email, u.address AS member_address,
               cs.name AS scheme_name, cs.monthly_subscription, s.ticket_number, i.month_number, i.amount_due
        FROM notifications n
        JOIN users u ON u.id = n.user_id
        JOIN chit_schemes cs ON cs.id = n.scheme_id
        JOIN installments i ON i.id = n.installment_id
        JOIN subscriptions s ON s.id = i.subscription_id
        WHERE n.id = ?
    ');
    $stmt->execute([$notification_id]);
    $notif = $stmt->fetch();
    if (!$notif) {
        throw new RuntimeException('Payment notification not found.');
    }

    $config = get_mail_config();
    $appUrl = rtrim($config['app_url'], '/');

    $txnId = $notif['transaction_id'];
    $amount = (float) $notif['amount'];
    $verifyToken = generate_verify_mail_token($notification_id, $txnId, $amount);

    $rawVerifyPath = '/admin/verify-mail.php?id=' . $notification_id . '&token=' . urlencode($verifyToken);
    $verifyUrl = $appUrl . (function_exists('mask_url') ? mask_url($rawVerifyPath) : $rawVerifyPath);
    $dashboardUrl = $appUrl . (function_exists('mask_url') ? mask_url('/admin/notifications.php') : '/admin/notifications.php');
    $badgeUrl = $appUrl . '/mail-badge.php?id=' . $notification_id . '&token=' . urlencode($verifyToken);

    $subject = "[MSS Chitty] New Payment Submission: ₹" . money($amount) . " from " . $notif['member_name'] . " (Txn: " . $txnId . ")";

    // HTML Email Template
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title>
    </head>
    <body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #F8FAFC; margin: 0; padding: 24px; color: #1E293B;">
      <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #E2E8F0; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
        
        <!-- Header -->
        <div style="background: #0F172A; padding: 24px 30px; text-align: left;">
          <div style="color: #10B981; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 4px;">MSS Chitty</div>
          <h1 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 700;">New UPI Payment Submitted</h1>
          <div style="color: #94A3B8; font-size: 13px; margin-top: 4px;">A member has submitted an installment payment awaiting administrator verification.</div>
        </div>

        <div style="padding: 30px;">
          <!-- Amount Highlight Card -->
          <div style="background: #F1F5F9; border-left: 4px solid #10B981; padding: 16px 20px; border-radius: 6px; margin-bottom: 24px;">
            <div style="font-size: 13px; color: #64748B; font-weight: 600; text-transform: uppercase;">Payment Amount</div>
            <div style="font-size: 28px; font-weight: 800; color: #0F172A; margin: 4px 0;">₹' . money($amount) . '</div>
            <div style="font-size: 13px; color: #475569;">
              UPI Transaction ID (UTR): <strong style="font-family: monospace; font-size: 15px; color: #0F172A;">' . htmlspecialchars($txnId, ENT_QUOTES, 'UTF-8') . '</strong>
            </div>
          </div>

          <!-- Member Details Table -->
          <h3 style="font-size: 15px; color: #0F172A; margin: 0 0 12px 0; border-bottom: 1px solid #E2E8F0; padding-bottom: 8px;">Transaction & Member Details</h3>
          <table style="width: 100%; font-size: 14px; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
              <td style="padding: 8px 0; color: #64748B; width: 140px;">Member Name:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">' . htmlspecialchars($notif['member_name'], ENT_QUOTES, 'UTF-8') . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Phone Number:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">' . htmlspecialchars($notif['member_phone'], ENT_QUOTES, 'UTF-8') . '</td>
            </tr>
            ' . (!empty($notif['member_email']) ? '
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Email:</td>
              <td style="padding: 8px 0; color: #0F172A;">' . htmlspecialchars($notif['member_email'], ENT_QUOTES, 'UTF-8') . '</td>
            </tr>' : '') . '
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Chit Scheme:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">' . htmlspecialchars($notif['scheme_name'], ENT_QUOTES, 'UTF-8') . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Chittal No.:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">#' . (int)$notif['ticket_number'] . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Installment Month:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">Month #' . (int)$notif['month_number'] . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Submission Date:</td>
              <td style="padding: 8px 0; color: #475569;">' . date('d M Y, h:i A', strtotime($notif['created_at'])) . '</td>
            </tr>
          </table>

          <!-- Verify Action Box -->
          <div style="background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 12px; padding: 22px 20px; text-align: center; margin-bottom: 24px;">
            <div style="font-size: 14px; color: #065F46; font-weight: 600; margin-bottom: 16px;">
              Verify this payment directly from this email to update the member\'s ledger:
            </div>
            
            <!-- Styled Action Button -->
            <table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
              <tr>
                <td align="center" style="border-radius: 8px; background-color: #10B981; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35);">
                  <a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="display: inline-block; padding: 14px 34px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 700; color: #ffffff; text-decoration: none; border-radius: 8px; letter-spacing: 0.02em;">
                    ✓ Verify &amp; Approve Payment
                  </a>
                </td>
              </tr>
            </table>

            <div style="margin-top: 14px; font-size: 12px; color: #047857;">
              Clicking directly approves the transaction and updates the installment ledger to <strong>Paid</strong> in the website (no login required).
            </div>
          </div>

          <div style="text-align: center; border-top: 1px solid #E2E8F0; padding-top: 16px;">
            <a href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '" style="color: #64748B; font-size: 13px; text-decoration: underline;">
              Go to Admin Notifications Dashboard &rarr;
            </a>
          </div>
        </div>

        <!-- Footer -->
        <div style="background: #F8FAFC; border-top: 1px solid #E2E8F0; padding: 16px 30px; font-size: 12px; color: #94A3B8; text-align: center;">
          Sent by MSS Chitty Automated Payment System · ' . htmlspecialchars($config['from_email'], ENT_QUOTES, 'UTF-8') . '
        </div>
      </div>
    </body>
    </html>
    ';

    $text = "MSS Chitty - New UPI Payment Submission\n\n"
          . "Amount: ₹" . money($amount) . "\n"
          . "Transaction ID: " . $txnId . "\n"
          . "Member: " . $notif['member_name'] . " (" . $notif['member_phone'] . ")\n"
          . "Scheme: " . $notif['scheme_name'] . " - Month #" . $notif['month_number'] . " (Chittal #" . $notif['ticket_number'] . ")\n"
          . "Submitted Date: " . date('d M Y, h:i A', strtotime($notif['created_at'])) . "\n\n"
          . "Verify Payment Link:\n" . $verifyUrl . "\n\n"
          . "Notifications Dashboard:\n" . $dashboardUrl . "\n";

    $headers = [
        'Message-ID' => "<notif-{$notification_id}-pending@mss-chitty>",
    ];

    return send_email_via_smtp($config['to_email'], $subject, $html, $text, $notification_id, $headers);
}

/**
 * Sends a test email to verify SMTP connectivity and credentials.
 */
function send_test_email(?string $recipient = null): array {
    $config = get_mail_config();
    $to = !empty($recipient) ? trim($recipient) : $config['to_email'];

    $subject = "[MSS Chitty] SMTP Configuration Test Mail";
    $html = '
    <div style="font-family: sans-serif; padding: 20px; background: #f8fafc; color: #1e293b;">
      <div style="max-width: 500px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px;">
        <h2 style="color: #10b981; margin-top: 0;">✓ SMTP Configuration Successful</h2>
        <p>This is a test email sent from <strong>MSS Chitty</strong> platform.</p>
        <p>Your SMTP mail configuration in <code>.env</code> is active and working correctly.</p>
        <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0;">
        <div style="font-size: 13px; color: #64748b;">
          <strong>Sender:</strong> ' . htmlspecialchars($config['from_name'] . ' <' . $config['from_email'] . '>') . '<br>
          <strong>Recipient:</strong> ' . htmlspecialchars($to) . '<br>
          <strong>SMTP Host:</strong> ' . htmlspecialchars($config['smtp_host'] . ':' . $config['smtp_port']) . '<br>
          <strong>Timestamp:</strong> ' . date('d M Y, h:i:s A') . '
        </div>
      </div>
    </div>';

    $text = "MSS Chitty - SMTP Configuration Test\n\nYour SMTP configuration is working successfully.\nSender: " . $config['from_email'] . "\nTimestamp: " . date('Y-m-d H:i:s');

    return send_email_via_smtp($to, $subject, $html, $text, null);
}

/**
 * Sends a confirmation email to the administrator when a payment has been verified.
 * Threads into the original payment submission email conversation.
 */
function send_upi_payment_verified_email(int $notification_id, int $admin_id = 0): array {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT n.*, u.name AS member_name, u.phone AS member_phone, u.email AS member_email,
               cs.name AS scheme_name, cs.monthly_subscription, s.ticket_number,
               i.month_number, i.amount_due, i.amount_paid, i.excess_amount,
               adm.name AS admin_name
        FROM notifications n
        JOIN users u ON u.id = n.user_id
        JOIN chit_schemes cs ON cs.id = n.scheme_id
        JOIN installments i ON i.id = n.installment_id
        JOIN subscriptions s ON s.id = i.subscription_id
        LEFT JOIN users adm ON adm.id = ?
        WHERE n.id = ?
    ');
    $stmt->execute([$admin_id, $notification_id]);
    $notif = $stmt->fetch();
    if (!$notif) {
        return ['success' => false, 'error' => 'Notification not found.'];
    }

    $config = get_mail_config();
    $appUrl = rtrim($config['app_url'], '/');
    $amount = (float) $notif['amount'];
    $txnId = $notif['transaction_id'];
    $adminName = !empty($notif['admin_name']) ? $notif['admin_name'] : 'Administrator';
    $verifiedTime = !empty($notif['verified_at']) ? date('d M Y, h:i A', strtotime($notif['verified_at'])) : date('d M Y, h:i A');

    $verifyToken = generate_verify_mail_token($notification_id, $txnId, $amount);
    $badgeUrl = $appUrl . '/mail-badge.php?id=' . $notification_id . '&token=' . urlencode($verifyToken);
    $dashboardUrl = $appUrl . (function_exists('mask_url') ? mask_url('/admin/notifications.php?tab=verified') : '/admin/notifications.php?tab=verified');

    $subject = "Re: [MSS Chitty] Verified: ₹" . money($amount) . " from " . $notif['member_name'] . " (Txn: " . $txnId . ")";

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title>
    </head>
    <body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #F8FAFC; margin: 0; padding: 24px; color: #1E293B;">
      <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #E2E8F0; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
        
        <!-- Header -->
        <div style="background: #0F172A; padding: 24px 30px; text-align: left;">
          <div style="color: #10B981; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 4px;">MSS Chitty</div>
          <h1 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 700;">Payment Verified &amp; Approved</h1>
          <div style="color: #94A3B8; font-size: 13px; margin-top: 4px;">The installment payment has been confirmed and credited to the member ledger.</div>
        </div>

        <div style="padding: 30px;">
          <!-- Verified Status Badge -->
          <div style="background: #ECFDF5; border-left: 4px solid #10B981; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="color: #065F46; font-size: 15px; font-weight: 700;">✓ Payment Confirmed &amp; Credited</div>
              <div style="color: #047857; font-size: 13px; margin-top: 2px;">
                Verified by <strong>' . htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') . '</strong> on ' . $verifiedTime . '
              </div>
            </div>
            <div>
              <span style="display: inline-block; background: #10B981; color: #ffffff; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 12px; text-transform: uppercase;">
                Status: Paid
              </span>
            </div>
          </div>

          <!-- Amount Highlight Card -->
          <div style="background: #F1F5F9; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px;">
            <div style="font-size: 12px; color: #64748B; font-weight: 600; text-transform: uppercase;">Amount Credited</div>
            <div style="font-size: 28px; font-weight: 800; color: #0F172A; margin: 4px 0;">₹' . money($amount) . '</div>
            <div style="font-size: 13px; color: #475569;">
              UPI Transaction ID (UTR): <strong style="font-family: monospace; font-size: 14px; color: #0F172A;">' . htmlspecialchars($txnId, ENT_QUOTES, 'UTF-8') . '</strong>
            </div>
          </div>

          <!-- Transaction & Member Details -->
          <h3 style="font-size: 15px; color: #0F172A; margin: 0 0 12px 0; border-bottom: 1px solid #E2E8F0; padding-bottom: 8px;">Transaction Summary</h3>
          <table style="width: 100%; font-size: 14px; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
              <td style="padding: 8px 0; color: #64748B; width: 140px;">Member Name:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">' . htmlspecialchars($notif['member_name'], ENT_QUOTES, 'UTF-8') . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Phone Number:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">' . htmlspecialchars($notif['member_phone'], ENT_QUOTES, 'UTF-8') . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Chit Scheme:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">' . htmlspecialchars($notif['scheme_name'], ENT_QUOTES, 'UTF-8') . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Chittal No.:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">#' . (int)$notif['ticket_number'] . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Installment Month:</td>
              <td style="padding: 8px 0; font-weight: 600; color: #0F172A;">Month #' . (int)$notif['month_number'] . '</td>
            </tr>
            <tr>
              <td style="padding: 8px 0; color: #64748B;">Ledger Status:</td>
              <td style="padding: 8px 0; font-weight: 700; color: #10B981;">PAID</td>
            </tr>
          </table>

          <!-- Live Status Badge Preview (Displays Verified State) -->
          <div style="margin-bottom: 24px; text-align: center;">
            <img src="' . htmlspecialchars($badgeUrl, ENT_QUOTES, 'UTF-8') . '" alt="Payment Verified &amp; Approved" width="520" height="96" style="display: block; border: 0; outline: none; width: 100%; max-width: 520px; height: auto; border-radius: 10px; margin: 0 auto;" />
          </div>

          <div style="text-align: center; border-top: 1px solid #E2E8F0; padding-top: 16px;">
            <a href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '" style="color: #64748B; font-size: 13px; text-decoration: underline;">
              View in Admin Notifications Dashboard &rarr;
            </a>
          </div>
        </div>

        <!-- Footer -->
        <div style="background: #F8FAFC; border-top: 1px solid #E2E8F0; padding: 16px 30px; font-size: 12px; color: #94A3B8; text-align: center;">
          Sent by MSS Chitty Automated Payment System · ' . htmlspecialchars($config['from_email'], ENT_QUOTES, 'UTF-8') . '
        </div>
      </div>
    </body>
    </html>
    ';

    $text = "MSS Chitty - Payment Verified & Approved\n\n"
          . "Amount: ₹" . money($amount) . "\n"
          . "Transaction ID: " . $txnId . "\n"
          . "Member: " . $notif['member_name'] . "\n"
          . "Status: PAID\n"
          . "Verified By: " . $adminName . " on " . $verifiedTime . "\n\n"
          . "Dashboard: " . $dashboardUrl . "\n";

    $headers = [
        'Message-ID' => "<notif-{$notification_id}-verified@mss-chitty>",
        'In-Reply-To' => "<notif-{$notification_id}-pending@mss-chitty>",
        'References' => "<notif-{$notification_id}-pending@mss-chitty>",
    ];

    return send_email_via_smtp($config['to_email'], $subject, $html, $text, $notification_id, $headers);
}

/**
 * Underlying helper that configures PHPMailer and dispatches the email.
 */
function send_email_via_smtp(string $recipient, string $subject, string $htmlBody, string $textBody, ?int $notification_id = null, array $customHeaders = []): array {
    $config = get_mail_config();

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_username'];
        $mail->Password = $config['smtp_password'];

        $enc = strtolower($config['smtp_encryption'] ?? 'tls');
        if ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port = (int) ($config['smtp_port'] ?: 587);
        $mail->Timeout = 12;
        $mail->CharSet = 'UTF-8';

        // SSL options for local dev environments
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($recipient, 'Administrator');
        $mail->addReplyTo($config['from_email'], $config['from_name']);

        if (!empty($customHeaders['Message-ID'])) {
            $mail->MessageID = $customHeaders['Message-ID'];
        }
        if (!empty($customHeaders['In-Reply-To'])) {
            $mail->addCustomHeader('In-Reply-To', $customHeaders['In-Reply-To']);
        }
        if (!empty($customHeaders['References'])) {
            $mail->addCustomHeader('References', $customHeaders['References']);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();

        log_mail_dispatch($notification_id, $recipient, $subject, 'sent', null);
        return [
            'success' => true,
            'message' => 'Email sent successfully to ' . $recipient,
        ];
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
        log_mail_dispatch($notification_id, $recipient, $subject, 'failed', $errorMsg);
        return [
            'success' => false,
            'error' => $errorMsg,
        ];
    }
}
