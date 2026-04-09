<?php
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__, 2) . '/api/image-helpers.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

if (empty($_FILES['image_file']) || $_FILES['image_file']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No file uploaded');
}

$db = getDb();
$destDir = __DIR__ . '/../u/resources';
$file = $_FILES['image_file'];

// Determine target height:
// 1. Explicit max_height from form
// 2. Context-based: look up page-image-height from the calling page's settings
// 3. Fall back to global defaults
$maxHeight = !empty($_POST['max_height']) ? (int)$_POST['max_height'] : null;

if (!$maxHeight && !empty($_POST['context'])) {
    $ctx = $_POST['context'];
    $stmt = $db->prepare("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = ? AND setting_code = 'page-image-height'");
    $stmt->execute([$ctx]);
    $dbHeight = $stmt->fetchColumn();
    if ($dbHeight) $maxHeight = (int)$dbHeight;
}

$result = processImageUpload($db, $file, $destDir, 'res_', null, $maxHeight);
if (!$result) errorResponse('Invalid image type');

jsonResponse(['path' => 'u/resources/' . $result['filename']]);
