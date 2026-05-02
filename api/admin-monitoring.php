<?php
/**
 * Admin API for monitoring dashboard.
 * GET                — list recent events (default 100, pageable)
 * GET ?summary=1     — counts by source/severity for dashboard header
 * PUT ?key=N         — mark event as resolved
 * DELETE ?key=N      — delete an event
 * POST ?action=run   — trigger a monitor scan now
 * POST ?action=clear — clear all resolved events
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$key = (int)($_GET['key'] ?? 0);

if ($method === 'GET' && isset($_GET['youtube_push'])) {
    $subsFile = sys_get_temp_dir() . '/yada_push_subscriptions.json';
    $pushFile = sys_get_temp_dir() . '/yada_live_push.json';
    $subs = file_exists($subsFile) ? json_decode(file_get_contents($subsFile), true) : [];
    $push = file_exists($pushFile) ? json_decode(file_get_contents($pushFile), true) : null;
    $now = time();
    $subStatus = [];
    foreach (($subs ?: []) as $id => $sub) {
        $subTime = strtotime($sub['subscribed_at'] ?? '');
        $ageDays = $subTime ? round(($now - $subTime) / 86400, 1) : null;
        $expiresIn = $subTime && isset($sub['lease_seconds'])
            ? round(($subTime + $sub['lease_seconds'] - $now) / 86400, 1)
            : null;
        $subStatus[] = [
            'channel_id' => $id,
            'name' => $sub['name'] ?? '',
            'status' => $sub['status'] ?? 'unknown',
            'subscribed_at' => $sub['subscribed_at'] ?? null,
            'age_days' => $ageDays,
            'expires_in_days' => $expiresIn,
            'topic' => $sub['topic'] ?? '',
            'healthy' => $expiresIn !== null && $expiresIn > 1,
        ];
    }
    $pushAge = file_exists($pushFile) ? round(($now - filemtime($pushFile)) / 60, 1) : null;
    jsonResponse([
        'subscriptions' => $subStatus,
        'last_push' => $push,
        'last_push_age_minutes' => $pushAge,
    ]);
}

if ($method === 'GET' && isset($_GET['summary'])) {
    // Auto-fixed: only counts events where the auto-fix system actively applied a code/SQL/schema change.
    // Excludes: info severity, honeypot events, noise/skip resolutions, and AI diagnosis-only entries.
    $autoFixedClause = "event_action_taken IS NOT NULL"
        . " AND event_severity != 'info'"
        . " AND event_source != 'honeypot'"
        . " AND event_action_taken NOT ILIKE '%diagnosis:%'"
        . " AND event_action_taken NOT ILIKE '%Without seeing%'"
        . " AND event_action_taken NOT ILIKE '%could not fix%'"
        . " AND event_action_taken NOT ILIKE '%already references%'"
        . " AND event_action_taken NOT ILIKE '%noise%'"
        . " AND event_action_taken NOT ILIKE '%Stale:%'"
        . " AND event_action_taken NOT ILIKE '%Browser %'"
        . " AND event_action_taken NOT ILIKE '%Client %'"
        . " AND event_action_taken NOT ILIKE '%Cascading%'"
        . " AND event_action_taken NOT ILIKE 'Skipped%'"
        . " AND event_action_taken NOT ILIKE '%Working as designed%'"
        . " AND ("
            . "event_action_taken ILIKE '%Fixed%'"
            . " OR event_action_taken ILIKE '%Initialize%'"
            . " OR event_action_taken ILIKE '%Initialized%'"
            . " OR event_action_taken ILIKE '%Added %'"
            . " OR event_action_taken ILIKE '%Renamed%'"
            . " OR event_action_taken ILIKE '%Cancelled%'"
            . " OR event_action_taken ILIKE '%Retried%'"
            . " OR event_action_taken ILIKE '%Sanitize%'"
            . " OR event_action_taken ILIKE '%Wrapped%'"
            . " OR event_action_taken ILIKE '%Completed%'"
            . " OR event_action_taken ILIKE '%Enabled%'"
            . " OR event_action_taken ILIKE '%Removed%'"
        . ")";

    $stmt = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN event_resolved_flag = FALSE THEN 1 ELSE 0 END) AS unresolved,
            SUM(CASE WHEN event_severity = 'error' AND event_resolved_flag = FALSE THEN 1 ELSE 0 END) AS errors,
            SUM(CASE WHEN event_severity = 'warning' AND event_resolved_flag = FALSE THEN 1 ELSE 0 END) AS warnings,
            SUM(CASE WHEN {$autoFixedClause} THEN 1 ELSE 0 END) AS auto_fixed,
            MIN(event_dtime) AS oldest,
            MAX(event_dtime) AS newest
        FROM yy_monitor_event
        WHERE event_dtime > NOW() - INTERVAL '7 days'
    ");
    $summary = $stmt->fetch();

    $bySource = $db->query("
        SELECT event_source, COUNT(*) AS cnt,
               SUM(CASE WHEN event_resolved_flag = FALSE THEN 1 ELSE 0 END) AS unresolved
        FROM yy_monitor_event
        WHERE event_dtime > NOW() - INTERVAL '7 days'
        GROUP BY event_source ORDER BY cnt DESC
    ")->fetchAll();

    jsonResponse(['summary' => $summary, 'by_source' => $bySource]);
}

if ($method === 'GET') {
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $source = $_GET['source'] ?? '';
    $severity = $_GET['severity'] ?? '';
    $status = $_GET['status'] ?? ''; // 'resolved', 'unresolved', ''

    $where = "1=1";
    $params = [];
    if ($source) { $where .= " AND event_source = ?"; $params[] = $source; }
    if ($severity) { $where .= " AND event_severity = ?"; $params[] = $severity; }
    if ($status === 'resolved') { $where .= " AND event_resolved_flag = TRUE"; }
    elseif ($status === 'unresolved') { $where .= " AND event_resolved_flag = FALSE"; }
    elseif ($status === 'auto_fixed') {
        $where .= " AND event_action_taken IS NOT NULL"
               . " AND event_severity != 'info'"
               . " AND event_source != 'honeypot'"
               . " AND event_action_taken NOT ILIKE '%diagnosis:%'"
               . " AND event_action_taken NOT ILIKE '%Without seeing%'"
               . " AND event_action_taken NOT ILIKE '%could not fix%'"
               . " AND event_action_taken NOT ILIKE '%already references%'"
               . " AND event_action_taken NOT ILIKE '%noise%'"
               . " AND event_action_taken NOT ILIKE '%Stale:%'"
               . " AND event_action_taken NOT ILIKE '%Browser %'"
               . " AND event_action_taken NOT ILIKE '%Client %'"
               . " AND event_action_taken NOT ILIKE '%Cascading%'"
               . " AND event_action_taken NOT ILIKE 'Skipped%'"
               . " AND event_action_taken NOT ILIKE '%Working as designed%'"
               . " AND ("
                   . "event_action_taken ILIKE '%Fixed%'"
                   . " OR event_action_taken ILIKE '%Initialize%'"
                   . " OR event_action_taken ILIKE '%Initialized%'"
                   . " OR event_action_taken ILIKE '%Added %'"
                   . " OR event_action_taken ILIKE '%Renamed%'"
                   . " OR event_action_taken ILIKE '%Cancelled%'"
                   . " OR event_action_taken ILIKE '%Retried%'"
                   . " OR event_action_taken ILIKE '%Sanitize%'"
                   . " OR event_action_taken ILIKE '%Wrapped%'"
                   . " OR event_action_taken ILIKE '%Completed%'"
                   . " OR event_action_taken ILIKE '%Enabled%'"
                   . " OR event_action_taken ILIKE '%Removed%'"
               . ")";
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_monitor_event WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT event_key, event_source, event_severity, event_message, event_detail,
               event_action_taken, event_resolve_notes, event_resolved_flag, event_dtime, event_resolved_dtime,
               event_file, event_referer, event_client_ip
        FROM yy_monitor_event
        WHERE $where
        ORDER BY event_dtime DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));

    jsonResponse(['events' => $stmt->fetchAll(), 'total' => $total]);
}

if ($method === 'PUT' && $key) {
    $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW() WHERE event_key = ?")
       ->execute([$key]);
    jsonResponse(['saved' => true]);
}

if ($method === 'DELETE' && $key) {
    $db->prepare("DELETE FROM yy_monitor_event WHERE event_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    if ($action === 'run') {
        // Trigger monitor scan inline
        ob_start();
        include __DIR__ . '/cron-monitor.php';
        $output = ob_get_clean();
        jsonResponse(['ran' => true, 'output' => $output]);
    }

    if ($action === 'autofix') {
        // Server-side gate: refuse to start a new run if one is already
        // active. Prevents the click-stacking that caused our flock-skip
        // problem. Stale runs (>30 min without an update) are ignored.
        $statusDir = '/var/www/html/jobs/autofix';
        @mkdir($statusDir, 0775, true);
        foreach (glob("$statusDir/run_*.json") as $f) {
            if (time() - filemtime($f) > 1800) continue;
            $existing = json_decode(@file_get_contents($f), true) ?: [];
            $st = $existing['state'] ?? '';
            if (in_array($st, ['starting', 'running', 'claude_queued', 'pattern_triage'], true)) {
                preg_match('/run_([a-f0-9]+)\.json$/', $f, $m);
                jsonResponse([
                    'error' => 'Auto-fix is already running. Wait for it to finish or click Stop.',
                    'active_run_id' => $m[1] ?? '',
                    'status_url' => '/api/admin-monitoring.php?action=autofix_status&run_id=' . ($m[1] ?? ''),
                ]);
            }
        }

        // Spawn auto-fix-error.php as a backgrounded job. Returns a run_id immediately;
        // the UI polls /admin-monitoring.php?action=autofix_status&run_id=... for live progress.
        // Status file lives in a BIND-MOUNTED location so the host-side
        // claude-fix-runner.sh can also write into it as it processes
        // queued Claude events.
        $runId = bin2hex(random_bytes(8));
        $statusFile = "$statusDir/run_{$runId}.json";
        $logFile = "/tmp/autofix_run_{$runId}.log";
        @file_put_contents($statusFile, json_encode(['state' => 'starting', 'run_id' => $runId, 'started' => date('c')]));

        $cmd = 'nohup php ' . escapeshellarg(__DIR__ . '/auto-fix-error.php') . ' ' . escapeshellarg($runId)
             . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';
        $pidOut = [];
        @exec($cmd, $pidOut);
        $pid = (int)($pidOut[0] ?? 0);

        // Also kick off cron-monitor and git-push in parallel (they're independent)
        @exec('nohup php ' . escapeshellarg(__DIR__ . '/cron-monitor.php') . ' > /tmp/autofix_cron_monitor.log 2>&1 < /dev/null &');
        @exec('/opt/yada-www/api/git-push.sh "Auto-Fix Now: manual sync" > /dev/null 2>&1 &');

        jsonResponse([
            'run_id' => $runId,
            'pid' => $pid,
            'status_url' => '/api/admin-monitoring.php?action=autofix_status&run_id=' . $runId,
        ]);
    }

    if ($action === 'autofix_active') {
        // Page-load probe: returns details of any in-progress run so the UI
        // can immediately switch into "monitoring" mode (button → Stop, poll
        // active run's status) instead of treating the page as idle.
        session_write_close();
        $statusDir = '/var/www/html/jobs/autofix';
        $found = null;
        foreach (glob("$statusDir/run_*.json") as $f) {
            if (time() - filemtime($f) > 1800) continue;
            $data = json_decode(@file_get_contents($f), true) ?: [];
            $st = $data['state'] ?? '';
            if (in_array($st, ['starting', 'running', 'claude_queued', 'pattern_triage'], true)) {
                preg_match('/run_([a-f0-9]+)\.json$/', $f, $m);
                $found = ['run_id' => $m[1] ?? '', 'state' => $st, 'started' => $data['started'] ?? ''];
                break;
            }
        }
        jsonResponse(['active' => $found !== null, 'run' => $found]);
    }

    if ($action === 'autofix_stop') {
        // Cancels an in-flight run: kills the auto-fix-error.php process
        // owning that run_id, removes any pending Claude queue files for the
        // run, and marks the status as cancelled. Host runner finishes
        // whatever Claude event it's currently in (no point cutting that
        // off mid-stream) but won't pick up further requests.
        $runId = preg_replace('/[^a-f0-9]/', '', $_POST['run_id'] ?? '');
        if (!$runId && $body = file_get_contents('php://input')) {
            $j = json_decode($body, true) ?: [];
            $runId = preg_replace('/[^a-f0-9]/', '', $j['run_id'] ?? '');
        }
        if (!$runId) errorResponse('run_id required', 400);
        // Kill the PHP background process for this run
        @exec('pkill -9 -f ' . escapeshellarg("auto-fix-error.php $runId") . ' 2>&1');
        // Drop pending Claude queue requests for this run
        $queueDir = '/var/www/html/jobs/claude-fix';
        foreach (glob("$queueDir/req_{$runId}_*.json") as $f) @unlink($f);
        // Mark status as cancelled
        $statusFile = "/var/www/html/jobs/autofix/run_{$runId}.json";
        if (file_exists($statusFile)) {
            $data = json_decode(@file_get_contents($statusFile), true) ?: [];
            $data['state'] = 'cancelled';
            $data['cancelled_at'] = date('c');
            @file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT));
        }
        jsonResponse(['stopped' => true, 'run_id' => $runId]);
    }

    if ($action === 'autofix_status') {
        // Read-only poll — release the session lock immediately so it
        // doesn't serialize against the long-running ?action=autofix POST
        // that's still in PHP's polling loop holding the session file open.
        // Without this the status JSON appears frozen until the run finishes.
        session_write_close();
        $runId = preg_replace('/[^a-f0-9]/', '', $_GET['run_id'] ?? '');
        if (!$runId) errorResponse('run_id required', 400);
        // Try bind-mounted location first (where new runs live), fall back
        // to /tmp for any in-flight legacy runs from before the move.
        $statusFile = "/var/www/html/jobs/autofix/run_{$runId}.json";
        if (!file_exists($statusFile)) {
            $statusFile = "/tmp/autofix_run_{$runId}.json";
        }
        if (!file_exists($statusFile)) {
            jsonResponse(['state' => 'unknown', 'run_id' => $runId]);
        }
        $data = json_decode(@file_get_contents($statusFile), true) ?: [];
        $data['run_id'] = $runId;
        jsonResponse($data);
    }

if ($action === 'add_notes') {
        $key = (int)($_GET['key'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $notes = trim($data['notes'] ?? '');
        if (!$key || !$notes) errorResponse('key and notes required');
        $timestamp = date('Y-m-d H:i');
        $entry = "[{$timestamp}] {$notes}";
        $db->prepare("UPDATE yy_monitor_event SET event_resolve_notes = CASE WHEN event_resolve_notes IS NULL THEN ? ELSE event_resolve_notes || E'\\n\\n' || ? END WHERE event_key = ?")
           ->execute([$entry, $entry, $key]);
        jsonResponse(['saved' => true]);
    }

    if ($action === 'clear') {
        $db->exec("DELETE FROM yy_monitor_event WHERE event_resolved_flag = TRUE");
        jsonResponse(['cleared' => true]);
    }
}

errorResponse('Invalid request', 400);
