<?php
/**
 * Shared logging for the public site search.
 *
 *   logSearch($pdo, 'books'|'transcripts', $q, $mode, $resultCount, $filters?, $responseMs?)
 *
 * Captures every dimension we want to analyse after the fact: query + mode +
 * scope + result count + filters + response time + session/user/IP/UA/referrer.
 * Used by /api/search.php (books) and /api/search-transcripts.php (video).
 * Failures are swallowed silently — logging must never break a search response.
 */

/**
 * Synthetic anonymous-session key. Keeps the existing algorithm from search.php
 * so a session that's mid-investigation continues to match its earlier rows
 * (alias auto-detection in search.php depends on per-session lookback).
 */
function searchLogSessionKey(): string {
    $ipUaSeed = ($_SERVER['REMOTE_ADDR'] ?? '?') . '|' . substr($_SERVER['HTTP_USER_AGENT'] ?? '?', 0, 80);
    return substr(hash('sha256', $ipUaSeed), 0, 32);
}

/**
 * Visitor IP, honoring the reverse-proxy chain (Cloudflare → Caddy → Apache).
 * Mirrors the same precedence config.php uses for auth logging.
 */
function searchLogClientIp(): ?string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? null;
    if (!$ip) return null;
    // X-Forwarded-For can be a comma-separated chain ("client, proxy1, proxy2")
    // — the leftmost entry is the originating client.
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return substr($ip, 0, 64);
}

/**
 * Insert one row into yy_search_log. Skips logging when $q is empty (no value
 * for analysis) but otherwise always writes — including zero-result queries
 * (the most analytically interesting cohort: gaps in our content).
 */
function logSearch(PDO $pdo, string $scope, string $q, string $mode, int $resultCount, array $filters = [], int $responseMs = 0): void {
    if ($q === '') return;
    // Record the mode the caller actually used (don't rewrite unknown values
    // to 'all' — that would hide UI/back-end mismatches from the analysis).
    $mode = substr(trim((string)$mode), 0, 20);
    if ($mode === '') $mode = 'all';
    $userKey = !empty($_SESSION['user_key']) ? (int)$_SESSION['user_key'] : null;
    $referrer = $_SERVER['HTTP_REFERER'] ?? null;
    if ($referrer !== null) $referrer = substr($referrer, 0, 2000);
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if ($userAgent !== null) $userAgent = substr($userAgent, 0, 500);
    $filtersJson = !empty($filters) ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null;

    try {
        $pdo->prepare("
            INSERT INTO yy_search_log
                (search_log_session, search_log_query, search_log_mode, search_log_scope,
                 search_log_result_count, search_log_user_key, search_log_ip,
                 search_log_user_agent, search_log_referrer, search_log_filters,
                 search_log_response_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)
        ")->execute([
            searchLogSessionKey(),
            $q,
            $mode,
            $scope,
            $resultCount,
            $userKey,
            searchLogClientIp(),
            $userAgent,
            $referrer,
            $filtersJson,
            $responseMs > 0 ? $responseMs : null,
        ]);
    } catch (Throwable $e) {
        // Log to PHP error log but never bubble — a logging failure must not
        // produce a failed search response to the user.
        error_log('logSearch failed: ' . $e->getMessage());
    }
}
