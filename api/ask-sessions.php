<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();

// GET ?session=N — single session with Q&A logs
if (isset($_GET['session'])) {
    $key = intval($_GET['session']);
    $stmt = $db->prepare('SELECT s.*, u.user_display_name AS ask_user_name, u.user_email AS ask_user_email FROM yy_ask_session s LEFT JOIN yy_user u ON s.user_key = u.user_key WHERE s.ask_session_key = ?');
    $stmt->execute([$key]);
    $session = $stmt->fetch();
    if (!$session) errorResponse('Session not found', 404);

    $stmt = $db->prepare('SELECT * FROM yy_ask_session_log WHERE ask_session_key = ? ORDER BY ask_log_dtime ASC');
    $stmt->execute([$key]);
    $logs = $stmt->fetchAll();

    jsonResponse(['session' => $session, 'logs' => $logs]);
}

// PUT — update admin note on a session log entry
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $logKey = (int)($data['ask_session_log_key'] ?? 0);
    $note = $data['admin_note'] ?? '';
    if (!$logKey) errorResponse('ask_session_log_key required');
    $db->prepare("UPDATE yy_ask_session_log SET ask_log_admin_note = ?, ask_log_admin_note_dtime = NOW() WHERE ask_session_log_key = ?")
       ->execute([$note, $logKey]);
    jsonResponse(['saved' => true]);
}

// GET — list sessions (paginated, newest first)
$page  = max(1, intval($_GET['page'] ?? 1));
$limit = min(100, max(10, intval($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$total = $db->query('SELECT COUNT(*) FROM yy_ask_session')->fetchColumn();

$stmt = $db->prepare(<<<SQL
    SELECT s.*,
           u.user_display_name AS ask_user_name, u.user_email AS ask_user_email,
           COALESCE(SUM(l.ask_log_prompt_tokens), 0) AS total_prompt_tokens,
           COALESCE(SUM(l.ask_log_completion_tokens), 0) AS total_completion_tokens,
           COALESCE(SUM(l.ask_log_duration_ms), 0) AS total_duration_ms,
           (SELECT ask_log_model FROM yy_ask_session_log WHERE ask_session_key = s.ask_session_key ORDER BY ask_log_dtime DESC LIMIT 1) AS model,
           COUNT(l.ask_session_log_key) AS total_questions,
           COUNT(l.ask_session_log_key) FILTER (WHERE l.ask_log_admin_note IS NULL OR l.ask_log_admin_note = '') AS pending_notes
    FROM yy_ask_session s
    LEFT JOIN yy_ask_session_log l ON l.ask_session_key = s.ask_session_key
    LEFT JOIN yy_user u ON s.user_key = u.user_key
    GROUP BY s.ask_session_key, u.user_display_name, u.user_email
    ORDER BY s.ask_session_dtime DESC
    LIMIT ? OFFSET ?
SQL);
$stmt->execute([$limit, $offset]);
$sessions = $stmt->fetchAll();

jsonResponse([
    'sessions'    => $sessions,
    'page'        => $page,
    'limit'       => $limit,
    'total'       => intval($total),
    'total_pages' => max(1, intval(ceil($total / $limit))),
]);
