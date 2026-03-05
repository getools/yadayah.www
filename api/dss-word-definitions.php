<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();
$stmt = $pdo->query("
    SELECT w.word_hebrew_text,
           d.word_hebrew_definition_summary,
           d.word_hebrew_definition_full
    FROM dss_word_hebrew_definition d
    JOIN dss_word_hebrew w ON w.word_hebrew_key = d.word_hebrew_key
    WHERE d.word_hebrew_definition_summary IS NOT NULL
      AND d.word_hebrew_definition_summary != ''
");
jsonResponse($stmt->fetchAll());
