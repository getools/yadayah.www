<?php
/**
 * Puget AI job log — admin-ai.html Config → Log.
 *
 * Current AND past jobs from every AI job table (the same 9 tables the queue
 * in _ai_queue.php reads), normalised to one row shape:
 *
 *   GET ?class=a,b&status=active|done|failed|cancelled&q=title|model|job#&limit=N&offset=N
 *       → {rows:[{class, key, status, submitted, started, finished, label,
 *                 sub, message, error, progress, user}], total, counts}
 *         Active rows also carry the live queue state from aiqJobs():
 *         state (running|queued|held), on_gpu, why.
 *   GET ?class=X&key=N
 *       → {class, key, row:{every column of that job row}, extra:{…}}
 *
 * ⚠ yy_tts_audio holds ONE row per chapter build slot (re-builds update it in
 * place), so TTS chapter builds show their latest run only.
 * llm_backfill has no job table — it only appears in the live queue.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_gpu_gate.php';

requireAuth();
$db = getDb();

/** class → [table, pk, status col, list SELECT (FROM … included)] */
function aiLogSources(): array {
    $title = "COALESCE(NULLIF(f.feed_item_title_override, ''), f.feed_item_title_import)";
    $gen = function (string $cls, string $p, string $tbl): array {
        return [$tbl, "{$p}_job_key", "{$p}_job_status",
            "SELECT '$cls' cls, j.{$p}_job_key k, j.{$p}_job_status st,
                    j.{$p}_job_dtime sub, j.{$p}_job_started_dtime sta, j.{$p}_job_completed_dtime fin,
                    j.{$p}_job_prompt lbl, concat_ws(' · ', pv.provider_label, j.{$p}_job_model_id) sub2,
                    j.{$p}_job_message msg, j.{$p}_job_error err, j.{$p}_job_progress::numeric prog,
                    j.{$p}_job_revision_user_key uk
               FROM $tbl j LEFT JOIN yy_provider pv ON pv.provider_key = j.provider_key"];
    };
    return [
        'tts_build' => ['yy_tts_audio', 'tts_audio_key', 'tts_audio_status',
            "SELECT 'tts_build' cls, a.tts_audio_key k, a.tts_audio_status st,
                    COALESCE(a.tts_audio_revision_dtime, a.tts_audio_dtime) sub, a.tts_audio_started_dtime sta, a.tts_audio_completed_dtime fin,
                    COALESCE(c.chapter_name, 'Chapter') lbl, concat_ws(' · ', v.volume_code, a.tts_audio_paragraph_count || ' paras') sub2,
                    a.tts_audio_message msg, a.tts_audio_error err, a.tts_audio_progress::numeric prog, a.tts_audio_revision_user_key uk
               FROM yy_tts_audio a
               LEFT JOIN yy_volume v ON v.volume_key = a.volume_key
               LEFT JOIN yy_chapter c ON c.chapter_key = a.chapter_key"],
        'stt' => ['yy_feed_item_transcript_job', 'feed_item_transcript_job_key', 'job_status',
            "SELECT 'stt' cls, j.feed_item_transcript_job_key k, j.job_status st,
                    j.job_dtime sub, COALESCE(j.job_started_dtime, j.job_running_since) sta, j.job_completed_dtime fin,
                    COALESCE($title, 'Feed item ' || j.feed_item_key) lbl, j.job_model sub2,
                    j.job_message msg, j.job_error err, j.job_progress::numeric prog, j.user_key uk
               FROM yy_feed_item_transcript_job j LEFT JOIN yy_feed_item f ON f.feed_item_key = j.feed_item_key"],
        'transcript_build' => ['yy_feed_item_transcript_init_job', 'job_key', 'job_status',
            "SELECT 'transcript_build' cls, j.job_key k, j.job_status st,
                    j.job_created sub, j.job_started sta, j.job_completed fin,
                    COALESCE($title, 'Feed item ' || j.job_item_key) lbl,
                    concat_ws(' · ', j.job_model, CASE WHEN j.job_rows_written IS NOT NULL THEN j.job_rows_written || ' rows' END) sub2,
                    NULL::text msg, j.job_error err, NULL::numeric prog, j.job_user_key uk
               FROM yy_feed_item_transcript_init_job j LEFT JOIN yy_feed_item f ON f.feed_item_key = j.job_item_key"],
        'image' => $gen('image', 't2i', 'yy_t2i_job'),
        'video' => $gen('video', 'i2v', 'yy_i2v_job'),
        'audio_gen' => $gen('audio_gen', 't2a', 'yy_t2a_job'),
        'tts_qa' => ['yy_tts_qa_job', 'job_key', 'job_status',
            "SELECT 'tts_qa' cls, q.job_key k, q.job_status st,
                    q.job_dtime sub, q.job_started sta, q.job_completed fin,
                    COALESCE(c.chapter_name, 'Chapter audio') lbl, concat_ws(' · ', v.volume_code, array_to_string(q.job_engines, ', ')) sub2,
                    q.job_message msg, q.job_error err, q.job_progress::numeric prog, q.job_user_key uk
               FROM yy_tts_qa_job q
               LEFT JOIN yy_tts_audio a ON a.tts_audio_key = q.job_audio_key
               LEFT JOIN yy_volume v ON v.volume_key = a.volume_key
               LEFT JOIN yy_chapter c ON c.chapter_key = a.chapter_key"],
        'tts_preview' => ['yy_tts_preview_job', 'job_key', 'job_status',
            "SELECT 'tts_preview' cls, p.job_key k, p.job_status st,
                    p.job_created sub, NULL::timestamptz sta, p.job_completed fin,
                    p.job_text lbl, concat_ws(' · ', p.job_voice_code, NULLIF(p.job_style, '')) sub2,
                    CASE WHEN p.job_para_total > 0 THEN p.job_para_done || '/' || p.job_para_total || ' chunks' END msg,
                    p.job_error err, CASE WHEN p.job_para_total > 0 THEN round(100.0 * p.job_para_done / p.job_para_total) END prog,
                    p.job_user_key uk
               FROM yy_tts_preview_job p"],
        // ask + ask_eval share yy_ask_job (job_kind = 'eval' → ask_eval).
        'ask' => ['yy_ask_job', 'ask_job_key', 'job_status',
            "SELECT CASE WHEN a.job_kind = 'eval' THEN 'ask_eval' ELSE 'ask' END cls, a.ask_job_key k, a.job_status st,
                    a.job_dtime sub, a.job_started_dtime sta, a.job_finished_dtime fin,
                    a.question_text lbl, concat_ws(' · ', a.job_kind, a.job_model, a.job_worker) sub2,
                    a.job_progress msg, a.job_error err, NULL::numeric prog, a.user_key uk
               FROM yy_ask_job a"],
    ];
}

const AILOG_STATUS = [
    'active'    => ['pending', 'queued', 'running'],
    'done'      => ['complete', 'completed', 'done'],
    'failed'    => ['failed', 'error'],
    'cancelled' => ['cancelled', 'canceled'],
];

$sources = aiLogSources();
$classOf = fn(string $c) => $c === 'ask_eval' ? 'ask' : $c;   // which source holds a class

// ── One job, every column ───────────────────────────────────────────────
if (isset($_GET['key'])) {
    $cls = (string)($_GET['class'] ?? '');
    $src = $sources[$classOf($cls)] ?? null;
    if (!$src) errorResponse('unknown class');
    [$tbl, $pk] = $src;
    $st = $db->prepare("SELECT row_to_json(t) FROM $tbl t WHERE $pk = ?");
    $st->execute([(int)$_GET['key']]);
    $row = $st->fetchColumn();
    if ($row === false) errorResponse('job not found', 404);
    $row = json_decode($row, true);

    // Names for the keys a person can't read.
    $extra = [];
    $userKeys = [];
    foreach ($row as $col => $v) if ($v && preg_match('/user_key$/', $col)) $userKeys[] = (int)$v;
    if ($userKeys) {
        $in = implode(',', array_unique($userKeys));
        foreach ($db->query("SELECT user_key, COALESCE(NULLIF(user_display_name, ''), NULLIF(user_name_full, ''), user_code) n
                               FROM yy_user WHERE user_key IN ($in)") as $u) $extra['user ' . $u['user_key']] = $u['n'];
    }
    if (!empty($row['provider_key'])) {
        $p = $db->prepare('SELECT provider_label, provider_endpoint FROM yy_provider WHERE provider_key = ?');
        $p->execute([(int)$row['provider_key']]);
        if ($pv = $p->fetch(PDO::FETCH_ASSOC)) { $extra['provider'] = $pv['provider_label']; $extra['endpoint'] = $pv['provider_endpoint']; }
    }
    $feed = $row['feed_item_key'] ?? $row['job_item_key'] ?? null;
    if ($feed) {
        $f = $db->prepare("SELECT COALESCE(NULLIF(feed_item_title_override, ''), feed_item_title_import) t, feed_item_duration_seconds d
                             FROM yy_feed_item WHERE feed_item_key = ?");
        $f->execute([(int)$feed]);
        if ($fi = $f->fetch(PDO::FETCH_ASSOC)) { $extra['feed item'] = $fi['t']; $extra['audio length (s)'] = $fi['d']; }
    }
    $audioKey = $tbl === 'yy_tts_audio' ? ($row['tts_audio_key'] ?? null) : ($row['job_audio_key'] ?? null);
    $volKey = $row['volume_key'] ?? null; $chKey = $row['chapter_key'] ?? null;
    if ($audioKey && !$chKey) {
        $a = $db->prepare('SELECT volume_key, chapter_key FROM yy_tts_audio WHERE tts_audio_key = ?');
        $a->execute([(int)$audioKey]);
        if ($ar = $a->fetch(PDO::FETCH_ASSOC)) { $volKey = $ar['volume_key']; $chKey = $ar['chapter_key']; }
    }
    if ($volKey) { $q = $db->prepare('SELECT volume_code FROM yy_volume WHERE volume_key = ?'); $q->execute([(int)$volKey]); $extra['volume'] = $q->fetchColumn() ?: null; }
    if ($chKey)  { $q = $db->prepare('SELECT chapter_name FROM yy_chapter WHERE chapter_key = ?'); $q->execute([(int)$chKey]); $extra['chapter'] = $q->fetchColumn() ?: null; }

    jsonResponse(['class' => $cls, 'key' => (int)$_GET['key'], 'table' => $tbl, 'row' => $row, 'extra' => array_filter($extra, fn($v) => $v !== null)]);
}

// ── List ────────────────────────────────────────────────────────────────
$want = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['class'] ?? '')))));
$parts = [];
foreach ($sources as $code => $src) {
    if ($want && !in_array($code, $want, true) && !($code === 'ask' && in_array('ask_eval', $want, true))) continue;
    $parts[] = $src[3];
}
if (!$parts) jsonResponse(['rows' => [], 'total' => 0, 'counts' => []]);

$where = []; $params = [];
if ($want) {
    $where[] = 'cls IN (' . implode(',', array_fill(0, count($want), '?')) . ')';
    array_push($params, ...$want);
}
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    // Title / model / job # only — message and error text are full of words
    // like "fallback" that drown a title search.
    if (ctype_digit($q)) { $where[] = '(k = ? OR lbl ILIKE ? OR sub2 ILIKE ?)'; $params[] = (int)$q; }
    else $where[] = '(lbl ILIKE ? OR sub2 ILIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($params, $like, $like);
}
$baseWhere = $where; $baseParams = $params;   // counts ignore the status filter
$status = (string)($_GET['status'] ?? '');
if (isset(AILOG_STATUS[$status])) {
    $where[] = 'st IN (' . implode(',', array_fill(0, count(AILOG_STATUS[$status]), '?')) . ')';
    array_push($params, ...AILOG_STATUS[$status]);
}
$union = '(' . implode("\n UNION ALL\n", $parts) . ') x';
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

// Active first (so "current" is always on top), then newest submitted.
$sql = "SELECT cls, k, st, EXTRACT(EPOCH FROM sub)::bigint sub, EXTRACT(EPOCH FROM sta)::bigint sta,
               EXTRACT(EPOCH FROM fin)::bigint fin, left(lbl, 300) lbl, sub2, left(msg, 500) msg, left(err, 2000) err, prog, uk,
               COUNT(*) OVER () total
          FROM $union" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
         ORDER BY (st IN ('pending','queued','running')) DESC, sub DESC NULLS LAST, k DESC
         LIMIT $limit OFFSET $offset";
$st = $db->prepare($sql);
$st->execute($params);
$raw = $st->fetchAll(PDO::FETCH_ASSOC);

$cst = $db->prepare("SELECT st, COUNT(*) n FROM $union" . ($baseWhere ? ' WHERE ' . implode(' AND ', $baseWhere) : '') . ' GROUP BY st');
$cst->execute($baseParams);
$counts = ['active' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0, 'other' => 0];
foreach ($cst->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $g = 'other';
    foreach (AILOG_STATUS as $name => $list) if (in_array($c['st'], $list, true)) { $g = $name; break; }
    $counts[$g] += (int)$c['n'];
}

$users = [];
$uks = array_unique(array_filter(array_map(fn($r) => (int)$r['uk'], $raw)));
if ($uks) {
    foreach ($db->query('SELECT user_key, COALESCE(NULLIF(user_display_name, \'\'), NULLIF(user_name_full, \'\'), user_code) n
                           FROM yy_user WHERE user_key IN (' . implode(',', $uks) . ')') as $u) $users[(int)$u['user_key']] = $u['n'];
}

// Live queue state for the active rows on this page.
$live = [];
$hasActive = false;
foreach ($raw as $r) if (in_array($r['st'], AILOG_STATUS['active'], true)) { $hasActive = true; break; }
if ($hasActive) {
    foreach (aiqJobs($db) as $j) $live[$j['class'] . ':' . $j['key']] = $j;
}

$rows = [];
foreach ($raw as $r) {
    $row = ['class' => $r['cls'], 'key' => (int)$r['k'], 'status' => $r['st'],
            'submitted' => $r['sub'] !== null ? (int)$r['sub'] : null, 'started' => $r['sta'] !== null ? (int)$r['sta'] : null,
            'finished' => $r['fin'] !== null ? (int)$r['fin'] : null,
            'label' => aiqShort($r['lbl'] ?? '', 160), 'sub' => (string)$r['sub2'], 'message' => (string)$r['msg'],
            'error' => (string)$r['err'], 'progress' => $r['prog'] !== null ? (float)$r['prog'] : null,
            'user' => $r['uk'] ? ($users[(int)$r['uk']] ?? ('#' . $r['uk'])) : null];
    if (isset($live[$r['cls'] . ':' . $r['k']])) {
        $j = $live[$r['cls'] . ':' . $r['k']];
        $row['state'] = $j['state']; $row['on_gpu'] = $j['on_gpu']; $row['why'] = $j['why'];
        if ($row['started'] === null && $j['started']) $row['started'] = $j['started'];
    }
    $rows[] = $row;
}

jsonResponse(['rows' => $rows, 'total' => $raw ? (int)$raw[0]['total'] : 0, 'counts' => $counts,
               'offset' => $offset, 'limit' => $limit]);
