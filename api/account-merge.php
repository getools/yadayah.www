<?php
/**
 * Account merge API — lets a logged-in user prove ownership of another account
 * and merge all content into their current account.
 *
 * POST actions:
 *   merge_auth_email  — authenticate account B via email/password
 *   merge_preview     — get counts of what will be merged (from session)
 *   merge_confirm     — execute the merge
 *   merge_cancel      — clear pending merge
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';

// ── Authenticate account B via email/password ──
if ($action === 'merge_auth_email') {
    $email = trim($data['email'] ?? '');
    $pass = $data['password'] ?? '';
    if (!$email || !$pass) errorResponse('Email and password are required');

    $stmt = $db->prepare("SELECT user_key, user_pass, user_display_name, user_email, user_active_flag FROM yy_user WHERE LOWER(user_email) = LOWER(?) AND user_pass IS NOT NULL");
    $stmt->execute([$email]);
    $target = $stmt->fetch();

    if (!$target || !$target['user_pass'] || !password_verify($pass, $target['user_pass'])) {
        errorResponse('Invalid email or password');
    }
    if ($target['user_active_flag'] === false || $target['user_active_flag'] === 'f') {
        errorResponse('That account is disabled');
    }
    $targetKey = (int)$target['user_key'];
    if ($targetKey === $userKey) {
        errorResponse('That is your current account');
    }

    // Store merge target in session (expires in 10 minutes)
    $token = bin2hex(random_bytes(16));
    $_SESSION['merge_target'] = [
        'user_key' => $targetKey,
        'display_name' => $target['user_display_name'],
        'email' => $target['user_email'],
        'token' => $token,
        'expires' => time() + 600,
    ];

    $preview = getMergePreview($db, $targetKey);
    $preview['target_name'] = $target['user_display_name'];
    $preview['target_email'] = $target['user_email'];
    $preview['token'] = $token;
    jsonResponse($preview);
}

// ── Authenticate account B via OAuth (called after OAuth callback sets merge_target) ──
if ($action === 'merge_preview') {
    $mt = $_SESSION['merge_target'] ?? null;
    if (!$mt || ($mt['expires'] ?? 0) < time()) {
        unset($_SESSION['merge_target']);
        errorResponse('No pending merge or it has expired');
    }
    $preview = getMergePreview($db, $mt['user_key']);
    $preview['target_name'] = $mt['display_name'];
    $preview['target_email'] = $mt['email'];
    $preview['token'] = $mt['token'];
    jsonResponse($preview);
}

// ── Execute the merge ──
if ($action === 'merge_confirm') {
    $mt = $_SESSION['merge_target'] ?? null;
    if (!$mt || ($mt['expires'] ?? 0) < time()) {
        unset($_SESSION['merge_target']);
        errorResponse('No pending merge or it has expired');
    }
    $token = $data['token'] ?? '';
    if ($token !== $mt['token']) errorResponse('Invalid merge token');

    $targetKey = (int)$mt['user_key'];
    $preview = getMergePreview($db, $targetKey);

    $db->beginTransaction();
    try {
        $a = $userKey;
        $b = $targetKey;

        // DM thread deduplication: find threads where both A and B are participants
        $stmt = $db->prepare("SELECT thread_key FROM yy_community_dm_participant WHERE user_key IN (?, ?) GROUP BY thread_key HAVING COUNT(DISTINCT user_key) = 2");
        $stmt->execute([$a, $b]);
        $sharedThreads = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($sharedThreads) {
            $in = implode(',', array_map('intval', $sharedThreads));
            // Shared threads: reassign B's messages to A, delete B's participant row
            $db->exec("UPDATE yy_community_dm_message SET user_key = {$a} WHERE user_key = {$b} AND thread_key IN ({$in})");
            $db->exec("DELETE FROM yy_community_dm_participant WHERE user_key = {$b} AND thread_key IN ({$in})");
            // Non-shared threads
            $db->exec("UPDATE yy_community_dm_participant SET user_key = {$a} WHERE user_key = {$b} AND thread_key NOT IN ({$in})");
            $db->exec("UPDATE yy_community_dm_message SET user_key = {$a} WHERE user_key = {$b} AND thread_key NOT IN ({$in})");
        } else {
            $db->prepare("UPDATE yy_community_dm_participant SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);
            $db->prepare("UPDATE yy_community_dm_message SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);
        }

        // Reassign content
        $db->prepare("UPDATE yy_community_topic SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);
        $db->prepare("UPDATE yy_community_reply SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);

        // Upsert pattern for unique-constrained tables
        $upsertTables = [
            'yy_community_watch' => 'topic_key',
            'yy_community_bookmark' => 'topic_key',
        ];
        foreach ($upsertTables as $table => $col) {
            $db->prepare("DELETE FROM {$table} WHERE user_key = ? AND {$col} IN (SELECT {$col} FROM {$table} WHERE user_key = ?)")->execute([$b, $a]);
            $db->prepare("UPDATE {$table} SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);
        }

        // Likes: unique on (user_key, target_type, target_key)
        $db->prepare("DELETE FROM yy_community_like WHERE user_key = ? AND (target_type, target_key) IN (SELECT target_type, target_key FROM yy_community_like WHERE user_key = ?)")->execute([$b, $a]);
        $db->prepare("UPDATE yy_community_like SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);

        // Reactions: unique on (user_key, target_type, target_key, reaction_code)
        $db->prepare("DELETE FROM yy_community_reaction WHERE user_key = ? AND (target_type, target_key, reaction_code) IN (SELECT target_type, target_key, reaction_code FROM yy_community_reaction WHERE user_key = ?)")->execute([$b, $a]);
        $db->prepare("UPDATE yy_community_reaction SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);

        // Poll votes
        $db->prepare("DELETE FROM yy_community_poll_vote WHERE user_key = ? AND option_key IN (SELECT option_key FROM yy_community_poll_vote WHERE user_key = ?)")->execute([$b, $a]);
        $db->prepare("UPDATE yy_community_poll_vote SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);

        // Notifications
        $db->prepare("UPDATE yy_community_notification SET user_key = ? WHERE user_key = ?")->execute([$a, $b]);
        $db->prepare("UPDATE yy_community_notification SET actor_key = ? WHERE actor_key = ?")->execute([$a, $b]);

        // Roles: copy B's roles to A (skip duplicates)
        $db->prepare("INSERT INTO yy_user_role (user_key, role_key) SELECT ?, role_key FROM yy_user_role WHERE user_key = ? ON CONFLICT DO NOTHING")->execute([$a, $b]);
        $db->prepare("DELETE FROM yy_user_role WHERE user_key = ?")->execute([$b]);

        // Settings: copy B's settings to A (skip duplicates)
        $db->prepare("INSERT INTO yy_user_setting (user_key, setting_key, user_setting_value) SELECT ?, setting_key, user_setting_value FROM yy_user_setting WHERE user_key = ? ON CONFLICT DO NOTHING")->execute([$a, $b]);
        $db->prepare("DELETE FROM yy_user_setting WHERE user_key = ?")->execute([$b]);

        // Disable account B
        $db->prepare("UPDATE yy_user SET user_active_flag = FALSE, user_email = 'merged-into-' || ? || ':' || user_email, user_code = 'merged:' || user_code WHERE user_key = ?")->execute([$a, $b]);

        // Audit log
        $db->prepare("INSERT INTO yy_account_merge (primary_user_key, merged_user_key, merged_content) VALUES (?, ?, ?)")
           ->execute([$a, $b, json_encode($preview)]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Merge failed: ' . $e->getMessage());
    }

    unset($_SESSION['merge_target']);
    jsonResponse(['merged' => true, 'merged_user_key' => $targetKey]);
}

// ── Cancel ──
if ($action === 'merge_cancel') {
    unset($_SESSION['merge_target']);
    jsonResponse(['cancelled' => true]);
}

errorResponse('Unknown action');

// ── Helper: get counts for merge preview ──
function getMergePreview(PDO $db, int $targetKey): array {
    $counts = [];
    $tables = [
        'topics' => "SELECT COUNT(*) FROM yy_community_topic WHERE user_key = ?",
        'replies' => "SELECT COUNT(*) FROM yy_community_reply WHERE user_key = ?",
        'dm_messages' => "SELECT COUNT(*) FROM yy_community_dm_message WHERE user_key = ?",
        'likes' => "SELECT COUNT(*) FROM yy_community_like WHERE user_key = ?",
        'bookmarks' => "SELECT COUNT(*) FROM yy_community_bookmark WHERE user_key = ?",
        'reactions' => "SELECT COUNT(*) FROM yy_community_reaction WHERE user_key = ?",
        'poll_votes' => "SELECT COUNT(*) FROM yy_community_poll_vote WHERE user_key = ?",
    ];
    foreach ($tables as $key => $sql) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$targetKey]);
        $counts[$key] = (int)$stmt->fetchColumn();
    }
    // Roles
    $stmt = $db->prepare("SELECT r.role_code FROM yy_user_role ur JOIN yy_role r ON ur.role_key = r.role_key WHERE ur.user_key = ?");
    $stmt->execute([$targetKey]);
    $counts['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $counts;
}
