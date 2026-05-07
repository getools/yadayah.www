<?php
/**
 * Admin endpoint for flipbook-wide settings (yy_setting scope='page', group='flipbook').
 * Currently exposes only the Auto Bookmark defaults (color, label, note) used
 * when rendering an auto bookmark whose individual fields are NULL.
 *
 *   GET — { code: value, ... }
 *   PUT — { code: value, ... }   UPSERTs each
 *
 * Mirrors api/admin-home.php exactly.
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db    = getDb();
$SCOPE = 'page';
$GROUP = 'flipbook';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = ? AND setting_group_code = ? ORDER BY setting_sort, setting_code");
    $stmt->execute([$SCOPE, $GROUP]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[$r['setting_code']] = $r['setting_value'];
    jsonResponse($out);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) errorResponse('Body must be a JSON object of {code: value}');
    $upd = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = ? AND setting_group_code = ? AND setting_code = ?");
    $ins = $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value_code, setting_value) VALUES (?, ?, ?, 'text', ?)");
    foreach ($input as $code => $value) {
        $upd->execute([$value, $SCOPE, $GROUP, $code]);
        if ($upd->rowCount() === 0) $ins->execute([$SCOPE, $GROUP, $code, $value]);
    }
    jsonResponse(['saved' => true]);
}

errorResponse('Method not allowed', 405);
