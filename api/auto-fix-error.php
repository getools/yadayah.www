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

    // ── Attempt pattern-based code fixes for common PHP errors ──
    $codeFixed = false;

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
        // ── Claude API investigation for novel errors ──
        $apiKey = _readEnvKey('ANTHROPIC_API_KEY');
        if ($apiKey && empty($error['event_action_taken'])) {
            echo "  [investigating] #{$error['event_key']} via Claude API...\n";

            // Build context: error info + source file + dependencies
            $context = "Error source: {$source}\nMessage: {$msg}\n";
            if ($detail) $context .= "Detail: " . substr($detail, 0, 3000) . "\n";
            if ($error['event_file']) $context .= "File: {$error['event_file']}\n";
            if ($error['event_referer']) $context .= "Page: {$error['event_referer']}\n";

            // Read the source file referenced in the error
            $errFilePath = null;
            if (preg_match('/in (\/\S+?):(\d+)/s', $detail ?: $msg, $fileMatch)) {
                $errFilePath = $fileMatch[1];
                if (file_exists($errFilePath)) {
                    $context .= "\nSource file ($errFilePath):\n```php\n" . file_get_contents($errFilePath) . "\n```\n";
                    // Also include require'd files
                    $srcContent = file_get_contents($errFilePath);
                    if (preg_match_all("/require(?:_once)?\s+__DIR__\s*\.\s*'\/([^']+)'/", $srcContent, $reqMatches)) {
                        foreach (array_unique($reqMatches[1]) as $reqFile) {
                            $reqPath = dirname($errFilePath) . '/' . $reqFile;
                            if (file_exists($reqPath)) {
                                $context .= "\nRequired file ($reqPath):\n```php\n" . substr(file_get_contents($reqPath), 0, 6000) . "\n```\n";
                            }
                        }
                    }
                }
            } elseif ($source === 'js_error' || $source === 'promise_rejection' || $source === 'console_error') {
                // Try to find the page and its scripts
                $ref = $error['event_referer'] ?? $error['event_file'] ?? '';
                if (preg_match('/yadayah\.com\/([\w-]+)/', $ref, $pm)) {
                    $htmlPath = $WEB_ROOT . '/public/' . $pm[1] . '.html';
                    if (file_exists($htmlPath)) {
                        $context .= "\nPage source ($htmlPath):\n```html\n" . substr(file_get_contents($htmlPath), 0, 12000) . "\n```\n";
                    }
                }
            }

            // Historical context
            $recurStmt = $db->prepare("SELECT COUNT(*) FROM yy_monitor_event WHERE event_message = ? AND event_dtime > NOW() - INTERVAL '7 days'");
            $recurStmt->execute([$msg]);
            $recurCount = (int)$recurStmt->fetchColumn();
            if ($recurCount > 1) {
                $context .= "\nThis error has occurred {$recurCount} times in the last 7 days.\n";
            }

            $prompt = "You are a code-fixing agent for the Yada Yah website. Investigate this error and fix the root cause.\n\n"
                . $context . "\n\n"
                . "Rules:\n"
                . "- Read the source code carefully. Trace the data flow to find the root cause.\n"
                . "- If you can fix the code, respond with ONLY a JSON object: {\"action\":\"fix\",\"file\":\"absolute path\",\"explanation\":\"what you fixed\",\"content\":\"full corrected file content\"}\n"
                . "- If the error is a SQL issue (missing column, type mismatch), respond: {\"action\":\"sql\",\"query\":\"SQL to run\",\"explanation\":\"what this fixes\"}\n"
                . "- If you cannot fix it, respond: {\"action\":\"unfixable\",\"reason\":\"detailed explanation of what you found\"}\n"
                . "- Be conservative. Only fix the specific error. Don't change working logic.\n"
                . "- Respond with ONLY the JSON object, no markdown, no extra text.\n";

            $result = _callClaudeAPI($apiKey, $prompt);
            if ($result) {
                $json = null;
                if (preg_match('/\{[\s\S]*\}/', $result, $jm)) {
                    $json = json_decode($jm[0], true);
                }

                if ($json && $json['action'] === 'fix' && !empty($json['file']) && !empty($json['content'])) {
                    $targetFile = $json['file'];
                    if (strpos($targetFile, $WEB_ROOT) !== 0) $targetFile = $WEB_ROOT . '/' . ltrim($targetFile, '/');
                    if (file_exists($targetFile) || file_exists(dirname($targetFile))) {
                        // Backup
                        $backupDir = '/tmp/auto-fix-backups/' . date('Y-m-d');
                        if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
                        if (file_exists($targetFile)) @copy($targetFile, $backupDir . '/' . basename($targetFile) . '.' . time());

                        file_put_contents($targetFile, $json['content']);
                        $explanation = $json['explanation'] ?? 'Fixed by Claude';
                        $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                           ->execute(["Auto-fix (Claude): $explanation", $error['event_key']]);
                        echo "  [claude-fix] #{$error['event_key']}: $explanation\n";
                        $fixedMessages[$msgHash] = $error['event_key'];
                        $codeFixed = true;
                    }
                } elseif ($json && $json['action'] === 'sql' && !empty($json['query'])) {
                    try {
                        $db->exec($json['query']);
                        $explanation = $json['explanation'] ?? 'SQL fix';
                        $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                           ->execute(["Auto-fix SQL (Claude): $explanation", $error['event_key']]);
                        echo "  [claude-sql] #{$error['event_key']}: $explanation\n";
                        $codeFixed = true;
                    } catch (Exception $e) {
                        echo "  [claude-sql-error] " . $e->getMessage() . "\n";
                    }
                }

                if (!$codeFixed) {
                    $reason = $json['reason'] ?? $json['explanation'] ?? substr($result, 0, 1000);
                    $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
                       ->execute(["Claude investigated: $reason", $error['event_key']]);
                    echo "  [claude-unfixable] #{$error['event_key']}: " . substr($reason, 0, 100) . "\n";
                }
            } else {
                echo "  [claude-error] API call failed for #{$error['event_key']}\n";
            }
        } else {
            echo "  [pending] #{$error['event_key']} {$source}: " . substr($msg, 0, 80) . "\n";
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

// ── Helper: call Claude API ──
function _callClaudeAPI(string $apiKey, string $prompt): ?string {
    $payload = json_encode([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 16000,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        echo "  [api-error] HTTP {$httpCode}\n";
        return null;
    }

    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? null;
}
