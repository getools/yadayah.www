<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$book = isset($_GET['book']) && $_GET['book'] !== '' ? $_GET['book'] : null;
$chapter = isset($_GET['chapter']) && $_GET['chapter'] !== '' ? (int)$_GET['chapter'] : null;
$verse = isset($_GET['verse']) && $_GET['verse'] !== '' ? (int)$_GET['verse'] : null;

$conditions = ["h.verse_text_hebrew IS NOT NULL AND h.verse_text_hebrew != ''"];
$params = [];

if ($book !== null) {
    $conditions[] = "h.book_code = ?";
    $params[] = $book;
}
if ($chapter !== null) {
    $conditions[] = "h.chapter_number = ?";
    $params[] = $chapter;
}
if ($verse !== null) {
    $conditions[] = "h.verse_number = ?";
    $params[] = $verse;
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$pdo = getDb();
$stmt = $pdo->prepare("
    SELECT h.verse_key, h.scroll_code, h.book_code,
           COALESCE(h.book_code_hebrew, eng.book_code_hebrew) AS book_code_hebrew,
           h.chapter_number, h.verse_number,
           h.verse_text_hebrew,
           h.verse_text_yt,
           eng.verse_text_english
    FROM dss_verse h
    LEFT JOIN (
        SELECT DISTINCT ON (book_code, chapter_number, verse_number)
            book_code, chapter_number, verse_number,
            verse_text_english, book_code_hebrew
        FROM dss_verse
        WHERE verse_text_english IS NOT NULL AND verse_text_english != ''
        ORDER BY book_code, chapter_number, verse_number
    ) eng ON h.book_code = eng.book_code
         AND h.chapter_number = eng.chapter_number
         AND h.verse_number = eng.verse_number
    $where
    ORDER BY h.book_code, h.chapter_number, h.verse_number, h.scroll_code
");
$stmt->execute($params);
jsonResponse($stmt->fetchAll());
