<?php
/**
 * Community direct messages API.
 * Uses yy_community_dm_thread, yy_community_dm_participant, yy_community_dm_message.
 * GET (no params or ?threads): list threads for current user.
 * GET ?thread=KEY: list messages in a thread.
 * POST action=new_thread: start new thread with a user.
 * POST action=send: send message to existing thread.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['thread'])) {
        $threadKey = (int)$_GET['thread'];

        // Verify user is participant
        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $userKey]);
        if (!$stmt->fetchColumn()) errorResponse('Thread not found', 404);

        // Update last read
        $db->prepare("UPDATE yy_community_dm_participant SET last_read_dtime = NOW() WHERE thread_key = ? AND user_key = ?")
           ->execute([$threadKey, $userKey]);

        // Get messages
        $stmt = $db->prepare("
            SELECT m.message_key, m.user_key, m.message_body, m.message_dtime,
                   u.user_name_display, u.user_avatar
            FROM yy_community_dm_message m
            LEFT JOIN yy_user u ON m.user_key = u.user_key
            WHERE m.thread_key = ? AND m.message_active_flag = TRUE
            ORDER BY m.message_dtime ASC
        ");
        $stmt->execute([$threadKey]);
        $messages = $stmt->fetchAll();

        // Get other participant info
        $stmt = $db->prepare("
            SELECT u.user_key, u.user_name_display, u.user_avatar, u.user_last_active_dtime
            FROM yy_community_dm_participant p
            JOIN yy_user u ON p.user_key = u.user_key
            WHERE p.thread_key = ? AND p.user_key != ?
            LIMIT 1
        ");
        $stmt->execute([$threadKey, $userKey]);
        $otherUser = $stmt->fetch();

        jsonResponse([
            'messages' => $messages,
            'thread' => ['thread_key' => $threadKey, 'other_user' => $otherUser ?: null],
        ]);
    }

    // List threads (default)
    $stmt = $db->prepare("
        SELECT t.thread_key, t.last_message_dtime,
               ou.user_key AS other_user_key, ou.user_name_display, ou.user_avatar, ou.user_last_active_dtime,
               last_msg.message_body AS last_message,
               (SELECT COUNT(*) FROM yy_community_dm_message m2
                WHERE m2.thread_key = t.thread_key AND m2.user_key != ?
                AND m2.message_dtime > COALESCE(my_p.last_read_dtime, '1970-01-01')
                AND m2.message_active_flag = TRUE) AS unread_count
        FROM yy_community_dm_thread t
        JOIN yy_community_dm_participant my_p ON t.thread_key = my_p.thread_key AND my_p.user_key = ?
        JOIN yy_community_dm_participant other_p ON t.thread_key = other_p.thread_key AND other_p.user_key != ?
        JOIN yy_user ou ON other_p.user_key = ou.user_key
        LEFT JOIN LATERAL (
            SELECT message_body FROM yy_community_dm_message
            WHERE thread_key = t.thread_key AND message_active_flag = TRUE
            ORDER BY message_dtime DESC LIMIT 1
        ) last_msg ON TRUE
        WHERE t.last_message_dtime IS NOT NULL
        ORDER BY t.last_message_dtime DESC
    ");
    $stmt->execute([$userKey, $userKey, $userKey]);
    $threads = $stmt->fetchAll();

    // Reshape for frontend
    $result = [];
    foreach ($threads as $t) {
        $result[] = [
            'thread_key' => $t['thread_key'],
            'last_message_dtime' => $t['last_message_dtime'],
            'last_message' => $t['last_message'],
            'unread_count' => (int)$t['unread_count'],
            'other_user' => [
                'user_key' => $t['other_user_key'],
                'user_name_display' => $t['user_name_display'],
                'user_avatar' => $t['user_avatar'],
                'user_last_active_dtime' => $t['user_last_active_dtime'],
            ],
        ];
    }

    jsonResponse(['threads' => $result]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';

    if ($action === 'new_thread') {
        $recipientKey = (int)($data['recipient_key'] ?? 0);
        $body = trim($data['body'] ?? '');
        if (!$recipientKey) errorResponse('recipient_key is required');
        if (!$body) errorResponse('Message cannot be empty');
        if ($recipientKey === $userKey) errorResponse('Cannot message yourself');

        // Check recipient exists
        $stmt = $db->prepare("SELECT 1 FROM yy_user WHERE user_key = ? AND user_active_flag = TRUE");
        $stmt->execute([$recipientKey]);
        if (!$stmt->fetchColumn()) errorResponse('Recipient not found', 404);

        // Check if thread already exists between these two users
        $stmt = $db->prepare("
            SELECT p1.thread_key FROM yy_community_dm_participant p1
            JOIN yy_community_dm_participant p2 ON p1.thread_key = p2.thread_key
            WHERE p1.user_key = ? AND p2.user_key = ?
            LIMIT 1
        ");
        $stmt->execute([$userKey, $recipientKey]);
        $existingThread = $stmt->fetchColumn();

        if ($existingThread) {
            $threadKey = (int)$existingThread;
        } else {
            // Create new thread
            $stmt = $db->prepare("INSERT INTO yy_community_dm_thread DEFAULT VALUES RETURNING thread_key");
            $stmt->execute();
            $threadKey = (int)$stmt->fetchColumn();

            // Add participants
            $db->prepare("INSERT INTO yy_community_dm_participant (thread_key, user_key) VALUES (?, ?)")->execute([$threadKey, $userKey]);
            $db->prepare("INSERT INTO yy_community_dm_participant (thread_key, user_key) VALUES (?, ?)")->execute([$threadKey, $recipientKey]);
        }

        // Insert message
        $stmt = $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body) VALUES (?, ?, ?) RETURNING message_key");
        $stmt->execute([$threadKey, $userKey, $body]);
        $messageKey = $stmt->fetchColumn();

        // Update thread last message time
        $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = NOW() WHERE thread_key = ?")->execute([$threadKey]);

        // Update last read for sender
        $db->prepare("UPDATE yy_community_dm_participant SET last_read_dtime = NOW() WHERE thread_key = ? AND user_key = ?")->execute([$threadKey, $userKey]);

        // Notify recipient
        notifyUser($db, $recipientKey, $userKey, 'dm', 'dm', $threadKey, null, 'sent you a message');

        jsonResponse(['success' => true, 'thread_key' => $threadKey]);
    }

    if ($action === 'send') {
        $threadKey = (int)($data['thread_key'] ?? 0);
        $body = trim($data['body'] ?? '');
        if (!$threadKey) errorResponse('thread_key is required');
        if (!$body) errorResponse('Message cannot be empty');

        // Verify user is participant
        $stmt = $db->prepare("SELECT 1 FROM yy_community_dm_participant WHERE thread_key = ? AND user_key = ?");
        $stmt->execute([$threadKey, $userKey]);
        if (!$stmt->fetchColumn()) errorResponse('Thread not found', 404);

        // Insert message
        $stmt = $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body) VALUES (?, ?, ?) RETURNING message_key");
        $stmt->execute([$threadKey, $userKey, $body]);
        $messageKey = $stmt->fetchColumn();

        // Update thread
        $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = NOW() WHERE thread_key = ?")->execute([$threadKey]);
        $db->prepare("UPDATE yy_community_dm_participant SET last_read_dtime = NOW() WHERE thread_key = ? AND user_key = ?")->execute([$threadKey, $userKey]);

        // Notify other participants
        $stmt = $db->prepare("SELECT user_key FROM yy_community_dm_participant WHERE thread_key = ? AND user_key != ?");
        $stmt->execute([$threadKey, $userKey]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $recipientKey) {
            notifyUser($db, (int)$recipientKey, $userKey, 'dm', 'dm', $threadKey, null, 'sent you a message');
        }

        jsonResponse(['success' => true, 'message_key' => $messageKey]);
    }

    errorResponse('Unknown action');
}

errorResponse('Method not allowed', 405);
