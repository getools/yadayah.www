<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

// PUT — save admin note on a log entry
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $logKey = (int)($data['ask_session_log_key'] ?? 0);
    $note = trim($data['admin_note'] ?? '');
    if (!$logKey) errorResponse('Log key required');

    $db->prepare("UPDATE yy_ask_session_log SET ask_log_admin_note = ?, ask_log_admin_note_dtime = NOW() WHERE ask_session_log_key = ?")
        ->execute([$note ?: null, $logKey]);
    jsonResponse(['saved' => true]);
}

// GET ?session=N — single session with Q&A logs
if (isset($_GET['session'])) {
    $key = intval($_GET['session']);
    $stmt = $db->prepare('SELECT * FROM yy_ask_session WHERE ask_session_key = ?');
    $stmt->execute([$key]);
    $session = $stmt->fetch();
    if (!$session) errorResponse('Session not found', 404);

    $stmt = $db->prepare('SELECT * FROM yy_ask_session_log WHERE ask_session_key = ? ORDER BY ask_log_dtime ASC');
    $stmt->execute([$key]);
    $logs = $stmt->fetchAll();

    jsonResponse(['session' => $session, 'logs' => $logs]);
}

// GET — list sessions (paginated, newest first)
$page  = max(1, intval($_GET['page'] ?? 1));
$limit = min(100, max(10, intval($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$total = $db->query('SELECT COUNT(*) FROM yy_ask_session')->fetchColumn();

$stmt = $db->prepare('
    SELECT s.*,
           COALESCE(SUM(l.ask_log_prompt_tokens), 0) AS total_prompt_tokens,
           COALESCE(SUM(l.ask_log_completion_tokens), 0) AS total_completion_tokens,
           COALESCE(SUM(l.ask_log_duration_ms), 0) AS total_duration_ms,
           (SELECT ask_log_model FROM yy_ask_session_log WHERE ask_session_key = s.ask_session_key ORDER BY ask_log_dtime DESC LIMIT 1) AS model
    FROM yy_ask_session s
    LEFT JOIN yy_ask_session_log l ON l.ask_session_key = s.ask_session_key
    GROUP BY s.ask_session_key
    ORDER BY s.ask_session_dtime DESC
    LIMIT ? OFFSET ?
');
$stmt->execute([$limit, $offset]);
$sessions = $stmt->fetchAll();

jsonResponse([
    'sessions'    => $sessions,
    'page'        => $page,
    'limit'       => $limit,
    'total'       => intval($total),
    'total_pages' => max(1, intval(ceil($total / $limit))),
]);
