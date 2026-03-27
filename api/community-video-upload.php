<?php
/**
 * Community video upload API.
 * POST: upload a video for use in community posts/DMs.
 * Returns URL to the saved video. Requires login.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

if (empty($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded or upload error');
}

$file = $_FILES['video'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowed = ['mp4', 'webm', 'mov', 'ogg', 'avi', 'mkv'];
if (!in_array($ext, $allowed)) {
    errorResponse('Invalid video type. Allowed: ' . implode(', ', $allowed));
}
if ($file['size'] > 100 * 1024 * 1024) {
    errorResponse('Video must be under 100MB');
}

$dir = __DIR__ . '/../u/community';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$filename = 'vid_' . $userKey . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $dir . '/' . $filename;

move_uploaded_file($file['tmp_name'], $dest);

$url = '/u/community/' . $filename;
jsonResponse(['url' => $url]);
