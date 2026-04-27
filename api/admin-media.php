<?php
require_once __DIR__ . '/config.php';
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDb();

switch ($method) {
    case 'GET':
        $stmt = $db->query("
            SELECT m.media_key, m.icon_key, m.media_code, m.media_link, m.media_sort, m.media_active_flag,
                   i.icon_name, i.icon_svg
            FROM yy_media m
            LEFT JOIN yy_icon i ON i.icon_key = m.icon_key
            ORDER BY m.media_sort, m.media_key
        ");
        $icons = $db->query("SELECT icon_key, icon_name FROM yy_icon WHERE icon_active_flag = TRUE ORDER BY icon_sort")->fetchAll();
        jsonResponse(['items' => $stmt->fetchAll(), 'icons' => $icons]);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $db->prepare("INSERT INTO yy_media (icon_key, media_code, media_link, media_sort, media_active_flag) VALUES (?, ?, ?, ?, ?) RETURNING media_key");
        $stmt->execute([
            $data['icon_key'] ?: null,
            $data['media_code'] ?? '',
            $data['media_link'] ?? '',
            intval($data['sort'] ?? 0),
            ($data['active'] ?? true) ? true : false,
        ]);
        $key = $stmt->fetchColumn();
        jsonResponse(['saved' => true, 'key' => $key]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['key'])) errorResponse('Key is required');
        $stmt = $db->prepare("UPDATE yy_media SET icon_key = ?, media_code = ?, media_link = ?, media_sort = ?, media_active_flag = ? WHERE media_key = ?");
        $stmt->execute([
            $data['icon_key'] ?: null,
            $data['media_code'] ?? '',
            $data['media_link'] ?? '',
            intval($data['sort'] ?? 0),
            ($data['active'] ?? true) ? true : false,
            $data['key'],
        ]);
        jsonResponse(['saved' => true]);
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['key'])) errorResponse('Key is required');
        $stmt = $db->prepare("DELETE FROM yy_media WHERE media_key = ?");
        $stmt->execute([$data['key']]);
        jsonResponse(['deleted' => true]);
        break;

    default:
        errorResponse('Method not allowed', 405);
}
