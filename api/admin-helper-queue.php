<?php
/**
 * Helper queue endpoint — given a helper_code, return the work-queue
 * items by running the bound yy_report's SQL (with default parameter
 * values), then enriching each row with current lock state.
 *
 * Lock state is derived the SAME way admin-recordings.php derives it:
 *   /tmp/recording_active_<feed_item_key>  (15s freshness window)
 *
 * Reusing the existing lock files means the helper queue and the
 * Recordings tab can never disagree about what's locked. Heartbeat /
 * release / force-unlock all go through the existing endpoints
 * (admin-recording-heartbeat.php etc.) — this file is read-only.
 *
 * GET ?helper=<helper_code>
 *   → { items: [...], helper: {...}, fetched_at: <iso> }
 *
 * Each item carries:
 *   feed_item_key, title, source_url, thumbnail_url, published,
 *   duration_seconds, audio_file, pages,
 *   lock: null | { by:<user_code>, age_s, state:'now'|'queued', is_mine:bool }
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();

$helperCode = trim((string)($_GET['helper'] ?? ''));
if ($helperCode === '') errorResponse('helper code required');

$h = $db->prepare("
    SELECT h.helper_key, h.helper_code, h.helper_label, h.helper_report_code,
           r.report_key, r.report_label, r.report_query
      FROM yy_helper h
      LEFT JOIN yy_report r ON r.report_code = h.helper_report_code
     WHERE h.helper_code = ?
       AND h.helper_active_flag = TRUE
");
$h->execute([$helperCode]);
$helper = $h->fetch();
if (!$helper) errorResponse('Helper not found', 404);

if (!$helper['report_key']) {
    jsonResponse([
        'helper'     => $helper,
        'items'      => [],
        'fetched_at' => date('c'),
        'note'       => 'Helper has no bound report yet — queue is empty.',
    ]);
}

// Substitute default parameter values into the SQL the same way the
// helper-list count query does (admin-helpers.php). The runner-style
// substitution is plain text; SQL-injection isn't a concern because the
// parameter defaults are admin-authored content, not user input.
$pq = $db->prepare("
    SELECT report_param_code, report_param_default, report_param_datatype
      FROM yy_report_param
     WHERE report_key = ?
");
$pq->execute([(int)$helper['report_key']]);
$params = $pq->fetchAll();

$sql = (string)$helper['report_query'];
foreach ($params as $p) {
    $code = $p['report_param_code'];
    $val  = $p['report_param_default'];
    $dt   = strtolower($p['report_param_datatype']);
    if ($val === null || $val === '') {
        $lit = 'NULL';
    } elseif (in_array($dt, ['int','decimal'])) {
        $lit = is_numeric($val) ? $val : 'NULL';
    } elseif ($dt === 'boolean') {
        $lit = (strtolower($val) === 'true' || $val === '1') ? 'TRUE' : 'FALSE';
    } else {
        $lit = $db->quote($val);
    }
    $sql = preg_replace('/:' . preg_quote($code, '/') . '\b/', $lit, $sql);
}

// Refuse if any unbound :params remain — better to error visibly than to
// fail at query execution with a confusing message.
if (preg_match('/:\w+/', $sql)) {
    errorResponse('Report has unbound parameters; ensure every :param has a default', 500);
}

// Run the report. Wrap in a sub-query so the report can be anything that
// returns a row set; we only need a stable column-name contract on the
// outer SELECT. The standard report contract for queue helpers expects
// at minimum a feed_item_key column; everything else is best-effort.
try {
    $rows = $db->query("SELECT * FROM ($sql) AS _q")->fetchAll();
} catch (Throwable $e) {
    errorResponse('Report query failed: ' . $e->getMessage(), 500);
}

// Pull richer feed_item details for each key (thumbnail, duration,
// embed_id, etc.) so the UI can render the same kind of row the
// Recordings tab does without each report having to spell every column.
$itemKeys = [];
foreach ($rows as $r) {
    if (!empty($r['feed_item_key'])) $itemKeys[] = (int)$r['feed_item_key'];
}
$detailMap = [];
if ($itemKeys) {
    $place = implode(',', array_fill(0, count($itemKeys), '?'));
    $dq = $db->prepare("
        SELECT fi.feed_item_key, fi.feed_item_title_import AS title,
               fi.feed_item_url AS source_url, fi.feed_item_thumbnail AS thumbnail_url,
               fi.feed_item_publish_import_dtime AS published,
               fi.feed_item_duration_seconds AS duration_seconds,
               fi.feed_item_audio_file AS audio_file,
               fi.feed_item_active_flag AS active_flag,
               f.feed_name AS feed_name
          FROM yy_feed_item fi
          LEFT JOIN yy_feed f ON f.feed_key = fi.feed_key
         WHERE fi.feed_item_key IN ($place)
    ");
    $dq->execute($itemKeys);
    foreach ($dq->fetchAll() as $d) {
        $detailMap[(int)$d['feed_item_key']] = $d;
    }
}

// Lock-state enrichment via /tmp/recording_active_<key>. 15s freshness.
$tmp     = sys_get_temp_dir();
$myCode  = (string)($user['user_code'] ?? '');
$now     = time();
$out     = [];
foreach ($rows as $r) {
    $key = (int)($r['feed_item_key'] ?? 0);
    if (!$key) continue;
    $detail = $detailMap[$key] ?? [];
    $merged = array_merge($r, $detail);
    $merged['feed_item_key'] = $key;

    $lock = null;
    $hbFile = "$tmp/recording_active_$key";
    if (is_file($hbFile)) {
        clearstatcache(true, $hbFile);
        $age = $now - (int)@filemtime($hbFile);
        if ($age <= 15) {
            $j = json_decode((string)@file_get_contents($hbFile), true) ?: [];
            $by = $j['user_code'] ?? 'unknown';
            $lock = [
                'by'      => $by,
                'age_s'   => $age,
                'state'   => !empty($j['recording_now']) ? 'now' : 'queued',
                'is_mine' => $by === $myCode,
            ];
        } else {
            @unlink($hbFile);
        }
    }
    $merged['lock'] = $lock;
    $out[] = $merged;
}

jsonResponse([
    'helper'     => $helper,
    'items'      => $out,
    'fetched_at' => date('c'),
]);
