<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();

$stmt = $db->query("
    SELECT timeline_key, timeline_date_yah, timeline_date_ce,
           timeline_headline, timeline_summary, timeline_image, timeline_video,
           timeline_priority, timeline_jump
    FROM yy_timeline
    ORDER BY timeline_sort ASC
");

$rows = $stmt->fetchAll();
jsonResponse(['events' => $rows, 'count' => count($rows)]);
