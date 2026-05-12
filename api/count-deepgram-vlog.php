<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';
$db = getDb();
$cfg = $db->query("
    SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
      FROM yy_feed_page fp JOIN yy_feed f ON f.feed_key = fp.feed_key JOIN yy_page p ON p.page_key = fp.page_key
     WHERE p.page_code = 'vlog' AND fp.feed_page_active_flag = TRUE ORDER BY fp.feed_page_sort LIMIT 1
")->fetch();
$where  = "feed_key = ? AND feed_item_active_flag = TRUE";
$params = [(int)$cfg['feed_key']];
buildFeedPageFilters($where, $params, $cfg['feed_page_filter_include'], $cfg['feed_page_filter_exclude'], $cfg['feed_page_filter_orientation']);
$where .= " AND feed_item_audio_file IS NOT NULL AND feed_item_audio_file <> ''"
       .  " AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_auto a WHERE a.feed_item_key = yy_feed_item.feed_item_key AND a.feed_item_transcript_auto_model = 'deepgram-nova-3')";

$stmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
$stmt->execute($params);
echo "Filtered vlog candidates without deepgram: " . $stmt->fetchColumn() . "\n";

$stmt = $db->prepare("SELECT feed_item_key, COALESCE(feed_item_title_override, feed_item_title_import) AS title, feed_item_duration_seconds AS secs FROM yy_feed_item WHERE $where ORDER BY COALESCE(feed_item_publish_override_dtime, feed_item_publish_import_dtime) DESC NULLS LAST LIMIT 5");
$stmt->execute($params);
echo "First 5:\n";
foreach ($stmt->fetchAll() as $r) {
    echo sprintf("  %d  %4ds  %s\n", $r['feed_item_key'], $r['secs'] ?: 0, substr($r['title'], 0, 80));
}

$stmt = $db->prepare("SELECT COALESCE(SUM(feed_item_duration_seconds),0)/60.0 AS total_min, AVG(feed_item_duration_seconds) AS avg_secs FROM yy_feed_item WHERE $where");
$stmt->execute($params);
$r = $stmt->fetch();
printf("Total audio: %.0f minutes (avg %.0f secs/item)\n", $r['total_min'], $r['avg_secs']);
printf("Est Deepgram cost at \$0.0043/min: \$%.2f\n", $r['total_min'] * 0.0043);
