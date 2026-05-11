<?php
/**
 * Multi-version transcript comparison for a single feed_item.
 *
 * Returns every rendering of the transcript currently in the database:
 *   - For each model present in yy_feed_item_transcript_auto: raw auto rows
 *   - For each model present in yy_feed_item_transcript_autoclean: corrected rows
 *   - The live yy_feed_item_transcript rows (the human-editable transcript)
 *
 * Rows are coordinated by SEGMENT TIMESTAMP (HH:MM:SS string). Different
 * model runs produce slightly different segment boundaries, so any timestamp
 * that appears in ANY source becomes a row in the output; cells are NULL
 * where the source doesn't have an entry at that exact timestamp.
 *
 * GET ?item_key=N → {
 *   item_key, has_any:bool,
 *   columns: [
 *     { code:'whisper-1__auto',      model:'whisper-1', kind:'auto'      },
 *     { code:'whisper-1__autoclean', model:'whisper-1', kind:'autoclean' },
 *     { code:'gpt-4o-mini-transcribe__auto', ... },
 *     ...
 *     { code:'current',              model:null,        kind:'current'   },
 *   ],
 *   rows: [
 *     { segment: '00:00:00', cells: { 'whisper-1__auto':'…', 'whisper-1__autoclean':'…', …, 'current':'…' } },
 *     …
 *   ]
 * }
 */
require_once __DIR__ . '/config.php';
requireAuth();
$db = getDb();

$itemKey = (int)($_GET['item_key'] ?? 0);
if (!$itemKey) errorResponse('item_key required');

// Pull all rows from each source. Cast segment to HH:MM:SS for stable
// equality across the three tables, AND concatenate rows that fall in the
// same whole second — important for whisper-1-word, where the underlying
// rows are one-per-word at millisecond precision. The display granularity
// is whole-second; same-second rows render as one space-joined line.
function loadRows(PDO $db, string $table, int $itemKey): array {
    $stmt = $db->prepare("
        SELECT to_char(feed_item_transcript_segment, 'HH24:MI:SS') AS segment,
               feed_item_transcript_text AS text
          FROM $table
         WHERE feed_item_key = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
    ");
    $stmt->execute([$itemKey]);
    $byseg = [];
    foreach ($stmt->fetchAll() as $r) {
        $seg = $r['segment'];
        $byseg[$seg] = isset($byseg[$seg]) ? $byseg[$seg] . ' ' . $r['text'] : $r['text'];
    }
    return $byseg;
}
function loadRowsByModel(PDO $db, string $table, string $modelCol, int $itemKey): array {
    $stmt = $db->prepare("
        SELECT to_char(feed_item_transcript_segment, 'HH24:MI:SS') AS segment,
               feed_item_transcript_text AS text,
               $modelCol AS model
          FROM $table
         WHERE feed_item_key = ?
         ORDER BY $modelCol, feed_item_transcript_sort, feed_item_transcript_segment
    ");
    $stmt->execute([$itemKey]);
    $byModel = [];
    foreach ($stmt->fetchAll() as $r) {
        $m = $r['model']; $seg = $r['segment'];
        if (isset($byModel[$m][$seg])) {
            $byModel[$m][$seg] .= ' ' . $r['text'];
        } else {
            $byModel[$m][$seg] = $r['text'];
        }
    }
    return $byModel;
}

$autoByModel      = loadRowsByModel($db, 'yy_feed_item_transcript_auto',      'feed_item_transcript_auto_model',      $itemKey);
$autocleanByModel = loadRowsByModel($db, 'yy_feed_item_transcript_autoclean', 'feed_item_transcript_autoclean_model', $itemKey);

$liveByseg = loadRows($db, 'yy_feed_item_transcript', $itemKey);

// Stable column ordering: sort model codes alphabetically; for each model,
// auto comes before autoclean. The live "Current" column lands last so
// the eye can sweep left-to-right "raw → cleaned → human."
$modelCodes = array_unique(array_merge(array_keys($autoByModel), array_keys($autocleanByModel)));
sort($modelCodes);

$columns = [];
foreach ($modelCodes as $m) {
    if (isset($autoByModel[$m])) {
        $columns[] = ['code' => $m . '__auto',      'model' => $m, 'kind' => 'auto',
                      'label' => $m . ' (auto)'];
    }
    if (isset($autocleanByModel[$m])) {
        $columns[] = ['code' => $m . '__autoclean', 'model' => $m, 'kind' => 'autoclean',
                      'label' => $m . ' (clean)'];
    }
}
$columns[] = ['code' => 'current', 'model' => null, 'kind' => 'current', 'label' => 'Current'];

// Union of every segment timestamp across every source.
$segSet = [];
foreach ($autoByModel as $segs)      foreach ($segs as $seg => $_) $segSet[$seg] = true;
foreach ($autocleanByModel as $segs) foreach ($segs as $seg => $_) $segSet[$seg] = true;
foreach ($liveByseg as $seg => $_)   $segSet[$seg] = true;
$allSegs = array_keys($segSet);
sort($allSegs);

$rows = [];
foreach ($allSegs as $seg) {
    $cells = [];
    foreach ($modelCodes as $m) {
        if (isset($autoByModel[$m]))      $cells[$m . '__auto']      = $autoByModel[$m][$seg]      ?? null;
        if (isset($autocleanByModel[$m])) $cells[$m . '__autoclean'] = $autocleanByModel[$m][$seg] ?? null;
    }
    $cells['current'] = $liveByseg[$seg] ?? null;
    $rows[] = ['segment' => $seg, 'cells' => $cells];
}

jsonResponse([
    'item_key' => $itemKey,
    'has_any'  => !empty($autoByModel) || !empty($autocleanByModel) || !empty($liveByseg),
    'columns'  => $columns,
    'rows'     => $rows,
]);
