<?php
// Live Dynamic Email Status Badge Endpoint
// Generates a dynamic real-time PNG image badge reflecting current payment verification state in emails.
// When pending: displays active "Verify & Approve Payment" button graphic.
// When verified: displays "Payment Verified & Approved" confirmation badge (button disappears).
// When rejected: displays "Payment Rejected" status badge.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mail.php';

// Disable error display so PNG binary stream is not corrupted
ini_set('display_errors', '0');
error_reporting(0);

$notificationId = (int) ($_GET['id'] ?? 0);
$token = trim((string) ($_GET['token'] ?? ''));

$status = 'pending';
$verifiedDate = null;

if ($notificationId > 0 && !empty($token)) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, status, transaction_id, amount, verified_at FROM notifications WHERE id = ?');
        $stmt->execute([$notificationId]);
        $row = $stmt->fetch();

        if ($row && validate_verify_mail_token((int)$row['id'], $row['transaction_id'], (float)$row['amount'], $token)) {
            $status = (string) $row['status'];
            if (!empty($row['verified_at'])) {
                $verifiedDate = date('d M Y, h:i A', strtotime($row['verified_at']));
            }
        }
    } catch (\Throwable $e) {
        // Fallback to pending state if database error occurs
        $status = 'pending';
    }
}

/**
 * Draws anti-aliased rounded rectangle in GD.
 */
function badge_rounded_rect($im, int $x, int $y, int $w, int $h, int $radius, int $color): void {
    imagefilledrectangle($im, $x + $radius, $y, $x + $w - $radius - 1, $y + $h - 1, $color);
    imagefilledrectangle($im, $x, $y + $radius, $x + $radius - 1, $y + $h - $radius - 1, $color);
    imagefilledrectangle($im, $x + $w - $radius, $y + $radius, $x + $w - 1, $y + $h - $radius - 1, $color);
    imagefilledellipse($im, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x + $w - $radius - 1, $y + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x + $radius, $y + $h - $radius - 1, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x + $w - $radius - 1, $y + $h - $radius - 1, $radius * 2, $radius * 2, $color);
}

/**
 * Draws clean vector checkmark.
 */
function badge_checkmark($im, int $cx, int $cy, int $size, int $color, int $thickness = 4): void {
    imagesetthickness($im, $thickness);
    imageline($im, $cx - (int)($size * 0.4), $cy, $cx - (int)($size * 0.1), $cy + (int)($size * 0.35), $color);
    imageline($im, $cx - (int)($size * 0.1), $cy + (int)($size * 0.35), $cx + (int)($size * 0.5), $cy - (int)($size * 0.4), $color);
}

/**
 * Draws clean vector X cross.
 */
function badge_cross($im, int $cx, int $cy, int $size, int $color, int $thickness = 4): void {
    imagesetthickness($im, $thickness);
    $half = (int)($size * 0.4);
    imageline($im, $cx - $half, $cy - $half, $cx + $half, $cy + $half, $color);
    imageline($im, $cx - $half, $cy + $half, $cx + $half, $cy - $half, $color);
}

$scale = 2; // High-DPI 2x scale for crystal crisp rendering on mobile and Retina screens
$width = 360 * $scale;
$height = 52 * $scale;

$im = imagecreatetruecolor($width, $height);
imagealphablending($im, true);
imagesavealpha($im, true);

$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefill($im, 0, 0, $transparent);

$fontBold = '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf';
if (!file_exists($fontBold)) {
    $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
}

if ($status === 'verified') {
    // Verified State: Light emerald pill with emerald border and dark green text
    $border = imagecolorallocate($im, 16, 185, 129); // #10B981
    $bg = imagecolorallocate($im, 236, 253, 245);     // #ECFDF5
    $r = 8 * $scale;
    $bw = 2 * $scale;

    badge_rounded_rect($im, 0, 0, $width, $height, $r, $border);
    badge_rounded_rect($im, $bw, $bw, $width - ($bw * 2), $height - ($bw * 2), $r - $bw, $bg);

    $vGreen = imagecolorallocate($im, 6, 95, 70); // #065F46
    $text = "Payment Verified & Approved";
    $bbox = imagettfbbox(14.5 * $scale, 0, $fontBold, $text);
    $tw = abs($bbox[4] - $bbox[0]);
    $totalW = $tw + 34 * $scale;
    $startX = ($width - $totalW) / 2;

    badge_checkmark($im, (int)($startX + 12 * $scale), (int)($height / 2), 20 * $scale, $vGreen, 5 * $scale);
    imagettftext($im, 14.5 * $scale, 0, (int)($startX + 34 * $scale), (int)(($height / 2) + 6 * $scale), $vGreen, $fontBold, $text);

} elseif ($status === 'rejected') {
    // Rejected State: Light red pill with red border
    $border = imagecolorallocate($im, 239, 68, 68); // #EF4444
    $bg = imagecolorallocate($im, 254, 242, 242);     // #FEF2F2
    $r = 8 * $scale;
    $bw = 2 * $scale;

    badge_rounded_rect($im, 0, 0, $width, $height, $r, $border);
    badge_rounded_rect($im, $bw, $bw, $width - ($bw * 2), $height - ($bw * 2), $r - $bw, $bg);

    $rRed = imagecolorallocate($im, 153, 27, 27); // #991B1B
    $text = "Payment Rejected";
    $bbox = imagettfbbox(14.5 * $scale, 0, $fontBold, $text);
    $tw = abs($bbox[4] - $bbox[0]);
    $totalW = $tw + 34 * $scale;
    $startX = ($width - $totalW) / 2;

    badge_cross($im, (int)($startX + 12 * $scale), (int)($height / 2), 18 * $scale, $rRed, 5 * $scale);
    imagettftext($im, 14.5 * $scale, 0, (int)($startX + 34 * $scale), (int)(($height / 2) + 6 * $scale), $rRed, $fontBold, $text);

} else {
    // Pending State: Vibrant emerald action button pill
    $btnBg = imagecolorallocate($im, 16, 185, 129); // #10B981
    $r = 8 * $scale;
    badge_rounded_rect($im, 0, 0, $width, $height, $r, $btnBg);

    $white = imagecolorallocate($im, 255, 255, 255);
    $text = "Verify & Approve Payment";
    $bbox = imagettfbbox(15.5 * $scale, 0, $fontBold, $text);
    $tw = abs($bbox[4] - $bbox[0]);
    $totalW = $tw + 34 * $scale;
    $startX = ($width - $totalW) / 2;

    badge_checkmark($im, (int)($startX + 12 * $scale), (int)($height / 2), 20 * $scale, $white, 5 * $scale);
    imagettftext($im, 15.5 * $scale, 0, (int)($startX + 34 * $scale), (int)(($height / 2) + 6 * $scale), $white, $fontBold, $text);
}

// Aggressive cache-busting headers to ensure email clients fetch updated status
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0, s-maxage=0, post-check=0, pre-check=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

imagepng($im);
imagedestroy($im);
exit;
