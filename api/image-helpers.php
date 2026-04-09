<?php
/**
 * Shared image upload helpers.
 * All admin image uploads save an original + a scaled copy.
 * Scaled dimensions come from yy_setting (page/chat: image-max-width, image-max-height).
 *
 * Usage:
 *   require_once __DIR__ . '/image-helpers.php';
 *   $result = processImageUpload($db, $file, $destDir, $filenamePrefix);
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

function imgFixExif($img, string $path, string $ext) {
    if (!in_array($ext, ['jpg', 'jpeg']) || !function_exists('exif_read_data')) return $img;
    $exif = @exif_read_data($path);
    if (!$exif || empty($exif['Orientation'])) return $img;
    switch ($exif['Orientation']) {
        case 3: return imagerotate($img, 180, 0);
        case 6: return imagerotate($img, -90, 0);
        case 8: return imagerotate($img, 90, 0);
    }
    return $img;
}

/**
 * Create a scaled copy that fits within maxW x maxH, preserving aspect ratio.
 * Never upscales. Returns true on success.
 */
function imgCreateScaled(string $origPath, string $scaledPath, string $ext, int $maxW, int $maxH): bool {
    // SVG: just copy as-is
    if ($ext === 'svg') return copy($origPath, $scaledPath);

    $src = imgLoad($origPath, $ext);
    if (!$src) return copy($origPath, $scaledPath);

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min($maxW / max($w, 1), $maxH / max($h, 1), 1.0);
    $newW = (int)round($w * $scale);
    $newH = (int)round($h * $scale);

    if ($newW === $w && $newH === $h) {
        imagedestroy($src);
        return copy($origPath, $scaledPath);
    }

    $resized = imagecreatetruecolor($newW, $newH);
    if (in_array($ext, ['png', 'gif'])) {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
    }
    imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
    imagedestroy($src);
    imgSave($resized, $scaledPath, $ext);
    imagedestroy($resized);
    return true;
}

/**
 * Process an uploaded image file: fix EXIF, save original, create scaled copy.
 * Returns ['original' => path, 'scaled' => path, 'filename' => name] or null on failure.
 *
 * @param PDO $db Database connection (for reading max dimensions)
 * @param array $file $_FILES entry
 * @param string $destDir Absolute path to the scaled output directory (e.g. /var/www/html/u/timeline)
 * @param string $prefix Filename prefix (e.g. 'img_25_')
 * @param string|null $forceFilename Use this exact filename instead of generating one
 * @param int|null $maxHeight Override max height (null = use global setting). Width auto-calculated to preserve aspect ratio.
 */
function processImageUpload(PDO $db, array $file, string $destDir, string $prefix = 'img_', ?string $forceFilename = null, ?int $maxHeight = null): ?array {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) return null;

    $origDir = $destDir . '/originals';
    if (!is_dir($origDir)) mkdir($origDir, 0755, true);
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $filename = $forceFilename ?: ($prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext);
    $origPath = $origDir . '/' . $filename;
    $scaledPath = $destDir . '/' . $filename;

    if ($ext === 'svg') {
        move_uploaded_file($file['tmp_name'], $origPath);
        copy($origPath, $scaledPath);
    } else {
        $src = imgLoad($file['tmp_name'], $ext);
        if ($src) {
            $src = imgFixExif($src, $file['tmp_name'], $ext);
            imgSave($src, $origPath, $ext);
            imagedestroy($src);
        } else {
            move_uploaded_file($file['tmp_name'], $origPath);
        }

        if ($maxHeight) {
            imgCreateScaled($origPath, $scaledPath, $ext, 99999, $maxHeight);
        } else {
            $dims = getImageMaxDimensions($db);
            imgCreateScaled($origPath, $scaledPath, $ext, $dims['width'], $dims['height']);
        }
    }

    return ['original' => $origPath, 'scaled' => $scaledPath, 'filename' => $filename];
}

/**
 * Rescale all originals in a directory using current settings.
 * Returns count of images rescaled.
 */
function rescaleAllImages(PDO $db, string $destDir): int {
    $origDir = $destDir . '/originals';
    if (!is_dir($origDir)) return 0;

    $dims = getImageMaxDimensions($db);
    $count = 0;
    foreach (glob($origDir . '/*') as $origPath) {
        $filename = basename($origPath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) continue;
        $scaledPath = $destDir . '/' . $filename;
        if (imgCreateScaled($origPath, $scaledPath, $ext, $dims['width'], $dims['height'])) {
            $count++;
        }
    }
    return $count;
}
