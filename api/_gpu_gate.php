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
 *   class      waits while
 *   'llm_bg'   TTS build queued/running · gen job active · any STT job queued/running
 *              (background transcript cleanup — lowest priority)
 *   'llm'      TTS build queued/running · gen job active · STT job running
 *              (Layer-3 step of a fresh consensus build — skipped, not waited:
 *              the build marks llm_reconcile=false and the backfill does it later)
 *   'gen'      TTS build queued/running · STT job running · LLM pass running
 *   'stt'      gen job active · LLM pass running · a video is being PACKAGED
 *              (its baselines are done and its build/LLM pass is queued or
 *              running — one video is finished before the next starts) (next job stays pending;
 *              gpu-queue-tick.php restarts the queue when the gate opens)
 *
 * "LLM pass running" = some process holds advisory lock GPU_LLM_LOCK (taken
 * by the backfill and the init worker around llmReconcileTranscript).
 */

const GPU_LLM_LOCK = 742010;

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

/** Null when $class may use the GPU now, else a short human-readable reason. */
function gpuGateReason(PDO $db, string $class): ?string {
    $why = [];
    if (in_array($class, ['llm_bg', 'llm', 'gen'], true)) {
        $t = gpuTtsActive($db);
        if ($t) $why[] = "TTS building ($t chapter" . ($t === 1 ? '' : 's') . ' left)';
    }
    if (in_array($class, ['llm_bg', 'llm', 'stt'], true)) {
        $g = gpuGenActive($db);
        if ($g) $why[] = "$g image/video/audio job" . ($g === 1 ? '' : 's');
    }
    if (in_array($class, ['llm_bg', 'llm', 'gen'], true)) {
        $s = gpuSttCounts($db);
        if ($s['running']) $why[] = 'transcription running';
        elseif ($class === 'llm_bg' && $s['pending']) $why[] = "{$s['pending']} transcription jobs queued";
    }
    if (in_array($class, ['gen', 'stt'], true) && gpuLlmActive($db)) $why[] = 'LLM transcript pass running';
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
 */
function gpuGateWait(PDO $db, string $class, ?callable $onWait = null, int $poll = 30): int {
    $t0 = time(); $last = null;
    while (($r = gpuGateReason($db, $class)) !== null) {
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
