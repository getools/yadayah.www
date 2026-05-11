<?php
// One-off: take an existing model's rows in yy_feed_item_transcript_auto
// + yy_feed_item_transcript_autoclean for a feed_item and re-clean them
// in place using the rolling-prefix dedup logic + HTML entity decoding.
// Useful when an earlier import naively concatenated rolling-caption
// duplicates and we want to fix the artifacts WITHOUT re-downloading.
//
// Usage (inside web container):
//   docker exec yada-www-web-1 php /var/www/html/api/dedupe_youtube_rows.php <feed_item_key> <model>

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // applyCorrectionDictionary

$itemKey = (int)($argv[1] ?? 0);
$model   = trim($argv[2] ?? '');
if (!$itemKey || $model === '') {
    fwrite(STDERR, "usage: php dedupe_youtube_rows.php <feed_item_key> <model>\n");
    exit(1);
}

$db = getDb();

// Pull current rows in order.
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
$rawRows = $stmt->fetchAll();
if (!$rawRows) { fwrite(STDERR, "no rows for item=$itemKey model=$model\n"); exit(2); }
fwrite(STDERR, "loaded " . count($rawRows) . " raw rows\n");

// Step 1: HTML entity decode the text in each row.
foreach ($rawRows as &$r) {
    $r['text'] = trim(html_entity_decode((string)$r['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}
unset($r);

// Step 2: rolling-prefix dedup. For each row, if its text is wholly
// contained in the previous emitted text → skip. Otherwise strip the
// largest suffix of the previous text that's a prefix of this row's text.
$clean = [];
$tail = '';
foreach ($rawRows as $r) {
    $t = $r['text'];
    if ($t === '') continue;
    // Wholly contained in previous tail? skip.
    if ($tail !== '' && strpos($tail, $t) !== false) continue;
    // Strip overlap.
    if ($tail !== '') {
        $maxOverlap = min(mb_strlen($tail), mb_strlen($t));
        for ($k = $maxOverlap; $k > 0; $k--) {
            $tailSuffix = mb_substr($tail, mb_strlen($tail) - $k);
            $tHead      = mb_substr($t, 0, $k);
            if (strcasecmp($tailSuffix, $tHead) === 0) {
                $t = trim(mb_substr($t, $k));
                break;
            }
        }
    }
    if ($t === '') continue;
    $clean[] = ['segment' => $r['segment'], 'text' => $t];
    $tail = $t;
}
fwrite(STDERR, "after dedup: " . count($clean) . " rows (was " . count($rawRows) . ")\n");

// Step 3: write back. DELETE both model's rows, INSERT cleaned.
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM yy_feed_item_transcript_auto      WHERE feed_item_key = ? AND feed_item_transcript_auto_model      = ?")->execute([$itemKey, $model]);
    $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")->execute([$itemKey, $model]);
    $insAuto = $db->prepare("
        INSERT INTO yy_feed_item_transcript_auto
            (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_auto_model)
        VALUES (?, ?::interval, ?, ?, ?)
    ");
    $insClean = $db->prepare("
        INSERT INTO yy_feed_item_transcript_autoclean
            (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_autoclean_model)
        VALUES (?, ?::interval, ?, ?, ?)
    ");
    // Write _auto rows first (the deduped raw text from the dedup pass above).
    $sort = 0;
    foreach ($clean as $r) {
        $raw = mb_substr($r['text'], 0, 2000);
        $insAuto->execute([$itemKey, $r['segment'], $raw, $sort, $model]);
        $sort++;
    }
    // Compute _autoclean rows by running the cross-row correction pass
    // against the deduped row set. Multi-word corrections that span row
    // boundaries collapse the affected rows onto the first row's segment.
    $cleanedRows = applyCorrectionsAcrossRows($db, $clean);
    $cleanSort = 0;
    foreach ($cleanedRows as $r) {
        $insClean->execute([$itemKey, $r['segment'], mb_substr($r['text'], 0, 2000), $cleanSort, $model]);
        $cleanSort++;
    }
    $db->commit();
    echo "done: wrote $sort _auto + $cleanSort _autoclean row(s) for item $itemKey model=$model (was " . count($rawRows) . ")\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "DB write failed: " . $e->getMessage() . "\n");
    exit(3);
}
