<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

// Strip own domain from target URLs — store relative paths only
function stripOwnDomain(?string $url): ?string {
    if (!$url) return $url;
    return preg_replace('#^https?://(www\.)?(yadayah\.com|books\.yadayah\.com)#i', '', trim($url));
}

if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(10, (int)($_GET['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $filter = trim($_GET['filter'] ?? '');

    $where = [];
    $params = [];
    if ($filter === 'queue') {
        $where[] = 'redirect_active_flag IS NULL AND (redirect_attack_flag IS NULL OR redirect_attack_flag = FALSE)';
    } elseif ($filter === 'active') {
        $where[] = 'redirect_active_flag = TRUE AND (redirect_attack_flag IS NULL OR redirect_attack_flag = FALSE)';
    } elseif ($filter === 'inactive') {
        $where[] = 'redirect_active_flag = FALSE AND (redirect_attack_flag IS NULL OR redirect_attack_flag = FALSE)';
    } elseif ($filter === 'bot') {
        $where[] = 'redirect_attack_flag = TRUE';
    }
    if ($search) {
        $where[] = '(redirect_request ILIKE ? OR redirect_target ILIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $whereStr = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_redirect" . $whereStr);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sort = trim($_GET['sort'] ?? 'date');
    $dir = strtoupper(trim($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $sortCol = 'redirect_dtime';
    if ($sort === 'hits') $sortCol = 'redirect_hit_count';
    elseif ($sort === 'url') $sortCol = 'redirect_request';
    $stmt = $db->prepare("SELECT * FROM yy_redirect" . $whereStr . " ORDER BY $sortCol $dir NULLS LAST LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params, [$limit, $offset]));

    jsonResponse([
        'redirects' => $stmt->fetchAll(),
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => max(1, (int)ceil($total / $limit)),
    ]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['redirect_request'])) errorResponse('redirect_request required');

    $stmt = $db->prepare('INSERT INTO yy_redirect (redirect_request, redirect_target, redirect_active_flag)
        VALUES (:req, :tgt, :active)
        ON CONFLICT (redirect_request) DO UPDATE SET
            redirect_target = EXCLUDED.redirect_target,
            redirect_active_flag = EXCLUDED.redirect_active_flag
        RETURNING *');
    $stmt->execute([
        ':req' => $data['redirect_request'],
        ':tgt' => stripOwnDomain($data['redirect_target'] ?? null),
        ':active' => !array_key_exists('redirect_active_flag', $data) || $data['redirect_active_flag'] === null ? null : ($data['redirect_active_flag'] ? 't' : 'f'),
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
        $params[':tgt'] = stripOwnDomain($data['redirect_target']);
    }
    if (false) { // queue flag removed — queue status is inferred from empty target
    }
    if (array_key_exists('redirect_active_flag', $data)) {
        $fields[] = 'redirect_active_flag = :active';
        $val = $data['redirect_active_flag'];
        $params[':active'] = ($val === null) ? null : ($val ? 't' : 'f');
    }
    if (array_key_exists('redirect_attack_flag', $data)) {
        $fields[] = 'redirect_attack_flag = :attack';
        $val = $data['redirect_attack_flag'];
        $params[':attack'] = ($val === null) ? null : ($val ? 't' : 'f');
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
