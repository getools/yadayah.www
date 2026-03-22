<?php
require_once __DIR__ . '/config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No image uploaded', 400);
}

$key = intval($_POST['key'] ?? 0);
if (!$key) {
    errorResponse('Memorial key is required', 400);
}

$file = $_FILES['image'];
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed)) {
    errorResponse('Invalid file type. Allowed: jpg, png, webp, gif', 400);
}

$ext = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'image/gif' => '.gif'];
$extension = $ext[$mime] ?? '.jpg';

// Build filename from memorial name
$db = getDb();
$stmt = $db->prepare("SELECT memorial_name_full FROM yy_memorial WHERE memorial_key = ?");
$stmt->execute([$key]);
$name = $stmt->fetchColumn();
if (!$name) {
    errorResponse('Memorial not found', 404);
}

$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
$filename = $key . '_' . $safeName . $extension;

$destDir = '/var/www/html/u/10-7-memorial';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}

$destPath = $destDir . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    errorResponse('Failed to save file', 500);
}

// Update database
$stmt = $db->prepare("UPDATE yy_memorial SET memorial_image_file = ? WHERE memorial_key = ?");
$stmt->execute([$filename, $key]);

jsonResponse(['saved' => true, 'filename' => $filename]);
