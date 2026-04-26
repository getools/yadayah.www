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

if ($method === 'GET' && isset($_GET['summary'])) {
    $stmt = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN event_resolved_flag = FALSE THEN 1 ELSE 0 END) AS unresolved,
            SUM(CASE WHEN event_severity = 'error' AND event_resolved_flag = FALSE THEN 1 ELSE 0 END) AS errors,
            SUM(CASE WHEN event_severity = 'warning' AND event_resolved_flag = FALSE THEN 1 ELSE 0 END) AS warnings,
            SUM(CASE WHEN event_action_taken IS NOT NULL THEN 1 ELSE 0 END) AS auto_fixed,
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

    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_monitor_event WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT event_key, event_source, event_severity, event_message, event_detail,
               event_action_taken, event_resolved_flag, event_dtime, event_resolved_dtime,
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
        ob_start();
        include __DIR__ . '/auto-fix-error.php';
        $output = ob_get_clean();
        jsonResponse(['ran' => true, 'output' => $output]);
    }

    if ($action === 'clear') {
        $db->exec("DELETE FROM yy_monitor_event WHERE event_resolved_flag = TRUE");
        jsonResponse(['cleared' => true]);
    }
}

errorResponse('Invalid request', 400);
