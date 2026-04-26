<?php
/**
 * Subscribe/unsubscribe to YouTube PubSubHubbub (WebSub) notifications.
 *
 * CLI:  php youtube-push-subscribe.php [subscribe|unsubscribe] [channel_id]
 * Web:  GET /api/youtube-push-subscribe.php?key=yada2026push&action=subscribe
 *       GET /api/youtube-push-subscribe.php?key=yada2026push&action=status
 *
 * Subscribes to YouTube's Atom feed for the channel, so we get push
 * notifications when videos are published or go live.
 *
 * Subscriptions expire after ~10 days and must be renewed.
 * Add to crontab: daily renewal via CLI.
 */
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/config.php';
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026push') {
        requireAuth();
    }
}

$HUB_URL = 'https://pubsubhubbub.appspot.com/subscribe';
$CALLBACK_URL = 'https://yadayah.com/api/youtube-push-callback.php';
$PUSH_SECRET = getenv('YOUTUBE_PUSH_SECRET') ?: 'yada2026push';

$isCli = php_sapi_name() === 'cli';
$action = $isCli ? ($argv[1] ?? 'subscribe') : ($_GET['action'] ?? 'subscribe');
$channelId = $isCli ? ($argv[2] ?? null) : ($_GET['channel_id'] ?? null);

// If no channel specified, subscribe to all active YouTube feeds
if (!$channelId) {
    if (php_sapi_name() !== 'cli') require_once __DIR__ . '/config.php';
    $db = function_exists('getDb') ? getDb() : null;
    if (!$db) {
        // CLI without config loaded
        $host = getenv('PG_HOST') ?: 'localhost';
        $port = getenv('PG_PORT') ?: '5432';
        $name = getenv('PG_DB') ?: 'yada';
        $user = getenv('PG_USER') ?: 'postgres';
        $pass = getenv('PG_PASS') ?: '';
        $db = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    $channels = $db->query("SELECT feed_account_id, feed_name FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE")->fetchAll();
} else {
    $channels = [['feed_account_id' => $channelId, 'feed_name' => $channelId]];
}

if ($action === 'status') {
    $statusFile = sys_get_temp_dir() . '/yada_push_subscriptions.json';
    $subs = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];
    if ($isCli) {
        foreach ($subs as $ch => $info) echo "{$ch}: {$info['status']} (subscribed: {$info['subscribed_at']})\n";
    } else {
        jsonResponse(['subscriptions' => $subs]);
    }
    exit;
}

$results = [];
$statusFile = sys_get_temp_dir() . '/yada_push_subscriptions.json';
$subs = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];

foreach ($channels as $ch) {
    $chId = $ch['feed_account_id'];
    $name = $ch['feed_name'];

    // YouTube channel feeds use channel_id; playlists use playlist_id
    if (strpos($chId, 'PL') === 0) {
        $topicUrl = 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $chId;
    } else {
        $topicUrl = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $chId;
    }

    $postData = [
        'hub.callback' => $CALLBACK_URL,
        'hub.topic' => $topicUrl,
        'hub.verify' => 'async',
        'hub.mode' => $action,
        'hub.secret' => $PUSH_SECRET,
        'hub.lease_seconds' => 864000, // 10 days
    ];

    $ch2 = curl_init($HUB_URL);
    curl_setopt_array($ch2, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch2);
    $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $error = curl_error($ch2);
    curl_close($ch2);

    // 202 = accepted (async verification pending), 204 = success
    $success = ($httpCode === 202 || $httpCode === 204);

    $results[] = [
        'channel' => $chId,
        'name' => $name,
        'action' => $action,
        'http_code' => $httpCode,
        'success' => $success,
        'error' => $error ?: null,
    ];

    if ($success) {
        $subs[$chId] = [
            'name' => $name,
            'status' => $action === 'subscribe' ? 'subscribed' : 'unsubscribed',
            'subscribed_at' => date('c'),
            'topic' => $topicUrl,
            'lease_seconds' => 864000,
        ];
    }

    if ($isCli) {
        echo "{$action} {$name} ({$chId}): HTTP {$httpCode} " . ($success ? 'OK' : 'FAILED') . ($error ? " — {$error}" : '') . "\n";
    }
}

file_put_contents($statusFile, json_encode($subs, JSON_PRETTY_PRINT));

if ($isCli) {
    echo "Done. " . count(array_filter($results, function($r) { return $r['success']; })) . "/" . count($results) . " succeeded.\n";
} else {
    jsonResponse(['results' => $results]);
}
