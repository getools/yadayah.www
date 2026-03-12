<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$VALID = ['gemini-flash', 'gpt-4o-mini', 'claude-haiku', 'claude-sonnet'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT setting_value FROM yy_setting WHERE setting_key = 'ask_model'");
    $val = $stmt->fetchColumn();
    jsonResponse(['model' => in_array($val, $VALID) ? $val : 'claude-sonnet']);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $model = $input['model'] ?? '';
    if (!in_array($model, $VALID)) {
        errorResponse('Invalid model. Must be one of: ' . implode(', ', $VALID));
    }
    $stmt = $db->prepare("
        INSERT INTO yy_setting (setting_key, setting_value, setting_dtime)
        VALUES ('ask_model', ?, CURRENT_TIMESTAMP)
        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, setting_dtime = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$model]);
    jsonResponse(['model' => $model, 'saved' => true]);
}

errorResponse('Method not allowed', 405);
