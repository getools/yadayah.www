<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();
$stmt = $pdo->query("
    SELECT d.book_code, MAX(d.book_code_hebrew) AS book_code_hebrew,
           s.yah_scroll_label_yy, COALESCE(s.yah_scroll_sort, 9999) AS sort_order
    FROM dss_verse d
    LEFT JOIN yah_scroll s ON s.yah_scroll_key = d.yah_scroll_key
    GROUP BY d.book_code, s.yah_scroll_label_yy, s.yah_scroll_sort
    ORDER BY sort_order, d.book_code
");
jsonResponse($stmt->fetchAll());
