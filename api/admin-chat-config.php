<?php
/**
 * Admin Chat/Community configuration API.
 * GET: returns all chat settings + categories
 * PUT: updates chat settings
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_key'])) errorResponse('Auth required', 401);

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Load chat settings
    $stmt = $db->query("
        SELECT setting_key, setting_code, setting_label, setting_value_code, setting_value, setting_sort
        FROM yy_setting
        WHERE setting_scope_code = 'page' AND setting_group_code = 'chat'
        ORDER BY setting_sort
    ");
    $settings = $stmt->fetchAll();

    // Load categories
    $cats = $db->query("
        SELECT category_key, category_name, category_slug, category_description,
               category_color, category_sort, category_active_flag
        FROM yy_community_category
        ORDER BY category_sort, category_name
    ")->fetchAll();

    jsonResponse(['settings' => $settings, 'categories' => $cats]);
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    // Update settings
    if (!empty($input['settings']) && is_array($input['settings'])) {
        $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_key = ? AND setting_scope_code = 'page' AND setting_group_code = 'chat'");
        foreach ($input['settings'] as $s) {
            if (isset($s['setting_key']) && isset($s['setting_value'])) {
                $stmt->execute([trim($s['setting_value']), (int)$s['setting_key']]);
            }
        }
    }

    jsonResponse(['saved' => true]);
}

errorResponse('Method not allowed', 405);
