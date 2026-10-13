<?php
/**
 * Puget AI job queue (2026-10-13) — ONE priority queue over every kind of work
 * the Puget GPU box does.
 *
 * Each kind of work is a job class (yy_ai_job_class, sql/ai-queue.sql) with a
 * priority and an "exclusive" flag. The jobs themselves stay in their own
 * tables (yy_tts_audio, yy_feed_item_transcript_job, yy_t2i_job, …); this file
 * reads them all into one list ranked by
 *
 *     class priority DESC, job's in-class priority DESC, submitted ASC
 *
 * and decides who may start next (aiqGateReason, behind gpuGateReason in
 * _gpu_gate.php):
 *   - exclusive classes (image/video/audio generation — the broker lease stops
 *     the STT/TTS containers — and the qwen2.5:72b LLM passes) never run beside
 *     anything else; shared classes (resident TTS/STT engines) run side by side;
 *   - a job waits while a conflicting job is ON the card, or while a conflicting
 *     job ranked ABOVE it is waiting (so a waiting exclusive job can't be
 *     starved by a stream of lower-priority shared jobs).
 * Running jobs are never preempted here.
 *
 * Classes whose own dispatcher runs one job at a time (stt, transcript_build,
 * tts_build) only compete with OTHER classes; classes that spawn one worker per
 * job (image/video/audio_gen) also wait for earlier jobs of their own class.
 * A gen worker that passes the gate holds advisory lock (AIQ_HOLD_NS[class],
 * job key) for the rest of its life — that is what "on the card" means for
 * those tables (their rows say 'running' while still waiting).
 *
 * aiqSnapshot() feeds the admin toolbar widget (api/admin-ai-queue.php).
 */

const AIQ_HOLD_NS = ['image' => 742021, 'video' => 742022, 'audio_gen' => 742023];
const AIQ_PARALLEL_CLASSES = ['image', 'video', 'audio_gen'];
const AIQ_EST_CACHE_SECS = 900;

function aiqClasses(PDO $db, bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = [];
    foreach ($db->query("SELECT ai_job_class_code code, ai_job_class_name name, ai_job_class_description description,
                                ai_job_class_priority priority, ai_job_class_exclusive_flag exclusive,
                                ai_job_class_est_secs est_secs, ai_job_class_sort sort
                           FROM yy_ai_job_class ORDER BY ai_job_class_priority DESC, ai_job_class_sort")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $c['priority'] = (int)$c['priority'];
        $c['exclusive'] = (bool)$c['exclusive'];
        $c['est_secs'] = (int)$c['est_secs'];
        $cache[$c['code']] = $c;
    }
    return $cache;
}

function aiqPidAlive($pid): bool {
    $pid = (int)$pid;
    return $pid > 0 && (@file_exists("/proc/$pid") || (function_exists('posix_kill') && @posix_kill($pid, 0)));
}

function aiqShort(?string $s, int $n = 90): string {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

/**
 * Every waiting / running Puget job as one flat list. Each row:
 *   class, key, state ('running' | 'queued' | 'held'), on_gpu (bool),
 *   label, sub, why (held/waiting reason), prio (in-class), submitted, started
 *   (unix secs), progress (0-100|null), message, est_model/est_audio/est_paras.
 * $limit caps the rows read per table (the gate only needs the head of each).
 */
function aiqJobs(PDO $db, int $limit = 0): array {
    $lim = $limit > 0 ? ' LIMIT ' . (int)$limit : '';
    $out = [];
    $now = time();

    // ── TTS chapter builds. Pending rows only count while the queue is moving
    // (same rule as gpuTtsActive) so an abandoned row can't hold anything.
    $moving = (bool)$db->query("SELECT COUNT(*) FILTER (WHERE tts_audio_status = 'running') > 0
                                     OR COALESCE(MAX(tts_audio_revision_dtime) > NOW() - INTERVAL '2 hours', false)
                                  FROM yy_tts_audio WHERE tts_audio_status IN ('pending','running')")->fetchColumn();
    foreach ($db->query("SELECT a.tts_audio_key k, a.tts_audio_status s, a.tts_audio_progress p, a.tts_audio_message m,
                                EXTRACT(EPOCH FROM COALESCE(a.tts_audio_revision_dtime, a.tts_audio_dtime))::bigint sub,
                                EXTRACT(EPOCH FROM a.tts_audio_started_dtime)::bigint st,
                                v.volume_code vol, c.chapter_name ch, a.tts_audio_paragraph_count paras
                           FROM yy_tts_audio a
                           LEFT JOIN yy_volume v ON v.volume_key = a.volume_key
                           LEFT JOIN yy_chapter c ON c.chapter_key = a.chapter_key
                          WHERE a.tts_audio_status IN ('pending','running')
                          ORDER BY (a.tts_audio_status = 'running') DESC, a.tts_audio_key$lim")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $run = $r['s'] === 'running';
        $out[] = ['class' => 'tts_build', 'key' => (int)$r['k'], 'state' => $run ? 'running' : ($moving ? 'queued' : 'held'),
                  'on_gpu' => $run, 'why' => $run || $moving ? '' : 'TTS queue stalled (no build activity for 2h)',
                  'label' => aiqShort($r['ch'] ?: 'Chapter'), 'sub' => (string)$r['vol'], 'prio' => 0,
                  'submitted' => (int)$r['sub'], 'started' => $run && $r['st'] && $r['st'] > $now - 43200 ? (int)$r['st'] : null,
                  'progress' => $r['p'] !== null ? (float)$r['p'] : null, 'message' => (string)$r['m'],
                  'est_paras' => (int)$r['paras']];
    }

    // ── STT engine runs. The single worker's own cap=1 is enforced by its
    // dispatcher; while a video is being PACKAGED the next one is held.
    if (!function_exists('gpuPackagingActive')) require_once __DIR__ . '/_gpu_gate.php';
    $packaging = gpuPackagingActive($db);
    foreach ($db->query("SELECT j.feed_item_transcript_job_key k, j.job_status s, j.job_progress p, j.job_message m,
                                EXTRACT(EPOCH FROM j.job_dtime)::bigint sub,
                                EXTRACT(EPOCH FROM COALESCE(j.job_running_since, j.job_started_dtime))::bigint st,
                                j.job_model model, COALESCE(j.job_priority, 0) prio, j.feed_item_key item,
                                COALESCE(NULLIF(f.feed_item_title_override, ''), f.feed_item_title_import) title,
                                f.feed_item_duration_seconds dur
                           FROM yy_feed_item_transcript_job j
                           LEFT JOIN yy_feed_item f ON f.feed_item_key = j.feed_item_key
                          WHERE j.job_status IN ('pending','running')
                          ORDER BY (j.job_status = 'running') DESC, j.job_priority DESC, j.feed_item_transcript_job_key$lim")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $run = $r['s'] === 'running';
        // Held back by packaging is still QUEUED (it starts right after) — and
        // the classes ranked below STT keep waiting for it, as before.
        $out[] = ['class' => 'stt', 'key' => (int)$r['k'], 'state' => $run ? 'running' : 'queued',
                  'on_gpu' => $run, 'why' => !$run && $packaging ? 'after the current video is finished' : '',
                  'label' => aiqShort($r['title'] ?: ('Feed item ' . $r['item'])), 'sub' => (string)$r['model'],
                  'prio' => (int)$r['prio'], 'submitted' => (int)$r['sub'], 'started' => $run ? ((int)$r['st'] ?: null) : null,
                  'progress' => $r['p'] !== null ? (float)$r['p'] : null, 'message' => (string)$r['m'],
                  'est_model' => (string)$r['model'], 'est_audio' => (int)$r['dur'], 'item' => (int)$r['item']];
    }

    // ── Consensus builds (+ their qwen2.5:72b pass). The build itself is CPU;
    // it is ON the card only while an LLM pass holds GPU_LLM_LOCK. A pending
    // build still waiting for its own transcriptions is held, not queued.
    $llm = gpuLlmActive($db);
    $initRunning = false;
    foreach ($db->query("SELECT ij.job_key k, ij.job_status s, EXTRACT(EPOCH FROM ij.job_created)::bigint sub,
                                EXTRACT(EPOCH FROM ij.job_started)::bigint st, COALESCE(ij.job_priority, 0) prio, ij.job_item_key item,
                                COALESCE(NULLIF(f.feed_item_title_override, ''), f.feed_item_title_import) title,
                                EXISTS (SELECT 1 FROM yy_feed_item_transcript_job sj WHERE sj.feed_item_key = ij.job_item_key
                                         AND sj.job_status IN ('pending','running')) AS wait_stt
                           FROM yy_feed_item_transcript_init_job ij
                           LEFT JOIN yy_feed_item f ON f.feed_item_key = ij.job_item_key
                          WHERE (ij.job_status = 'pending' AND ij.job_created > NOW() - INTERVAL '24 hours')
                             OR (ij.job_status = 'running' AND COALESCE(ij.job_started, ij.job_created) > NOW() - INTERVAL '4 hours')
                          ORDER BY (ij.job_status = 'running') DESC, ij.job_priority DESC, ij.job_key$lim")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $run = $r['s'] === 'running';
        $waitStt = !$run && ($r['wait_stt'] === true || $r['wait_stt'] === 't');
        if ($run) $initRunning = true;
        $out[] = ['class' => 'transcript_build', 'key' => (int)$r['k'], 'state' => $run ? 'running' : ($waitStt ? 'held' : 'queued'),
                  'on_gpu' => $run && $llm, 'why' => $waitStt ? 'waiting for its transcriptions' : '',
                  'label' => aiqShort($r['title'] ?: ('Feed item ' . $r['item'])), 'sub' => $run && $llm ? 'AI cleanup (qwen2.5:72b)' : 'consensus build',
                  'prio' => (int)$r['prio'], 'submitted' => (int)$r['sub'], 'started' => $run ? ((int)$r['st'] ?: null) : null,
                  'progress' => null, 'message' => '', 'item' => (int)$r['item']];
    }
    if ($llm && !$initRunning) {
        $out[] = ['class' => 'llm_backfill', 'key' => 0, 'state' => 'running', 'on_gpu' => true, 'why' => '',
                  'label' => 'Background transcript cleanup', 'sub' => 'qwen2.5:72b', 'prio' => 0,
                  'submitted' => $now, 'started' => null, 'progress' => null, 'message' => ''];
    }

    // ── Image / video / audio generation: one worker per job. A row is ON the
    // card when its worker holds the AIQ_HOLD_NS lock; a pre-lock worker that is
    // past the gate (running, not "Waiting…"/"Submitting…", pid alive) counts too.
    $holders = [];
    foreach ($db->query("SELECT classid::bigint ns, objid::bigint k FROM pg_locks
                          WHERE locktype = 'advisory' AND granted AND objsubid = 2
                            AND classid IN (" . implode(',', AIQ_HOLD_NS) . ")")->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $holders[(int)$h['ns'] . ':' . (int)$h['k']] = true;
    }
    $gen = ['image' => ['yy_t2i_job', 't2i'], 'video' => ['yy_i2v_job', 'i2v'], 'audio_gen' => ['yy_t2a_job', 't2a']];
    foreach ($gen as $cls => [$tbl, $p]) {
        $rows = []; $chainAlive = false;
        // Puget engines only — cloud providers share these tables but not the card
        // (same host list as gpuIsPugetEndpoint).
        foreach ($db->query("SELECT j.{$p}_job_key k, j.{$p}_job_status s, j.{$p}_job_progress pr, j.{$p}_job_message m,
                                    EXTRACT(EPOCH FROM j.{$p}_job_dtime)::bigint sub, EXTRACT(EPOCH FROM j.{$p}_job_started_dtime)::bigint st,
                                    j.{$p}_job_model_id model, j.{$p}_job_prompt prompt, j.{$p}_job_worker_pid pid
                               FROM $tbl j JOIN yy_provider pv ON pv.provider_key = j.provider_key
                              WHERE j.{$p}_job_status IN ('pending','queued','running')
                                AND pv.provider_endpoint ~ '^https?://(100\\.88\\.122\\.21|gpu\\.yadayah\\.com|localhost|127\\.0\\.0\\.1|172\\.17\\.0\\.1)(:|/|$)'
                              ORDER BY j.{$p}_job_key")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = $r;
            if ($r['s'] === 'running' && aiqPidAlive($r['pid'])) $chainAlive = true;
        }
        foreach ($rows as $r) {
            $msg = (string)$r['m'];
            $alive = aiqPidAlive($r['pid']);
            $held = isset($holders[AIQ_HOLD_NS[$cls] . ':' . (int)$r['k']]);
            $legacy = $r['s'] === 'running' && $alive && stripos($msg, 'Waiting for GPU') !== 0 && $msg !== 'Submitting to engine';
            $onGpu = $held || $legacy;
            // A row nothing will ever start can't block anyone: a 'running' row
            // whose worker is gone, or a 'pending' one with no live worker and
            // no live chain (the per-table cap=1 worker promotes the next on exit).
            $dead = !$onGpu && ($alive ? false : ($r['s'] === 'running' || (!$chainAlive && (int)$r['sub'] < $now - 6 * 3600)));
            $out[] = ['class' => $cls, 'key' => (int)$r['k'], 'state' => $onGpu ? 'running' : ($dead ? 'held' : 'queued'),
                      'on_gpu' => $onGpu, 'why' => $dead ? 'worker not running' : '',
                      'label' => aiqShort($r['prompt'] ?: '(no prompt)'), 'sub' => (string)$r['model'], 'prio' => 0,
                      'submitted' => (int)$r['sub'], 'started' => $onGpu && $r['st'] ? (int)$r['st'] : null,
                      'progress' => $r['pr'] !== null ? (float)$r['pr'] : null, 'message' => $msg];
        }
    }

    // ── TTS QA re-transcriptions.
    foreach ($db->query("SELECT q.job_key k, q.job_status s, q.job_progress p, q.job_message m,
                                EXTRACT(EPOCH FROM q.job_dtime)::bigint sub, EXTRACT(EPOCH FROM q.job_started)::bigint st,
                                c.chapter_name ch, v.volume_code vol
                           FROM yy_tts_qa_job q
                           LEFT JOIN yy_tts_audio a ON a.tts_audio_key = q.job_audio_key
                           LEFT JOIN yy_volume v ON v.volume_key = a.volume_key
                           LEFT JOIN yy_chapter c ON c.chapter_key = a.chapter_key
                          WHERE q.job_status IN ('pending','queued','running')
                          ORDER BY q.job_key$lim")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $run = $r['s'] === 'running';
        $out[] = ['class' => 'tts_qa', 'key' => (int)$r['k'], 'state' => $run ? 'running' : 'queued', 'on_gpu' => $run, 'why' => '',
                  'label' => aiqShort($r['ch'] ?: 'Chapter audio'), 'sub' => (string)$r['vol'], 'prio' => 0,
                  'submitted' => (int)$r['sub'], 'started' => $run ? ((int)$r['st'] ?: null) : null,
                  'progress' => $r['p'] !== null ? (float)$r['p'] : null, 'message' => (string)$r['m']];
    }

    // ── TTS previews.
    foreach ($db->query("SELECT job_key k, job_status s, EXTRACT(EPOCH FROM job_created)::bigint sub, job_voice_code voice,
                                job_text txt, job_para_done d, job_para_total t
                           FROM yy_tts_preview_job WHERE job_status IN ('pending','queued','running')
                          ORDER BY job_key$lim")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $run = $r['s'] === 'running';
        $out[] = ['class' => 'tts_preview', 'key' => (int)$r['k'], 'state' => $run ? 'running' : 'queued', 'on_gpu' => $run, 'why' => '',
                  'label' => aiqShort($r['txt']), 'sub' => (string)$r['voice'], 'prio' => 0,
                  'submitted' => (int)$r['sub'], 'started' => $run ? (int)$r['sub'] : null,
                  'progress' => $r['t'] ? round(100 * (int)$r['d'] / (int)$r['t']) : null, 'message' => ''];
    }

    // ── Ask Yada answers (claimed by ask_worker.py on Puget under a lease).
    foreach ($db->query("SELECT ask_job_key k, job_status s, job_progress pr, job_kind kind, job_model model, question_text q,
                                COALESCE(job_priority, 0) prio, EXTRACT(EPOCH FROM job_dtime)::bigint sub,
                                EXTRACT(EPOCH FROM job_started_dtime)::bigint st, job_lease_until > now() AS leased
                           FROM yy_ask_job WHERE job_status IN ('queued','pending','running')
                          ORDER BY job_priority DESC, ask_job_key$lim")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $run = $r['s'] === 'running' && ($r['leased'] === true || $r['leased'] === 't');
        $out[] = ['class' => $r['kind'] === 'eval' ? 'ask_eval' : 'ask', 'key' => (int)$r['k'], 'state' => $run ? 'running' : 'queued', 'on_gpu' => $run, 'why' => '',
                  'label' => aiqShort($r['q']), 'sub' => trim($r['kind'] . ' ' . $r['model']), 'prio' => (int)$r['prio'],
                  'submitted' => (int)$r['sub'], 'started' => $run ? ((int)$r['st'] ?: null) : null,
                  'progress' => null, 'message' => (string)$r['pr']];
    }

    return $out;
}

/** Sort key: higher = first. Returns [classPriority, inClassPriority, -submitted, -key]. */
function aiqRank(array $j, array $classes): array {
    return [(int)($classes[$j['class']]['priority'] ?? 0), (int)$j['prio'], -(int)$j['submitted'], -(int)$j['key']];
}

/** Order: running first (oldest start first), then queued by rank, then held. */
function aiqSort(array $jobs, array $classes): array {
    $stateOrder = ['running' => 0, 'queued' => 1, 'held' => 2];
    usort($jobs, function ($a, $b) use ($classes, $stateOrder) {
        if ($a['state'] !== $b['state']) return $stateOrder[$a['state']] <=> $stateOrder[$b['state']];
        if ($a['state'] === 'running') return ($a['started'] ?? PHP_INT_MAX) <=> ($b['started'] ?? PHP_INT_MAX);
        return aiqRank($b, $classes) <=> aiqRank($a, $classes);
    });
    return $jobs;
}

/**
 * Null when a job of $class (job $key, or the head of the class) may use the
 * GPU now, else a short human-readable reason.
 */
function aiqGateReason(PDO $db, string $class, ?int $key = null): ?string {
    $classes = aiqClasses($db);
    if (!isset($classes[$class])) return null;
    $jobs = aiqJobs($db, 50);
    $parallel = in_array($class, AIQ_PARALLEL_CLASSES, true);
    $llmPair = ['transcript_build', 'llm_backfill'];

    // Who am I? The given job, else my class's running job (the caller of a
    // single-dispatcher class), else its best queued job.
    $self = null;
    foreach ($jobs as $j) {
        if ($j['class'] !== $class || $j['state'] === 'held') continue;
        if ($key !== null) {
            if ($j['key'] === $key) { $self = $j; break; }
            continue;
        }
        if ($self === null
            || ($j['state'] === 'running' && $self['state'] !== 'running')
            || ($j['state'] === $self['state'] && aiqRank($j, $classes) > aiqRank($self, $classes))) $self = $j;
    }
    if ($self === null) $self = ['class' => $class, 'key' => (int)$key, 'prio' => 0, 'submitted' => time()];
    $myRank = aiqRank($self, $classes);
    $myExcl = $classes[$class]['exclusive'];

    $running = []; $ahead = [];
    foreach ($jobs as $j) {
        if ($j['class'] === $class && $j['key'] === $self['key']) continue;
        // Single-dispatcher classes manage their own lane (STT cap=1, one init worker, TTS build lock).
        if ($j['class'] === $class && !$parallel) continue;
        if (!($myExcl || ($classes[$j['class']]['exclusive'] ?? false))) continue;   // shared + shared: side by side
        if ($j['on_gpu']) {
            // The LLM passes serialise on GPU_LLM_LOCK themselves (gpuLlmLock).
            if (in_array($class, $llmPair, true) && in_array($j['class'], $llmPair, true)) continue;
            $running[$j['class']] = ($running[$j['class']] ?? 0) + 1;
        } elseif ($j['state'] === 'queued' && aiqRank($j, $classes) > $myRank) {
            $ahead[$j['class']] = ($ahead[$j['class']] ?? 0) + 1;
        }
    }
    $why = [];
    foreach ($running as $c => $n) $why[] = $classes[$c]['name'] . ' running';
    foreach ($ahead as $c => $n) {
        $more = $n >= 50 ? '50+' : (string)$n;
        $why[] = "$more {$classes[$c]['name']} job" . ($n === 1 ? '' : 's') . " ahead (priority {$classes[$c]['priority']})";
    }
    return $why ? implode(', ', $why) : null;
}

/** Take / drop the "this gen job is on the card" marker (session advisory lock). */
function aiqHold(PDO $db, string $class, int $key): bool {
    if (!isset(AIQ_HOLD_NS[$class]) || $key <= 0) return false;
    return (bool)$db->query('SELECT pg_try_advisory_lock(' . AIQ_HOLD_NS[$class] . ', ' . $key . ')')->fetchColumn();
}
function aiqUnhold(PDO $db, string $class, int $key): void {
    if (!isset(AIQ_HOLD_NS[$class]) || $key <= 0) return;
    try { $db->query('SELECT pg_advisory_unlock(' . AIQ_HOLD_NS[$class] . ', ' . $key . ')'); } catch (\Throwable $e) {}
}

/**
 * Typical run times from the last 30 days (cached 15 min in /tmp):
 *   stt_ratio[model] = run secs per audio sec, stt_med[model] = median secs,
 *   tts_para = secs per paragraph, med[class] = median secs.
 */
function aiqEstimates(PDO $db): array {
    $file = sys_get_temp_dir() . '/yy_ai_queue_est.json';
    if (is_file($file) && filemtime($file) > time() - AIQ_EST_CACHE_SECS) {
        $e = json_decode((string)@file_get_contents($file), true);
        if (is_array($e)) return $e;
    }
    $e = ['stt_ratio' => [], 'stt_med' => [], 'tts_para' => null, 'med' => []];
    try {
        foreach ($db->query("SELECT j.job_model m,
                                    percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM j.job_completed_dtime - COALESCE(j.job_running_since, j.job_started_dtime))) med,
                                    percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM j.job_completed_dtime - COALESCE(j.job_running_since, j.job_started_dtime)) / NULLIF(f.feed_item_duration_seconds, 0)) ratio
                               FROM yy_feed_item_transcript_job j JOIN yy_feed_item f ON f.feed_item_key = j.feed_item_key
                              WHERE j.job_status = 'complete' AND j.job_completed_dtime > NOW() - INTERVAL '30 days'
                                AND COALESCE(j.job_running_since, j.job_started_dtime) IS NOT NULL
                              GROUP BY 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['med'] !== null) $e['stt_med'][$r['m']] = (float)$r['med'];
            if ($r['ratio'] !== null) $e['stt_ratio'][$r['m']] = (float)$r['ratio'];
        }
        $e['tts_para'] = $db->query("SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM tts_audio_completed_dtime - tts_audio_started_dtime) / tts_audio_paragraph_count)
                                       FROM yy_tts_audio WHERE tts_audio_completed_dtime > NOW() - INTERVAL '90 days' AND tts_audio_paragraph_count > 0
                                        AND tts_audio_completed_dtime - tts_audio_started_dtime BETWEEN INTERVAL '1 second' AND INTERVAL '6 hours'")->fetchColumn();
        $e['tts_para'] = $e['tts_para'] !== null ? (float)$e['tts_para'] : null;
        $med = [
            'image'            => "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM t2i_job_completed_dtime - t2i_job_started_dtime)) FROM yy_t2i_job WHERE t2i_job_status = 'complete' AND t2i_job_completed_dtime > NOW() - INTERVAL '90 days'",
            'video'            => "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM i2v_job_completed_dtime - i2v_job_started_dtime)) FROM yy_i2v_job WHERE i2v_job_status = 'complete' AND i2v_job_completed_dtime > NOW() - INTERVAL '90 days'",
            'audio_gen'        => "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM t2a_job_completed_dtime - t2a_job_started_dtime)) FROM yy_t2a_job WHERE t2a_job_status = 'complete' AND t2a_job_completed_dtime > NOW() - INTERVAL '90 days'",
            'tts_qa'           => "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM job_completed - job_started)) FROM yy_tts_qa_job WHERE job_status = 'done' AND job_completed > NOW() - INTERVAL '90 days'",
            'tts_preview'      => "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM job_completed - job_created)) FROM yy_tts_preview_job WHERE job_status = 'done' AND job_completed > NOW() - INTERVAL '90 days'",
            'ask'              => "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM job_finished_dtime - job_started_dtime)) FROM yy_ask_job WHERE job_status = 'done' AND job_finished_dtime > NOW() - INTERVAL '90 days'",
            'transcript_build' => "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM job_completed - job_started)) FROM yy_feed_item_transcript_init_job WHERE job_status = 'done' AND job_completed > NOW() - INTERVAL '7 days'",
        ];
        foreach ($med as $c => $sql) {
            $v = $db->query($sql)->fetchColumn();
            if ($v !== null && $v !== false && (float)$v > 0) $e['med'][$c] = (float)$v;
        }
    } catch (\Throwable $ex) { /* estimates are best-effort; class defaults apply */ }
    @file_put_contents($file, json_encode($e));
    return $e;
}

/** Estimated total run time (secs) of one job. */
function aiqEstimate(array $j, array $classes, array $est): int {
    $def = (int)($classes[$j['class']]['est_secs'] ?? 300);
    switch ($j['class']) {
        case 'stt':
            $m = $j['est_model'] ?? '';
            if (!empty($j['est_audio']) && isset($est['stt_ratio'][$m])) return max(5, (int)round($est['stt_ratio'][$m] * $j['est_audio']));
            return (int)round($est['stt_med'][$m] ?? $def);
        case 'tts_build':
            if (!empty($j['est_paras']) && !empty($est['tts_para'])) return max(30, (int)round($est['tts_para'] * $j['est_paras']));
            return $def;
        case 'transcript_build':
            // Most recent builds skipped/deferred the LLM pass, so their median
            // undershoots a build that runs it — never estimate below the default.
            return (int)round(max($est['med'][$j['class']] ?? 0, $def));
        default:
            return (int)round(max($est['med'][$j['class']] ?? 0, 0) ?: $def);
    }
}

/**
 * Everything the toolbar widget shows: running jobs, the queue in pick order
 * with estimated duration / start / finish, per-class totals, and the classes.
 * Start/finish come from a small simulation: shared classes run one lane each
 * side by side, an exclusive job waits for everything before it and holds
 * everything after it.
 */
function aiqSnapshot(PDO $db, int $limit = 300): array {
    $classes = aiqClasses($db);
    $est = aiqEstimates($db);
    $jobs = aiqSort(aiqJobs($db), $classes);
    $now = time();

    $lane = []; $barrier = $now; $maxEnd = $now;
    $running = []; $queued = []; $held = []; $byClass = [];
    foreach ($jobs as $j) {
        $c = $classes[$j['class']] ?? ['exclusive' => false, 'name' => $j['class'], 'priority' => 0];
        $dur = aiqEstimate($j, $classes, $est);
        $row = ['class' => $j['class'], 'class_name' => $c['name'], 'priority' => $c['priority'], 'key' => $j['key'],
                'state' => $j['state'], 'on_gpu' => $j['on_gpu'], 'label' => $j['label'], 'sub' => $j['sub'],
                'why' => $j['why'], 'message' => $j['message'], 'progress' => $j['progress'],
                'submitted' => $j['submitted'], 'started' => $j['started'], 'est_secs' => $dur];
        if ($j['state'] === 'running') {
            $el = $j['started'] ? max(0, $now - $j['started']) : 0;
            $p = $j['progress'];
            $left = ($p !== null && $p > 2 && $p < 100 && $el > 0) ? (int)round($el * (100 - $p) / $p) : max($dur - $el, (int)round($dur * 0.1));
            $end = $now + $left;
            $row['remaining_secs'] = $left;
            $row['eta'] = $end;
            $lane[$j['class']] = max($lane[$j['class']] ?? $now, $end);
            if ($c['exclusive'] && $j['on_gpu']) $barrier = max($barrier, $end);
            $maxEnd = max($maxEnd, $end);
            $running[] = $row;
        } elseif ($j['state'] === 'queued') {
            if ($c['exclusive']) {
                $start = max($maxEnd, $barrier);
                $end = $start + $dur;
                $barrier = $end;
            } else {
                $start = max($lane[$j['class']] ?? $now, $barrier);
                $end = $start + $dur;
                $lane[$j['class']] = $end;
            }
            $maxEnd = max($maxEnd, $end);
            $row['start_at'] = $start;
            $row['eta'] = $end;
            $b = &$byClass[$j['class']];
            $b['count'] = ($b['count'] ?? 0) + 1;
            $b['est_secs'] = ($b['est_secs'] ?? 0) + $dur;
            $b['eta'] = $end;
            unset($b);
            if (count($queued) < $limit) $queued[] = $row;
        } else {
            $b = &$byClass[$j['class']];
            $b['held'] = ($b['held'] ?? 0) + 1;
            unset($b);
            if (count($held) < $limit) $held[] = $row;
        }
    }
    $totalQueued = array_sum(array_map(fn($b) => $b['count'] ?? 0, $byClass));
    $totalHeld = array_sum(array_map(fn($b) => $b['held'] ?? 0, $byClass));
    return [
        'now' => $now, 'running' => $running, 'queued' => $queued, 'held' => $held,
        'queued_total' => $totalQueued, 'held_total' => $totalHeld, 'all_done_at' => $maxEnd,
        'by_class' => $byClass, 'classes' => array_values($classes),
    ];
}
