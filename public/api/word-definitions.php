<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get all definitions for a word, or get POS list, or get source list
        if (isset($_GET['pos_list'])) {
            $stmt = $db->query("SELECT word_pos_key, word_pos_code, word_pos_label, word_pos_gender_flag, word_pos_plural_flag FROM yy_word_pos ORDER BY word_pos_key");
            jsonResponse($stmt->fetchAll());
        }

        if (isset($_GET['source_list'])) {
            $stmt = $db->query("SELECT word_definition_source_key, word_definition_source_code, word_definition_source_label FROM yy_word_definition_source WHERE word_definition_source_active_flag = true ORDER BY word_definition_source_sort, word_definition_source_key");
            jsonResponse($stmt->fetchAll());
        }

        if (isset($_GET['gender_list'])) {
            $stmt = $db->query("SELECT word_gender_key, word_gender_code, word_gender_label FROM yy_word_gender ORDER BY word_gender_key");
            jsonResponse($stmt->fetchAll());
        }

        $wordKey = $_GET['word_key'] ?? null;
        if (!$wordKey || !ctype_digit($wordKey)) {
            errorResponse('word_key is required');
        }

        $stmt = $db->prepare("
            SELECT d.word_definition_key, d.word_key, d.word_pos_key, d.word_source_key,
                   d.word_definition_text, d.word_definition_active_flag,
                   d.word_gender_key, d.word_definition_plural_flag,
                   d.word_definition_source_key,
                   p.word_pos_label
            FROM yy_word_definition d
            LEFT JOIN yy_word_pos p ON p.word_pos_key = d.word_pos_key
            WHERE d.word_key = ? AND d.word_definition_active_flag = true
            ORDER BY d.word_pos_key
        ");
        $stmt->execute([(int)$wordKey]);
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        // Create or reactivate a definition for word_key + pos_key
        setCurrentUser($db, $user['user_key']);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) errorResponse('Invalid JSON');

        $wordKey = (int)($data['word_key'] ?? 0);
        $posKey = (int)($data['word_pos_key'] ?? 0);
        $sourceKey = (int)($data['word_source_key'] ?? 2); // default YY
        if (!$wordKey || !$posKey) errorResponse('word_key and word_pos_key are required');

        // Check if record already exists (active or inactive)
        $stmt = $db->prepare("SELECT * FROM yy_word_definition WHERE word_key = ? AND word_pos_key = ? AND word_source_key = ?");
        $stmt->execute([$wordKey, $posKey, $sourceKey]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Reactivate
            $stmt = $db->prepare("UPDATE yy_word_definition SET word_definition_active_flag = TRUE WHERE word_definition_key = ?");
            $stmt->execute([$existing['word_definition_key']]);
            $stmt = $db->prepare("
                SELECT d.*, p.word_pos_label
                FROM yy_word_definition d
                LEFT JOIN yy_word_pos p ON p.word_pos_key = d.word_pos_key
                WHERE d.word_definition_key = ?
            ");
            $stmt->execute([$existing['word_definition_key']]);
            jsonResponse($stmt->fetch());
        } else {
            // Create new
            $stmt = $db->prepare("
                INSERT INTO yy_word_definition (word_key, word_pos_key, word_source_key, word_definition_source_key, word_definition_text, word_definition_active_flag)
                VALUES (?, ?, ?, ?, '', TRUE)
            ");
            $stmt->execute([$wordKey, $posKey, $sourceKey, $sourceKey]);
            $newKey = (int)$db->lastInsertId('yy_word_definition_word_definition_key_seq');

            $stmt = $db->prepare("
                SELECT d.*, p.word_pos_label
                FROM yy_word_definition d
                LEFT JOIN yy_word_pos p ON p.word_pos_key = d.word_pos_key
                WHERE d.word_definition_key = ?
            ");
            $stmt->execute([$newKey]);
            jsonResponse($stmt->fetch(), 201);
        }
        break;

    case 'PUT':
        // Update definition text or active flag
        setCurrentUser($db, $user['user_key']);
        $defKey = $_GET['word_definition_key'] ?? null;
        if (!$defKey || !ctype_digit($defKey)) errorResponse('word_definition_key is required');

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) errorResponse('Invalid JSON');

        $sets = [];
        $params = [];
        if (array_key_exists('word_definition_text', $data)) {
            $sets[] = 'word_definition_text = ?';
            $params[] = $data['word_definition_text'];
        }
        if (array_key_exists('word_definition_active_flag', $data)) {
            $sets[] = 'word_definition_active_flag = ?';
            $params[] = $data['word_definition_active_flag'] ? 't' : 'f';
        }
        if (array_key_exists('word_gender_key', $data)) {
            $sets[] = 'word_gender_key = ?';
            $params[] = $data['word_gender_key'] ? (int)$data['word_gender_key'] : null;
        }
        if (array_key_exists('word_definition_plural_flag', $data)) {
            $sets[] = 'word_definition_plural_flag = ?';
            $params[] = $data['word_definition_plural_flag'] ? 't' : 'f';
        }
        if (array_key_exists('word_definition_source_key', $data)) {
            $sets[] = 'word_definition_source_key = ?';
            $params[] = $data['word_definition_source_key'] ? (int)$data['word_definition_source_key'] : null;
        }

        if (empty($sets)) errorResponse('Nothing to update');

        $params[] = (int)$defKey;
        $stmt = $db->prepare("UPDATE yy_word_definition SET " . implode(', ', $sets) . " WHERE word_definition_key = ?");
        $stmt->execute($params);

        jsonResponse(['success' => true]);
        break;

    case 'DELETE':
        setCurrentUser($db, $user['user_key']);
        $defKey = $_GET['word_definition_key'] ?? null;
        if (!$defKey || !ctype_digit($defKey)) errorResponse('word_definition_key is required');

        $stmt = $db->prepare("DELETE FROM yy_word_definition WHERE word_definition_key = ?");
        $stmt->execute([(int)$defKey]);
        jsonResponse(['deleted' => true]);
        break;

    default:
        errorResponse('Method not allowed', 405);
}
