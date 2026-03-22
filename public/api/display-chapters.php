<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$scrollKey = isset($_GET['scroll_key']) && $_GET['scroll_key'] !== '' ? (int)$_GET['scroll_key'] : null;
if ($scrollKey === null) {
    errorResponse('scroll_key is required');
}

$pdo = getDb();
$stmt = $pdo->prepare('SELECT yah_chapter_key, yah_scroll_key, yah_chapter_number FROM yah_chapter WHERE yah_scroll_key = ? AND yah_chapter_count > 0 ORDER BY yah_chapter_sort, yah_chapter_number');
$stmt->execute([$scrollKey]);
jsonResponse($stmt->fetchAll());
