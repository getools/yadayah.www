<?php
/**
 * Recordings queue endpoint with per-column filtering.
 *
 * GET ?page&per_page&sort&dir
 *     &key=          — substring match on feed_item_key (text)
 *     &title=        — substring match on title
 *     &pub_from=     — published date >= YYYY-MM-DD
 *     &pub_to=       — published date <= YYYY-MM-DD
 *     &feed_key=N    — exact feed match
 *     &feed_keys=    — comma-separated yy_feed.feed_key list (any of)
 *     &dur_min_s=N   — duration_seconds >= N
 *     &dur_max_s=N   — duration_seconds <= N
 *     &status=...    — comma-separated subset of: complete, partial, new
 *     &item_status=  — comma-separated subset of: active, restricted, inactive
 *     &page_keys=    — comma-separated yy_page.page_key list
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_transcript_stage.php';
$user = requireAuth();
$db = getDb();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(99999, (int)($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;

$keyQ        = trim((string)($_GET['key'] ?? ''));
$titleQ      = trim((string)($_GET['title'] ?? ''));
$pubFrom     = trim((string)($_GET['pub_from'] ?? ''));
$pubTo       = trim((string)($_GET['pub_to'] ?? ''));
$feedKey     = (int)($_GET['feed_key'] ?? 0);
$feedKeys    = array_values(array_unique(array_filter(array_map('intval',
    explode(',', (string)($_GET['feed_keys'] ?? ''))), function($n){ return $n > 0; })));
if ($feedKey > 0 && !in_array($feedKey, $feedKeys, true)) $feedKeys[] = $feedKey;
// editors filter: comma-separated user_keys from yy_transcript_edit_log (any of),
// plus 'none' = nobody has edited it (system fixes don't count).
$editorParts = array_map('trim', explode(',', (string)($_GET['editors'] ?? '')));
$editorNone  = in_array('none', $editorParts, true);
$editorKeys  = array_values(array_unique(array_filter(array_map('intval', $editorParts), function($n){ return $n > 0; })));
$durMin      = isset($_GET['dur_min_s']) && $_GET['dur_min_s'] !== '' ? (int)$_GET['dur_min_s'] : null;
$durMax      = isset($_GET['dur_max_s']) && $_GET['dur_max_s'] !== '' ? (int)$_GET['dur_max_s'] : null;
$statusRaw   = trim((string)($_GET['status'] ?? ''));
$statuses    = $statusRaw === '' ? [] : array_filter(array_map('trim', explode(',', $statusRaw)));
// page_keys filter: comma-separated list of yy_page.page_key. Items shown
// must be in the pool (yy_section_item) of an Items section on one of these pages.
$pageKeysRaw = trim((string)($_GET['page_keys'] ?? ''));
$pageKeys    = $pageKeysRaw === '' ? [] :
    array_values(array_filter(array_map('intval',
        array_map('trim', explode(',', $pageKeysRaw))), function($n){ return $n > 0; }));
// item_status filter: comma-separated subset of: active, restricted, inactive
//   active     = active_flag = TRUE  AND restricted_flag IS NOT TRUE
//   restricted = restricted_flag = TRUE
//   inactive   = active_flag = FALSE OR restricted_flag = TRUE
$itemStatusRaw = trim((string)($_GET['item_status'] ?? ''));
$itemStatuses  = $itemStatusRaw === '' ? [] : array_filter(array_map('trim', explode(',', $itemStatusRaw)));

// category_keys filter: comma-separated yy_category.category_key (Items-section
// categories). Items shown must be filed under at least one of them.
$categoryKeysRaw = trim((string)($_GET['category_keys'] ?? ''));
$categoryKeys    = $categoryKeysRaw === '' ? [] :
    array_values(array_filter(array_map('intval',
        array_map('trim', explode(',', $categoryKeysRaw))), function($n){ return $n > 0; }));

// caption_status filter: comma-separated transcript_state values — nomp3,
// none, queued, processing, failed, ai_queued, ready, editing, approved, uploaded (old
// values captions / reviewed / pending / missing / editable still accepted).
$captionStatusRaw = trim((string)($_GET['caption_status'] ?? ''));
$captionStatuses  = $captionStatusRaw === '' ? [] : array_filter(array_map('trim', explode(',', $captionStatusRaw)));

// mp3 filter: comma-separated subset of: yes, no.
//   yes = feed_item_audio_file IS NOT NULL
//   no  = feed_item_audio_file IS NULL
$mp3Raw = trim((string)($_GET['mp3'] ?? ''));
$mp3Filter = $mp3Raw === '' ? [] : array_filter(array_map('trim', explode(',', $mp3Raw)));

// episode filter: free-text ILIKE match against feed_item_episode.
$episodeQ = trim((string)($_GET['episode'] ?? ''));

$sortMap = [
    'title'      => 'COALESCE(fi.feed_item_title_override, fi.feed_item_title_import)',
    'published'  => 'COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime)',
    'duration'   => 'fi.feed_item_duration_seconds',
    'feed'       => 'f.feed_name',
    'editors'    => "(SELECT MAX(l.edit_dtime) FROM yy_transcript_edit_log l WHERE l.feed_item_key = fi.feed_item_key AND l.edit_user_key > 0)",
    'key'        => 'fi.feed_item_key',
    'processed'  => "CASE
                       WHEN fi.feed_item_audio_file IS NOT NULL THEN 2
                       WHEN fi.feed_item_audio_resume_seconds > 0 THEN 1
                       ELSE 0 END",
];
$sort = $_GET['sort'] ?? 'published';
$sortCol = $sortMap[$sort] ?? $sortMap['published'];
$dir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = "fi.feed_item_type = 'video'
       AND lower(f.feed_site_code) = 'youtube'
       AND fi.feed_item_external_id IS NOT NULL
       AND fi.feed_item_external_id != ''";
$params = [];

// Apply item_status filter. Default (no checkboxes checked) = active-only,
// matching prior behavior where the recordings list was implicitly active=TRUE.
if (empty($itemStatuses)) {
    $where .= " AND fi.feed_item_active_flag = TRUE AND COALESCE(fi.feed_item_restricted_flag, FALSE) = FALSE";
} else {
    $isOrs = [];
    foreach ($itemStatuses as $s) {
        if ($s === 'active')     $isOrs[] = '(fi.feed_item_active_flag = TRUE AND COALESCE(fi.feed_item_restricted_flag, FALSE) = FALSE)';
        elseif ($s === 'restricted') $isOrs[] = 'fi.feed_item_restricted_flag = TRUE';
        elseif ($s === 'inactive')   $isOrs[] = '(fi.feed_item_active_flag = FALSE OR fi.feed_item_restricted_flag = TRUE)';
    }
    if ($isOrs) $where .= " AND (" . implode(' OR ', $isOrs) . ")";
}

if ($keyQ !== '')   { $where .= " AND CAST(fi.feed_item_key AS TEXT) LIKE ?"; $params[] = $keyQ . '%'; }
if ($titleQ !== '') { $where .= " AND COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) ILIKE ?"; $params[] = '%' . $titleQ . '%'; }
if ($pubFrom !== '') { $where .= " AND COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) >= ?"; $params[] = $pubFrom; }
if ($pubTo !== '')   { $where .= " AND COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) < (?::date + INTERVAL '1 day')"; $params[] = $pubTo; }
if ($feedKeys)      { $where .= " AND fi.feed_key IN (" . implode(',', array_fill(0, count($feedKeys), '?')) . ")"; array_push($params, ...$feedKeys); }
if ($editorKeys || $editorNone) {
    $edOrs = [];
    if ($editorKeys) {
        $edOrs[] = "EXISTS (SELECT 1 FROM yy_transcript_edit_log l WHERE l.feed_item_key = fi.feed_item_key
                     AND l.edit_user_key IN (" . implode(',', array_fill(0, count($editorKeys), '?')) . "))";
        array_push($params, ...$editorKeys);
    }
    if ($editorNone) $edOrs[] = "NOT EXISTS (SELECT 1 FROM yy_transcript_edit_log l WHERE l.feed_item_key = fi.feed_item_key AND l.edit_user_key > 0)";
    $where .= " AND (" . implode(' OR ', $edOrs) . ")";
}
if ($durMin !== null && $durMin >= 0) { $where .= " AND fi.feed_item_duration_seconds >= ?"; $params[] = $durMin; }
if ($durMax !== null && $durMax > 0)  { $where .= " AND fi.feed_item_duration_seconds <= ?"; $params[] = $durMax; }
if ($episodeQ !== '')                 { $where .= " AND fi.feed_item_episode ILIKE ?"; $params[] = '%' . $episodeQ . '%'; }
if (!empty($pageKeys)) {
    $placeholders = implode(',', array_fill(0, count($pageKeys), '?'));
    $where .= " AND EXISTS (SELECT 1 FROM yy_section_item si
                              JOIN yy_section s ON s.section_key = si.section_key AND s.section_type = 'items'
                              WHERE si.feed_item_key = fi.feed_item_key
                                AND s.page_key IN ($placeholders))";
    foreach ($pageKeys as $pk) $params[] = $pk;
}
if (!empty($categoryKeys)) {
    $placeholders = implode(',', array_fill(0, count($categoryKeys), '?'));
    $where .= " AND EXISTS (SELECT 1 FROM yy_section_item csi
                              WHERE csi.feed_item_key = fi.feed_item_key
                                AND csi.category_key IN ($placeholders))";
    foreach ($categoryKeys as $ck) $params[] = $ck;
}
if (!empty($mp3Filter)) {
    // yes/no are mutually exclusive — checking both = no filter applied.
    $hasYes = in_array('yes', $mp3Filter, true);
    $hasNo  = in_array('no',  $mp3Filter, true);
    if ($hasYes && !$hasNo)      $where .= " AND fi.feed_item_audio_file IS NOT NULL";
    else if ($hasNo && !$hasYes) $where .= " AND fi.feed_item_audio_file IS NULL";
}
$sttAny = "EXISTS(SELECT 1 FROM yy_feed_item_transcript_job cj WHERE cj.feed_item_key = fi.feed_item_key AND cj.job_status IN ('pending','running'))";
// Transcript button state, first that applies:
//   nomp3      no MP3 on disk
//   processing STT job or editable-transcript build running
//   queued     STT job or build pending
//   failed     latest build errored and there is no transcript (or nobody is editing it)
//   none       no transcript
//   approved   latest review Approved, and no upload or the upload is older
//   uploaded   on YouTube as captions
//   editing    an admin changed it (edit_status 'Editing'), left a bookmark, or flagged Errors
//   ai_queued  built fast and the AI cleanup pass (transcript-llm-backfill.php) hasn't run
//              on it since — no finished AI snapshot or backfill skip newer than the build
//   ready      baseline built, nobody has touched it yet
$txInit   = "(SELECT ij.job_status FROM yy_feed_item_transcript_init_job ij
               WHERE ij.job_item_key = fi.feed_item_key ORDER BY ij.job_key DESC LIMIT 1)";
$txVal    = "(SELECT v.validation_status FROM yy_feed_item_transcript_validation v
               WHERE v.feed_item_key = fi.feed_item_key ORDER BY v.validation_dtime DESC LIMIT 1)";
$txValDt  = "(SELECT v.validation_dtime FROM yy_feed_item_transcript_validation v
               WHERE v.feed_item_key = fi.feed_item_key ORDER BY v.validation_dtime DESC LIMIT 1)";
$txValBm  = "(SELECT v.validation_bookmark_seconds FROM yy_feed_item_transcript_validation v
               WHERE v.feed_item_key = fi.feed_item_key ORDER BY v.validation_dtime DESC LIMIT 1)";
$txUpl    = "fi.feed_item_yt_caption_uploaded_dtime";
$txAiPending = "EXISTS (SELECT 1 FROM (SELECT ij.job_status, ij.job_params, ij.job_created
                                         FROM yy_feed_item_transcript_init_job ij
                                        WHERE ij.job_item_key = fi.feed_item_key ORDER BY ij.job_key DESC LIMIT 1) lj
        WHERE lj.job_status = 'done'
          AND (lj.job_params LIKE '%\"llm_reconcile\": false%' OR lj.job_params LIKE '%\"llm_reconcile\":false%')
          AND NOT EXISTS (SELECT 1 FROM yy_transcript_snapshot sn WHERE sn.feed_item_key = fi.feed_item_key
                 AND sn.snapshot_reason LIKE 'pre auto LLM reconcile%' AND sn.snapshot_reason NOT LIKE '%incomplete%'
                 AND sn.snapshot_dtime >= lj.job_created)
          AND NOT EXISTS (SELECT 1 FROM yy_transcript_llm_backfill_skip sk WHERE sk.feed_item_key = fi.feed_item_key
                 AND sk.skip_dtime >= lj.job_created))";
$txStateSql = "(CASE
    WHEN NULLIF(btrim(fi.feed_item_audio_file), '') IS NULL THEN 'nomp3'
    WHEN EXISTS(SELECT 1 FROM yy_feed_item_transcript_job cj WHERE cj.feed_item_key = fi.feed_item_key AND cj.job_status = 'running')
         OR $txInit = 'running' THEN 'processing'
    WHEN $sttAny OR $txInit = 'pending' THEN 'queued'
    WHEN NOT EXISTS(SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key)
         THEN CASE WHEN $txInit = 'error' THEN 'failed' ELSE 'none' END
    WHEN $txVal = 'Approved' AND ($txUpl IS NULL OR $txValDt > $txUpl) THEN 'approved'
    WHEN $txUpl IS NOT NULL THEN 'uploaded'
    WHEN EXISTS(SELECT 1 FROM yy_feed_item_transcript_status es WHERE es.feed_item_key = fi.feed_item_key AND es.edit_status = 'Editing')
         OR COALESCE($txValBm, 0) > 0 OR $txVal = 'Errors' THEN 'editing'
    WHEN $txInit = 'error' THEN 'failed'
    WHEN $txAiPending THEN 'ai_queued'
    ELSE 'ready'
  END)";
if (!empty($captionStatuses)) {
    $legacy = ['captions' => ['uploaded'], 'reviewed' => ['approved'], 'pending' => ['ai_queued', 'ready', 'editing'],
               'missing' => ['none'], 'editable' => ['ai_queued', 'ready', 'editing', 'approved', 'uploaded'],
               'generating' => ['processing']];
    $want = [];
    foreach ($captionStatuses as $cs) foreach ($legacy[$cs] ?? [$cs] as $w) $want[$w] = true;
    $want = array_values(array_intersect(array_keys($want),
        ['nomp3', 'none', 'queued', 'processing', 'failed', 'ai_queued', 'ready', 'editing', 'approved', 'uploaded']));
    if ($want) {
        $where .= " AND $txStateSql IN (" . implode(',', array_fill(0, count($want), '?')) . ")";
        array_push($params, ...$want);
    }
}

// Status filter (Recording column): new, partial, recording, queued,
// encoding, transcribing, complete — see the block below.
// Two direct EXISTS on the link table's partial indexes. The old
// sib IN (... UNION ALL ...) form planned as a nested anti-join that
// scanned ~7M rows (37s) whenever New was the only Recording filter.
$clusterHasAudioSql = "(
    EXISTS (SELECT 1 FROM yy_feed_item_link cl JOIN yy_feed_item sib ON sib.feed_item_key = cl.feed_item_key_b
             WHERE cl.feed_item_key_a = fi.feed_item_key
               AND cl.feed_item_link_confirmed_flag IS DISTINCT FROM FALSE
               AND sib.feed_item_audio_file IS NOT NULL)
 OR EXISTS (SELECT 1 FROM yy_feed_item_link cl JOIN yy_feed_item sib ON sib.feed_item_key = cl.feed_item_key_a
             WHERE cl.feed_item_key_b = fi.feed_item_key
               AND cl.feed_item_link_confirmed_flag IS DISTINCT FROM FALSE
               AND sib.feed_item_audio_file IS NOT NULL)
)";
if (!empty($statuses)) {
    // Mirrors the Recording cell, which shows the first that applies:
    // Recording / Queued (live recording-window heartbeat), Encoding (finalize
    // state file), Transcribing (STT job pending/running), then Complete /
    // Partial / New from the MP3 columns. Same files + freshness rules as the
    // in_progress block further down.
    $recNow = $recQueued = $recEnc = [];
    $tmpDir = sys_get_temp_dir();
    foreach (glob("$tmpDir/recording_active_*") ?: [] as $f) {
        $k = (int)substr(basename($f), strlen('recording_active_'));
        clearstatcache(true, $f);
        if ($k <= 0 || time() - (int)@filemtime($f) > 15) continue;
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && !empty($j['recording_now'])) $recNow[] = $k; else $recQueued[] = $k;
    }
    foreach (glob("$tmpDir/finalize_*.state") ?: [] as $f) {
        $k = (int)substr(basename($f), strlen('finalize_'));
        $st = json_decode((string)@file_get_contents($f), true) ?: [];
        $fs = $st['status'] ?? '';
        if ($k > 0 && $fs !== '' && !in_array($fs, ['complete', 'error', 'idle'], true)) $recEnc[] = $k;
    }
    $inKeys = function (array $keys) {
        return $keys ? 'fi.feed_item_key IN (' . implode(',', array_map('intval', $keys)) . ')' : 'FALSE';
    };
    $isRec    = $inKeys(array_merge($recNow, $recQueued));
    $isEnc    = $inKeys($recEnc);
    // Partial / New rows have no MP3, so they can't have STT jobs; leaving
    // $sttAny out of their test also keeps the planner off a 30s+ anti-join.
    $idleRec  = "NOT ($isRec) AND NOT ($isEnc)";
    $idle     = "$idleRec AND NOT $sttAny";
    $hasAudio = "fi.feed_item_audio_file IS NOT NULL";
    $resumed  = "COALESCE(fi.feed_item_audio_resume_seconds, 0) > 0";
    $statusOrs = [];
    foreach ($statuses as $s) {
        if ($s === 'recording')        $statusOrs[] = $inKeys($recNow);
        elseif ($s === 'queued')       $statusOrs[] = $inKeys($recQueued);
        elseif ($s === 'encoding')     $statusOrs[] = "($isEnc AND NOT ($isRec))";
        elseif ($s === 'transcribing') $statusOrs[] = "($sttAny AND NOT ($isRec) AND NOT ($isEnc))";
        elseif ($s === 'complete')     $statusOrs[] = "($idle AND ($hasAudio OR (NOT $resumed AND $clusterHasAudioSql)))";
        elseif ($s === 'partial')      $statusOrs[] = "($idleRec AND NOT $hasAudio AND $resumed)";
        elseif ($s === 'new')          $statusOrs[] = "($idleRec AND NOT $hasAudio AND NOT $resumed AND NOT $clusterHasAudioSql)";
    }
    if ($statusOrs) $where .= " AND (" . implode(' OR ', $statusOrs) . ")";
}

$cntStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi JOIN yy_feed f ON f.feed_key = fi.feed_key WHERE $where");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$orderBy = "$sortCol $dir NULLS LAST, fi.feed_item_key DESC";
// Multi-column sort from the admin table (Shift+click): ?sorts=col:asc,col2:desc.
// Columns whitelisted via $sortMap, directions forced to ASC/DESC; unknown or
// duplicate entries are skipped. Falls back to the single sort/dir above.
$sortsApplied = [];
$sortsRaw = trim((string)($_GET['sorts'] ?? ''));
if ($sortsRaw !== '') {
    $orderParts = [];
    foreach (explode(',', $sortsRaw) as $sortPart) {
        $sortBits = explode(':', trim($sortPart), 2);
        $sortKey = $sortBits[0];
        if (!isset($sortMap[$sortKey]) || isset($sortsApplied[$sortKey])) continue;
        $sortDir = strtolower(trim($sortBits[1] ?? '')) === 'asc' ? 'ASC' : 'DESC';
        $sortsApplied[$sortKey] = strtolower($sortDir);
        $orderParts[] = $sortMap[$sortKey] . " $sortDir NULLS LAST";
        if (count($orderParts) >= 8) break;
    }
    if ($orderParts) $orderBy = implode(', ', $orderParts) . ", fi.feed_item_key DESC";
}

$stmt = $db->prepare("
    SELECT fi.feed_item_key,
           fi.feed_item_external_id,
           fi.feed_item_url,
           COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title,
           fi.feed_item_thumbnail,
           fi.feed_item_duration,
           fi.feed_item_duration_seconds,
           COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS published_dtime,
           fi.feed_item_audio_file,
           fi.feed_item_episode,
           fi.feed_item_audio_resume_seconds AS resume_seconds,
           fi.feed_item_active_flag,
           fi.feed_item_restricted_flag,
           CASE
             WHEN fi.feed_item_restricted_flag = TRUE THEN 'restricted'
             WHEN fi.feed_item_active_flag = FALSE   THEN 'inactive'
             ELSE 'active'
           END AS item_status,
           f.feed_site_code,
           f.feed_key,
           f.feed_name,
           (f.feed_yt_caption_refresh_token IS NOT NULL) AS feed_yt_connected,
           fi.feed_item_yt_caption_status         AS yt_caption_status,
           fi.feed_item_yt_caption_uploaded_dtime AS yt_caption_uploaded_dtime,
           fi.feed_item_yt_caption_message        AS yt_caption_message,
           (SELECT COUNT(*) FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key) AS yt_caption_segment_count,
           fi.feed_item_yt_caption_segments_at_upload AS yt_caption_segments_at_upload,
           -- Transcribe-column inputs (mirrored from admin-feeds.php Items
           -- listing so the same transcribeBtnHtml() helper can render in
           -- both tabs without divergence).
           EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key) AS has_transcript,
           -- Machine-pipeline stage (engines / build / AI cleanup) — see _transcript_stage.php
           " . txStageSelectSql('fi.feed_item_key') . " AS transcript_stage,
           -- Transcript button state — the same expression the Transcript filter uses
           $txStateSql AS transcript_state,
           -- People who have changed this transcript (yy_transcript_edit_log),
           -- most recent first. user 0 = system-automated fixes, left out.
           -- n = hand edits, b = find/replace changes.
           (SELECT json_agg(json_build_object(
                       'name', COALESCE(NULLIF(btrim(u.user_name_display), ''), NULLIF(btrim(u.user_display_name), ''), u.user_code),
                       'n', e.n, 'b', e.b, 'last', e.last) ORDER BY e.last DESC)
              FROM (SELECT l.edit_user_key,
                           COUNT(*) FILTER (WHERE l.edit_batch_key IS NULL)     AS n,
                           COUNT(*) FILTER (WHERE l.edit_batch_key IS NOT NULL) AS b,
                           MAX(l.edit_dtime) AS last
                      FROM yy_transcript_edit_log l
                     WHERE l.feed_item_key = fi.feed_item_key AND l.edit_user_key > 0
                     GROUP BY l.edit_user_key) e
              JOIN yy_user u ON u.user_key = e.edit_user_key) AS transcript_editors,
           (SELECT v.validation_status FROM yy_feed_item_transcript_validation v
              WHERE v.feed_item_key = fi.feed_item_key
              ORDER BY v.validation_dtime DESC LIMIT 1) AS transcript_validation_status,
           (SELECT v.validation_dtime FROM yy_feed_item_transcript_validation v
              WHERE v.feed_item_key = fi.feed_item_key
              ORDER BY v.validation_dtime DESC LIMIT 1) AS transcript_validation_dtime,
           (SELECT v.validation_bookmark_seconds FROM yy_feed_item_transcript_validation v
              WHERE v.feed_item_key = fi.feed_item_key
              ORDER BY v.validation_dtime DESC LIMIT 1) AS transcript_bookmark_seconds,
           CASE
             WHEN fi.feed_item_audio_file IS NOT NULL THEN 'complete'
             WHEN COALESCE(fi.feed_item_audio_resume_seconds, 0) > 0 THEN 'partial'
             WHEN $clusterHasAudioSql THEN 'complete'
             ELSE 'new'
           END AS processed_status
      FROM yy_feed_item fi
      JOIN yy_feed f ON f.feed_key = fi.feed_key
     WHERE $where
     ORDER BY $orderBy
     LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$items = $stmt->fetchAll();

// Attach the Items sections whose pool (yy_section_item) holds each item, so
// the UI can render "On Pages" chips linking to where the item appears.
if ($items) {
    $itemKeys = array_map(fn($r) => (int)$r['feed_item_key'], $items);
    $ph = implode(',', array_fill(0, count($itemKeys), '?'));
    $pgStmt = $db->prepare("
        SELECT si.feed_item_key, s.section_key, s.section_label, s.section_title, s.section_active_flag,
               p.page_key, p.page_code, p.page_title, p.page_url, p.page_active_flag,
               c.category_key, c.category_title, c.category_slug
          FROM yy_section_item si
          JOIN yy_section s ON s.section_key = si.section_key AND s.section_type = 'items'
          LEFT JOIN yy_page p ON p.page_key = s.page_key
          LEFT JOIN yy_category c ON c.category_key = si.category_key
         WHERE si.feed_item_key IN ($ph)
         ORDER BY p.page_header_sort, p.page_key, s.section_sort, s.section_key");
    $pgStmt->execute($itemKeys);
    $pagesByItem = [];
    foreach ($pgStmt->fetchAll() as $r) {
        $k = (int)$r['feed_item_key'];
        unset($r['feed_item_key']);
        $pagesByItem[$k][] = $r;
    }
    foreach ($items as &$it) {
        $it['sections_on'] = $pagesByItem[(int)$it['feed_item_key']] ?? [];
    }
    unset($it);

    // Mark items currently being processed by a finalize / transcribe
    // pipeline so the Recordings tab UI can disable selection and prevent
    // two operators from kicking off conflicting work on the same video.
    // Two signals:
    //   - finalize state file at /tmp/finalize_<key>.state with status
    //     other than complete/error (validating/encoding/finalizing)
    //   - any pending/running row in yy_feed_item_transcript_job
    $jobStmt = $db->prepare("
        SELECT feed_item_key, STRING_AGG(job_status, ',') AS statuses
        FROM yy_feed_item_transcript_job
        WHERE feed_item_key IN ($ph)
          AND job_status IN ('pending', 'running')
        GROUP BY feed_item_key
    ");
    $jobStmt->execute($itemKeys);
    $activeJobByItem = [];
    foreach ($jobStmt->fetchAll() as $r) {
        $activeJobByItem[(int)$r['feed_item_key']] = $r['statuses'];
    }

    foreach ($items as &$it) {
        $key = (int)$it['feed_item_key'];
        $reasons = [];

        $stateFile = sys_get_temp_dir() . "/finalize_$key.state";
        if (is_file($stateFile)) {
            $st = json_decode((string)@file_get_contents($stateFile), true) ?: [];
            $finStatus = $st['status'] ?? '';
            if ($finStatus !== '' && !in_array($finStatus, ['complete', 'error', 'idle'], true)) {
                $reasons[] = "finalize:$finStatus";
            }
        }
        if (!empty($activeJobByItem[$key])) {
            $reasons[] = 'transcribe:' . $activeJobByItem[$key];
        }
        // Recording-popout heartbeat: covers the whole batch the moment an
        // operator clicks "Process Selected". The popout pings
        // /api/admin-recording-heartbeat.php every ~5s with the FULL item
        // list. The file's mtime is our freshness check; the file's body
        // is JSON with the locking user_code so the UI can surface
        // "Locked by <name>" instead of just "Locked".
        $hbFile = sys_get_temp_dir() . "/recording_active_$key";
        if (is_file($hbFile)) {
            clearstatcache(true, $hbFile);
            $age = time() - (int)@filemtime($hbFile);
            if ($age <= 15) {
                $body = @file_get_contents($hbFile);
                $j = $body ? json_decode($body, true) : null;
                $by      = (is_array($j) && !empty($j['user_code']))     ? $j['user_code']         : 'unknown';
                $nowFlag = (is_array($j) && !empty($j['recording_now'])) ? 'now' : 'queued';
                $reasons[] = "recording_active:by:$by:{$age}s_ago:$nowFlag";
            } else {
                // Stale — clean up so it doesn't sit on disk forever.
                @unlink($hbFile);
            }
        }

        // (Parts-mtime fallback removed 2026-05-04. It was added for a
        // window when popouts lacked the heartbeat code, but it caused a
        // 6-minute delay between popout-close and the row clearing —
        // operators expected the lock to release immediately on close.
        // The heartbeat is now reliable for every popout opened post-
        // deploy, so the fallback is no longer needed and was harmful.)

        $it['in_progress']        = !empty($reasons);
        $it['in_progress_reason'] = implode(', ', $reasons);
    }
    unset($it);
}

// Pages with at least one Items section holding items — used to render the
// checkbox filter under the "On Pages" column header.
$allPagesStmt = $db->query("
    SELECT p.page_key, p.page_code, p.page_title, p.page_active_flag
      FROM yy_page p
     WHERE EXISTS (SELECT 1 FROM yy_section_item si
                     JOIN yy_section s ON s.section_key = si.section_key AND s.section_type = 'items'
                    WHERE s.page_key = p.page_key)
     ORDER BY p.page_header_sort, p.page_key");
$allPages = $allPagesStmt->fetchAll();

// Items-section categories with at least one filed item, for the checkbox
// filter under the Categories column header.
$allCategoriesStmt = $db->query("
    SELECT c.category_key, c.category_title, c.category_slug, c.section_key, s.page_key, p.page_code, p.page_title
      FROM yy_category c
      JOIN yy_section s ON s.section_key = c.section_key AND s.section_type = 'items'
      JOIN yy_page p ON p.page_key = s.page_key
     WHERE c.category_active_flag = TRUE
       AND EXISTS (SELECT 1 FROM yy_section_item si WHERE si.category_key = c.category_key)
     ORDER BY p.page_title, s.section_sort, s.section_key, c.category_sort, c.category_title");
$allCategories = $allCategoriesStmt->fetchAll();

// Everyone who has ever edited a transcript, for the Editors filter.
$editorsStmt = $db->query("
    SELECT e.edit_user_key AS user_key,
           COALESCE(NULLIF(btrim(u.user_name_display), ''), NULLIF(btrim(u.user_display_name), ''), u.user_code) AS name,
           e.items
      FROM (SELECT edit_user_key, COUNT(DISTINCT feed_item_key) AS items
              FROM yy_transcript_edit_log WHERE edit_user_key > 0 GROUP BY edit_user_key) e
      JOIN yy_user u ON u.user_key = e.edit_user_key
     ORDER BY 2
");

$feedsStmt = $db->query("
    SELECT DISTINCT f.feed_key, f.feed_name
      FROM yy_feed f
      JOIN yy_feed_item fi ON fi.feed_key = f.feed_key
     WHERE fi.feed_item_active_flag = TRUE AND fi.feed_item_type = 'video'
     ORDER BY f.feed_name
");

jsonResponse([
    'items' => $items,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => max(1, (int)ceil($total / max(1, $perPage))),
    'sort' => $sort, 'dir' => strtolower($dir),
    'sorts' => implode(',', array_map(function ($c, $d) { return "$c:$d"; }, array_keys($sortsApplied), $sortsApplied)),
    'feeds' => $feedsStmt->fetchAll(),
    'editors' => $editorsStmt->fetchAll(),
    'pages' => $allPages,
    'categories' => $allCategories,
    'filters' => [
        'key' => $keyQ, 'title' => $titleQ,
        'pub_from' => $pubFrom, 'pub_to' => $pubTo,
        'feed_key' => $feedKey,
        'feed_keys' => $feedKeys,
        'editors' => array_merge($editorKeys, $editorNone ? ['none'] : []),
        'dur_min_s' => $durMin, 'dur_max_s' => $durMax,
        'status' => $statuses,
        'item_status' => $itemStatuses,
        'page_keys' => $pageKeys,
        'category_keys' => $categoryKeys,
        'mp3' => $mp3Filter,
        'caption_status' => $captionStatuses,
        'episode' => $episodeQ,
    ],
]);
