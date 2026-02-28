<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$book = isset($_GET['book']) && $_GET['book'] !== '' ? $_GET['book'] : null;
$chapter = isset($_GET['chapter']) && $_GET['chapter'] !== '' ? (int)$_GET['chapter'] : null;

if ($book === null || $chapter === null) {
    errorResponse('book and chapter are required');
}

$pdo = getDb();
$stmt = $pdo->prepare("SELECT DISTINCT verse_number FROM dss_verse WHERE book_code = ? AND chapter_number = ? ORDER BY verse_number");
$stmt->execute([$book, $chapter]);
jsonResponse($stmt->fetchAll());
