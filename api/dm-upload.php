<?php
/**
 * DM file upload API.
 * Handles images, videos, and documents for direct messages.
 * Files are stored in /u/messages/{thread_key}/ subdirectories.
 * For new threads (no thread_key yet), files go to /u/messages/tmp_{user_key}/
 * and are moved when the thread is created.
 *
 * POST params:
 *   - thread_key (optional): existing thread key for subdirectory
 *   - image OR video OR file: the uploaded file
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$threadKey = $_POST['thread_key'] ?? null;

// Build destination directory
$subdir = $threadKey ? (int)$threadKey : 'tmp_' . $userKey;
$baseDir = __DIR__ . '/../u/messages/' . $subdir;
$baseUrl = '/u/messages/' . $subdir;

// ── Image upload ──
if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image'];
    if ($file['size'] > 10 * 1024 * 1024) errorResponse('Image must be under 10MB');

    $db = getDb();
    $destDir = $baseDir;
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $result = processImageUpload($db, $file, $destDir, 'img_' . $userKey . '_');
    if (!$result) errorResponse('Invalid image type');

    jsonResponse(['url' => $baseUrl . '/' . $result['filename']]);
}

// ── Video upload ──
if (!empty($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['video'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowed = ['mp4', 'webm', 'mov', 'ogg', 'avi', 'mkv'];
    if (!in_array($ext, $allowed)) {
        errorResponse('Invalid video type. Allowed: ' . implode(', ', $allowed));
    }
    if ($file['size'] > 100 * 1024 * 1024) errorResponse('Video must be under 100MB');

    if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

    $filename = 'vid_' . $userKey . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $baseDir . '/' . $filename;
    move_uploaded_file($file['tmp_name'], $dest);

    jsonResponse(['url' => $baseUrl . '/' . $filename]);
}

// ── Document upload ──
if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['file'];
    $maxSize = 20 * 1024 * 1024;
    if ($file['size'] > $maxSize) errorResponse('File must be under 20MB');

    $allowed = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt'  => ['text/plain'],
        'rtf'  => ['application/rtf', 'text/rtf'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        errorResponse('File type not allowed: .' . $ext);
    }

    $docsDir = $baseDir . '/docs';
    if (!is_dir($docsDir)) mkdir($docsDir, 0775, true);

    $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $safeName = substr($safeName, 0, 80);
    $filename = 'doc_' . $userKey . '_' . dechex(time()) . '_' . $safeName . '.' . $ext;

    $destPath = $docsDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        errorResponse('Failed to save file');
    }

    jsonResponse(['url' => $baseUrl . '/docs/' . $filename, 'name' => $origName]);
}

errorResponse('No file uploaded or upload error');
