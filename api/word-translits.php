<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        // Create a new translit for a word
        setCurrentUser($db, $user['user_key']);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) errorResponse('Invalid JSON');

        $wordKey = (int)($data['word_key'] ?? 0);
        if (!$wordKey) errorResponse('word_key is required');

        $text = strtolower(trim($data['word_translit_text'] ?? ''));

        // Get next sort value
        $stmt = $db->prepare("SELECT COALESCE(MAX(word_translit_sort), -1) + 1 FROM yy_word_translit WHERE word_key = ?");
        $stmt->execute([$wordKey]);
        $sort = (int)$stmt->fetchColumn();

        $count = 0;
        if ($text !== '') {
            $count = countTranslitInTranslations($db, $text);
        }

        $stmt = $db->prepare("INSERT INTO yy_word_translit (word_key, word_translit_text, word_translit_sort, word_translit_count_yy) VALUES (?, ?, ?, ?)");
        $stmt->execute([$wordKey, $text, $sort, $count]);
        $newKey = (int)$db->lastInsertId('yy_word_translit_word_translit_key_seq');

        jsonResponse([
            'word_translit_key' => $newKey,
            'word_key' => $wordKey,
            'word_translit_text' => $text,
            'word_translit_sort' => $sort,
            'word_translit_count_yy' => $count
        ], 201);
        break;

    case 'PUT':
        // Update translit text
        setCurrentUser($db, $user['user_key']);
        $translitKey = $_GET['word_translit_key'] ?? null;
        if (!$translitKey || !ctype_digit($translitKey)) errorResponse('word_translit_key is required');

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) errorResponse('Invalid JSON');

        $text = strtolower(trim($data['word_translit_text'] ?? ''));
        $count = 0;
        if ($text !== '') {
            $count = countTranslitInTranslations($db, $text);
        }

        $stmt = $db->prepare("UPDATE yy_word_translit SET word_translit_text = ?, word_translit_count_yy = ? WHERE word_translit_key = ?");
        $stmt->execute([$text, $count, (int)$translitKey]);

        jsonResponse([
            'word_translit_key' => (int)$translitKey,
            'word_translit_text' => $text,
            'word_translit_count_yy' => $count
        ]);
        break;

    case 'DELETE':
        setCurrentUser($db, $user['user_key']);
        $translitKey = $_GET['word_translit_key'] ?? null;
        if (!$translitKey || !ctype_digit($translitKey)) errorResponse('word_translit_key is required');

        $stmt = $db->prepare("DELETE FROM yy_word_translit WHERE word_translit_key = ?");
        $stmt->execute([(int)$translitKey]);
        jsonResponse(['deleted' => true]);
        break;

    default:
        errorResponse('Method not allowed', 405);
}

function countTranslitInTranslations(PDO $db, string $translit): int {
    $stmt = $db->query("SELECT translation_copy FROM yy_translation WHERE translation_copy IS NOT NULL");
    $pattern = '/(?<![a-zA-Z\'])' . preg_quote(strtolower($translit), '/') . '(?![a-zA-Z\'])/i';
    $count = 0;
    while ($row = $stmt->fetch()) {
        $copy = $row['translation_copy'];
        if (preg_match_all('/<i[^>]*>(.*?)<\/i>/si', $copy, $matches)) {
            foreach ($matches[1] as $italic) {
                $clean = strip_tags($italic);
                $count += preg_match_all($pattern, $clean);
            }
        }
        if (preg_match_all('/<span\s+class="word"[^>]*>(.*?)<\/span>/si', $copy, $matches)) {
            foreach ($matches[1] as $word) {
                $clean = strip_tags($word);
                $count += preg_match_all($pattern, $clean);
            }
        }
    }
    return min($count, 32767);
}
