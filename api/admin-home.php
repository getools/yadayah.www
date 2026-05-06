<?php
/**
 * Admin endpoint for the home-page settings (yy_setting scope='page', group='home').
 *
 * GET — return all current home settings as { code: value, ... }
 * PUT — body { code: value, ... } — UPSERTs each, then busts the public
 *       site-config.php cache so the change shows up on next page load.
 *
 * Mirrors api/admin-doyouyada-config.php (same scope/group convention).
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$SCOPE = 'page';
$GROUP = 'home';
$SITE_CACHE = sys_get_temp_dir() . '/yada_site_config.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = ? AND setting_group_code = ? ORDER BY setting_sort, setting_code");
    $stmt->execute([$SCOPE, $GROUP]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['setting_code']] = $row['setting_value'];
    }
    jsonResponse($result);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) errorResponse('Body must be a JSON object of {code: value}');
    $upd = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = ? AND setting_group_code = ? AND setting_code = ?");
    $ins = $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value_code, setting_value) VALUES (?, ?, ?, 'text', ?)");
    foreach ($input as $code => $value) {
        $upd->execute([$value, $SCOPE, $GROUP, $code]);
        if ($upd->rowCount() === 0) {
            $ins->execute([$SCOPE, $GROUP, $code, $value]);
        }
    }
    // Bust the public site-config cache so the homepage picks up the
    // new values on its next load.
    if (file_exists($SITE_CACHE)) @unlink($SITE_CACHE);
    jsonResponse(['saved' => true]);
}

errorResponse('Method not allowed', 405);
