<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$volumeKey = $_GET['volume_key'] ?? null;
if (!$volumeKey || !ctype_digit($volumeKey)) {
    errorResponse('volume_key is required and must be an integer');
}

$db = getDb();
$stmt = $db->prepare('SELECT chapter_key, volume_key, chapter_number, chapter_name, chapter_label, chapter_sort FROM yy_chapter WHERE volume_key = ? ORDER BY chapter_sort');
$stmt->execute([(int)$volumeKey]);
jsonResponse($stmt->fetchAll());
