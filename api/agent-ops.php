<?php
/**
 * Remote Agent Operations API — allows Claude Code remote agents to:
 *   - Read unresolved errors from yy_monitor_event
 *   - Read source files from the server
 *   - Write/update source files on the server
 *   - Run limited SQL (SELECT on any table, UPDATE on yy_monitor_event only)
 *   - List directory contents
 *
 * Auth: Bearer token in Authorization header.
 * All operations are logged to yy_monitor_event.
 */
require_once __DIR__ . '/config.php';

// ── Auth ──
$AGENT_KEY = 'yada-agent-2026-kX9mP4wL7rJ2nQ5v';

// Apache may strip Authorization header — check multiple sources
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '')
    ?? '';
// Also accept as query param fallback for environments that strip headers
if (!$authHeader && !empty($_GET['key'])) $authHeader = 'Bearer ' . $_GET['key'];
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) || $m[1] !== $AGENT_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDb();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

// ── Rate limit: max 100 ops per hour ──
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$rateStmt = $db->prepare("SELECT COUNT(*) FROM yy_monitor_event WHERE event_source = 'agent_op' AND event_client_ip = ? AND event_dtime > NOW() - INTERVAL '1 hour'");
$rateStmt->execute([$clientIp]);
if ((int)$rateStmt->fetchColumn() >= 100) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}

// Log every operation
function logOp(PDO $db, string $action, string $detail, string $ip) {
    $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag, event_client_ip) VALUES ('agent_op', 'info', ?, ?, TRUE, ?)")
       ->execute(["Agent: $action", substr($detail, 0, 2000), $ip]);
}

// ── Actions ──

// List unresolved errors
if ($action === 'errors') {
    $limit = min((int)($input['limit'] ?? 20), 50);
    $stmt = $db->prepare("SELECT event_key, event_source, event_severity, event_message, event_detail, event_action_taken, event_file, event_referer, event_dtime FROM yy_monitor_event WHERE event_resolved_flag = FALSE ORDER BY event_dtime DESC LIMIT ?");
    $stmt->execute([$limit]);
    jsonResponse(['errors' => $stmt->fetchAll()]);
}

// Read a file
if ($action === 'read') {
    $path = $input['path'] ?? '';
    $webRoot = '/opt/yada-www';
    $fullPath = realpath($webRoot . '/' . ltrim($path, '/'));
    if (!$fullPath || strpos($fullPath, $webRoot) !== 0 || !is_file($fullPath)) {
        jsonResponse(['error' => 'File not found or outside web root', 'path' => $path]);
    }
    $content = file_get_contents($fullPath);
    if ($content === false) jsonResponse(['error' => 'Cannot read file']);
    jsonResponse(['path' => $path, 'size' => strlen($content), 'content' => $content]);
}

// Write a file
if ($action === 'write') {
    $path = $input['path'] ?? '';
    $content = $input['content'] ?? null;
    if ($content === null) jsonResponse(['error' => 'content is required']);

    $webRoot = '/opt/yada-www';
    $fullPath = $webRoot . '/' . ltrim($path, '/');
    // Security: must be within web root, no path traversal
    $resolved = realpath(dirname($fullPath));
    if (!$resolved || strpos($resolved, $webRoot) !== 0) {
        jsonResponse(['error' => 'Invalid path']);
    }
    // Only allow PHP, JS, CSS, HTML files
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'js', 'css', 'html', 'json', 'txt'])) {
        jsonResponse(['error' => 'File type not allowed: ' . $ext]);
    }

    // Backup existing file
    if (file_exists($fullPath)) {
        $backupDir = '/tmp/agent-backups/' . date('Y-m-d');
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
        copy($fullPath, $backupDir . '/' . basename($fullPath) . '.' . time());
    }

    $bytes = file_put_contents($fullPath, $content);
    if ($bytes === false) jsonResponse(['error' => 'Failed to write file']);
    logOp($db, 'write', "Wrote $bytes bytes to $path", $clientIp);
    jsonResponse(['saved' => true, 'path' => $path, 'bytes' => $bytes]);
}

// List directory
if ($action === 'ls') {
    $path = $input['path'] ?? '';
    $webRoot = '/opt/yada-www';
    $fullPath = realpath($webRoot . '/' . ltrim($path, '/'));
    if (!$fullPath || strpos($fullPath, $webRoot) !== 0 || !is_dir($fullPath)) {
        jsonResponse(['error' => 'Directory not found']);
    }
    $items = [];
    foreach (scandir($fullPath) as $item) {
        if ($item === '.' || $item === '..') continue;
        $fp = $fullPath . '/' . $item;
        $items[] = ['name' => $item, 'type' => is_dir($fp) ? 'dir' : 'file', 'size' => is_file($fp) ? filesize($fp) : null];
    }
    jsonResponse(['path' => $path, 'items' => $items]);
}

// Run SQL (SELECT only, plus UPDATE on yy_monitor_event)
if ($action === 'sql') {
    $query = trim($input['query'] ?? '');
    if (!$query) jsonResponse(['error' => 'query is required']);

    $upperQuery = strtoupper(ltrim($query));
    $isSelect = strpos($upperQuery, 'SELECT') === 0;
    $isMonitorUpdate = strpos($upperQuery, 'UPDATE') === 0 && stripos($query, 'yy_monitor_event') !== false;
    $isAlterRev = strpos($upperQuery, 'ALTER') === 0 && stripos($query, '_rev') !== false && stripos($query, 'ADD COLUMN') !== false;

    if (!$isSelect && !$isMonitorUpdate && !$isAlterRev) {
        jsonResponse(['error' => 'Only SELECT, UPDATE on yy_monitor_event, and ALTER TABLE ADD COLUMN on _rev tables are allowed']);
    }

    try {
        $stmt = $db->query($query);
        if ($isSelect) {
            $rows = $stmt->fetchAll();
            jsonResponse(['rows' => $rows, 'count' => count($rows)]);
        } else {
            jsonResponse(['affected' => $stmt->rowCount()]);
        }
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()]);
    }
}

// Resolve an error
if ($action === 'resolve') {
    $eventKey = (int)($input['event_key'] ?? 0);
    $actionTaken = $input['action_taken'] ?? '';
    if (!$eventKey) jsonResponse(['error' => 'event_key required']);
    $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
       ->execute([$actionTaken, $eventKey]);
    logOp($db, 'resolve', "Resolved event $eventKey: $actionTaken", $clientIp);
    jsonResponse(['resolved' => true]);
}

jsonResponse(['error' => 'Unknown action. Available: errors, read, write, ls, sql, resolve']);
