<?php
require_once __DIR__ . '/config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$UPLOAD_DIR  = __DIR__ . '/../u/logo';
$CONFIG_CACHE = sys_get_temp_dir() . '/yada_site_config.json';
$NAV_CACHE    = sys_get_temp_dir() . '/yada_page_nav.json';

if (empty($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded');
}

$file = $_FILES['logo_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
if (!in_array($ext, $allowed)) {
    errorResponse('Image must be: ' . implode(', ', $allowed));
}

if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0777, true);
    chmod($UPLOAD_DIR, 0777);
}

$safeName = time() . '.' . $ext;
$dest = "$UPLOAD_DIR/$safeName";
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    errorResponse('Failed to save image');
}

$path = '/u/logo/' . $safeName;

$db = getDb();

// Resize image to configured logo-height if set (skip SVG)
if ($ext !== 'svg') {
    $hStmt = $db->prepare("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'config' AND setting_code = 'logo-height'");
    $hStmt->execute();
    $targetH = (int) $hStmt->fetchColumn();
    if ($targetH > 0) {
        $info = @getimagesize($dest);
        if ($info) {
            $origW = $info[0]; $origH = $info[1]; $mime = $info['mime'];
            if ($origH > $targetH) {
                $targetW = (int) round($origW * ($targetH / $origH));
                $src = null;
                switch ($mime) {
                    case 'image/jpeg': $src = imagecreatefromjpeg($dest); break;
                    case 'image/png':  $src = imagecreatefrompng($dest);  break;
                    case 'image/gif':  $src = imagecreatefromgif($dest);  break;
                    case 'image/webp': $src = imagecreatefromwebp($dest); break;
                }
                if ($src) {
                    $dst = imagecreatetruecolor($targetW, $targetH);
                    // Preserve transparency for PNG/GIF/WebP
                    if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
                        imagealphablending($dst, false);
                        imagesavealpha($dst, true);
                        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                        imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
                    }
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);
                    switch ($mime) {
                        case 'image/jpeg': imagejpeg($dst, $dest, 90); break;
                        case 'image/png':  imagepng($dst, $dest);      break;
                        case 'image/gif':  imagegif($dst, $dest);      break;
                        case 'image/webp': imagewebp($dst, $dest, 90); break;
                    }
                    imagedestroy($src);
                    imagedestroy($dst);
                }
            }
        }
    }
}

// Check if the logo setting already exists
$stmt = $db->prepare("SELECT setting_key FROM yy_setting WHERE setting_scope_code = 'config' AND setting_code = 'logo'");
$stmt->execute();
$existing = $stmt->fetchColumn();

if ($existing) {
    $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_key = ?");
    $stmt->execute([$path, $existing]);
} else {
    $stmt = $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_label, setting_value_code, setting_sort, setting_value) VALUES ('config', '', 'logo', 'Logo', 'image', 10, ?)");
    $stmt->execute([$path]);
}

// Bust caches
if (file_exists($CONFIG_CACHE)) @unlink($CONFIG_CACHE);
if (file_exists($NAV_CACHE))    @unlink($NAV_CACHE);

jsonResponse(['path' => $path]);
