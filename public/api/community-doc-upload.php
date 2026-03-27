<?php
/**
 * Community document upload API.
 * POST: upload a document for use in community posts.
 * Returns URL to the saved file. Requires login.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded or upload error');
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv', 'zip'];
if (!in_array($ext, $allowed)) {
    errorResponse('Allowed types: ' . implode(', ', $allowed));
}

if ($file['size'] > 20 * 1024 * 1024) {
    errorResponse('File must be under 20MB');
}

$dir = __DIR__ . '/../u/community/docs';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$filename = 'doc_' . $userKey . '_' . time() . '_' . $safeName . '.' . $ext;
$dest = $dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    errorResponse('Failed to save file');
}

jsonResponse(['url' => '/u/community/docs/' . $filename, 'name' => $file['name']]);
