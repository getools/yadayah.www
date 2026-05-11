<?php
/**
 * "Initialize Transcript" endpoint.
 *
 * Takes a chosen auto-transcribed model's rows out of
 * yy_feed_item_transcript_auto, applies the YadaYah enhancement pass
 * (currently: applyCorrectionDictionary, which absorbs every confirmed
 * Search-&-Replace and bulk-replace into a literal substitution
 * dictionary), writes the cleaned-up rows into
 * yy_feed_item_transcript_autoclean (tagged with the source model), and
 * then copies those rows into the live yy_feed_item_transcript for human
 * editing.
 *
 * POST { item_key:N, model:'whisper-1' }
 *   → { rows_written:int, autoclean_model:'whisper-1', message:'...' }
 *
 * Destructive on yy_feed_item_transcript: replaces the live rows entirely
 * with the autoclean rows of the chosen model. Caller should confirm with
 * the operator before invoking — there is no undo here aside from the
 * weekly DB backup. (The Initialize Transcript modal in admin-feeds.html
 * is responsible for that confirmation.)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // applyCorrectionDictionary

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST only', 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$itemKey = (int)($data['item_key'] ?? 0);
$model   = trim($data['model'] ?? '');
if (!$itemKey) errorResponse('item_key required');
if ($model === '') errorResponse('model required');

// Source rows from _auto (for this item + model)
$srcStmt = $db->prepare("
    SELECT feed_item_transcript_segment::text AS segment,
           feed_item_transcript_text          AS text,
           feed_item_transcript_sort          AS sort
      FROM yy_feed_item_transcript_auto
     WHERE feed_item_key = ?
       AND feed_item_transcript_auto_model = ?
     ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
");
$srcStmt->execute([$itemKey, $model]);
$rows = $srcStmt->fetchAll();
if (!$rows) errorResponse('No rows in _auto for item=' . $itemKey . ' model=' . $model);

$db->beginTransaction();
try {
    // Replace any prior _autoclean rows for this (item, model) so re-runs
    // are clean. Other models' autoclean rows are untouched.
    $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")
       ->execute([$itemKey, $model]);

    $insClean = $db->prepare("
        INSERT INTO yy_feed_item_transcript_autoclean
            (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text,
             feed_item_transcript_sort, feed_item_transcript_autoclean_model)
        VALUES (?, ?::interval, ?, ?, ?)
    ");

    // Live table: this is the destructive step. Wipe and replace.
    $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")
       ->execute([$itemKey]);
    $insLive = $db->prepare("
        INSERT INTO yy_feed_item_transcript
            (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort,
             feed_item_transcript_revision_user_key)
        VALUES (?, ?::interval, ?, ?, ?)
    ");

    // YadaYah enhancement: apply the correction dictionary across the full
    // row sequence so multi-word substitutions (e.g. "Yada Yahda" →
    // "YadaYah") match phrases that straddle row boundaries. The merge
    // collapses spanning rows onto the first row's segment timestamp.
    $cleanedRows = applyCorrectionsAcrossRows($db, $rows);
    $count = 0;
    foreach ($cleanedRows as $i => $r) {
        $clean = mb_substr((string)$r['text'], 0, 2000);
        $insClean->execute([$itemKey, $r['segment'], $clean, $i, $model]);
        $insLive ->execute([$itemKey, $r['segment'], $clean, $i, $user['user_key']]);
        $count++;
    }
    $db->commit();

    jsonResponse([
        'rows_written'    => $count,
        'autoclean_model' => $model,
        'message'         => 'Initialized live transcript from ' . $model . ' (' . $count . ' rows). Apply further edits via the transcript editor.',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    errorResponse('Initialize failed: ' . $e->getMessage());
}
