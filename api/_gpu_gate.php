<?php
/**
 * Puget GPU gate (2026-10-12) — keeps the one 96 GB card from running out.
 *
 * Resident engines (tts-engine ~16G + STT engines ~20G + f5) always fit
 * together. What overflows the card is (a) ollama qwen2.5:72b (~50G) for the
 * transcript LLM passes and (b) image/video/audio generation, whose broker
 * lease unloads ollama and STOPS the STT/TTS containers (killing their jobs).
 * So those two are gated; TTS builds are top priority and never wait.
 *
 * 2026-10-13: WHO waits for whom is now decided by the Puget AI job queue
 * (_ai_queue.php): one queue over every job table, ordered by job-class
 * priority (yy_ai_job_class, editable from the admin toolbar queue widget),
 * then in-class priority, then submitted time. Gate class names map to job
 * classes; with the default priorities the behaviour matches the old rules:
 *
 *   gate class   job class          waits while (defaults)
 *   'llm_bg'     llm_backfill       anything is running or queued (lowest priority)
 *   'llm'        transcript_build   TTS / QA / gen running or queued ahead · STT running
 *                (Layer-3 of a fresh consensus build — waits up to 3h, then deferred)
 *   'gen'/'image'/'video'/'audio_gen'   anything running · TTS / QA / earlier gen queued
 *   'stt'        stt                gen / LLM running · gen / build queued ahead ·
 *                                   a video is being PACKAGED (gpuPackagingActive)
 *                (next job stays pending; gpu-queue-tick.php restarts the queue)
 *
 * "LLM pass running" = some process holds advisory lock GPU_LLM_LOCK (taken
 * by the backfill and the init worker around llmReconcileTranscript).
 */

require_once __DIR__ . '/_ai_queue.php';

const GPU_LLM_LOCK = 742010;
const GPU_GATE_CLASS_MAP = ['llm_bg' => 'llm_backfill', 'llm' => 'transcript_build', 'gen' => 'image'];

// Pending builds only count while the TTS queue is actually moving (a build
// running, or any build row touched in the last 2h) — so an abandoned
// 'pending' row can't close the gate forever.
function gpuTtsActive(PDO $db): int {
    return (int)$db->query("SELECT CASE WHEN COUNT(*) FILTER (WHERE tts_audio_status = 'running') > 0
                                          OR MAX(tts_audio_revision_dtime) > NOW() - INTERVAL '2 hours'
                                        THEN COUNT(*) ELSE 0 END
                              FROM yy_tts_audio WHERE tts_audio_status IN ('pending','running')")->fetchColumn();
}

/** Image/video/audio generation jobs that hold (or are waiting for) the GPU. */
function gpuGenActive(PDO $db): int {
    return (int)$db->query("SELECT (SELECT COUNT(*) FROM yy_t2i_job WHERE t2i_job_status = 'running')
                                 + (SELECT COUNT(*) FROM yy_i2v_job WHERE i2v_job_status = 'running')
                                 + (SELECT COUNT(*) FROM yy_t2a_job WHERE t2a_job_status = 'running')")->fetchColumn();
}

function gpuSttCounts(PDO $db): array {
    $r = $db->query("SELECT COUNT(*) FILTER (WHERE job_status = 'running') AS running,
                            COUNT(*) FILTER (WHERE job_status = 'pending') AS pending
                       FROM yy_feed_item_transcript_job WHERE job_status IN ('pending','running')")->fetch(PDO::FETCH_ASSOC);
    return ['running' => (int)$r['running'], 'pending' => (int)$r['pending']];
}

/**
 * Per-video pipeline (2026-10-13): consensus builds that are PACKAGING a video
 * (build → LLM cleanup → anchor → speaker naming) — i.e. pending/running init
 * jobs whose item has NO engine (STT) job still pending/running. While one
 * exists the STT queue does not start the next video, so each video is
 * finished before the next begins. Builds still waiting for their own
 * baselines don't count (that would deadlock), and stale rows (pending > 24h,
 * running > 4h) can't hold the queue shut.
 */
function gpuPackagingActive(PDO $db): int {
    return (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_init_job ij
                             WHERE ij.job_model = 'consensus'
                               AND ((ij.job_status = 'pending' AND ij.job_created > NOW() - INTERVAL '24 hours')
                                 OR (ij.job_status = 'running' AND COALESCE(ij.job_started, ij.job_created) > NOW() - INTERVAL '4 hours'))
                               AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_job sj
                                                WHERE sj.feed_item_key = ij.job_item_key
                                                  AND sj.job_status IN ('pending','running'))")->fetchColumn();
}

function gpuLlmActive(PDO $db): bool {
    return (bool)$db->query("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND granted
                                               AND classid = 0 AND objid = " . GPU_LLM_LOCK . " AND objsubid = 1)")->fetchColumn();
}

/**
 * Null when $class may use the GPU now, else a short human-readable reason.
 * $class is a gate class ('stt', 'llm', 'llm_bg', 'gen') or a job class code
 * from yy_ai_job_class; $jobKey names the asking job (gen workers pass it).
 */
function gpuGateReason(PDO $db, string $class, ?int $jobKey = null): ?string {
    $why = [];
    $r = aiqGateReason($db, GPU_GATE_CLASS_MAP[$class] ?? $class, $jobKey);
    if ($r !== null) $why[] = $r;
    if ($class === 'stt') {
        $p = gpuPackagingActive($db);
        if ($p) $why[] = "finishing $p video" . ($p === 1 ? '' : 's') . ' (build / AI cleanup) before the next';
    }
    return $why ? implode(', ', $why) : null;
}

/** True when the endpoint is an engine on the Puget box (cloud APIs are never gated). */
function gpuIsPugetEndpoint(string $endpoint): bool {
    return (bool)preg_match('#^https?://(100\.88\.122\.21|gpu\.yadayah\.com|localhost|127\.0\.0\.1|172\.17\.0\.1)(:|/|$)#', $endpoint);
}

/**
 * Block until $class may use the GPU. $onWait($reason) is called when the
 * reason changes (status message); returns seconds waited.
 *
 * For one-worker-per-job classes (image/video/audio_gen) with a $jobKey, the
 * job then holds its AIQ_HOLD_NS lock — that is what marks it "on the card"
 * to everyone else — until the worker exits. The gate is re-checked with the
 * lock held, so two workers passing at the same instant can't both proceed.
 */
function gpuGateWait(PDO $db, string $class, ?callable $onWait = null, int $poll = 30, ?int $jobKey = null): int {
    $t0 = time(); $last = null;
    $jobClass = GPU_GATE_CLASS_MAP[$class] ?? $class;
    $hold = $jobKey !== null && isset(AIQ_HOLD_NS[$jobClass]);
    while (true) {
        $r = gpuGateReason($db, $class, $jobKey);
        if ($r === null && $hold) {
            if (aiqHold($db, $jobClass, $jobKey)) {
                $r = gpuGateReason($db, $class, $jobKey);
                if ($r === null) break;
                aiqUnhold($db, $jobClass, $jobKey);
            } else {
                $r = 'claiming the GPU';
            }
        }
        if ($r === null) break;
        if ($r !== $last && $onWait) $onWait($r);
        $last = $r;
        sleep($poll);
    }
    return time() - $t0;
}

/** Hold / release the "LLM pass running" marker (session advisory lock). */
function gpuLlmLock(PDO $db): bool {
    return (bool)$db->query('SELECT pg_try_advisory_lock(' . GPU_LLM_LOCK . ')')->fetchColumn();
}
function gpuLlmUnlock(PDO $db): void {
    try { $db->query('SELECT pg_advisory_unlock(' . GPU_LLM_LOCK . ')'); } catch (\Throwable $e) {}
}

/** Unload every resident ollama model (frees ~50G). Skipped while an LLM pass holds the lock. */
function gpuOllamaUnloadIdle(PDO $db): array {
    if (gpuLlmActive($db)) return [];
    require_once __DIR__ . '/gpu-client.php';
    $ps = gpuOllamaRequest('GET', '/api/ps', null, 15);
    $out = [];
    foreach ((array)($ps['data']['models'] ?? []) as $m) {
        $name = (string)($m['name'] ?? '');
        if ($name === '') continue;
        $r = gpuOllamaRequest('POST', '/api/generate', ['model' => $name, 'keep_alive' => 0], 60);
        $out[] = $name . (empty($r['ok']) ? ' (unload failed)' : '');
    }
    return $out;
}
