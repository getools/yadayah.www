<?php
// Dry-run preview of the two gap-fix options for a feed_item, focused on a
// time window. NO database writes. Shows three views side-by-side:
//   (current)   the live transcript rows in the window today
//   (option 1)  one-shot fix: replace sparse rows in the window with a
//               single whisper-1-segment row that has the full sentence
//   (option 2)  algorithm fix: re-derive whisper-1-word-join for the
//               whole item under a "sparse-window fallback" rule (when a
//               youtube row's time bucket has fewer whisper-1-word rows
//               than would be expected for its token count, substitute
//               the youtube row's text directly), then show that range.
//
// Usage:
//   docker exec yada-www-web-1 php /var/www/html/api/preview_gap_fix.php <feed_item_key> <start_seg> <end_seg>
// Example:
//   docker exec yada-www-web-1 php /var/www/html/api/preview_gap_fix.php 1011471 00:10:43 00:11:05

require_once __DIR__ . '/config.php';

$itemKey = (int)($argv[1] ?? 0);
$winStart = trim($argv[2] ?? '');
$winEnd   = trim($argv[3] ?? '');
if (!$itemKey || $winStart === '' || $winEnd === '') {
    fwrite(STDERR, "usage: php preview_gap_fix.php <feed_item_key> <start_seg> <end_seg>\n");
    exit(1);
}

$db = getDb();

function secs(string $hms): float {
    if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $hms, $m)) {
        return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (float)$m[3];
    }
    return 0.0;
}
function norm(string $s): string {
    return trim(mb_strtolower($s), " \t\n\r.,;:!?\"()[]<>");
}

$winStartSecs = secs($winStart);
$winEndSecs   = secs($winEnd);

function loadModel(PDO $db, int $itemKey, string $model): array {
    $st = $db->prepare("
        SELECT feed_item_transcript_segment::text AS segment,
               feed_item_transcript_text          AS text,
               feed_item_transcript_sort          AS sort
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
    ");
    $st->execute([$itemKey, $model]);
    return $st->fetchAll();
}

$wordRows    = loadModel($db, $itemKey, 'whisper-1-word');
$ytRows      = loadModel($db, $itemKey, 'youtube');
$segmentRows = loadModel($db, $itemKey, 'whisper-1-segment');

// CURRENT live rows in window
$st = $db->prepare("
    SELECT feed_item_transcript_segment::text AS segment, feed_item_transcript_text AS text
      FROM yy_feed_item_transcript
     WHERE feed_item_key = ?
       AND feed_item_transcript_segment >= ?::interval
       AND feed_item_transcript_segment <  ?::interval
     ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
");
$st->execute([$itemKey, $winStart, $winEnd]);
$liveRows = $st->fetchAll();

echo str_repeat('─', 100) . "\n";
echo "CURRENT live transcript rows [$winStart .. $winEnd)\n";
echo str_repeat('─', 100) . "\n";
foreach ($liveRows as $r) {
    printf("  %-13s | %s\n", $r['segment'], mb_substr($r['text'], 0, 90));
}

// ─── OPTION 1 PREVIEW ───
// Find the contiguous "sparse" run in the window (no whisper-1-word rows
// in the gap), then replace those rows with one whisper-1-segment row
// covering the same time range.
echo "\n" . str_repeat('─', 100) . "\n";
echo "OPTION 1 (manual fix) — replace sparse rows with whisper-1-segment content\n";
echo str_repeat('─', 100) . "\n";

// Find whisper-1-word coverage in the window
$wordCoverage = []; // segments present
foreach ($wordRows as $w) {
    $t = secs((string)$w['segment']);
    if ($t >= $winStartSecs && $t < $winEndSecs) $wordCoverage[] = $t;
}
// Identify gap: longest stretch with no whisper words
sort($wordCoverage);
$prevT = $winStartSecs;
$gapStart = null; $gapEnd = null; $maxGap = 0;
foreach (array_merge($wordCoverage, [$winEndSecs]) as $t) {
    $g = $t - $prevT;
    if ($g > $maxGap) {
        $maxGap = $g;
        $gapStart = $prevT;
        $gapEnd = $t;
    }
    $prevT = $t;
}
if ($maxGap < 2.0) {
    echo "  (no significant gap > 2s in this window — Option 1 would not apply)\n";
} else {
    printf("  detected gap: %.2fs (from %.2fs to %.2fs)\n", $maxGap, $gapStart, $gapEnd);
    // Pull whisper-1-segment text overlapping the gap
    $segText = '';
    foreach ($segmentRows as $sr) {
        $t = secs((string)$sr['segment']);
        if ($t >= $gapStart - 1.0 && $t < $gapEnd + 1.0) {
            $segText .= ($segText ? ' ' : '') . (string)$sr['text'];
        }
    }
    // Compose the post-fix row list: keep rows outside the gap, replace
    // gap with one whisper-1-segment row at $gapStart.
    echo "\n  Resulting live rows would look like:\n";
    foreach ($liveRows as $r) {
        $t = secs((string)$r['segment']);
        if ($t >= $gapStart && $t < $gapEnd) {
            // skip — replaced
            continue;
        }
        printf("    %-13s | %s\n", $r['segment'], mb_substr($r['text'], 0, 90));
    }
    $insertSeg = sprintf('%02d:%02d:%02d', (int)($gapStart / 3600),
        (int)(($gapStart % 3600) / 60), $gapStart % 60);
    printf("    %-13s | %s   ← INSERTED from whisper-1-segment\n",
        $insertSeg, mb_substr($segText, 0, 90));
}

// ─── OPTION 2 PREVIEW ───
// Re-derive whisper-1-word-join under the sparse-fallback rule, but only
// emit rows whose segment falls in the window.
echo "\n" . str_repeat('─', 100) . "\n";
echo "OPTION 2 (algorithm fallback) — youtube text fills rows where whisper-1-word is sparse\n";
echo str_repeat('─', 100) . "\n";

$wordTimes = array_map(function($r) { return secs((string)$r['segment']); }, $wordRows);
$nw = count($wordRows);
$firstIdxAtOrAfter = function (float $T, int $startIdx) use ($wordTimes, $nw): int {
    for ($i = $startIdx; $i < $nw; $i++) if ($wordTimes[$i] >= $T) return $i;
    return $nw;
};

$nr = count($ytRows);
$wsIdx = 0;
$anchors = array_fill(0, $nr, -1);
$prevAnchor = -1;
$ANCHOR_TIME_SLACK = 4.0;
// Phase 1: anchors (same as production algorithm)
foreach ($ytRows as $yi => $refRow) {
    if (!preg_match_all('/\S+/u', (string)$refRow['text'], $mm)) continue;
    $tokens = $mm[0];
    $T = secs((string)$refRow['segment']);
    $minIdx = $prevAnchor + 1;
    $maxIdx = $firstIdxAtOrAfter($T + $ANCHOR_TIME_SLACK, $minIdx);
    if ($maxIdx <= $minIdx) $maxIdx = min($nw, $minIdx + 1);
    $anchor = -1;
    foreach ($tokens as $tok) {
        $rn = norm($tok);
        if ($rn === '') continue;
        for ($i = $minIdx; $i < $maxIdx; $i++) {
            if (norm((string)$wordRows[$i]['text']) === $rn) { $anchor = $i; break 2; }
        }
    }
    if ($anchor < 0) {
        $tIdx = $firstIdxAtOrAfter($T, $minIdx);
        if ($tIdx < $nw) $anchor = $tIdx;
    }
    if ($anchor < 0) break;
    if ($yi === 0 && $anchor > 0) $anchor = 0;
    $anchors[$yi] = $anchor;
    $prevAnchor = $anchor;
}

// Phase 2: build rows, applying fallback when sparse
for ($yi = 0; $yi < $nr; $yi++) {
    $start = $anchors[$yi];
    if ($start < 0) continue;
    $end = $nw - 1;
    for ($j = $yi + 1; $j < $nr; $j++) {
        if ($anchors[$j] > $start) { $end = $anchors[$j] - 1; break; }
    }
    if ($start > $end) continue;
    $rowSeg = $wordRows[$start]['segment'];
    $rowSegSecs = secs((string)$rowSeg);
    if ($rowSegSecs < $winStartSecs - 0.5 || $rowSegSecs >= $winEndSecs + 0.5) continue;

    // Sparseness check: token count of youtube row vs whisper word count
    $ytTokenCount = preg_match_all('/\S+/u', (string)$ytRows[$yi]['text'], $tmp);
    $wordCount = $end - $start + 1;
    $sparse = ($wordCount < max(1, (int)($ytTokenCount * 0.5))) && ($wordCount < 2);

    if ($sparse) {
        $text = (string)$ytRows[$yi]['text'];
        printf("    %-13s | %s   ← FALLBACK to youtube text (%d whisper words for %d yt tokens)\n",
            $rowSeg, mb_substr($text, 0, 90), $wordCount, $ytTokenCount);
    } else {
        $parts = [];
        for ($i = $start; $i <= $end; $i++) $parts[] = (string)$wordRows[$i]['text'];
        $text = implode(' ', $parts);
        printf("    %-13s | %s\n", $rowSeg, mb_substr($text, 0, 90));
    }
}
echo "\n";
