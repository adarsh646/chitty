<?php
// URL Masking & Base64 Routing Helper for Chitty Platform.
// Masks internal page paths and query parameters on the URL using URL-safe Base64 encoding.

/**
 * Encodes a string into URL-safe Base64 (RFC 4648 §5).
 */
function base64_url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Decodes a URL-safe Base64 encoded string.
 * Supports both URL-safe Base64 and standard Base64.
 */
function base64_url_decode(string $data): ?string {
    $data = strtr($data, '-_', '+/');
    $mod4 = strlen($data) % 4;
    if ($mod4) {
        $data .= str_repeat('=', 4 - $mod4);
    }
    $decoded = base64_decode($data, false);
    return ($decoded !== false && is_string($decoded)) ? $decoded : null;
}

/**
 * Transforms an internal relative page URL into a Base64-masked URL (e.g. /?p=...).
 * Leaves static assets (CSS, JS, images, fonts) and external URLs unchanged.
 */
function mask_url(string $url): string {
    $url = trim($url);
    if ($url === '' || str_starts_with($url, '#')) {
        return $url;
    }

    // Skip external protocols or mailto/tel/javascript
    if (preg_match('/^(?:https?:|\/\/|mailto:|tel:|javascript:)/i', $url)) {
        return $url;
    }

    // Skip if already masked
    if (str_starts_with($url, '/?p=') || str_starts_with($url, '/index.php?p=') || str_starts_with($url, '?p=')) {
        return $url;
    }

    // Skip static assets
    if (preg_match('/\.(?:css|js|svg|png|jpg|jpeg|gif|ico|webp|woff2?|ttf|eot|otf|map|pdf)(?:\?.*)?$/i', $url)) {
        return $url;
    }

    // Skip root homepage path
    if ($url === '/') {
        return '/';
    }

    // Ensure leading slash for consistency
    if (!str_starts_with($url, '/')) {
        $url = '/' . $url;
    }

    return '/?p=' . base64_url_encode($url);
}

/**
 * Decodes a masked URL string back to its original internal path and query string.
 */
function unmask_url(string $maskedUrl): ?string {
    $parsed = parse_url($maskedUrl);
    $query = $parsed['query'] ?? '';
    if (!empty($query)) {
        parse_str($query, $params);
        if (!empty($params['p'])) {
            return base64_url_decode($params['p']);
        }
    }
    return base64_url_decode($maskedUrl);
}

/**
 * Rewrites internal page href and action links in HTML to their Base64-masked counterparts.
 */
function mask_html_urls(string $html): string {
    return preg_replace_callback(
        '/\b(href|action)=(["\'])(.*?)\2/i',
        function ($m) {
            $attr = $m[1];
            $quote = $m[2];
            $url = $m[3];
            $masked = mask_url($url);
            return $attr . '=' . $quote . $masked . $quote;
        },
        $html
    );
}

/**
 * Safely redirects to an internal page with its path masked using Base64.
 */
function redirect_masked(string $url): void {
    header('Location: ' . mask_url($url));
    exit;
}

/**
 * Starts an output buffer that automatically rewrites internal links to masked URLs upon flush.
 */
function start_url_mask_buffer(): void {
    static $bufferStarted = false;
    if (!$bufferStarted && php_sapi_name() !== 'cli') {
        $bufferStarted = true;
        ob_start('mask_html_urls');
    }
}
