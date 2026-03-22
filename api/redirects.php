<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $queue = isset($_GET['queue']) ? $_GET['queue'] === '1' : null;
    $sql = 'SELECT * FROM yy_redirect';
    $params = [];
    if ($queue !== null) {
        $sql .= ' WHERE redirect_queue_flag = :q';
        $params[':q'] = $queue ? 't' : 'f';
    }
    $sql .= ' ORDER BY redirect_dtime DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['redirect_request'])) errorResponse('redirect_request required');

    $stmt = $db->prepare('INSERT INTO yy_redirect (redirect_request, redirect_target, redirect_queue_flag, redirect_active_flag)
        VALUES (:req, :tgt, :queue, :active)
        ON CONFLICT (redirect_request) DO UPDATE SET
            redirect_target = EXCLUDED.redirect_target,
            redirect_queue_flag = EXCLUDED.redirect_queue_flag,
            redirect_active_flag = EXCLUDED.redirect_active_flag
        RETURNING *');
    $stmt->execute([
        ':req' => $data['redirect_request'],
        ':tgt' => $data['redirect_target'] ?? null,
        ':queue' => ($data['redirect_queue_flag'] ?? false) ? 't' : 'f',
        ':active' => ($data['redirect_active_flag'] ?? false) ? 't' : 'f',
    ]);
    jsonResponse($stmt->fetch());
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['redirect_key'])) errorResponse('redirect_key required');

    $fields = [];
    $params = [':key' => $data['redirect_key']];

    if (array_key_exists('redirect_request', $data)) {
        $fields[] = 'redirect_request = :req';
        $params[':req'] = $data['redirect_request'];
    }
    if (array_key_exists('redirect_target', $data)) {
        $fields[] = 'redirect_target = :tgt';
        $params[':tgt'] = $data['redirect_target'];
    }
    if (array_key_exists('redirect_queue_flag', $data)) {
        $fields[] = 'redirect_queue_flag = :queue';
        $params[':queue'] = $data['redirect_queue_flag'] ? 't' : 'f';
    }
    if (array_key_exists('redirect_active_flag', $data)) {
        $fields[] = 'redirect_active_flag = :active';
        $params[':active'] = $data['redirect_active_flag'] ? 't' : 'f';
    }

    if (empty($fields)) errorResponse('No fields to update');

    $stmt = $db->prepare('UPDATE yy_redirect SET ' . implode(', ', $fields) . ' WHERE redirect_key = :key RETURNING *');
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) errorResponse('Not found', 404);
    jsonResponse($row);
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['redirect_key'])) errorResponse('redirect_key required');

    $stmt = $db->prepare('DELETE FROM yy_redirect WHERE redirect_key = :key');
    $stmt->execute([':key' => $data['redirect_key']]);
    jsonResponse(['deleted' => $stmt->rowCount()]);
}
