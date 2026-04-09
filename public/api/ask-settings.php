<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$VALID = ['gemini-flash', 'gpt-4o-mini', 'claude-haiku', 'claude-sonnet'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_code = 'ask_model'");
    $model = $stmt->fetchColumn();

    $stmt2 = $db->query("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_code = 'ask_custom_prompt'");
    $prompt = $stmt2->fetchColumn();

    jsonResponse([
        'model' => in_array($model, $VALID) ? $model : 'claude-sonnet',
        'custom_prompt' => $prompt ?: '',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);

    // Save model if provided
    if (isset($input['model'])) {
        $model = $input['model'];
        if (!in_array($model, $VALID)) {
            errorResponse('Invalid model. Must be one of: ' . implode(', ', $VALID));
        }
        $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = 'app' AND setting_code = 'ask_model'");
        $stmt->execute([$model]);
    }

    // Save custom prompt if provided
    if (array_key_exists('custom_prompt', $input)) {
        $prompt = trim($input['custom_prompt'] ?? '');
        $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = 'app' AND setting_code = 'ask_custom_prompt'");
        $stmt->execute([$prompt]);
    }

    jsonResponse(['saved' => true]);
}

errorResponse('Method not allowed', 405);
