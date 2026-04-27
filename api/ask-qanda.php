<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

// GET — list all Q&A records
if ($method === 'GET') {
    $stmt = $db->query('SELECT * FROM yy_ask_qanda ORDER BY ask_qanda_sort, ask_qanda_key');
    jsonResponse(['items' => $stmt->fetchAll()]);
}

// POST — create new Q&A
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['question']) || empty($input['answer'])) {
        errorResponse('Question and answer are required', 400);
    }
    $stmt = $db->prepare('INSERT INTO yy_ask_qanda (ask_qanda_question, ask_qanda_answer, ask_qanda_sort, ask_qanda_active_flag) VALUES (?, ?, ?, ?) RETURNING ask_qanda_key');
    $stmt->execute([
        trim($input['question']),
        trim($input['answer']),
        intval($input['sort'] ?? 0),
        ($input['active'] ?? true) ? true : false,
    ]);
    jsonResponse(['saved' => true, 'key' => (int)$stmt->fetchColumn()]);
}

// PUT — update existing Q&A
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['key'])) errorResponse('Key is required', 400);
    if (empty($input['question']) || empty($input['answer'])) {
        errorResponse('Question and answer are required', 400);
    }
    $stmt = $db->prepare('UPDATE yy_ask_qanda SET ask_qanda_question = ?, ask_qanda_answer = ?, ask_qanda_sort = ?, ask_qanda_active_flag = ? WHERE ask_qanda_key = ?');
    $stmt->execute([
        trim($input['question']),
        trim($input['answer']),
        intval($input['sort'] ?? 0),
        ($input['active'] ?? true) ? true : false,
        intval($input['key']),
    ]);
    jsonResponse(['saved' => true]);
}

// DELETE — delete Q&A
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['key'])) errorResponse('Key is required', 400);
    $stmt = $db->prepare('DELETE FROM yy_ask_qanda WHERE ask_qanda_key = ?');
    $stmt->execute([intval($input['key'])]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
