<?php
// Rebuild yy_feed_item_transcript_autoclean for one (feed_item_key, model)
// pair from the current yy_feed_item_transcript_auto rows, using the
// cross-row correction matcher. No re-transcription; pure DB work.
//
// Usage (in the web container):
//   docker exec yada-www-web-1 php /var/www/html/api/rebuild_autoclean.php <feed_item_key> [model]
//
// If <model> is omitted, rebuilds every model present in _auto for that item.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php';

$itemKey = (int)($argv[1] ?? 0);
$onlyModel = trim($argv[2] ?? '');
if (!$itemKey) {
    fwrite(STDERR, "usage: php rebuild_autoclean.php <feed_item_key> [model]\n");
    exit(1);
}

$db = getDb();

// Discover which models to rebuild.
if ($onlyModel !== '') {
    $models = [$onlyModel];
} else {
    $stmt = $db->prepare("
        SELECT DISTINCT feed_item_transcript_auto_model AS m
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
         ORDER BY 1
    ");
    $stmt->execute([$itemKey]);
    $models = array_column($stmt->fetchAll(), 'm');
}
if (!$models) { fwrite(STDERR, "no _auto data for item $itemKey\n"); exit(2); }

foreach ($models as $model) {
    $stmt = $db->prepare("
        SELECT feed_item_transcript_segment::text AS segment,
               feed_item_transcript_text          AS text,
               feed_item_transcript_sort          AS sort
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
           AND feed_item_transcript_auto_model = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
    ");
    $stmt->execute([$itemKey, $model]);
    $autoRows = $stmt->fetchAll();
    if (!$autoRows) {
        echo "skip $model: 0 _auto rows for item $itemKey\n";
        continue;
    }

    $cleanedRows = applyCorrectionsAcrossRows($db, $autoRows);

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")
           ->execute([$itemKey, $model]);
        $ins = $db->prepare("
            INSERT INTO yy_feed_item_transcript_autoclean
                (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text,
                 feed_item_transcript_sort, feed_item_transcript_autoclean_model)
            VALUES (?, ?::interval, ?, ?, ?)
        ");
        $sort = 0;
        foreach ($cleanedRows as $r) {
            $ins->execute([$itemKey, $r['segment'], mb_substr((string)$r['text'], 0, 2000), $sort, $model]);
            $sort++;
        }
        $db->commit();
        echo "rebuilt $model: " . count($autoRows) . " _auto → " . count($cleanedRows) . " _autoclean rows\n";
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        fwrite(STDERR, "$model failed: " . $e->getMessage() . "\n");
    }
}
