<?php
/**
 * Remote Agent API — Authenticated endpoint for the scheduled Claude Code agent.
 * Allows the remote agent to query errors, read files, apply fixes, check logs,
 * verify DB schema, and mark errors resolved — all via curl from the cloud.
 *
 * Auth: Bearer token in REMOTE_AGENT_TOKEN env var.
 *
 * Actions (POST with JSON body):
 *   query_errors     — Get unresolved errors from yy_monitor_event
 *   read_file        — Read a source file from the web root
 *   list_files       — List files matching a glob pattern
 *   search_files     — Search file contents (grep)
 *   write_file       — Write/update a source file (with backup)
 *   run_sql          — Execute safe SQL (SELECT, ALTER on _rev tables)
 *   check_logs       — Get recent Apache/PHP error log entries
 *   curl_endpoint    — Test an API endpoint internally
 *   db_schema        — Get column info for a table
 *   resolve_error    — Mark an error as resolved with description
 *   note_error       — Add action_taken note without resolving
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(120);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Load env ──
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') !== false && $line[0] !== '#') putenv(trim($line));
    }
}

// ── Auth ──
$AGENT_TOKEN = getenv('REMOTE_AGENT_TOKEN');
if (!$AGENT_TOKEN) {
    http_response_code(503);
    echo json_encode(['error' => 'Remote agent not configured']);
    exit;
}

// Apache may strip Authorization header — check multiple sources
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '')
    ?? '';
// Also support X-Agent-Token as fallback
$xToken = $_SERVER['HTTP_X_AGENT_TOKEN'] ?? '';
$tokenFromHeader = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $tokenFromHeader = trim($m[1]);
} elseif ($xToken) {
    $tokenFromHeader = trim($xToken);
}
if (!$tokenFromHeader || !hash_equals($AGENT_TOKEN, $tokenFromHeader)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

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

$WEB_ROOT = '/var/www/html';

// ── Parse request ──
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

if (!$action) {
    http_response_code(400);
    echo json_encode(['error' => 'action required']);
    exit;
}

// Log every remote agent action
$db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('remote_agent', 'info', ?, ?, TRUE)")
   ->execute(["Remote agent action: $action", json_encode(array_diff_key($input, ['content' => 1]))]);

try {
    switch ($action) {

    // ── Query unresolved errors ──
    case 'query_errors':
        $limit = min((int)($input['limit'] ?? 30), 50);
        $source = $input['source'] ?? null;

        $sql = "SELECT event_key, event_source, event_severity, event_message, event_detail, event_file, event_referer, event_action_taken, event_dtime
                FROM yy_monitor_event
                WHERE event_resolved_flag = FALSE
                  AND event_source NOT IN ('agent_op', 'honeypot', 'remote_agent')
                  AND event_severity IN ('error', 'warning')";
        $params = [];
        if ($source) {
            $sql .= " AND event_source = ?";
            $params[] = $source;
        }
        $sql .= " ORDER BY event_dtime DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        respond(['errors' => $stmt->fetchAll()]);

    // ── Read a source file ──
    case 'read_file':
        $path = $input['path'] ?? '';
        if (!$path) respond(['error' => 'path required'], 400);
        $fullPath = resolvePath($path, $WEB_ROOT);
        if (!$fullPath) respond(['error' => 'Path outside web root or not found'], 403);

        $content = file_get_contents($fullPath);
        $lines = substr_count($content, "\n") + 1;
        // Truncate very large files
        if (strlen($content) > 50000) {
            $content = substr($content, 0, 50000) . "\n\n[Truncated at 50KB — file has $lines lines total]";
        }
        respond(['path' => $fullPath, 'lines' => $lines, 'size' => filesize($fullPath), 'content' => $content]);

    // ── List files matching a glob ──
    case 'list_files':
        $pattern = $input['pattern'] ?? '';
        if (!$pattern) respond(['error' => 'pattern required'], 400);
        $globPattern = $WEB_ROOT . '/' . ltrim($pattern, '/');
        $files = glob($globPattern);
        if (!$files) {
            // Try under public/
            $globPattern = $WEB_ROOT . '/public/' . ltrim($pattern, '/');
            $files = glob($globPattern);
        }
        $result = [];
        foreach ($files as $f) {
            if (!is_file($f)) continue;
            if (strpos(realpath($f), $WEB_ROOT) !== 0) continue;
            $result[] = [
                'path' => $f,
                'size' => filesize($f),
                'modified' => date('c', filemtime($f)),
            ];
        }
        respond(['files' => $result]);

    // ── Search file contents (grep) ──
    case 'search_files':
        $pattern = $input['pattern'] ?? '';
        $glob = $input['glob'] ?? '**/*.php';
        if (!$pattern) respond(['error' => 'pattern required'], 400);

        // Use grep on server
        $escapedPattern = escapeshellarg($pattern);
        $searchPath = $WEB_ROOT;
        $cmd = "grep -rn --include=" . escapeshellarg(basename($glob)) . " $escapedPattern $searchPath 2>/dev/null | head -50";
        $output = shell_exec($cmd) ?: '';

        respond(['matches' => trim($output), 'command' => $cmd]);

    // ── Write/update a file (with backup) ──
    case 'write_file':
        $path = $input['path'] ?? '';
        $content = $input['content'] ?? null;
        if (!$path || $content === null) respond(['error' => 'path and content required'], 400);

        $fullPath = $path;
        if (strpos($fullPath, $WEB_ROOT) !== 0) $fullPath = $WEB_ROOT . '/' . ltrim($fullPath, '/');
        $realDir = realpath(dirname($fullPath));
        if (!$realDir || strpos($realDir, $WEB_ROOT) !== 0) respond(['error' => 'Path outside web root'], 403);

        // Backup existing file
        if (file_exists($fullPath)) {
            $backupDir = '/tmp/remote-agent-backups/' . date('Y-m-d');
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            copy($fullPath, $backupDir . '/' . basename($fullPath) . '.' . time());
        }

        $bytes = file_put_contents($fullPath, $content);
        if ($bytes === false) respond(['error' => 'Failed to write file'], 500);

        respond(['written' => true, 'path' => $fullPath, 'bytes' => $bytes]);

    // ── Run safe SQL ──
    case 'run_sql':
        $query = trim($input['query'] ?? '');
        if (!$query) respond(['error' => 'query required'], 400);

        $upper = strtoupper($query);
        // Allow SELECT, ALTER TABLE on _rev tables, and UPDATE on yy_monitor_event
        $allowed = false;
        if (strpos($upper, 'SELECT') === 0) {
            $allowed = true;
        } elseif (strpos($upper, 'ALTER') === 0 && stripos($query, '_rev') !== false && stripos($query, 'ADD COLUMN') !== false) {
            $allowed = true;
        } elseif (strpos($upper, 'UPDATE') === 0 && stripos($query, 'yy_monitor_event') !== false) {
            $allowed = true;
        }

        if (!$allowed) respond(['error' => 'Only SELECT, ALTER on _rev tables, and UPDATE on yy_monitor_event are allowed'], 403);

        if (strpos($upper, 'SELECT') === 0) {
            $stmt = $db->query($query);
            respond(['rows' => $stmt->fetchAll()]);
        } else {
            $db->exec($query);
            respond(['executed' => true]);
        }

    // ── Check Apache/PHP error logs ──
    case 'check_logs':
        $lines = min((int)($input['lines'] ?? 50), 200);
        $filter = $input['filter'] ?? '';

        // Apache error log inside Docker
        $logFile = '/var/log/apache2/error.log';
        $output = '';
        if (file_exists($logFile)) {
            if ($filter) {
                $escapedFilter = escapeshellarg($filter);
                $output = shell_exec("grep -i $escapedFilter $logFile 2>/dev/null | tail -$lines") ?: '';
            } else {
                $output = shell_exec("tail -$lines $logFile 2>/dev/null") ?: '';
            }
        }

        // Also check PHP error log
        $phpLog = ini_get('error_log');
        $phpOutput = '';
        if ($phpLog && file_exists($phpLog)) {
            if ($filter) {
                $escapedFilter = escapeshellarg($filter);
                $phpOutput = shell_exec("grep -i $escapedFilter $phpLog 2>/dev/null | tail -$lines") ?: '';
            } else {
                $phpOutput = shell_exec("tail -$lines $phpLog 2>/dev/null") ?: '';
            }
        }

        respond([
            'apache_log' => trim($output),
            'php_log' => trim($phpOutput),
            'apache_log_path' => $logFile,
            'php_log_path' => $phpLog ?: '(not configured)',
        ]);

    // ── Curl an API endpoint internally ──
    case 'curl_endpoint':
        $endpoint = $input['endpoint'] ?? '';
        $method = strtoupper($input['method'] ?? 'GET');
        $body = $input['body'] ?? null;
        if (!$endpoint) respond(['error' => 'endpoint required'], 400);

        // Only allow local endpoints
        $url = 'http://localhost/' . ltrim($endpoint, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        // Truncate large responses
        if (strlen($responseBody) > 10000) $responseBody = substr($responseBody, 0, 10000) . "\n[truncated]";

        respond([
            'http_code' => $httpCode,
            'headers' => trim($headers),
            'body' => $responseBody,
        ]);

    // ── Get DB schema for a table ──
    case 'db_schema':
        $table = $input['table'] ?? '';
        if (!$table) respond(['error' => 'table required'], 400);
        // Sanitize table name
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table)) respond(['error' => 'Invalid table name'], 400);

        $stmt = $db->prepare("
            SELECT column_name, data_type, is_nullable, column_default, character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ?
            ORDER BY ordinal_position
        ");
        $stmt->execute([$table]);
        $columns = $stmt->fetchAll();

        if (!$columns) respond(['error' => "Table '$table' not found"], 404);

        // Also get indexes
        $idxStmt = $db->prepare("
            SELECT indexname, indexdef
            FROM pg_indexes
            WHERE schemaname = 'public' AND tablename = ?
        ");
        $idxStmt->execute([$table]);
        $indexes = $idxStmt->fetchAll();

        // Check for _rev table
        $revTable = $table . '_rev';
        $revStmt = $db->prepare("
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ?
            ORDER BY ordinal_position
        ");
        $revStmt->execute([$revTable]);
        $revColumns = $revStmt->fetchAll();

        respond([
            'table' => $table,
            'columns' => $columns,
            'indexes' => $indexes,
            'rev_table' => $revColumns ? $revTable : null,
            'rev_columns' => $revColumns ?: null,
        ]);

    // ── Mark error resolved ──
    case 'resolve_error':
        $eventKey = (int)($input['event_key'] ?? 0);
        $description = $input['description'] ?? '';
        if (!$eventKey || !$description) respond(['error' => 'event_key and description required'], 400);

        $db->prepare("UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = ? WHERE event_key = ?")
           ->execute(["Remote agent: $description", $eventKey]);
        respond(['resolved' => true, 'event_key' => $eventKey]);

    // ── Add note without resolving ──
    case 'note_error':
        $eventKey = (int)($input['event_key'] ?? 0);
        $note = $input['note'] ?? '';
        if (!$eventKey || !$note) respond(['error' => 'event_key and note required'], 400);

        $db->prepare("UPDATE yy_monitor_event SET event_action_taken = ? WHERE event_key = ?")
           ->execute(["Remote agent note: $note", $eventKey]);
        respond(['noted' => true, 'event_key' => $eventKey]);

    default:
        respond(['error' => "Unknown action: $action"], 400);
    }
} catch (Exception $e) {
    respond(['error' => $e->getMessage()], 500);
}

// ── Helpers ──
function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function resolvePath(string $path, string $webRoot): ?string {
    $fullPath = $path;
    if (strpos($fullPath, $webRoot) !== 0) {
        $fullPath = $webRoot . '/' . ltrim($fullPath, '/');
    }
    if (!file_exists($fullPath)) return null;
    $real = realpath($fullPath);
    if (!$real || strpos($real, $webRoot) !== 0) return null;
    return $real;
}
