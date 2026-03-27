<?php
/**
 * Community polls API.
 * GET ?topic=KEY: get poll for a topic with results.
 * POST action=create: create a poll for a topic.
 * POST action=vote: vote on a poll option.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $topicKey = (int)($_GET['topic'] ?? 0);
    if (!$topicKey) errorResponse('topic query param is required');

    $stmt = $db->prepare("SELECT poll_key, poll_question, poll_multi_vote, poll_dtime FROM yy_community_poll WHERE topic_key = ?");
    $stmt->execute([$topicKey]);
    $poll = $stmt->fetch();
    if (!$poll) { jsonResponse(['poll' => null]); exit; }

    // Get options with vote counts
    $stmt = $db->prepare("
        SELECT o.option_key, o.option_text, o.option_sort_order,
               COUNT(v.vote_key) AS vote_count
        FROM yy_community_poll_option o
        LEFT JOIN yy_community_poll_vote v ON o.option_key = v.option_key
        WHERE o.poll_key = ?
        GROUP BY o.option_key
        ORDER BY o.option_sort_order
    ");
    $stmt->execute([$poll['poll_key']]);
    $options = $stmt->fetchAll();

    // Total votes
    $totalVotes = array_sum(array_column($options, 'vote_count'));

    // Current user's votes
    $userVotes = [];
    if ($userKey) {
        $stmt = $db->prepare("
            SELECT v.option_key FROM yy_community_poll_vote v
            JOIN yy_community_poll_option o ON v.option_key = o.option_key
            WHERE o.poll_key = ? AND v.user_key = ?
        ");
        $stmt->execute([$poll['poll_key'], $userKey]);
        $userVotes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    jsonResponse([
        'poll' => $poll,
        'options' => $options,
        'total_votes' => $totalVotes,
        'user_votes' => $userVotes,
    ]);
}

if ($method === 'POST') {
    if (!$userKey) errorResponse('Login required', 401);

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';

    if ($action === 'create') {
        $topicKey = (int)($data['topic_key'] ?? 0);
        $question = trim($data['question'] ?? '');
        $options = $data['options'] ?? [];
        $multiVote = !empty($data['multi_vote']);

        if (!$topicKey) errorResponse('topic_key is required');
        if (!$question) errorResponse('question is required');
        if (!is_array($options) || count($options) < 2) errorResponse('At least 2 options required');
        if (count($options) > 20) errorResponse('Maximum 20 options allowed');

        // Verify user owns this topic
        $stmt = $db->prepare("SELECT user_key FROM yy_community_topic WHERE topic_key = ? AND topic_active_flag = TRUE");
        $stmt->execute([$topicKey]);
        $topicOwner = $stmt->fetchColumn();
        if (!$topicOwner) errorResponse('Topic not found', 404);
        if ((int)$topicOwner !== $userKey) errorResponse('Only the topic author can create a poll', 403);

        // Check no poll exists yet
        $stmt = $db->prepare("SELECT 1 FROM yy_community_poll WHERE topic_key = ?");
        $stmt->execute([$topicKey]);
        if ($stmt->fetchColumn()) errorResponse('This topic already has a poll');

        $stmt = $db->prepare("
            INSERT INTO yy_community_poll (topic_key, user_key, poll_question, poll_multi_vote)
            VALUES (?, ?, ?, ?)
            RETURNING poll_key
        ");
        $stmt->execute([$topicKey, $userKey, $question, $multiVote ? 'TRUE' : 'FALSE']);
        $pollKey = $stmt->fetchColumn();

        $optStmt = $db->prepare("INSERT INTO yy_community_poll_option (poll_key, option_text, option_sort_order) VALUES (?, ?, ?)");
        foreach ($options as $i => $optText) {
            $optStmt->execute([$pollKey, trim($optText), $i + 1]);
        }

        jsonResponse(['success' => true, 'poll_key' => $pollKey]);
    }

    if ($action === 'vote') {
        $optionKey = (int)($data['option_key'] ?? 0);
        if (!$optionKey) errorResponse('option_key is required');

        // Get poll info
        $stmt = $db->prepare("
            SELECT o.poll_key, p.poll_multi_vote
            FROM yy_community_poll_option o
            JOIN yy_community_poll p ON o.poll_key = p.poll_key
            WHERE o.option_key = ?
        ");
        $stmt->execute([$optionKey]);
        $info = $stmt->fetch();
        if (!$info) errorResponse('Option not found', 404);

        // Check existing vote
        $stmt = $db->prepare("SELECT vote_key FROM yy_community_poll_vote WHERE option_key = ? AND user_key = ?");
        $stmt->execute([$optionKey, $userKey]);
        $existingVote = $stmt->fetchColumn();

        if ($existingVote) {
            // Remove vote (toggle)
            $db->prepare("DELETE FROM yy_community_poll_vote WHERE vote_key = ?")->execute([$existingVote]);
            jsonResponse(['success' => true, 'voted' => false]);
        }

        $multiVote = ($info['poll_multi_vote'] === true || $info['poll_multi_vote'] === 't');

        if (!$multiVote) {
            // Remove any existing vote on this poll
            $db->prepare("
                DELETE FROM yy_community_poll_vote
                WHERE user_key = ? AND option_key IN (
                    SELECT option_key FROM yy_community_poll_option WHERE poll_key = ?
                )
            ")->execute([$userKey, $info['poll_key']]);
        }

        $db->prepare("INSERT INTO yy_community_poll_vote (option_key, user_key) VALUES (?, ?)")
           ->execute([$optionKey, $userKey]);

        jsonResponse(['success' => true, 'voted' => true]);
    }

    errorResponse('Unknown action');
}

errorResponse('Method not allowed', 405);
