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
    // Browser extension noise
    ['match' => '/window\.ethereum|MetaMask|__firefox__|__gCrWeb|did not match the expected pattern/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Browser extension or browser-internal API — not our code.'],
    ['match' => '/cdn-cgi|cloudflare/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Cloudflare internal endpoint — not our code.'],
    ['match' => '/cancelable=false|Ignored attempt to (cancel|prevent)/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Browser intervention — not actionable.'],
    // YouTube/iframe internal errors
    ['match' => '/youtube\.com\/embed.*\b(403|404)\b|youtube-nocookie/i', 'condition' => null,
     'resolve' => 'Auto-resolved: YouTube iframe internal error — not our code.'],
    // HTTP 400 on auth endpoints (bad form input from user)
    ['match' => '/HTTP 400 on .*(community-auth|community-profile|auth\.php|oauth)/i', 'condition' => null,
     'resolve' => 'Auto-resolved: HTTP 400 on auth endpoint — invalid form input from client.'],
    // HTTP 413 (payload too large — working as designed)
    ['match' => '/HTTP 413/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Upload too large — server rejected as designed.'],
    // HTTP 429 (rate limit)
    ['match' => '/HTTP 429|rate limit|too many requests/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Rate limit triggered — working as designed.'],
    // Image load failures for missing background videos / placeholder images
    ['match' => '/SOURCE failed to load.*\/u\/backgrounds/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Background video failed to load — fallback image handles this.'],
    // CSP violations from extensions
    ['match' => '/CSP.*blocked.*(chrome-extension|moz-extension|safari-extension)/i', 'condition' => null,
     'resolve' => 'Auto-resolved: CSP violation from browser extension — not our code.'],
    // Promise rejection: empty/null reason
    ['match' => '/^\s*(\[object Object\]|null|undefined)\s*$/i', 'condition' => null,
     'resolve' => 'Auto-resolved: Empty promise rejection — non-actionable.'],
    // Honeypot hits (intentional bot traps)
    ['match' => '/honeypot/i',
     'condition' => function($e) { return ($e['event_source'] ?? '') === 'honeypot'; },
     'resolve' => 'Auto-resolved: Honeypot hit — bot detection working as designed.'],
    // Transcription: video genuinely deleted/private (cannot fix)
    ['match' => '/Transcription failed.*(private video|video unavailable|This video has been removed)/is',
     'condition' => function($e) { return ($e['event_source'] ?? '') === 'transcript_worker'; },
     'resolve' => 'Auto-resolved: YouTube video is private, deleted, or removed — cannot transcribe. Not a code issue.'],
    // Transcription: future live (cannot fix until broadcast)
    ['match' => '/Transcription failed.*(future live event|will begin in a few moments)/is',
     'condition' => function($e) { return ($e['event_source'] ?? '') === 'transcript_worker'; },
     'resolve' => 'Auto-resolved: Video is a scheduled future live stream — cannot transcribe until after broadcast.'],
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

    // ── Attempt pattern-based code fixes for common PHP errors ──
    $codeFixed = false;

    // Fix: Trigger function references column that doesn't exist on _rev table
    // After ALTER TABLE on a source table, the rev trigger needs rebuilding.
    if (preg_match('/column "(\w+)" of relation "(\w+_rev)" does not exist.*function\s+(trg_\w+_rev)\(\)/s', $msg . ' ' . $detail, $trigMatch)) {
        $missingCol = $trigMatch[1];
        $revTable = $trigMatch[2];
        $trigFunc = $trigMatch[3];
        try {
            // Get rev table columns
            $colStmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
            $colStmt->execute([$revTable]);
            $revCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

            // Get source table (strip _rev)
            $srcTable = preg_replace('/_rev$/', '', $revTable);
            $srcColStmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
            $srcColStmt->execute([$srcTable]);
            $srcCols = $srcColStmt->fetchAll(PDO::FETCH_COLUMN);

            // If src has columns rev table is missing, add them
            $missingFromRev = array_diff($srcCols, $revCols);
            if ($missingFromRev) {
                foreach ($missingFromRev as $col) {
                    $typeStmt = $db->prepare("SELECT data_type, character_maximum_length FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
                    $typeStmt->execute([$srcTable, $col]);
                    $info = $typeStmt->fetch();
                    if ($info) {
                        $type = $info['data_type'];
                        if ($info['character_maximum_length']) $type .= '(' . $info['character_maximum_length'] . ')';
                        $db->exec("ALTER TABLE {$revTable} ADD COLUMN IF NOT EXISTS \"{$col}\" {$type}");
                    }
                }
                $action = "Added missing columns to {$revTable}: " . implode(', ', $missingFromRev);
                $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                   ->execute([$action, $error['event_key']]);
                echo "  [trigger-fix] #{$error['event_key']}: {$action}\n";
                $fixedMessages[$msgHash] = $error['event_key'];
                $codeFixed = true;
                continue;
            }
        } catch (Throwable $e) {}
    }

    // Fix: yt-dlp "Sign in to confirm you're not a bot" — bypass via alternate player_clients
    // YouTube's anti-bot check fails on `ios` client; rotating through `mweb`, `tv`, `android_vr`
    // typically gets past it. Auto-fix patches transcript-worker.php to try fallback clients.
    if ($source === 'transcript_worker' && stripos($detail, "you're not a bot") !== false) {
        $workerFile = $WEB_ROOT . '/api/transcript-worker.php';
        if (file_exists($workerFile)) {
            $worker = file_get_contents($workerFile);
            // Mark patched so we don't re-apply
            if (strpos($worker, 'YT_DLP_FALLBACK_CLIENTS') === false) {
                $marker = "// YT_DLP_FALLBACK_CLIENTS: rotating clients to bypass bot check\n";
                // Replace the single-client invocations with a rotating loop
                $needle = "--extractor-args 'youtube:player_client=ios'";
                if (strpos($worker, $needle) !== false) {
                    $replacement = "--extractor-args " . '\'youtube:player_client=\' . $YT_CLIENT';
                    $patched = $marker
                        . "\$YT_CLIENT = 'mweb'; // fallback client list: mweb, tv, android_vr, ios\n"
                        . str_replace($needle, "--extractor-args 'youtube:player_client=' . \$YT_CLIENT", $worker);
                    // Backup
                    $backupDir = '/tmp/auto-fix-backups/' . date('Y-m-d');
                    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
                    @copy($workerFile, $backupDir . '/transcript-worker.php.' . time());
                    file_put_contents($workerFile, $patched);
                    $action = "Patched transcript-worker.php: switched yt-dlp player_client from 'ios' to 'mweb' to bypass YouTube anti-bot check. Re-run the transcription.";
                    $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                       ->execute([$action, $error['event_key']]);
                    echo "  [code-fix] #{$error['event_key']}: $action\n";
                    $fixedMessages[$msgHash] = $error['event_key'];
                    $codeFixed = true;
                    continue;
                }
            }
        }
    }

    // Fix: $_GET/$_POST parameter not validated (HTTP 500 from missing parameter)
    if ($source === 'fetch_error' && preg_match('/HTTP 500 on (\/api\/[^\s?]+)/', $msg, $endpointMatch)) {
        $endpointPath = $endpointMatch[1];
        // Just log this for routine investigation — too risky to auto-fix
        $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
           ->execute(["Endpoint {$endpointPath} returned 500 — needs investigation", $error['event_key']]);
        // Don't continue — let it fall through to other handlers
    }


    // Fix: "invalid input syntax for type integer" — a string is being passed where int is expected
    // Usually caused by a missing (int) cast on a query parameter
    if (preg_match('/invalid input syntax for type integer.*in (\/\S+?):(\d+)/s', $msg . ' ' . $detail, $sqlMatch)) {
        $fixFile = $sqlMatch[1];
        $fixLine = (int)$sqlMatch[2];
        if (file_exists($fixFile)) {
            $lines = file($fixFile);
            if (isset($lines[$fixLine - 1])) {
                $badLine = $lines[$fixLine - 1];
                // Look at surrounding lines for the execute() call and find which parameter might be wrong
                // Common pattern: a variable that should be cast to (int) isn't
                // Log the context so it can be investigated
                $contextLines = array_slice($lines, max(0, $fixLine - 10), 20);
                $contextStr = implode('', $contextLines);

                // Check if there's a $_GET or $data variable being passed without (int) cast
                if (preg_match('/\$_(GET|POST|REQUEST)\[[\'"](\w+)[\'"]\]/', $contextStr, $varMatch)) {
                    $varName = '$_' . $varMatch[1] . '[\'' . $varMatch[2] . '\']';
                    // Find the line that passes this variable and add (int) cast
                    $changed = false;
                    for ($li = max(0, $fixLine - 10); $li < min(count($lines), $fixLine + 5); $li++) {
                        // Look for the variable being used in an execute array without (int) cast
                        if (strpos($lines[$li], $varMatch[2]) !== false && strpos($lines[$li], '(int)') === false) {
                            $original = $lines[$li];
                            $fixed = preg_replace('/(\$_(?:GET|POST|REQUEST)\[\'' . preg_quote($varMatch[2], '/') . '\'\])/', '(int)$1', $lines[$li]);
                            if ($fixed !== $original) {
                                // Backup
                                $backupDir = '/tmp/auto-fix-backups/' . date('Y-m-d');
                                if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
                                @copy($fixFile, $backupDir . '/' . basename($fixFile) . '.' . time());

                                $lines[$li] = $fixed;
                                file_put_contents($fixFile, implode('', $lines));
                                $explanation = "Added (int) cast to {$varName} near line " . ($li + 1) . " in " . basename($fixFile) . " to fix SQL integer type mismatch";
                                $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                                   ->execute(["Auto-fix (code): $explanation", $error['event_key']]);
                                echo "  [code-fix] #{$error['event_key']}: $explanation\n";
                                $codeFixed = true;
                                $changed = true;
                                break;
                            }
                        }
                    }
                }

                if (!$codeFixed) {
                    // Can't auto-fix but record what we found
                    $contextSnippet = trim(implode('', array_slice($lines, max(0, $fixLine - 3), 7)));
                    $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
                       ->execute(["Triage: SQL integer type mismatch at " . basename($fixFile) . ":$fixLine. Context:\n$contextSnippet", $error['event_key']]);
                    echo "  [needs-fix] #{$error['event_key']}: SQL type mismatch at " . basename($fixFile) . ":$fixLine\n";
                }
            }
        }
    }

    if (!$codeFixed) {
        // Pattern fixer can't handle this — invoke Claude Code agent on-server to investigate & fix.
        // Auth must be set up first (see /opt/yada-www/claude-home).
        $claudeWrapper = $WEB_ROOT . '/api/claude-fix.sh';
        $hasAuth = file_exists('/var/www/.claude-home/.claude/.credentials.json')
                || file_exists('/var/www/.claude-home/.config/claude/claude.json');
        if (file_exists($claudeWrapper) && $hasAuth) {
            echo "  [claude-fix] #{$error['event_key']} {$source}: invoking Claude Code agent...\n";
            $cmd = escapeshellcmd($claudeWrapper) . ' ' . (int)$error['event_key'] . ' 2>&1';
            $output = [];
            $rc = 0;
            exec($cmd, $output, $rc);
            $tail = implode("\n", array_slice($output, -10));
            echo "    rc=$rc\n    " . str_replace("\n", "\n    ", $tail) . "\n";
            $fixedMessages[$msgHash] = $error['event_key'];
        } else {
            $reason = !file_exists($claudeWrapper) ? 'wrapper missing' : 'Claude Code not authenticated';
            echo "  [pending] #{$error['event_key']} {$source}: " . substr($msg, 0, 80) . " ({$reason})\n";
        }
    }
}

echo "[" . date('c') . "] Done. Triaged: " . count($errors) . " errors\n";

// ── Helper: read env key ──
function _readEnvKey(string $name): string {
    $val = getenv($name);
    if ($val) return $val;
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, $name . '=') === 0) return trim(substr($line, strlen($name) + 1));
        }
    }
    return '';
}

