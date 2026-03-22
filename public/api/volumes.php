<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$seriesKey = $_GET['series_key'] ?? null;
if (!$seriesKey || !ctype_digit($seriesKey)) {
    errorResponse('series_key is required and must be an integer');
}

$db = getDb();
$stmt = $db->prepare("SELECT volume_key, series_key, volume_number, volume_name, volume_label, volume_sort, CONCAT(volume_number, ' - ', COALESCE(volume_label, volume_name)) AS display_text FROM yy_volume WHERE series_key = ? ORDER BY volume_sort");
$stmt->execute([(int)$seriesKey]);
jsonResponse($stmt->fetchAll());
