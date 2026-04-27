<?php
require_once __DIR__ . '/config.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    errorResponse('Method not allowed', 405);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $words = file_get_contents('php://input');
} else {
    $words = isset($_GET['words']) ? $_GET['words'] : '';
}
if (!$words) {
    jsonResponse([]);
}

// Normalize curly quotes to ASCII apostrophes
$words = str_replace(["\u{2018}", "\u{2019}", "\u{2032}"], "'", $words);
$wordList = array_filter(array_map('trim', explode('|', $words)));
if (empty($wordList)) {
    jsonResponse([]);
}

$pg = getDb();

$placeholders = implode(',', array_fill(0, count($wordList), 'LOWER(?)'));
$params = array_map('strtolower', $wordList);

$sql = "
    SELECT w.word_key, s.word_translit_text,
           w.word_translit, w.word_yt, w.word_hebrew, w.word_strongs
    FROM yy_word_translit s
    JOIN yy_word w ON s.word_key = w.word_key
    WHERE w.word_active_flag = true
      AND LOWER(s.word_translit_text) IN ($placeholders)
    ORDER BY s.word_translit_sort
";

$stmt = $pg->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Index by lowercase word_translit_text (first match wins per word)
// Also collect word_keys to fetch all translits
$result = [];
$wordIds = [];
foreach ($rows as $row) {
    $key = strtolower($row['word_translit_text']);
    if (!isset($result[$key])) {
        $result[$key] = $row;
        $wordIds[$row['word_key']] = true;
    }
}

// Fetch all translits for matched word_keys
if (!empty($wordIds)) {
    $idList = array_keys($wordIds);
    $idPlaceholders = implode(',', array_fill(0, count($idList), '?'));
    $spSql = "SELECT word_key, word_translit_text FROM yy_word_translit WHERE word_key IN ($idPlaceholders) ORDER BY word_key, word_translit_sort";
    $spStmt = $pg->prepare($spSql);
    $spStmt->execute($idList);
    $spRows = $spStmt->fetchAll();

    // Group translits by word_key
    $spByWord = [];
    foreach ($spRows as $sp) {
        $spByWord[$sp['word_key']][] = $sp['word_translit_text'];
    }

    // Attach spellings to each result
    foreach ($result as $key => &$entry) {
        $wid = $entry['word_key'];
        $entry['word_spellings'] = isset($spByWord[$wid]) ? $spByWord[$wid] : [];
    }
    unset($entry);

    // Fetch definitions with POS labels
    $defSql = "
        SELECT d.word_key, d.word_definition_text, d.word_gender_key, d.word_definition_plural_flag,
               p.word_pos_label, g.word_gender_label
        FROM yy_word_definition d
        LEFT JOIN yy_word_pos p ON p.word_pos_key = d.word_pos_key
        LEFT JOIN yy_word_gender g ON g.word_gender_key = d.word_gender_key
        WHERE d.word_key IN ($idPlaceholders)
          AND d.word_definition_active_flag = true
        ORDER BY d.word_pos_key
    ";
    $defStmt = $pg->prepare($defSql);
    $defStmt->execute($idList);
    $defRows = $defStmt->fetchAll();

    // Group definitions by word_key
    $defByWord = [];
    foreach ($defRows as $def) {
        $defByWord[$def['word_key']][] = [
            'pos_label' => $def['word_pos_label'],
            'text' => $def['word_definition_text'],
            'gender_key' => $def['word_gender_key'],
            'gender_label' => $def['word_gender_label'],
            'plural_flag' => $def['word_definition_plural_flag'],
        ];
    }

    // Attach definitions to each result
    foreach ($result as $key => &$entry) {
        $wid = $entry['word_key'];
        $entry['definitions'] = isset($defByWord[$wid]) ? $defByWord[$wid] : [];
    }
    unset($entry);
}

jsonResponse($result);
