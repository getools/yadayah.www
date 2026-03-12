<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

$UPLOAD_DIR = __DIR__ . '/../public/u/backgrounds/';

switch ($method) {

case 'GET':
    $stmt = $db->query("SELECT asset_key, asset_name, asset_file, asset_active_flag, asset_sort FROM yy_asset WHERE asset_group_code = 'headerfooter' ORDER BY asset_sort, asset_name");
    jsonResponse($stmt->fetchAll());

case 'POST':
    $name = trim($_POST['asset_name'] ?? '');
    if ($name === '') errorResponse('Name is required');

    $active = ($_POST['asset_active_flag'] ?? '1') === '1';
    $filePath = null;

    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $filePath = handleUpload($_FILES['file']);
    }

    $stmt = $db->prepare("INSERT INTO yy_asset (asset_group_code, asset_name, asset_file, asset_active_flag, user_key) VALUES ('headerfooter', ?, ?, ?, ?) RETURNING asset_key");
    $stmt->execute([$name, $filePath, $active ? 't' : 'f', $user['user_key']]);
    $row = $stmt->fetch();
    jsonResponse(['asset_key' => $row['asset_key']], 201);

case 'PUT':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    $existing = $db->prepare("SELECT asset_key, asset_file FROM yy_asset WHERE asset_key = ? AND asset_group_code = 'headerfooter'");
    $existing->execute([$key]);
    $row = $existing->fetch();
    if (!$row) errorResponse('Not found', 404);

    $name = trim($_POST['asset_name'] ?? '');
    if ($name === '') errorResponse('Name is required');

    $active = ($_POST['asset_active_flag'] ?? '1') === '1';
    $filePath = $row['asset_file'];

    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        if ($filePath) deleteFile($filePath);
        $filePath = handleUpload($_FILES['file']);
    }

    $stmt = $db->prepare("UPDATE yy_asset SET asset_name = ?, asset_file = ?, asset_active_flag = ?, user_key = ? WHERE asset_key = ?");
    $stmt->execute([$name, $filePath, $active ? 't' : 'f', $user['user_key'], $key]);
    jsonResponse(['ok' => true]);

case 'DELETE':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    $existing = $db->prepare("SELECT asset_file FROM yy_asset WHERE asset_key = ? AND asset_group_code = 'headerfooter'");
    $existing->execute([$key]);
    $row = $existing->fetch();
    if (!$row) errorResponse('Not found', 404);

    if ($row['asset_file']) deleteFile($row['asset_file']);

    $db->prepare("DELETE FROM yy_asset WHERE asset_key = ?")->execute([$key]);
    jsonResponse(['ok' => true]);

default:
    errorResponse('Method not allowed', 405);
}

function handleUpload(array $file): string {
    global $UPLOAD_DIR;
    if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $imageTypes = ['jpg','jpeg','png','gif','webp','svg'];
    $videoTypes = ['mp4','mov','avi','mkv','wmv','flv','webm'];
    $allowed = array_merge($imageTypes, $videoTypes);
    if (!in_array($ext, $allowed)) {
        errorResponse('File type not allowed. Allowed: ' . implode(', ', $allowed));
    }

    $tmpPath = $UPLOAD_DIR . uniqid('tmp_') . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
        errorResponse('Upload failed');
    }

    if (in_array($ext, $videoTypes)) {
        $filename = uniqid('bg_') . '.webm';
        $dest = $UPLOAD_DIR . $filename;
        $tmpEsc = escapeshellarg($tmpPath);
        $destEsc = escapeshellarg($dest);
        $cmd = "ffmpeg -i $tmpEsc -c:v libvpx-vp9 -crf 30 -b:v 0 -an -y $destEsc 2>&1";
        exec($cmd, $output, $exitCode);
        unlink($tmpPath);
        if ($exitCode !== 0) {
            errorResponse('Video conversion failed: ' . implode("\n", array_slice($output, -5)));
        }
    } else {
        $filename = uniqid('bg_') . '.' . $ext;
        $dest = $UPLOAD_DIR . $filename;
        rename($tmpPath, $dest);
    }

    return 'u/backgrounds/' . $filename;
}

function deleteFile(string $relPath): void {
    $full = __DIR__ . '/../public/' . $relPath;
    if (file_exists($full)) unlink($full);
}
