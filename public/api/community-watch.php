<?php
/**
 * Community watch/subscribe API.
 * POST: toggle watch on a topic.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') errorResponse('Method not allowed', 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$topicKey = (int)($data['topic_key'] ?? 0);
if (!$topicKey) errorResponse('topic_key is required');

// Verify topic exists
$stmt = $db->prepare("SELECT 1 FROM yy_community_topic WHERE topic_key = ? AND topic_active_flag = TRUE");
$stmt->execute([$topicKey]);
if (!$stmt->fetchColumn()) errorResponse('Topic not found', 404);

// Check if already watching
$stmt = $db->prepare("SELECT watch_key FROM yy_community_watch WHERE user_key = ? AND topic_key = ?");
$stmt->execute([$userKey, $topicKey]);
$existing = $stmt->fetchColumn();

if ($existing) {
    $db->prepare("DELETE FROM yy_community_watch WHERE watch_key = ?")->execute([$existing]);
    jsonResponse(['watching' => false]);
} else {
    $db->prepare("INSERT INTO yy_community_watch (user_key, topic_key) VALUES (?, ?)")->execute([$userKey, $topicKey]);
    jsonResponse(['watching' => true]);
}
