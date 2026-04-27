<?php
/**
 * Ask Yada Feedback API — mod ratings and learned corrections.
 *
 * POST: save feedback rating or create a learned correction
 * GET: list feedback/learned items (for admin)
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$userKey = $_SESSION['user_key'] ?? null;

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? 'rate';

    if ($action === 'rate') {
        $logKey = (int)($data['ask_session_log_key'] ?? 0);
        $sessionKey = (int)($data['ask_session_key'] ?? 0);
        $rating = $data['rating'] ?? '';

        if (!$logKey) errorResponse('ask_session_log_key required');
        // Accept 0-100 numeric rating or legacy string values
        if (is_numeric($rating)) {
            $rating = max(0, min(100, (int)$rating));
        } elseif (!in_array($rating, ['good', 'needs_correction', 'bad'])) {
            errorResponse('Invalid rating (0-100 or good/needs_correction/bad)');
        }

        $stmt = $db->prepare("
            INSERT INTO yy_ask_feedback (ask_session_key, ask_session_log_key, user_key, feedback_rating)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$sessionKey ?: null, $logKey, $userKey, $rating]);

        // Save numeric rating on the log row
        if (is_numeric($rating)) {
            $db->prepare("UPDATE yy_ask_session_log SET ask_log_rating = ? WHERE ask_session_log_key = ?")
               ->execute([(int)$rating, $logKey]);
        }

        // If rated "good" (or 70+), embed the Q&A pair for future retrieval
        if ($rating === 'good' || (is_numeric($rating) && (int)$rating >= 70)) {
            try {
                require_once __DIR__ . '/ask-rag.php';
                $logStmt = $db->prepare("SELECT ask_log_question, ask_log_response FROM yy_ask_session_log WHERE ask_session_log_key = ?");
                $logStmt->execute([$logKey]);
                $log = $logStmt->fetch();
                if ($log && $log['ask_log_response']) {
                    embedQAPair($db, $logKey, $log['ask_log_question'], $log['ask_log_response']);
                }
            } catch (Exception $e) {
                // Non-critical
            }
        }

        jsonResponse(['saved' => true]);
    }

    if ($action === 'learn') {
        $logKey = (int)($data['ask_session_log_key'] ?? 0);
        $correctedAnswer = trim($data['corrected_answer'] ?? '');

        if (!$logKey) errorResponse('ask_session_log_key required');
        if (!$correctedAnswer) errorResponse('Corrected answer is required');

        // Get the original question
        $logStmt = $db->prepare("SELECT ask_log_question FROM yy_ask_session_log WHERE ask_session_log_key = ?");
        $logStmt->execute([$logKey]);
        $question = $logStmt->fetchColumn();

        // Save correction on the log row for display
        $db->prepare("UPDATE yy_ask_session_log SET ask_log_corrected_answer = ? WHERE ask_session_log_key = ?")
           ->execute([$correctedAnswer, $logKey]);

        // Save as learned correction with high priority
        $stmt = $db->prepare("
            INSERT INTO yy_ask_learned (learned_type, learned_question, learned_answer, learned_priority, user_key)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute(['correction', $question, $correctedAnswer, 100, $userKey]);

        // Embed the corrected Q&A pair
        try {
            require_once __DIR__ . '/ask-rag.php';
            embedQAPair($db, $logKey, $question, $correctedAnswer);
        } catch (Exception $e) {
            // Non-critical
        }

        jsonResponse(['saved' => true]);
    }
}

if ($method === 'GET') {
    // Admin list feedback/learned items
    $feedbackStmt = $db->prepare("SELECT * FROM yy_ask_feedback ORDER BY feedback_key DESC LIMIT 100");
    $feedbackStmt->execute();
    $feedback = $feedbackStmt->fetchAll();

    $learnedStmt = $db->prepare("SELECT * FROM yy_ask_learned ORDER BY learned_key DESC LIMIT 100");
    $learnedStmt->execute();
    $learned = $learnedStmt->fetchAll();

    jsonResponse(['feedback' => $feedback, 'learned' => $learned]);
}

errorResponse('Method not allowed');
?>
            VALUES ('correction', ?, ?, 100, ?)
            RETURNING ask_learned_key
        ");
        $stmt->execute([$question, $correctedAnswer, $userKey]);
        $learnedKey = $stmt->fetchColumn();

        // Also save a feedback record
        $db->prepare("
            INSERT INTO yy_ask_feedback (ask_session_log_key, user_key, feedback_rating, feedback_corrected_answer, ask_feedback_key)
            VALUES (?, ?, 'needs_correction', ?, ?)
        ")->execute([$logKey, $userKey, $correctedAnswer, $learnedKey]);

        // Embed the correction for RAG
        try {
            require_once __DIR__ . '/ask-rag.php';
            $voyageKey = getenv('VOYAGE_API_KEY') ?: '';
            if (!$voyageKey) {
                $vkStmt = $db->query("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_code = 'voyage-api-key'");
                $voyageKey = $vkStmt->fetchColumn() ?: '';
            }
            if ($voyageKey && $question) {
                $text = "Q: " . $question . "\nCORRECT ANSWER: " . mb_substr($correctedAnswer, 0, 2000);
                $embedding = generateEmbedding($text, $voyageKey);
                if ($embedding) {
                    storeEmbedding($db, 'correction', $learnedKey, $text, $embedding, [
                        'question' => $question,
                        'correction' => mb_substr($correctedAnswer, 0, 500),
                    ]);
                }
            }
        } catch (Exception $e) {
            // Non-critical
        }

        jsonResponse(['saved' => true, 'ask_learned_key' => $learnedKey]);
    }

    errorResponse('Unknown action');
}

if ($method === 'GET') {
    // List learned corrections
    $stmt = $db->query("
        SELECT l.*, u.user_display_name
        FROM yy_ask_learned l
        LEFT JOIN yy_user u ON l.user_key = u.user_key
        WHERE l.learned_active_flag = TRUE
        ORDER BY l.learned_priority DESC, l.learned_dtime DESC
    ");
    jsonResponse(['learned' => $stmt->fetchAll()]);
}

errorResponse('Method not allowed', 405);
