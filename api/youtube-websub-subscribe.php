<?php
/**
 * Subscribe to YouTube WebSub push notifications.
 * Run this on deploy and via cron every 7 days to renew the lease.
 *
 * Cron: 0 3 * * 0 docker exec yada-www-web-1 php /var/www/html/api/youtube-websub-subscribe.php
 */
require_once __DIR__ . '/config.php';

$HUB_URL = 'https://pubsubhubbub.appspot.com/subscribe';
$CALLBACK = 'https://yadayah.com/api/youtube-webhook.php';
$LEASE_SECONDS = 864000; // 10 days

$db = getDb();

// Get all active YouTube channel feeds
$feeds = $db->query("SELECT feed_key, feed_name, feed_account_id FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE AND feed_account_id LIKE 'UC%'")->fetchAll();

$results = [];

foreach ($feeds as $feed) {
    $topic = 'https://www.youtube.com/xml/feeds/videos.xml?channel_id=' . urlencode($feed['feed_account_id']);

    $postData = http_build_query([
        'hub.mode' => 'subscribe',
        'hub.topic' => $topic,
        'hub.callback' => $CALLBACK,
        'hub.verify' => 'async',
        'hub.lease_seconds' => $LEASE_SECONDS,
    ]);

    $ch = curl_init($HUB_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $success = ($httpCode >= 200 && $httpCode < 300);
    $results[] = [
        'feed' => $feed['feed_name'],
        'channel' => $feed['feed_account_id'],
        'status' => $httpCode,
        'success' => $success,
    ];

    echo ($success ? "[OK]" : "[FAIL]") . " {$feed['feed_name']} ({$feed['feed_account_id']}): HTTP {$httpCode}\n";
}

// Log
$db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('youtube_webhook', 'info', ?, ?, TRUE)")
   ->execute(['WebSub subscription ' . (count($results) > 0 ? 'renewed' : 'none'), json_encode($results)]);

if (php_sapi_name() !== 'cli') {
    jsonResponse(['subscriptions' => $results]);
}
