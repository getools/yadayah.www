<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$VALID = ['', 'gemini-flash', 'gpt-4o-mini', 'claude-haiku', 'claude-sonnet'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_code = 'ask_model'");
    $model = $stmt->fetchColumn();

    $stmt2 = $db->query("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_code = 'ask_custom_prompt'");
    $prompt = $stmt2->fetchColumn();

    // Page settings (title, summary, ban-title, ban-message)
    $stmt3 = $db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = 'ask'");
    $page = [];
    foreach ($stmt3->fetchAll() as $r) { $page[$r['setting_code']] = $r['setting_value']; }

    jsonResponse([
        'model' => in_array($model, $VALID, true) ? $model : 'claude-sonnet',
        'custom_prompt' => $prompt ?: '',
        'page' => $page,
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
        // Invalidate page config cache so frontend picks up offline status
        $cacheFile = sys_get_temp_dir() . '/yada_ask_config.json';
        if (file_exists($cacheFile)) unlink($cacheFile);
    }

    // Save custom prompt if provided
    if (array_key_exists('custom_prompt', $input)) {
        $prompt = trim($input['custom_prompt'] ?? '');
        $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = 'app' AND setting_code = 'ask_custom_prompt'");
        $stmt->execute([$prompt]);
    }

    // Save page settings if provided
    if (isset($input['page']) && is_array($input['page'])) {
        $allowed = ['title', 'summary', 'placeholder', 'ban-title', 'ban-message', 'page-image', 'page-image-height'];
        foreach ($input['page'] as $code => $val) {
            if (in_array($code, $allowed)) {
                $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = 'page' AND setting_group_code = 'ask' AND setting_code = ?");
                $stmt->execute([trim($val), $code]);
            }
        }
        // Invalidate page config cache
        $cacheFile = sys_get_temp_dir() . '/yada_ask_config.json';
        if (file_exists($cacheFile)) unlink($cacheFile);
    }

    jsonResponse(['saved' => true]);
}

errorResponse('Method not allowed', 405);
