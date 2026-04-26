<?php
require_once __DIR__ . '/config.php';

$pdo = getDb();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'letters':
        $stmt = $pdo->query("SELECT letter_key, letter_yt, letter_hebrew, letter_label, letter_overview, letter_numeric_value, letter_sort, letter_info, letter_image, letter_image_scale FROM yy_letter WHERE letter_active = true ORDER BY letter_sort ASC, letter_key ASC");
        jsonResponse($stmt->fetchAll());

    case 'words':
        $letter = $_GET['letter'] ?? '';
        if (!$letter || strlen($letter) !== 1) errorResponse('letter parameter required (single character)');
        $stmt = $pdo->prepare("
            SELECT w.word_key, w.word_strongs, w.word_hebrew, w.word_translit, w.word_yt,
                   w.word_count_yy,
                   (SELECT string_agg(ws.word_translit_text, ', ' ORDER BY ws.word_translit_sort, ws.word_translit_key)
                    FROM yy_word_translit ws WHERE ws.word_key = w.word_key) AS word_spellings
            FROM yy_word w
            WHERE w.word_active_flag = true
              AND LEFT(w.word_yt, 1) = ?
            ORDER BY w.word_strongs ASC
        ");
        $stmt->execute([$letter]);
        $words = $stmt->fetchAll();

        // Fetch definitions for all matched words
        if (!empty($words)) {
            $wordKeys = array_column($words, 'word_key');
            $ph = implode(',', array_fill(0, count($wordKeys), '?'));
            $defStmt = $pdo->prepare("
                SELECT d.word_key, d.word_definition_text, d.word_gender_key, d.word_definition_plural_flag,
                       p.word_pos_label, g.word_gender_label
                FROM yy_word_definition d
                LEFT JOIN yy_word_pos p ON p.word_pos_key = d.word_pos_key
                LEFT JOIN yy_word_gender g ON g.word_gender_key = d.word_gender_key
                WHERE d.word_key IN ($ph)
                  AND d.word_definition_active_flag = true
                ORDER BY d.word_pos_key
            ");
            $defStmt->execute($wordKeys);
            $defRows = $defStmt->fetchAll();

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
            foreach ($words as &$w) {
                $w['definitions'] = $defByWord[$w['word_key']] ?? [];
            }
            unset($w);
        }
        jsonResponse($words);

    default:
        errorResponse('Invalid action. Use: letters, words');
}
