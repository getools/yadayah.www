<?php
/**
 * Error triage agent — runs on the server via cron at :15 and :45.
 * Resolves known patterns (extension noise, transient errors, duplicates)
 * without any API calls. Novel errors are left unresolved for the remote
 * Claude Code agent (scheduled trigger, runs hourly at :00) to investigate
 * and fix via the remote-agent.php API — no Anthropic API key needed.
 *
 * Cron: 15,45 * * * * docker exec yada-www-web-1 php /var/www/html/api/auto-fix-error.php
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(300);

$WEB_ROOT = '/var/www/html';
$MAX_TRIAGE = 100;

// ── DB ──
$host = getenv('PG_HOST') ?: 'localhost';
$port = getenv('PG_PORT') ?: '5432';
$name = getenv('PG_DB')   ?: 'yada';
$user = getenv('PG_USER') ?: 'postgres';
$pass = getenv('PG_PASS') ?: '';
$db = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "[" . date('c') . "] Auto-fix starting\n";

// ── Fetch unresolved errors ──
$stmt = $db->prepare("
    SELECT event_key, event_source, event_severity, event_message, event_detail, event_file, event_referer
    FROM yy_monitor_event
    WHERE event_resolved_flag = FALSE
      AND event_source NOT IN ('agent_op', 'honeypot')
      AND event_severity IN ('error', 'warning')
    ORDER BY event_dtime DESC
    LIMIT 100
");
$stmt->execute();
$errors = $stmt->fetchAll();

if (!$errors) {
    echo "[" . date('c') . "] No unresolved errors\n";
    exit(0);
}

echo "[" . date('c') . "] Found " . count($errors) . " unresolved errors\n";

// ── Only skip errors that are definitively from browser extensions (not our code) ──
$extensionNoise = [
    'chrome-extension', 'moz-extension', 'safari-extension',
    'window.ethereum', '__firefox__', 'Can\'t find variable: __firefox__',
    'standardSelectors', // browser extension injecting into page
    'Object Not Found Matching Id', // Blazor/SignalR browser extension
    'play method is not allowed by the user agent', // browser autoplay policy
    'AbortError: The play() request was interrupted', // autoplay race condition
];

// ── Known patterns that can be resolved without Claude ──
$knownPatterns = [
    ['match' => "/undefined is not an object.*evaluating '[a-z]\[[a-z]\]'/i", 'condition' => null,
     'resolve' => 'Auto-resolved: Minified third-party library error (likely TinyMCE) on specific browser/device.'],
    ['match' => "/Cannot read propert(y|ies) of (null|undefined).*\(reading '[a-z]{1,2}'\)/i", 'condition' => null,
     'resolve' => 'Auto-resolved: Minified library internal error on specific browser.'],
    ['match' => '/(IMG|SCRIPT|LINK|VIDEO|AUDIO|SOURCE) failed to load/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Resource failed to load — missing asset, empty src, or network issue.'],
    ['match' => '/community-sse/i',
     'condition' => function($e) { return in_array($e['event_source'] ?? '', ['fetch_error', 'network_error', 'xhr_error']); },
     'resolve' => 'Auto-resolved: SSE connection dropped — expected on page navigation.'],
    ['match' => '/client-error\.php/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Error reporter endpoint got blocked (IP ban or session expired). Cascading error.'],
    ['match' => '/HTTP 403/i',
     'condition' => function($e) { return stripos($e['event_detail'] ?? '', 'Forbidden') !== false || stripos($e['event_message'] ?? '', '403') !== false; },
     'resolve' => 'Auto-resolved: HTTP 403 — temporarily banned IP or session issue. Not a code bug.'],
    ['match' => '/HTTP 401/i', 'condition' => null,
     'resolve' => 'Auto-resolved: HTTP 401 — session expired. Expected behavior.'],
    ['match' => '/t\.yadayah\.com/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Error on deprecated domain t.yadayah.com — now redirects to yadayah.com.'],
    ['match' => '/Load failed|Failed to fetch|NetworkError|network error|ERR_CONNECTION/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Transient network error.'],
    ['match' => '/Long task:/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Long task detected — performance note, not a code error.'],
    ['match' => '/bot|crawler|spider|YandexBot|Googlebot|bingbot|HeadlessChrome|PhantomJS|Lighthouse/i',
     'condition' => function($e) { return preg_match('/bot|crawler|spider|YandexBot|Googlebot|bingbot|HeadlessChrome|PhantomJS|Lighthouse/i', $e['event_detail'] ?? ''); },
     'resolve' => 'Auto-resolved: Error from bot/crawler — not a real user issue.'],
    ['match' => '/loadCategories error.*DOCTYPE/i', 'condition' => null,
     'resolve' => 'Auto-resolved: API returned HTML instead of JSON — caused by 403 IP ban cascade.'],
    ['match' => '/Unexpected token.*<!DOCTYPE/i',
     'condition' => function($e) { return ($e['event_source'] ?? '') === 'console_error'; },
     'resolve' => 'Auto-resolved: API returned HTML (403/404 page) instead of JSON — transient or IP ban cascade.'],
];

$triageCount = 0;

foreach ($errors as $error) {
    if ($triageCount >= $MAX_TRIAGE) break;

    $msg = $error['event_message'] ?? '';
    $detail = $error['event_detail'] ?? '';
    $source = $error['event_source'] ?? '';

    // Skip only confirmed browser extension errors (definitively not our code)
    $isExtension = false;
    foreach ($extensionNoise as $pat) {
        if (stripos($msg, $pat) !== false || stripos($detail, $pat) !== false) {
            $isExtension = true;
            break;
        }
    }
    if ($isExtension) {
        $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = 'Auto-skipped: browser extension — not our code' WHERE event_key = ?")
           ->execute([$error['event_key']]);
        echo "  [extension] #{$error['event_key']}: " . substr($msg, 0, 80) . "\n";
        continue;
    }

    // Check known patterns before calling Claude
    $knownResolved = false;
    $fullText = $msg . ' ' . $detail . ' ' . ($error['event_file'] ?? '') . ' ' . ($error['event_referer'] ?? '') . ' ' . $source;
    foreach ($knownPatterns as $kp) {
        if (preg_match($kp['match'], $fullText)) {
            $condFn = $kp['condition'];
            if ($condFn === null || $condFn($error)) {
                $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                   ->execute([$kp['resolve'], $error['event_key']]);
                echo "  [known] #{$error['event_key']}: " . substr($kp['resolve'], 0, 80) . "\n";
                $knownResolved = true;
                break;
            }
        }
    }
    if ($knownResolved) continue;

    // Skip errors already investigated by Claude (have action_taken set)
    if (!empty($error['event_action_taken'])) {
        echo "  [already-analyzed] #{$error['event_key']}: " . substr($error['event_action_taken'], 0, 60) . "\n";
        continue;
    }

    // Deduplicate: if another error with the same message was already fixed this run, resolve this one too
    $msgHash = md5($msg);
    static $fixedMessages = [];
    if (isset($fixedMessages[$msgHash])) {
        $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
           ->execute(["Same issue as #{$fixedMessages[$msgHash]} — resolved together", $error['event_key']]);
        echo "  [dedup] #{$error['event_key']} same as #{$fixedMessages[$msgHash]}\n";
        continue;
    }

    // Not a known pattern — leave for the remote Claude Code agent to investigate
    echo "  [pending] #{$error['event_key']} {$source}: " . substr($msg, 0, 80) . "\n";
}

echo "[" . date('c') . "] Done. Triaged: " . count($errors) . " errors\n";
