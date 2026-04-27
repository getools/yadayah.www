<?php
/**
 * Auto-fix agent — runs on the server via cron.
 * Checks yy_monitor_event for unresolved errors, uses Claude API to analyze
 * and fix them, then marks them resolved.
 *
 * Cron: every 30 min via docker exec
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(300);

// ── Config ──
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') !== false && $line[0] !== '#') putenv(trim($line));
    }
}

$ANTHROPIC_KEY = getenv('ANTHROPIC_API_KEY');
if (!$ANTHROPIC_KEY) { echo "[" . date('c') . "] No ANTHROPIC_API_KEY\n"; exit(1); }

$WEB_ROOT = '/var/www/html';
$MAX_FIXES = 20;
$MODEL = 'claude-sonnet-4-6';

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
    LIMIT 20
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

$fixCount = 0;

foreach ($errors as $error) {
    if ($fixCount >= $MAX_FIXES) break;

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

    // ── Gather context for Claude ──
    $context = "Error source: {$source}\nMessage: {$msg}\n";
    if ($detail) $context .= "Detail: " . substr($detail, 0, 2000) . "\n";
    if ($error['event_file']) $context .= "File: {$error['event_file']}\n";
    if ($error['event_referer']) $context .= "Page: {$error['event_referer']}\n";

    // Try to read the relevant source file
    $fileContent = null;
    $filePath = null;
    if ($source === 'php_error' && preg_match('/in\s+(\/var\/www\/html\/[^\s:]+):?(\d+)?/', $detail ?: $msg, $m)) {
        $filePath = $m[1];
        if (file_exists($filePath)) {
            $fileContent = file_get_contents($filePath);
            $context .= "\nSource file ($filePath):\n```php\n" . substr($fileContent, 0, 8000) . "\n```\n";
        }
    } elseif (($source === 'fetch_error' || $source === 'xhr_error') && preg_match('/on\s+\/api\/([^\s?]+)/', $msg, $m)) {
        $filePath = $WEB_ROOT . '/api/' . $m[1];
        if (file_exists($filePath)) {
            $fileContent = file_get_contents($filePath);
            $context .= "\nSource file ($filePath):\n```php\n" . substr($fileContent, 0, 8000) . "\n```\n";
            // Also include required/included files for full context
            if (preg_match_all("/require(?:_once)?\s+__DIR__\s*\.\s*'\/([^']+)'/", $fileContent, $reqMatches)) {
                foreach ($reqMatches[1] as $reqFile) {
                    $reqPath = $WEB_ROOT . '/api/' . $reqFile;
                    if (file_exists($reqPath)) {
                        $reqContent = file_get_contents($reqPath);
                        $context .= "\nRequired file ($reqPath):\n```php\n" . substr($reqContent, 0, 4000) . "\n```\n";
                    }
                }
            }
        }
    } elseif (in_array($source, ['js_error', 'promise_rejection', 'console_error'])) {
        $jsFile = $error['event_file'] ?? '';
        $resolved = false;

        // Try direct JS file path: https://yadayah.com/js/something.js
        if (!$resolved && preg_match('/yadayah\.com(\/[^\s:?]+\.js)/', $jsFile, $m)) {
            $localPath = $WEB_ROOT . '/public' . $m[1];
            if (!file_exists($localPath)) $localPath = $WEB_ROOT . $m[1];
            if (file_exists($localPath)) {
                $filePath = $localPath;
                $fileContent = file_get_contents($filePath);
                $context .= "\nSource file ($filePath):\n```javascript\n" . substr($fileContent, 0, 8000) . "\n```\n";
                $resolved = true;
            }
        }

        // Try HTML page reference: https://yadayah.com/chat:17:5968 or just /chat:17
        if (!$resolved && preg_match('/yadayah\.com\/([\w-]+)(?::\d+){0,2}/', $jsFile, $m)) {
            $pageName = $m[1];
            $htmlPath = $WEB_ROOT . '/public/' . $pageName . '.html';
            if (file_exists($htmlPath)) {
                $filePath = $htmlPath;
                $htmlContent = file_get_contents($htmlPath);
                $scripts = [];
                if (preg_match_all('/src="([^"]*\.js[^"]*)"/', $htmlContent, $sm)) {
                    $scripts = $sm[1];
                }
                $context .= "\nError occurred on page: $pageName.html\n";
                $context .= "Scripts loaded by this page:\n" . implode("\n", $scripts) . "\n";

                // Extract function/symbol name from error message
                $searchSymbol = null;
                if (preg_match('/(\w+) is not defined/', $msg, $symMatch)) {
                    $searchSymbol = $symMatch[1];
                } elseif (preg_match("/evaluating '([^']+)'/", $msg, $symMatch)) {
                    $searchSymbol = $symMatch[1];
                }

                // Search the page's JS files for the symbol
                if ($searchSymbol) {
                    $context .= "\nSearching for '$searchSymbol' in page scripts:\n";
                    foreach ($scripts as $scriptSrc) {
                        // Skip tinymce and external scripts
                        if (stripos($scriptSrc, 'tinymce') !== false) continue;
                        if (strpos($scriptSrc, 'http') === 0) continue;
                        $cleanSrc = preg_replace('/\?.*$/', '', $scriptSrc);
                        $jsPath = $WEB_ROOT . $cleanSrc;
                        if (!file_exists($jsPath)) $jsPath = $WEB_ROOT . '/public' . $cleanSrc;
                        if (!file_exists($jsPath)) continue;
                        $jsContent = file_get_contents($jsPath);
                        if (stripos($jsContent, $searchSymbol) !== false) {
                            // Found it — include this file as context
                            $context .= "\nFound '$searchSymbol' in $scriptSrc:\n```javascript\n" . substr($jsContent, 0, 8000) . "\n```\n";
                            $filePath = $jsPath;
                            $resolved = true;
                            break;
                        }
                    }
                    if (!$resolved) {
                        // Also check inline scripts in the HTML
                        if (stripos($htmlContent, $searchSymbol) !== false) {
                            $context .= "\nFound '$searchSymbol' in inline script of $pageName.html:\n```html\n" . substr($htmlContent, 0, 8000) . "\n```\n";
                            $resolved = true;
                        } else {
                            $context .= "\n'$searchSymbol' NOT FOUND in any script loaded by this page.\n";
                        }
                    }
                }

                if (preg_match("/evaluating '[a-z]{1,2}\[[a-z]{1,2}\]'/", $msg)) {
                    $context .= "\nNote: The error uses minified variable names (single-letter), suggesting it originates from a minified third-party library (e.g., TinyMCE) rather than site code.\n";
                }
            }
        }

        // Try referer page if we still don't have a source file
        if (!$resolved) {
            $refUrl = $error['event_referer'] ?? $error['event_file'] ?? '';
            if (!$refUrl && preg_match('/yadayah\.com\/([\w-]+)/', $jsFile, $rm)) {
                $refUrl = 'https://yadayah.com/' . $rm[1];
            }
            if ($refUrl && preg_match('/yadayah\.com\/([\w-]+)/', $refUrl, $m)) {
                $pageName = $m[1];
                $htmlPath = $WEB_ROOT . '/public/' . $pageName . '.html';
                if (file_exists($htmlPath)) {
                    $filePath = $htmlPath;
                    $htmlContent = file_get_contents($htmlPath);
                    $scripts = [];
                    if (preg_match_all('/src="([^"]*\.js[^"]*)"/', $htmlContent, $sm)) {
                        $scripts = $sm[1];
                    }
                    $context .= "\nPage source ($pageName.html):\n```html\n" . substr($htmlContent, 0, 12000) . "\n```\n";
                    $context .= "\nScripts loaded: " . implode(", ", $scripts) . "\n";

                    // For JSON parse errors, find the fetch calls and include the relevant JS
                    if (stripos($msg, 'json') !== false || stripos($msg, 'JSON') !== false) {
                        foreach ($scripts as $scriptSrc) {
                            if (stripos($scriptSrc, 'tinymce') !== false) continue;
                            if (strpos($scriptSrc, 'http') === 0) continue;
                            $cleanSrc = preg_replace('/\?.*$/', '', $scriptSrc);
                            $jsPath = $WEB_ROOT . $cleanSrc;
                            if (!file_exists($jsPath)) $jsPath = $WEB_ROOT . '/public' . $cleanSrc;
                            if (!file_exists($jsPath)) continue;
                            $jsContent = file_get_contents($jsPath);
                            // Find raw .json() calls that aren't using safeJson
                            $hasRawJson = preg_match('/\.json\(\)/', $jsContent);
                            $hasSafeJson = stripos($jsContent, 'safeJson') !== false;
                            if ($hasRawJson || $hasSafeJson) {
                                $context .= "\nJS file with JSON parsing ($scriptSrc):\n```javascript\n" . substr($jsContent, 0, 6000) . "\n```\n";
                                if ($hasRawJson) {
                                    // Find specific lines with raw .json()
                                    $lines = explode("\n", $jsContent);
                                    $rawLines = [];
                                    foreach ($lines as $ln => $lineText) {
                                        if (preg_match('/\.json\(\)/', $lineText) && stripos($lineText, 'safeJson') === false) {
                                            $rawLines[] = ($ln + 1) . ': ' . trim($lineText);
                                        }
                                    }
                                    if ($rawLines) {
                                        $context .= "\nWARNING: Found raw .json() calls (should use safeJson instead) in $scriptSrc:\n" . implode("\n", $rawLines) . "\n";
                                    }
                                }
                            }
                        }
                    }
                    $resolved = true;
                }
            }
        }
    }

    // ── Historical analysis: check error log for patterns ──
    // 1. How many times has this exact error occurred?
    $recurStmt = $db->prepare("SELECT COUNT(*) FROM yy_monitor_event WHERE event_message = ? AND event_dtime > NOW() - INTERVAL '7 days'");
    $recurStmt->execute([$msg]);
    $recurCount = (int)$recurStmt->fetchColumn();

    // 2. Have we tried to fix this before? What happened?
    $prevAttempts = $db->prepare("
        SELECT event_action_taken, event_resolved_flag, event_dtime
        FROM yy_monitor_event
        WHERE event_message = ? AND event_action_taken IS NOT NULL
        ORDER BY event_dtime DESC LIMIT 5
    ");
    $prevAttempts->execute([$msg]);
    $pastFixes = $prevAttempts->fetchAll();

    // 3. What other errors are occurring on the same page/file around the same time?
    $relatedErrors = [];
    $referer = $error['event_referer'] ?? '';
    $eventFile = $error['event_file'] ?? '';
    if ($referer || $eventFile) {
        $relStmt = $db->prepare("
            SELECT DISTINCT event_message, event_source, COUNT(*) as cnt
            FROM yy_monitor_event
            WHERE event_dtime > NOW() - INTERVAL '24 hours'
              AND event_resolved_flag = FALSE
              AND event_key != ?
              AND (event_referer = ? OR event_file = ? OR event_referer = ? OR event_file = ?)
            GROUP BY event_message, event_source
            ORDER BY cnt DESC LIMIT 10
        ");
        $relStmt->execute([$error['event_key'], $referer, $referer, $eventFile, $eventFile]);
        $relatedErrors = $relStmt->fetchAll();
    }

    // 4. What are the top unresolved error patterns across the whole site?
    $topUnresolved = $db->query("
        SELECT event_source, LEFT(event_message, 120) as msg_pattern, COUNT(*) as cnt
        FROM yy_monitor_event
        WHERE event_resolved_flag = FALSE AND event_dtime > NOW() - INTERVAL '7 days'
        GROUP BY event_source, LEFT(event_message, 120)
        ORDER BY cnt DESC LIMIT 10
    ")->fetchAll();

    // Build the historical context
    $historyContext = '';

    if ($recurCount > 1) {
        $historyContext .= "\n── ERROR HISTORY ──\n";
        $historyContext .= "This exact error has occurred {$recurCount} times in the last 7 days.\n";
    }

    if ($pastFixes) {
        $historyContext .= "\nPrevious fix attempts for this error:\n";
        foreach ($pastFixes as $pf) {
            $resolved = ($pf['event_resolved_flag'] === true || $pf['event_resolved_flag'] === 't') ? 'RESOLVED' : 'UNRESOLVED';
            $historyContext .= "  [{$resolved}] " . substr($pf['event_action_taken'], 0, 300) . "\n";
        }
        $historyContext .= "\nIf previous fixes didn't work, you need a DIFFERENT approach. Don't repeat the same failed fix.\n";
    }

    if ($relatedErrors) {
        $historyContext .= "\nOther unresolved errors on the same page/file:\n";
        foreach ($relatedErrors as $re) {
            $historyContext .= "  [{$re['event_source']}] x{$re['cnt']}: {$re['event_message']}\n";
        }
        $historyContext .= "\nThese related errors may share a common root cause. Consider whether fixing one would fix them all.\n";
    }

    if ($topUnresolved) {
        $historyContext .= "\nTop unresolved error patterns site-wide (last 7 days):\n";
        foreach ($topUnresolved as $tu) {
            $historyContext .= "  [{$tu['event_source']}] x{$tu['cnt']}: {$tu['msg_pattern']}\n";
        }
        $historyContext .= "\nIf you see patterns (e.g. many 403s, many JSON parse errors, many resource errors), think about systemic fixes — don't just fix one instance.\n";
    }

    if ($historyContext) {
        $context .= $historyContext;
    }

    // Include error-reporter.js for recurring client errors so Claude can fix the reporting
    if ($recurCount > 2 && in_array($source, ['fetch_error', 'xhr_error', 'resource_error', 'network_error', 'promise_rejection', 'console_error'])) {
        $reporterPath = $WEB_ROOT . '/public/js/error-reporter.js';
        if (file_exists($reporterPath)) {
            $context .= "\nError reporter source ($reporterPath):\n```javascript\n" . file_get_contents($reporterPath) . "\n```\n";
        }
    }

    // ── Call Claude API ──
    $rules = "Rules:\n"
        . "- ALWAYS investigate the root cause. Don't dismiss errors as 'noise' or 'transient'. Ask: what code on OUR side caused this?\n"
        . "- USE THE ERROR HISTORY provided above. If this error keeps recurring, previous fixes clearly didn't work — try a different, deeper approach.\n"
        . "- If related errors share the same page/file, look for a COMMON ROOT CAUSE that fixes all of them at once.\n"
        . "- If the site-wide error patterns show a systemic issue (e.g. many pages with the same bug pattern), use search_replace to fix ALL files at once.\n"
        . "- If the same error keeps being logged despite fixes, maybe the error reporter (error-reporter.js) is logging things it shouldn't — fix the reporter.\n"
        . "- For HTTP 401/403 errors that recur: these are from IP bans or session expiry. Fix the error-reporter.js to not log them.\n"
        . "- For 'Unexpected end of JSON input' or JSON parse errors: the root cause is a fetch().then(r => r.json()) call that doesn't handle non-JSON responses. Search ALL included JS files for raw .json() calls and replace with safeJson(r). The safeJson function is defined in admin-login.js.\n"
        . "- IMPORTANT: when the error references a page like 'admin-pages:468', the line number counts across the ENTIRE rendered page including all loaded scripts. Check ALL script src files loaded by that page.\n"
        . "- For resource load failures (IMG/SCRIPT failed to load): find which code generates the bad src attribute and fix it.\n"
        . "- For fetch errors returning HTML instead of JSON: find which API endpoint is broken or which fetch URL is wrong.\n"
        . "- For errors on t.yadayah.com: find the code that references the deprecated domain and change it to yadayah.com.\n"
        . "- If you can fix the code in ONE file, respond: {\"action\":\"fix\",\"file\":\"path\",\"explanation\":\"what you fixed\",\"content\":\"full corrected file content\"}\n"
        . "- If the fix is a pattern that likely exists in MULTIPLE files (e.g. the same unsafe call repeated across many pages), respond: {\"action\":\"search_replace\",\"glob\":\"glob pattern like public/admin-*.html\",\"find\":\"exact string to find\",\"replace\":\"replacement string\",\"explanation\":\"what this fixes and why it's systemic\"}\n"
        . "  - The search_replace action will find ALL files matching the glob, and in each file replace ALL occurrences of 'find' with 'replace'.\n"
        . "  - Use this when the same bug pattern exists across many files (e.g. missing error handling on fetch calls, wrong domain references, missing null checks).\n"
        . "- If the error is from a missing DB column on a _rev table, respond: {\"action\":\"sql\",\"query\":\"ALTER TABLE ... ADD COLUMN ...\",\"explanation\":\"added missing column\"}\n"
        . "- If you genuinely cannot fix it (not enough context, need files you can't access), respond: {\"action\":\"unfixable\",\"reason\":\"specific explanation of what you investigated and what's needed to fix it\"}\n"
        . "- Be conservative with code changes. Don't change working logic. Only fix the specific error.\n"
        . "- Respond with ONLY the JSON object, no markdown fencing, no extra text.\n";

    echo "  [analyze] #{$error['event_key']} {$source}: " . substr($msg, 0, 80) . "\n";

    // Try with progressively smaller context if the API rejects the request
    $contextLimit = 15000;
    $response = null;
    while ($contextLimit >= 2000) {
        $trimmedContext = $context;
        if (strlen($trimmedContext) > $contextLimit) {
            $trimmedContext = substr($trimmedContext, 0, $contextLimit) . "\n\n[Context truncated to " . round($contextLimit / 1000) . "KB]";
        }
        $prompt = "You are a code-fixing agent for the Yada Yah website. Investigate this error thoroughly and fix the root cause.\n\n"
            . $trimmedContext . "\n\n" . $rules;

        $response = callClaude($ANTHROPIC_KEY, $MODEL, $prompt);
        if ($response !== null) break;

        // API failed — retry with half the context
        $prevLimit = $contextLimit;
        $contextLimit = (int)($contextLimit / 2);
        echo "  [retry] Reducing context from {$prevLimit} to {$contextLimit} bytes\n";
    }
    if (!$response) {
        echo "  [error] Claude API call failed at all context sizes\n";
        $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
           ->execute(["Auto-fix: API failed even with minimal context", $error['event_key']]);
        continue;
    }

    // Parse response
    $json = null;
    if (preg_match('/\{[\s\S]*\}/', $response, $jm)) {
        $json = json_decode($jm[0], true);
    }
    if (!$json || !isset($json['action'])) {
        echo "  [error] Could not parse Claude response\n";
        $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
           ->execute(["Auto-fix: unparseable response: " . $response, $error['event_key']]);
        continue;
    }

    // ── Apply fix ──
    if ($json['action'] === 'fix' && !empty($json['file']) && !empty($json['content'])) {
        $targetFile = $json['file'];
        if (strpos($targetFile, $WEB_ROOT) !== 0) $targetFile = $WEB_ROOT . '/' . ltrim($targetFile, '/');
        $realTarget = realpath(dirname($targetFile));
        if (!$realTarget || strpos($realTarget, $WEB_ROOT) !== 0) {
            echo "  [blocked] Path outside web root: {$targetFile}\n";
            continue;
        }

        // Backup
        if (file_exists($targetFile)) {
            $backupDir = '/tmp/auto-fix-backups/' . date('Y-m-d');
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            copy($targetFile, $backupDir . '/' . basename($targetFile) . '.' . time());
        }

        $bytes = file_put_contents($targetFile, $json['content']);
        if ($bytes === false) {
            echo "  [error] Failed to write {$targetFile}\n";
            continue;
        }

        $explanation = $json['explanation'] ?? 'Auto-fixed by Claude';
        $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
           ->execute(["Auto-fix: $explanation", $error['event_key']]);
        $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('agent_op', 'info', ?, ?, TRUE)")
           ->execute(["Auto-fix applied: " . basename($targetFile), $explanation]);

        echo "  [fixed] #{$error['event_key']} → {$targetFile} ({$bytes} bytes): {$explanation}\n";
        $fixedMessages[$msgHash] = $error['event_key'];
        $fixCount++;

    } elseif ($json['action'] === 'search_replace' && !empty($json['find']) && !empty($json['replace']) && !empty($json['glob'])) {
        $find = $json['find'];
        $replace = $json['replace'];
        // Reject no-op replacements
        if ($find === $replace) {
            echo "  [search_replace] Rejected: find and replace are identical\n";
            $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
               ->execute(["Auto-fix rejected: search_replace with identical find/replace — Claude gave a useless response", $error['event_key']]);
            continue;
        }
        // Try multiple glob base paths
        $globPattern = $WEB_ROOT . '/' . ltrim($json['glob'], '/');
        if (!glob($globPattern)) $globPattern = $WEB_ROOT . '/public/' . ltrim($json['glob'], '/');
        $explanation = $json['explanation'] ?? 'Systemic pattern fix';
        $matchedFiles = glob($globPattern);
        if (!$matchedFiles) {
            echo "  [search_replace] No files match glob: {$json['glob']}\n";
        } else {
            $filesFixed = 0;
            $totalReplacements = 0;
            foreach ($matchedFiles as $mf) {
                if (!is_file($mf) || strpos(realpath($mf), $WEB_ROOT) !== 0) continue;
                $content = file_get_contents($mf);
                $count = substr_count($content, $find);
                if ($count > 0) {
                    // Backup
                    $backupDir = '/tmp/auto-fix-backups/' . date('Y-m-d');
                    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
                    copy($mf, $backupDir . '/' . basename($mf) . '.' . time());

                    $newContent = str_replace($find, $replace, $content);
                    file_put_contents($mf, $newContent);
                    $filesFixed++;
                    $totalReplacements += $count;
                    echo "    → " . basename($mf) . ": {$count} replacements\n";
                }
            }
            if ($filesFixed > 0) {
                $summary = "Auto-fix search_replace: {$explanation} — {$totalReplacements} replacements in {$filesFixed} files matching {$json['glob']}";
                $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                   ->execute([$summary, $error['event_key']]);
                $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('agent_op', 'info', ?, ?, TRUE)")
                   ->execute(["Auto-fix systemic: {$explanation}", "Pattern: '{$find}' → '{$replace}'\nGlob: {$json['glob']}\nFiles: {$filesFixed}, Replacements: {$totalReplacements}"]);
                echo "  [search_replace] #{$error['event_key']}: {$filesFixed} files, {$totalReplacements} replacements: {$explanation}\n";

                // Also resolve any OTHER unresolved errors with the same message pattern
                $similarStmt = $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_resolved_flag = FALSE AND event_message LIKE ? AND event_key != ?");
                $msgPattern = '%' . substr($msg, 0, 50) . '%';
                $similarStmt->execute(["Auto-resolved: same root cause fixed by systemic patch in event #{$error['event_key']}", $msgPattern, $error['event_key']]);
                $similarCount = $similarStmt->rowCount();
                if ($similarCount > 0) {
                    echo "    → Also resolved {$similarCount} similar errors\n";
                }

                $fixCount++;
            } else {
                echo "  [search_replace] Pattern not found in any files\n";
            }
        }

    } elseif ($json['action'] === 'sql' && !empty($json['query'])) {
        $query = $json['query'];
        $upper = strtoupper(trim($query));
        if (strpos($upper, 'ALTER') === 0 && stripos($query, '_rev') !== false && stripos($query, 'ADD COLUMN') !== false) {
            try {
                $db->exec($query);
                $explanation = $json['explanation'] ?? 'Added missing column';
                $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
                   ->execute(["Auto-fix SQL: $explanation", $error['event_key']]);
                $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('agent_op', 'info', ?, ?, TRUE)")
                   ->execute(["Auto-fix SQL: $query", $explanation]);
                echo "  [sql] #{$error['event_key']}: {$query}\n";
                $fixCount++;
            } catch (Exception $e) {
                echo "  [sql-error] " . $e->getMessage() . "\n";
            }
        } else {
            echo "  [blocked] Unsafe SQL: " . substr($query, 0, 100) . "\n";
        }

    } elseif ($json['action'] === 'unfixable' || $json['action'] === 'skip' || $json['action'] === 'resolve') {
        // Leave unresolved — record what we found but don't mark resolved
        $reason = $json['reason'] ?? 'Agent could not produce a fix';
        $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
           ->execute(["Auto-fix attempted: $reason", $error['event_key']]);
        echo "  [unresolved] #{$error['event_key']}: {$reason}\n";
    }
}

echo "[" . date('c') . "] Done. Fixed: {$fixCount}\n";

// Write fix summary for git-push.sh to use as commit message
if ($fixCount > 0) {
    $summaryFile = '/tmp/auto-fix-summary.txt';
    file_put_contents($summaryFile, "Auto-fix: {$fixCount} error(s) fixed at " . date('Y-m-d H:i'));
}

// ── Claude API helper ──
function callClaude(string $apiKey, string $model, string $prompt): ?string {
    $payload = json_encode([
        'model' => $model,
        'max_tokens' => 8192,
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
        echo "  [api-error] HTTP {$httpCode}: " . substr($response ?: 'no response', 0, 200) . "\n";
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['content'][0]['text'])) {
        echo "  [api-error] Unexpected response format\n";
        return null;
    }

    return $data['content'][0]['text'];
}
