<?php
/**
 * Runs scheduled site checks/tests and logs results.
 * Submits failures to the error monitoring system for AI auto-fix.
 *
 * Usage: php cron-test.php
 * Or:    GET /api/cron-test.php?key=yada2026test
 * Or:    GET /api/cron-test.php?key=yada2026test&test_key=N  (run one test)
 */
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026test') {
        require_once __DIR__ . '/config.php';
        requireAuth();
    } else {
        require_once __DIR__ . '/config.php';
    }
} else {
    require_once __DIR__ . '/config.php';
}

$db = getDb();
$now = date('Y-m-d H:i:s');
$isCli = php_sapi_name() === 'cli';
$singleKey = (int)($_GET['test_key'] ?? 0);

// Fetch tests to run
if ($singleKey) {
    $stmt = $db->prepare("SELECT * FROM yy_test WHERE test_key = ? AND test_active_flag = TRUE");
    $stmt->execute([$singleKey]);
} else {
    // Find tests that are due based on their schedule
    $stmt = $db->query("
        SELECT * FROM yy_test
        WHERE test_active_flag = TRUE
          AND (
            test_last_run IS NULL
            OR (test_schedule_interval_minutes IS NOT NULL
                AND test_last_run + (test_schedule_interval_minutes || ' minutes')::interval <= NOW())
            OR (test_schedule_time IS NOT NULL
                AND test_last_run::date < CURRENT_DATE
                AND CURRENT_TIME >= test_schedule_time)
          )
        ORDER BY test_sort, test_key
    ");
}
$tests = $stmt->fetchAll();

if ($isCli) echo "[{$now}] Running " . count($tests) . " test(s)...\n";

$results = [];
$logStmt = $db->prepare("
    INSERT INTO yy_test_log (test_key, test_log_status, test_log_message, test_log_detail, test_log_duration_ms, test_log_http_status)
    VALUES (?, ?, ?, ?, ?, ?)
");
$updateStmt = $db->prepare("
    UPDATE yy_test SET test_last_run = NOW(), test_last_status = ?, test_last_message = ? WHERE test_key = ?
");

// Monitor event insert for failures
$monitorStmt = $db->prepare("
    INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_file, event_referer)
    VALUES ('site_test', 'error', ?, ?, ?, ?)
");

foreach ($tests as $test) {
    $key = (int)$test['test_key'];
    $type = $test['test_type'] ?? 'url_check';
    $config = $test['test_config'] ? json_decode($test['test_config'], true) : [];
    $title = $test['test_title'];

    if ($isCli) echo "  [{$key}] {$title} ({$type})... ";

    $startMs = microtime(true);
    $status = 'pass';
    $message = '';
    $detail = '';
    $httpStatus = null;

    try {
        switch ($type) {
            case 'url_check':
                $result = runUrlCheck($config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                $httpStatus = $result['http_status'] ?? null;
                break;

            case 'api_check':
                $result = runApiCheck($config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                $httpStatus = $result['http_status'] ?? null;
                break;

            case 'db_check':
                $result = runDbCheck($db, $config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                break;

            case 'content_check':
                $result = runContentCheck($config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                $httpStatus = $result['http_status'] ?? null;
                break;

            case 'feed_freshness':
                require_once __DIR__ . '/test-feed-freshness.php';
                $result = runFeedFreshnessCheck($db, $config);
                $status = $result['status'];
                $message = $result['message'];
                $detail = $result['detail'] ?? '';
                break;

            default:
                $status = 'skip';
                $message = "Unknown test type: {$type}";
        }
    } catch (Throwable $e) {
        $status = 'fail';
        $message = $e->getMessage();
        $detail = $e->getTraceAsString();
    }

    $durationMs = (int)((microtime(true) - $startMs) * 1000);

    // Log result
    $logStmt->execute([$key, $status, substr($message, 0, 2000), substr($detail, 0, 4000), $durationMs, $httpStatus]);
    $updateStmt->execute([$status, substr($message, 0, 2000), $key]);

    // Submit failure to monitoring system
    if ($status === 'fail') {
        $monitorStmt->execute([
            "Test failed: {$title}",
            substr($message . "\n" . $detail, 0, 4000),
            "test:{$key}:{$type}",
            $config['url'] ?? null,
        ]);
    }

    if ($isCli) echo "{$status} ({$durationMs}ms)" . ($status === 'fail' ? " — {$message}" : '') . "\n";

    $results[] = ['test_key' => $key, 'title' => $title, 'status' => $status, 'message' => $message, 'duration_ms' => $durationMs];
}

// Prune old test logs (keep 90 days)
$db->exec("DELETE FROM yy_test_log WHERE test_log_dtime < NOW() - INTERVAL '90 days'");

// If failures were logged, trigger AI auto-fix
if (count(array_filter($results, function($r) { return $r['status'] === 'fail'; })) > 0) {
    if (file_exists(__DIR__ . '/auto-fix-error.php')) {
        include __DIR__ . '/auto-fix-error.php';
    }
}

$summary = [
    'timestamp' => $now,
    'tests_run' => count($results),
    'passed' => count(array_filter($results, function($r) { return $r['status'] === 'pass'; })),
    'failed' => count(array_filter($results, function($r) { return $r['status'] === 'fail'; })),
    'results' => $results,
];

if ($isCli) {
    echo "[{$now}] Done: " . $summary['passed'] . " passed, " . $summary['failed'] . " failed\n";
} else {
    jsonResponse($summary);
}

// ── Test type implementations ──

function runUrlCheck(array $config): array {
    $url = $config['url'] ?? '';
    if (!$url) return ['status' => 'fail', 'message' => 'No URL configured'];

    $expectedStatus = $config['expected_status'] ?? 200;
    $timeout = $config['timeout'] ?? 15;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY => !isset($config['expect_content']),
    ]);
    $body = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 'fail', 'message' => "Connection failed: {$error}", 'http_status' => 0];
    }
    if ($httpStatus !== $expectedStatus) {
        return ['status' => 'fail', 'message' => "Expected HTTP {$expectedStatus}, got {$httpStatus}", 'http_status' => $httpStatus, 'detail' => substr($body, 0, 500)];
    }
    if (isset($config['expect_content']) && strpos($body, $config['expect_content']) === false) {
        return ['status' => 'fail', 'message' => "Expected content not found: " . substr($config['expect_content'], 0, 100), 'http_status' => $httpStatus, 'detail' => substr($body, 0, 500)];
    }

    return ['status' => 'pass', 'message' => "HTTP {$httpStatus} OK", 'http_status' => $httpStatus];
}

function runApiCheck(array $config): array {
    $url = $config['url'] ?? '';
    if (!$url) return ['status' => 'fail', 'message' => 'No URL configured'];

    $timeout = $config['timeout'] ?? 15;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 'fail', 'message' => "Connection failed: {$error}", 'http_status' => 0];
    }
    if ($httpStatus >= 400) {
        return ['status' => 'fail', 'message' => "HTTP {$httpStatus}", 'http_status' => $httpStatus, 'detail' => substr($body, 0, 500)];
    }

    $json = json_decode($body, true);
    if ($json === null && $body !== 'null') {
        return ['status' => 'fail', 'message' => 'Invalid JSON response', 'http_status' => $httpStatus, 'detail' => substr($body, 0, 500)];
    }
    if (isset($json['error'])) {
        return ['status' => 'fail', 'message' => "API error: {$json['error']}", 'http_status' => $httpStatus];
    }
    if (isset($config['expect_key']) && !isset($json[$config['expect_key']])) {
        return ['status' => 'fail', 'message' => "Missing expected key: {$config['expect_key']}", 'http_status' => $httpStatus];
    }
    if (isset($config['min_count']) && isset($config['count_key'])) {
        $count = $json[$config['count_key']] ?? (is_array($json[$config['expect_key']] ?? null) ? count($json[$config['expect_key']]) : 0);
        if ($count < $config['min_count']) {
            return ['status' => 'fail', 'message' => "{$config['count_key']} = {$count}, expected >= {$config['min_count']}", 'http_status' => $httpStatus];
        }
    }

    return ['status' => 'pass', 'message' => "HTTP {$httpStatus} OK", 'http_status' => $httpStatus];
}

function runDbCheck(PDO $db, array $config): array {
    $query = $config['query'] ?? '';
    if (!$query) return ['status' => 'fail', 'message' => 'No query configured'];

    // Safety: only allow SELECT
    if (!preg_match('/^\s*SELECT\s/i', $query)) {
        return ['status' => 'fail', 'message' => 'Only SELECT queries are allowed'];
    }

    $stmt = $db->query($query);
    $rows = $stmt->fetchAll();
    $count = count($rows);

    if (isset($config['expect_min']) && $count < $config['expect_min']) {
        return ['status' => 'fail', 'message' => "Got {$count} rows, expected >= {$config['expect_min']}", 'detail' => json_encode($rows[0] ?? null)];
    }
    if (isset($config['expect_max']) && $count > $config['expect_max']) {
        return ['status' => 'fail', 'message' => "Got {$count} rows, expected <= {$config['expect_max']}", 'detail' => json_encode($rows[0] ?? null)];
    }
    if (isset($config['expect_zero']) && $config['expect_zero'] && $count > 0) {
        return ['status' => 'fail', 'message' => "Expected 0 rows, got {$count}", 'detail' => json_encode($rows[0] ?? null)];
    }

    return ['status' => 'pass', 'message' => "{$count} row(s) OK"];
}

function runContentCheck(array $config): array {
    $url = $config['url'] ?? '';
    if (!$url) return ['status' => 'fail', 'message' => 'No URL configured'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $config['timeout'] ?? 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['status' => 'fail', 'message' => "Connection failed: {$error}", 'http_status' => 0];
    if ($httpStatus >= 400) return ['status' => 'fail', 'message' => "HTTP {$httpStatus}", 'http_status' => $httpStatus];

    $checks = $config['checks'] ?? [];
    foreach ($checks as $check) {
        $pattern = $check['contains'] ?? null;
        $notContains = $check['not_contains'] ?? null;
        if ($pattern && strpos($body, $pattern) === false) {
            return ['status' => 'fail', 'message' => "Missing: " . substr($pattern, 0, 100), 'http_status' => $httpStatus];
        }
        if ($notContains && strpos($body, $notContains) !== false) {
            return ['status' => 'fail', 'message' => "Unwanted content found: " . substr($notContains, 0, 100), 'http_status' => $httpStatus];
        }
    }

    return ['status' => 'pass', 'message' => "HTTP {$httpStatus} OK — all checks passed", 'http_status' => $httpStatus];
}
