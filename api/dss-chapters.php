<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$book = isset($_GET['book']) && $_GET['book'] !== '' ? $_GET['book'] : null;
if ($book === null) {
    errorResponse('book is required');
}

$pdo = getDb();
$stmt = $pdo->prepare("SELECT DISTINCT chapter_number FROM dss_verse WHERE book_code = ? ORDER BY chapter_number");
$stmt->execute([$book]);
jsonResponse($stmt->fetchAll());
