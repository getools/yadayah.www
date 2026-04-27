<?php
/**
 * Shared image upload helpers.
 * All admin image uploads save an original + a scaled copy.
 * Scaled dimensions come from yy_setting (page/chat: image-max-width, image-max-height).
 *
 * Usage:
 *   require_once __DIR__ . '/image-helpers.php';
 *   $result = processImageUpload($db, $_FILES['file'], $destDir, $namePrefix);
 *   // $result = ['original' => '/u/.../originals/file.jpg', 'scaled' => '/u/.../file.jpg', 'filename' => 'file.jpg']
 *
 *   rescaleAllImages($db, $destDir);
 *   // Regenerates all scaled copies from originals in $destDir/originals/
 */

function getImageMaxDimensions(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = $db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = 'chat' AND setting_code IN ('image-max-width', 'image-max-height')");
        $cfg = [];
        foreach ($stmt->fetchAll() as $r) $cfg[$r['setting_code']] = $r['setting_value'];
    } catch (Exception $e) { $cfg = []; }
    $cache = [
        'width'  => max(100, min(4000, (int)($cfg['image-max-width'] ?? 800))),
        'height' => max(100, min(4000, (int)($cfg['image-max-height'] ?? 600))),
    ];
    return $cache;
}

function imgLoad(string $path, string $ext) {
    switch ($ext) {
        case 'png':  return @imagecreatefrompng($path);
        case 'gif':  return @imagecreatefromgif($path);
        case 'webp': return @imagecreatefromwebp($path);
        default:     return @imagecreatefromjpeg($path);
    }
}

function imgSave($img, string $path, string $ext): void {
    switch ($ext) {
        case 'png':  imagepng($img, $path, 8); break;
        case 'gif':  imagegif($img, $path); break;
        case 'webp': imagewebp($img, $path, 85); break;
        default:     imagejpeg($img, $path, 85); break;
    }
}

function scaleImage(string $srcPath, string $destPath, int $maxW, int $maxH): bool {
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    $img = imgLoad($srcPath, $ext);
    if (!$img) return false;

    $origW = imagesx($img);
    $origH = imagesy($img);

    if ($origW <= $maxW && $origH <= $maxH) {
        // No scaling needed, just copy
        imagedestroy($img);
        return copy($srcPath, $destPath);
    }

    $ratio = min($maxW / $origW, $maxH / $origH);
    $newW = (int)round($origW * $ratio);
    $newH = (int)round($origH * $ratio);

    $scaled = imagecreatetruecolor($newW, $newH);
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $newW, $newH, $transparent);
    }
    imagecopyresampled($scaled, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    if (!is_dir(dirname($destPath))) {
        mkdir(dirname($destPath), 0775, true);
    }

    imgSave($scaled, $destPath, $ext);
    imagedestroy($img);
    imagedestroy($scaled);
    return true;
}

/**
 * Process an uploaded image file.
 *
 * @param PDO    $db
 * @param array  $file       Entry from $_FILES
 * @param string $destDir    Absolute filesystem path to destination directory
 * @param string $namePrefix Optional prefix for the generated filename
 * @return array|false  ['original'=>'...','scaled'=>'...','filename'=>'...'] or false on failure
 */
function processImageUpload(PDO $db, array $file, string $destDir, string $namePrefix = '') {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return false;
    }

    $allowed = ['jpg','jpeg','png','gif','webp'];
    $origName = $file['name'] ?? 'upload';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return false;
    }

    $uniqueName = ($namePrefix ? $namePrefix . '-' : '') . uniqid('', true) . '.' . $ext;

    $originalsDir = rtrim($destDir, '/') . '/originals';
    if (!is_dir($originalsDir)) {
        mkdir($originalsDir, 0755, true);
    }

    $originalPath = $originalsDir . '/' . $uniqueName;
    if (!move_uploaded_file($file['tmp_name'], $originalPath)) {
        return false;
    }

    $scaledPath = rtrim($destDir, '/') . '/' . $uniqueName;
    $dims = getImageMaxDimensions($db);
    $ok = scaleImage($originalPath, $scaledPath, $dims['width'], $dims['height']);
    if (!$ok) {
        // If scaling fails, use the original as the scaled copy
        copy($originalPath, $scaledPath);
    }

    return [
        'original' => $originalPath,
        'scaled'   => $scaledPath,
        'filename' => $uniqueName,
    ];
}

/**
 * Regenerate all scaled copies from originals.
 *
 * @param PDO    $db
 * @param string $destDir  Absolute filesystem path to destination directory
 * @return int  Number of images rescaled
 */
function rescaleAllImages(PDO $db, string $destDir): int {
    $originalsDir = rtrim($destDir, '/') . '/originals';
    if (!is_dir($originalsDir)) {
        return 0;
    }

    $dims = getImageMaxDimensions($db);
    $allowed = ['jpg','jpeg','png','gif','webp'];
    $count = 0;

    $files = scandir($originalsDir);
    if (!$files) return 0;

    foreach ($files as $fname) {
        if ($fname === '.' || $fname === '..') continue;
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;

        $srcPath  = $originalsDir . '/' . $fname;
        $destPath = rtrim($destDir, '/') . '/' . $fname;

        if (scaleImage($srcPath, $destPath, $dims['width'], $dims['height'])) {
            $count++;
        }
    }

    return $count;
}
