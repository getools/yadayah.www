<?php
require_once __DIR__ . '/config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$UPLOAD_DIR = __DIR__ . '/../u/resources';

if (empty($_FILES['image_file']) || $_FILES['image_file']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded');
}

$file = $_FILES['image_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
if (!in_array($ext, $allowed)) {
    errorResponse('Image must be: ' . implode(', ', $allowed));
}

if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0777, true);
    chmod($UPLOAD_DIR, 0777);
}
$safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
$dest = "$UPLOAD_DIR/$safeName";

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    errorResponse('Failed to save image');
}

jsonResponse(['path' => "u/resources/$safeName"]);
