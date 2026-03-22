<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$stmt = $db->query("
    SELECT memorial_key, memorial_name_full, memorial_summary,
           memorial_image_file, memorial_image_url, memorial_bio_url
    FROM yy_memorial
    WHERE memorial_active_flag = true
    ORDER BY memorial_sort, memorial_key
");
jsonResponse(['items' => $stmt->fetchAll()]);
