<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No image uploaded', 400);
}

$key = intval($_POST['key'] ?? 0);
if (!$key) errorResponse('Memorial key is required', 400);

$db = getDb();
$stmt = $db->prepare("SELECT memorial_name_full FROM yy_memorial WHERE memorial_key = ?");
$stmt->execute([$key]);
$name = $stmt->fetchColumn();
if (!$name) errorResponse('Memorial not found', 404);

$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
$ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
$filename = $key . '_' . $safeName . '.' . ($ext ?: 'jpg');

$destDir = '/var/www/html/u/10-7-memorial';
$result = processImageUpload($db, $_FILES['image'], $destDir, '', $filename);
if (!$result) errorResponse('Invalid image type');

$stmt = $db->prepare("UPDATE yy_memorial SET memorial_image_file = ? WHERE memorial_key = ?");
$stmt->execute([$result['filename'], $key]);

jsonResponse(['saved' => true, 'filename' => $result['filename']]);
