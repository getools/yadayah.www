<?php
/**
 * Admin endpoint for managing the YouTube cookies file used by transcript-worker.
 *
 * GET                          — return status: file exists, size, mtime, line count
 * POST (multipart 'file')      — upload a new Netscape-format cookies.txt; replaces the existing one
 * DELETE                       — wipe the cookies file (transcripts will fail with bot-detection again)
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

// Cookies live in /tmp inside the container — writable by www-data and NOT
// reachable via any web URL. Persists across `docker restart`; lost only on
// full container rebuild. transcript-worker.php reads the same path.
$cookiesPath = '/tmp/youtube-cookies.txt';

function cookiesStatus(string $path): array {
    if (!file_exists($path)) return ['present' => false];
    $contents = @file_get_contents($path) ?: '';
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    $cookieRows = 0;
    $earliestExpiry = null;
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;
        $parts = preg_split('/\s+/', $ln);
        if (count($parts) >= 7) {
            $cookieRows++;
            $exp = (int)$parts[4];
            // 0 == session cookie (no expiry); ignore those
            if ($exp > 0 && ($earliestExpiry === null || $exp < $earliestExpiry)) {
                $earliestExpiry = $exp;
            }
        }
    }
    return [
        'present' => true,
        'size' => filesize($path),
        'modified_dtime' => date('c', filemtime($path)),
        'cookie_count' => $cookieRows,
        'earliest_expiry_dtime' => $earliestExpiry ? date('c', $earliestExpiry) : null,
        'days_until_earliest_expiry' => $earliestExpiry ? max(0, (int)(($earliestExpiry - time()) / 86400)) : null,
    ];
}

if ($method === 'GET') {
    jsonResponse(cookiesStatus($cookiesPath));
}

if ($method === 'POST') {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        errorResponse('No file uploaded (or upload error code ' . ($_FILES['file']['error'] ?? '?') . ')');
    }
    $tmpName = $_FILES['file']['tmp_name'];
    $size = filesize($tmpName);
    if ($size > 1_000_000) errorResponse('File too large (>1MB) — not a cookies.txt');

    $contents = file_get_contents($tmpName);
    // Sanity check: must look like Netscape cookies format. Header is optional;
    // we just need to see at least one line that looks like a tab-separated cookie record.
    $looksLikeCookies = false;
    foreach (preg_split('/\r\n|\r|\n/', $contents) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;
        $parts = preg_split('/\s+/', $ln);
        // Netscape format: domain, flag, path, secure, expiry, name, value
        if (count($parts) >= 7 && (stripos($parts[0], 'youtube') !== false || stripos($parts[0], 'google') !== false)) {
            $looksLikeCookies = true;
            break;
        }
    }
    if (!$looksLikeCookies) {
        errorResponse('File does not look like a YouTube/Google cookies.txt — first non-comment line must be a tab-separated Netscape cookie record');
    }

    if (!@file_put_contents($cookiesPath, $contents)) {
        errorResponse('Failed to write ' . $cookiesPath . ' — check permissions');
    }
    @chmod($cookiesPath, 0660); // www-data can read/write (yt-dlp may need to update cookies)
    logMonitorEvent('youtube_cookies', 'info', 'YouTube cookies refreshed by ' . ($user['user_code'] ?? 'admin'),
        'size=' . $size . ' bytes, written to ' . $cookiesPath, true);
    jsonResponse(['ok' => true] + cookiesStatus($cookiesPath));
}

if ($method === 'DELETE') {
    if (file_exists($cookiesPath)) @unlink($cookiesPath);
    jsonResponse(['ok' => true, 'deleted' => true]);
}

errorResponse('Method not allowed', 405);
