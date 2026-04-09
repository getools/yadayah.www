<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
$user = requireAuth();
$db = getDb();

$UPLOAD_DIR = __DIR__ . '/../u/timeline';

$method = $_SERVER['REQUEST_METHOD'];
// Support _method override for PUT via POST (PHP doesn't parse multipart for PUT)
if ($method === 'POST' && !empty($_POST['_method']) && strtoupper($_POST['_method']) === 'PUT') {
    $method = 'PUT';
}

if ($method === 'GET') {
    if (isset($_GET['key'])) {
        $stmt = $db->prepare("SELECT * FROM yy_timeline WHERE timeline_key = ?");
        $stmt->execute([(int)$_GET['key']]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Record not found', 404);
        jsonResponse($row);
    }
    $stmt = $db->query("
        SELECT timeline_key, timeline_sort, timeline_headline, timeline_date_yah, timeline_date_ce,
               timeline_image, timeline_video, timeline_priority, timeline_dtime
        FROM yy_timeline ORDER BY timeline_sort ASC
    ");
    jsonResponse(['events' => $stmt->fetchAll()]);
}

if ($method === 'POST' || $method === 'PUT') {
    if ($method === 'PUT' && (empty($_GET['key']) || !ctype_digit($_GET['key']))) {
        errorResponse('key is required', 400);
    }

    // Handle both multipart/form-data (file uploads) and JSON
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
    }

    // Validate required fields
    $errors = [];
    if (empty($data['timeline_headline'])) $errors[] = 'Headline is required.';
    if (!isset($data['timeline_sort']) || $data['timeline_sort'] === '') $errors[] = 'Sort order is required.';
    if (!empty($errors)) jsonResponse(['errors' => $errors], 422);

    // Handle image upload
    $imageValue = $data['timeline_image'] ?? null;
    if (!empty($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        $result = processImageUpload($db, $_FILES['image_file'], $UPLOAD_DIR, 'tl_');
        if (!$result) errorResponse('Invalid image type');
        $imageValue = "u/timeline/" . $result['filename'];
    }

    // Video: use URL/path (upload+conversion handled by admin-timeline-upload-video.php)
    $videoValue = $data['timeline_video'] ?? null;

    // Treat empty strings as null
    $dateYah = !empty($data['timeline_date_yah']) ? $data['timeline_date_yah'] : null;
    $dateCe = !empty($data['timeline_date_ce']) ? $data['timeline_date_ce'] : null;
    $summary = !empty($data['timeline_summary']) ? $data['timeline_summary'] : null;
    $imageValue = !empty($imageValue) ? $imageValue : null;
    $videoValue = !empty($videoValue) ? $videoValue : null;
    $priority = isset($data['timeline_priority']) && $data['timeline_priority'] !== '' ? (int)$data['timeline_priority'] : 0;
    $jump = !empty($data['timeline_jump']) ? $data['timeline_jump'] : null;

    if ($method === 'POST') {
        $stmt = $db->prepare("
            INSERT INTO yy_timeline (timeline_sort, timeline_headline, timeline_date_yah, timeline_date_ce,
                                     timeline_summary, timeline_image, timeline_video, timeline_priority, timeline_jump, user_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (float)$data['timeline_sort'], $data['timeline_headline'], $dateYah, $dateCe,
            $summary, $imageValue, $videoValue, $priority, $jump, $user['user_key']
        ]);
        $newKey = $db->lastInsertId('yy_timeline_timeline_key_seq');
        $stmt = $db->prepare("SELECT * FROM yy_timeline WHERE timeline_key = ?");
        $stmt->execute([(int)$newKey]);
        jsonResponse($stmt->fetch(), 201);
    } else {
        $key = (int)$_GET['key'];
        $stmt = $db->prepare("
            UPDATE yy_timeline SET timeline_sort = ?, timeline_headline = ?, timeline_date_yah = ?,
                   timeline_date_ce = ?, timeline_summary = ?, timeline_image = ?, timeline_video = ?,
                   timeline_priority = ?, timeline_jump = ?, user_key = ?
            WHERE timeline_key = ?
        ");
        $stmt->execute([
            (float)$data['timeline_sort'], $data['timeline_headline'], $dateYah, $dateCe,
            $summary, $imageValue, $videoValue, $priority, $jump, $user['user_key'], $key
        ]);
        $stmt = $db->prepare("SELECT * FROM yy_timeline WHERE timeline_key = ?");
        $stmt->execute([$key]);
        jsonResponse($stmt->fetch());
    }
}

if ($method === 'DELETE') {
    if (empty($_GET['key']) || !ctype_digit($_GET['key'])) {
        errorResponse('key is required', 400);
    }
    $stmt = $db->prepare('DELETE FROM yy_timeline WHERE timeline_key = ?');
    $stmt->execute([(int)$_GET['key']]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
