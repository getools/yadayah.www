<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$UPLOAD_DIR   = __DIR__ . '/../u/logo';
$CONFIG_CACHE = sys_get_temp_dir() . '/yada_site_config.json';
$NAV_CACHE    = sys_get_temp_dir() . '/yada_page_nav.json';

$db = getDb();

// Rescale action (when height is changed in admin)
$input = json_decode(file_get_contents('php://input'), true);
if (($input['action'] ?? '') === 'rescale') {
    $hStmt = $db->prepare("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'config' AND setting_code = 'logo-height'");
    $hStmt->execute();
    $targetH = (int)$hStmt->fetchColumn() ?: null;

    $origDir = $UPLOAD_DIR . '/originals';
    if (!is_dir($origDir)) errorResponse('No originals found');
    $count = 0;
    foreach (glob($origDir . '/*') as $origPath) {
        $filename = basename($origPath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) continue;
        $scaledPath = $UPLOAD_DIR . '/' . $filename;
        if ($targetH) {
            imgCreateScaled($origPath, $scaledPath, $ext, 99999, $targetH);
        } else {
            $dims = getImageMaxDimensions($db);
            imgCreateScaled($origPath, $scaledPath, $ext, $dims['width'], $dims['height']);
        }
        $count++;
    }
    if (file_exists($CONFIG_CACHE)) @unlink($CONFIG_CACHE);
    if (file_exists($NAV_CACHE))    @unlink($NAV_CACHE);
    jsonResponse(['rescaled' => true, 'count' => $count]);
}

// Upload
if (empty($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded');
}

// Use max_height from form if provided, otherwise from DB setting
$targetH = !empty($_POST['max_height']) ? (int)$_POST['max_height'] : null;
if (!$targetH) {
    $hStmt = $db->prepare("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'config' AND setting_code = 'logo-height'");
    $hStmt->execute();
    $targetH = (int)$hStmt->fetchColumn() ?: null;
}

$result = processImageUpload($db, $_FILES['logo_file'], $UPLOAD_DIR, 'logo_', null, $targetH);
if (!$result) errorResponse('Invalid image type');

$path = '/u/logo/' . $result['filename'];

// Upsert logo setting
$stmt = $db->prepare("SELECT setting_key FROM yy_setting WHERE setting_scope_code = 'config' AND setting_code = 'logo'");
$stmt->execute();
$existing = $stmt->fetchColumn();
if ($existing) {
    $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_key = ?")->execute([$path, $existing]);
} else {
    $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_label, setting_value_code, setting_sort, setting_value) VALUES ('config', '', 'logo', 'Logo', 'image', 10, ?)")->execute([$path]);
}

if (file_exists($CONFIG_CACHE)) @unlink($CONFIG_CACHE);
if (file_exists($NAV_CACHE))    @unlink($NAV_CACHE);

jsonResponse(['path' => $path]);
