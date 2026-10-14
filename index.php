<?php
// Main Entry Point & URL Masking Dispatcher for Chitty Platform.
// Routes requests containing Base64-masked page paths in the 'p' query parameter.

require_once __DIR__ . '/includes/auth.php';

// Check if a masked page path is provided (e.g. /?p=L2FkbWluL2luZGV4LnBocA)
if (isset($_GET['p']) && is_string($_GET['p']) && trim($_GET['p']) !== '') {
    $masked = trim($_GET['p']);
    $decoded = base64_url_decode($masked);

    if ($decoded !== null && $decoded !== '') {
        $parsed = parse_url($decoded);
        $rawPath = $parsed['path'] ?? '';
        $rawQuery = $parsed['query'] ?? '';

        // Merge query parameters from the decoded URL into $_GET and $_REQUEST
        if (!empty($rawQuery)) {
            parse_str($rawQuery, $decodedGet);
            foreach ($decodedGet as $k => $v) {
                $_GET[$k] = $v;
                $_REQUEST[$k] = $v;
            }
        }

        // Clean path and prevent directory traversal
        $relPath = ltrim($rawPath, '/');
        $relPath = str_replace(['../', '..\\'], '', $relPath);

        // Security check: must resolve to a valid .php file inside application root
        $rootDir = realpath(__DIR__);
        $targetFile = realpath(__DIR__ . '/' . $relPath);

        if (
            $targetFile !== false
            && str_starts_with($targetFile, $rootDir)
            && is_file($targetFile)
            && pathinfo($targetFile, PATHINFO_EXTENSION) === 'php'
            && $targetFile !== realpath(__FILE__) // prevent infinite recursion to root index.php router
        ) {
            // Set virtual environment variables for the dispatched script
            $_SERVER['PAGE_URI'] = $decoded;
            $_SERVER['SCRIPT_NAME'] = '/' . $relPath;
            $_SERVER['PHP_SELF'] = '/' . $relPath;

            // Start output buffering to automatically rewrite internal links to masked URLs
            start_url_mask_buffer();

            // Execute the routed target script
            require $targetFile;
            exit;
        }
    }

    // Invalid or non-existent route: render 404
    http_response_code(404);
    $message = 'The requested masked page was not found.';
    require_once __DIR__ . '/error.php';
    exit;
}

// Direct root access without 'p' parameter: redirect based on current session
$user = current_user();
if (!$user) {
    redirect_masked('/login.php');
} elseif ($user['role'] === 'admin') {
    redirect_masked('/admin/index.php');
} else {
    redirect_masked('/member/index.php');
}
exit;
