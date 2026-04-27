<?php
/**
 * Public API: returns active livestream info.
 * A stream is considered live when feed_stream_flag = TRUE AND feed_stream_dtime > NOW() - 3 hours.
 *
 * GET — returns {live: true/false, feed_name, feed_site_code, feed_source_url}
 */
require_once __DIR__ . '/config.php';

$cacheFile = sys_get_temp_dir() . '/yada_livestream_status.json';
$cacheTtl = 30;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    header('Content-Type: application/json; charset=utf-8');
    readfile($cacheFile);
    exit;
}

$db = getDb();
$stmt = $db->query("
    SELECT feed_key, feed_name, feed_site_code, feed_source_url, feed_account_id, feed_stream_dtime
    FROM yy_feed
    WHERE feed_stream_flag = TRUE AND feed_active_flag = TRUE
      AND feed_stream_dtime IS NOT NULL AND feed_stream_dtime > NOW() - INTERVAL '3 hours'
    LIMIT 1
");
$row = $stmt->fetch();

if ($row) {
    $result = [
        'live' => true,
        'feed_key' => (int)$row['feed_key'],
        'feed_name' => $row['feed_name'],
        'feed_site_code' => $row['feed_site_code'],
        'feed_source_url' => $row['feed_source_url'],
        'feed_account_id' => $row['feed_account_id'],
        'stream_since' => $row['feed_stream_dtime'],
    ];
} else {
    // Not live — still return feed_key for admin controls
    $anyStream = $db->query("SELECT feed_key, feed_name, feed_site_code, feed_source_url FROM yy_feed WHERE feed_stream_flag = TRUE AND feed_active_flag = TRUE LIMIT 1")->fetch();
    $result = ['live' => false];
    if ($anyStream) {
        $result['feed_key'] = (int)$anyStream['feed_key'];
        $result['feed_name'] = $anyStream['feed_name'];
        $result['feed_site_code'] = $anyStream['feed_site_code'];
        $result['feed_source_url'] = $anyStream['feed_source_url'];
    }
}

$json = json_encode($result);
file_put_contents($cacheFile, $json);
header('Content-Type: application/json; charset=utf-8');
echo $json;
