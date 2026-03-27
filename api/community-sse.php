<?php
/**
 * Server-Sent Events endpoint for real-time community updates.
 * Streams unread DM count, unread notification count, and new DM events.
 * Client connects via EventSource; PHP polls DB every 3 seconds.
 */

// Need session for user_key but must close it to avoid blocking other requests
ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$userKey = $_SESSION['user_key'] ?? null;
session_write_close(); // Release session lock immediately

if (!$userKey) {
    http_response_code(401);
    echo "data: {\"error\":\"Login required\"}\n\n";
    exit;
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx
header('Access-Control-Allow-Origin: *');

// Disable output buffering
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
while (ob_get_level()) ob_end_flush();

// DB connection
$host = getenv('PG_HOST') ?: 'localhost';
$port = getenv('PG_PORT') ?: '5433';
$name = getenv('PG_DB')   ?: 'yada';
$user = getenv('PG_USER') ?: 'postgres';
$pass = getenv('PG_PASS') ?: 'yada_password';
$dsn = "pgsql:host=$host;port=$port;dbname=$name";
$db = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Track previous state to only send changes
$prevNotif = -1;
$prevDm = -1;
$prevLatestDmKey = 0;

// Send initial retry interval (3 seconds)
echo "retry: 3000\n\n";
flush();

$maxRuntime = 300; // 5 minute max connection, client auto-reconnects
$start = time();

while (true) {
    if (connection_aborted()) break;
    if ((time() - $start) > $maxRuntime) {
        echo "event: reconnect\ndata: {}\n\n";
        flush();
        break;
    }

    // Unread notification count
    $stmt = $db->prepare("SELECT COUNT(*) FROM yy_community_notification WHERE user_key = ? AND read_flag = FALSE");
    $stmt->execute([$userKey]);
    $notifCount = (int)$stmt->fetchColumn();

    // Unread DM count
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM yy_community_dm_message m
        JOIN yy_community_dm_participant p ON p.thread_key = m.thread_key AND p.user_key = ?
        WHERE m.user_key != ? AND m.message_active_flag = TRUE
          AND m.message_dtime > COALESCE(p.last_read_dtime, '1970-01-01')
    ");
    $stmt->execute([$userKey, $userKey]);
    $dmCount = (int)$stmt->fetchColumn();

    // Latest DM message key (to detect new messages in open thread)
    $stmt = $db->prepare("
        SELECT COALESCE(MAX(m.message_key), 0)
        FROM yy_community_dm_message m
        JOIN yy_community_dm_participant p ON p.thread_key = m.thread_key AND p.user_key = ?
        WHERE m.user_key != ? AND m.message_active_flag = TRUE
    ");
    $stmt->execute([$userKey, $userKey]);
    $latestDmKey = (int)$stmt->fetchColumn();

    // Send updates only when something changed
    $changed = false;

    if ($notifCount !== $prevNotif || $dmCount !== $prevDm) {
        $payload = json_encode(['unread_notifications' => $notifCount, 'unread_dm' => $dmCount]);
        echo "event: counts\ndata: $payload\n\n";
        $prevNotif = $notifCount;
        $prevDm = $dmCount;
        $changed = true;
    }

    if ($latestDmKey > $prevLatestDmKey) {
        // Find which thread the new message is in
        if ($prevLatestDmKey > 0) {
            $stmt = $db->prepare("
                SELECT m.thread_key, m.message_key, m.message_body, m.message_dtime,
                       u.user_display_name, u.user_avatar
                FROM yy_community_dm_message m
                LEFT JOIN yy_user u ON m.user_key = u.user_key
                WHERE m.message_key > ? AND m.user_key != ?
                  AND m.message_active_flag = TRUE
                  AND m.thread_key IN (SELECT thread_key FROM yy_community_dm_participant WHERE user_key = ?)
                ORDER BY m.message_key ASC
            ");
            $stmt->execute([$prevLatestDmKey, $userKey, $userKey]);
            $newMessages = $stmt->fetchAll();
            foreach ($newMessages as $msg) {
                $payload = json_encode($msg);
                echo "event: dm\ndata: $payload\n\n";
            }
            $changed = true;
        }
        $prevLatestDmKey = $latestDmKey;
    }

    if ($changed) {
        flush();
    }

    sleep(3);
}
