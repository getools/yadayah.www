<?php
/**
 * FlipHTML5 session admin endpoint.
 *
 * GET  → { state: 'active'|'expired'|'unknown', message, updated_at,
 *          cookies_present: bool, cookies_count: int, cookies_saved_at }
 * POST → save a fresh cookie jar so the upload script can skip form login.
 *
 * Storage lives in /var/www/html/jobs/fliphtml5/ (host: /opt/yada-www/public/
 * jobs/fliphtml5/, also bind-mounted into the rsshub container at
 * /host_jobs/fliphtml5). Three players read/write here:
 *   - This PHP endpoint (web container)
 *   - The Node upload script (rsshub container)
 *   - The host worker shell (book-pipeline-worker.sh)
 *
 * The cookie input from the admin can be one of:
 *   1. JSON array exported by EditThisCookie / Cookie-Editor
 *   2. JSON object with a `cookies: [...]` field
 *   3. Raw "Cookie:" header value: `key=val; key2=val2; …`
 *   4. A cURL command line containing -H 'Cookie: …'
 * We normalize to puppeteer's setCookie() shape.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();

$jobsDir = '/var/www/html/jobs/fliphtml5';
@mkdir($jobsDir, 0775, true);
$cookiesFile = $jobsDir . '/cookies.json';
$statusFile  = $jobsDir . '/session-status.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = ['state' => 'unknown', 'message' => '', 'updated_at' => null];
    if (file_exists($statusFile)) {
        $raw = @file_get_contents($statusFile);
        $parsed = $raw ? json_decode($raw, true) : null;
        if (is_array($parsed)) $status = array_merge($status, $parsed);
    }
    $cookiesPresent = false;
    $cookiesCount = 0;
    $cookiesSavedAt = null;
    if (file_exists($cookiesFile)) {
        $raw = @file_get_contents($cookiesFile);
        $parsed = $raw ? json_decode($raw, true) : null;
        $list = is_array($parsed) && isset($parsed['cookies']) && is_array($parsed['cookies'])
            ? $parsed['cookies']
            : (is_array($parsed) ? $parsed : []);
        if ($list) {
            $cookiesPresent = true;
            $cookiesCount = count($list);
            $cookiesSavedAt = $parsed['saved_at'] ?? null;
        }
    }
    jsonResponse([
        'state'            => $status['state'],
        'message'          => $status['message'],
        'updated_at'       => $status['updated_at'],
        'cookies_present'  => $cookiesPresent,
        'cookies_count'    => $cookiesCount,
        'cookies_saved_at' => $cookiesSavedAt,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? 'save_cookies';

    if ($action === 'clear_cookies') {
        @unlink($cookiesFile);
        @file_put_contents($statusFile, json_encode([
            'state' => 'unknown', 'message' => 'Cookies cleared by admin',
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT));
        jsonResponse(['cleared' => true]);
    }

    if ($action === 'save_cookies') {
        $input = trim((string)($body['cookies'] ?? ''));
        if ($input === '') errorResponse('cookies field required');

        $list = parseCookiesInput($input);
        if (empty($list)) errorResponse('Could not parse any cookies from input');

        // Filter to FlipHTML5 cookies only — pasting cURL from a different
        // tab would otherwise leak unrelated session cookies.
        $list = array_values(array_filter($list, function($c) {
            $domain = strtolower($c['domain'] ?? '');
            return $domain === '' || strpos($domain, 'fliphtml5.com') !== false;
        }));
        if (empty($list)) errorResponse('No fliphtml5.com cookies found in input');

        $payload = ['cookies' => $list, 'saved_at' => gmdate('c'), 'saved_by' => $user['user_name'] ?? null];
        if (file_put_contents($cookiesFile, json_encode($payload, JSON_PRETTY_PRINT)) === false) {
            errorResponse('Failed to write cookies file');
        }
        @chmod($cookiesFile, 0660);
        // Mark status pending so the UI shows "saved — will be tested on next upload".
        @file_put_contents($statusFile, json_encode([
            'state' => 'pending',
            'message' => 'Cookies saved (' . count($list) . ') — will be exercised on next FlipHTML5 upload',
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT));
        jsonResponse(['saved' => true, 'count' => count($list)]);
    }

    errorResponse('Unknown action: ' . $action);
}

errorResponse('Method not allowed', 405);

// ── Cookie parsing helpers ──────────────────────────────────────────

function parseCookiesInput(string $input): array {
    $input = trim($input);

    // Try JSON first — covers the EditThisCookie / Cookie-Editor exports.
    if ($input !== '' && ($input[0] === '[' || $input[0] === '{')) {
        $parsed = json_decode($input, true);
        if (is_array($parsed)) {
            $list = isset($parsed['cookies']) && is_array($parsed['cookies'])
                ? $parsed['cookies']
                : (array_is_list($parsed) ? $parsed : []);
            if ($list) return normalizeCookieList($list);
        }
    }

    // cURL command? Extract the Cookie header.
    if (preg_match("/-H\\s+['\"]?Cookie:\\s*([^'\"]+)['\"]?/i", $input, $m)) {
        return parseCookieHeader($m[1]);
    }
    if (preg_match("/--cookie\\s+['\"]?([^'\"]+)['\"]?/i", $input, $m)) {
        return parseCookieHeader($m[1]);
    }

    // Otherwise treat the whole thing as a Cookie header.
    return parseCookieHeader($input);
}

function parseCookieHeader(string $header): array {
    $list = [];
    foreach (explode(';', $header) as $pair) {
        $pair = trim($pair);
        if ($pair === '') continue;
        $eq = strpos($pair, '=');
        if ($eq === false) continue;
        $name = trim(substr($pair, 0, $eq));
        $value = trim(substr($pair, $eq + 1));
        if ($name === '') continue;
        $list[] = [
            'name' => $name,
            'value' => $value,
            'domain' => '.fliphtml5.com',
            'path' => '/',
            'secure' => true,
        ];
    }
    return $list;
}

// Normalize EditThisCookie / Puppeteer cookie shapes to puppeteer.setCookie.
function normalizeCookieList(array $raw): array {
    $out = [];
    foreach ($raw as $c) {
        if (!is_array($c) || empty($c['name'])) continue;
        $cookie = [
            'name'   => (string)$c['name'],
            'value'  => (string)($c['value'] ?? ''),
            'domain' => $c['domain'] ?? '.fliphtml5.com',
            'path'   => $c['path'] ?? '/',
            'secure' => $c['secure'] ?? true,
        ];
        if (isset($c['httpOnly'])) $cookie['httpOnly'] = (bool)$c['httpOnly'];
        if (isset($c['sameSite']) && $c['sameSite']) $cookie['sameSite'] = ucfirst(strtolower((string)$c['sameSite']));
        // EditThisCookie uses expirationDate (seconds since epoch); puppeteer
        // wants `expires` in the same units.
        if (isset($c['expirationDate'])) $cookie['expires'] = (float)$c['expirationDate'];
        elseif (isset($c['expires']) && is_numeric($c['expires'])) $cookie['expires'] = (float)$c['expires'];
        $out[] = $cookie;
    }
    return $out;
}
