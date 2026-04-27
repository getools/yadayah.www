<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$KEYS = ['memorial_title', 'memorial_subtitle', 'memorial_columns', 'memorial_auto_scroll'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = [];
    foreach ($KEYS as $key) {
        $stmt = $db->prepare("SELECT setting_value FROM yy_setting WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result[$key] = $stmt->fetchColumn() ?: '';
    }
    jsonResponse($result);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    foreach ($KEYS as $key) {
        if (array_key_exists($key, $input)) {
            $stmt = $db->prepare("
                INSERT INTO yy_setting (setting_key, setting_value, setting_dtime)
                VALUES (?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, setting_dtime = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$key, trim($input[$key] ?? '')]);
        }
    }
    jsonResponse(['saved' => true]);
}

errorResponse('Method not allowed', 405);
