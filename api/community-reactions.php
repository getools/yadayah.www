<?php
/**
 * Community reactions API.
 * POST: toggle reaction on a topic or reply.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') errorResponse('Method not allowed', 405);
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$targetType = $data['target_type'] ?? '';
$targetKey = (int)($data['target_key'] ?? 0);
$reactionCode = trim($data['reaction_code'] ?? '');

if (!in_array($targetType, ['topic', 'reply'])) errorResponse('target_type must be topic or reply');
if (!$targetKey) errorResponse('target_key is required');
if (!$reactionCode) errorResponse('reaction_code is required');

// Check if already reacted with this code
$stmt = $db->prepare("
    SELECT reaction_key FROM yy_community_reaction
    WHERE user_key = ? AND target_type = ? AND target_key = ? AND reaction_code = ?
");
$stmt->execute([$userKey, $targetType, $targetKey, $reactionCode]);
$existing = $stmt->fetchColumn();

if ($existing) {
    // Remove reaction
    $db->prepare("DELETE FROM yy_community_reaction WHERE reaction_key = ?")->execute([$existing]);
} else {
    // Add reaction
    $db->prepare("
        INSERT INTO yy_community_reaction (user_key, target_type, target_key, reaction_code)
        VALUES (?, ?, ?, ?)
    ")->execute([$userKey, $targetType, $targetKey, $reactionCode]);
}

// Return aggregated reactions for this target
$stmt = $db->prepare("
    SELECT reaction_code, COUNT(*) AS count
    FROM yy_community_reaction
    WHERE target_type = ? AND target_key = ?
    GROUP BY reaction_code
    ORDER BY count DESC
");
$stmt->execute([$targetType, $targetKey]);
$reactions = $stmt->fetchAll();

// Check which ones current user has
$stmt = $db->prepare("
    SELECT reaction_code FROM yy_community_reaction
    WHERE user_key = ? AND target_type = ? AND target_key = ?
");
$stmt->execute([$userKey, $targetType, $targetKey]);
$userReactions = $stmt->fetchAll(PDO::FETCH_COLUMN);

jsonResponse([
    'reactions' => $reactions,
    'user_reactions' => $userReactions,
]);
