<?php
/**
 * Community image upload API.
 * POST: upload an image for use in community posts.
 * Returns URL to the saved image. Requires login.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') errorResponse('Method not allowed', 405);

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded or upload error');
}

$file = $_FILES['image'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
    errorResponse('Invalid image type. Allowed: jpg, png, gif, webp');
}
if ($file['size'] > 5 * 1024 * 1024) {
    errorResponse('Image must be under 5MB');
}

$dir = __DIR__ . '/../u/community';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$filename = 'img_' . $userKey . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $dir . '/' . $filename;

// Load and resize to max 1200px wide
$src = null;
switch ($ext) {
    case 'png': $src = @imagecreatefrompng($file['tmp_name']); break;
    case 'gif': $src = @imagecreatefromgif($file['tmp_name']); break;
    case 'webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
    default: $src = @imagecreatefromjpeg($file['tmp_name']); break;
}

if ($src) {
    $w = imagesx($src);
    $h = imagesy($src);

    if ($w > 1200) {
        $newW = 1200;
        $newH = (int)round($h * (1200 / $w));
        $resized = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG/GIF
        if (in_array($ext, ['png', 'gif'])) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        $src = $resized;
    }

    // Save as original format
    switch ($ext) {
        case 'png': imagepng($src, $dest, 8); break;
        case 'gif': imagegif($src, $dest); break;
        case 'webp': imagewebp($src, $dest, 85); break;
        default: imagejpeg($src, $dest, 85); break;
    }
    imagedestroy($src);
} else {
    // Fallback: just move the file
    move_uploaded_file($file['tmp_name'], $dest);
}

$url = '/u/community/' . $filename;
jsonResponse(['url' => $url]);
