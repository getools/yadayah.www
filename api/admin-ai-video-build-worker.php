<?php
/**
 * AI generation Video build worker (yy_i2v_job).
 *
 * Spawned per yy_i2v_job by admin-ai-video.php. POSTs multipart to the
 * provider's /generate, polls for progress, downloads the finished MP4 to
 * /public/u/i2v-videos/i2v_<job_key>.mp4 .
 *
 * An input image is never required. With none uploaded: a service that
 * advertises supports_t2v runs text-to-video directly; any other (image-to-
 * video-only weights) first gets a start frame rendered from the same prompt
 * on the image engine — see generateStartFrame(). Override which image
 * service does that with params.start_frame_provider (a t2i model id).
 *
 * Mirrors admin-tts-build-worker's shape: queue-promotion shutdown hook,
 * cancellation re-checks, dual host/container paths, ffprobe duration.
 *
 * Usage:  php admin-ai-video-build-worker.php <i2v_job_key>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';

$jobKey = (int)($argv[1] ?? 0);
if (!$jobKey) {
    fwrite(STDERR, "i2v_job_key required\n");
    exit(2);
}

$db = getDb();

// ── Queue promotion ───────────────────────────────────────────────────
// Single GPU; single concurrent build. When this worker exits (success,
// failure, OOM), promote the next pending row.
$MAX_CONCURRENT = 1;
register_shutdown_function(function() use (&$db, $MAX_CONCURRENT) {
    try {
        if (!$db) $db = getDb();
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_i2v_job WHERE i2v_job_status='running'")->fetchColumn();
        if ($running >= $MAX_CONCURRENT) return;
        $next = (int)$db->query("
          SELECT i2v_job_key FROM yy_i2v_job
           WHERE i2v_job_status='pending' AND i2v_job_worker_pid IS NULL
           ORDER BY i2v_job_dtime ASC LIMIT 1
        ")->fetchColumn();
        if (!$next) return;
        $logFile = sys_get_temp_dir() . '/ai_video_build_' . $next . '.log';
        $pid = spawnCappedWorker(__FILE__, [(string)$next], $logFile, [
            'cpu_secs' => 7200, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_i2v_job SET i2v_job_worker_pid=? WHERE i2v_job_key=?")
               ->execute([$pid, $next]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "promote-next failed: " . $e->getMessage() . "\n");
    }
});

function updateJob(PDO $db, int $k, array $fields): void {
    if (!$fields) return;
    $set = []; $params = [];
    foreach ($fields as $col => $val) { $set[] = "$col = ?"; $params[] = $val; }
    $params[] = $k;
    $db->prepare("UPDATE yy_i2v_job SET " . implode(', ', $set) . ", i2v_job_revision_dtime=NOW() WHERE i2v_job_key=?")
       ->execute($params);
}

function bailJob(PDO $db, int $k, string $err): void {
    $short = trim(substr(str_replace(["\r", "\n"], ' ', $err), 0, 240));
    updateJob($db, $k, [
        'i2v_job_status'          => 'failed',
        'i2v_job_message'         => 'Failed: ' . $short,
        'i2v_job_error'           => $err,
        'i2v_job_completed_dtime' => date('Y-m-d H:i:sO'),
    ]);
    fwrite(STDERR, "FAIL: $err\n");
    exit(1);
}

/**
 * Does this video engine generate from the prompt alone? Asked of the engine
 * itself (/models), which is the only authority — the DB's provider_settings
 * can drift. Returns null when the engine can't be reached or doesn't know the
 * code, in which case the caller leaves the job alone and lets the submit fail
 * with the real transport error.
 */
function engineSupportsT2v(string $endpoint, string $code): ?bool {
    $ch = curl_init($endpoint . '/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $http >= 400) return null;
    $arr = json_decode((string)$resp, true);
    if (!is_array($arr)) return null;
    foreach ($arr as $m) {
        if (($m['code'] ?? '') === $code) return !empty($m['supports_t2v']);
    }
    return null;
}

/** The text-to-image service used to render an auto start frame. Prefers the
 *  job's own params.start_frame_provider, then Flux Schnell (4 steps — seconds,
 *  not minutes), then the first active t2i provider by sort order. */
function pickStartFrameProvider(PDO $db, ?string $want): ?array {
    $stmt = $db->prepare("
      SELECT p.provider_label, p.provider_model_id, p.provider_endpoint, p.provider_settings
        FROM yy_provider p
        JOIN yy_functionality_provider fp ON fp.provider_key = p.provider_key
        JOIN yy_functionality f ON f.functionality_key = fp.functionality_key
       WHERE f.functionality_code = 't2i'
         AND p.provider_active_flag
         AND fp.functionality_provider_active_flag
       ORDER BY (p.provider_model_id = ?) DESC,
                (p.provider_model_id = 'flux-1-schnell') DESC,
                fp.functionality_provider_sort
       LIMIT 1
    ");
    $stmt->execute([(string)($want ?? '')]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Render a start frame from the video job's own prompt and drop it into the
 * job's uploads dir, so an image-to-video-only checkpoint can still be driven
 * from a prompt alone. Returns the absolute path; bails the job on failure.
 */
function generateStartFrame(PDO $db, int $jobKey, array $job, array $vparams, string $jobDir, string $relBase): string {
    $want = $vparams['start_frame_provider'] ?? null;
    $t2i  = pickStartFrameProvider($db, is_string($want) ? $want : null);
    if (!$t2i) bailJob($db, $jobKey, "no image service available to render a start frame");

    $t2iSettings = is_string($t2i['provider_settings'])
        ? (json_decode($t2i['provider_settings'], true) ?: [])
        : ($t2i['provider_settings'] ?: []);
    $t2iEndpoint = rtrim((string)($t2i['provider_endpoint'] ?? ''), '/');
    $t2iCode     = (string)($t2iSettings['engine'] ?? $t2i['provider_model_id']);
    $t2iLabel    = (string)($t2i['provider_label'] ?: $t2i['provider_model_id']);
    if ($t2iEndpoint === '') bailJob($db, $jobKey, "image service '$t2iLabel' has no endpoint");

    // Match the video's frame size so the i2v engine doesn't have to rescale.
    $maxDim = (int)($t2iSettings['max_dim'] ?? 1024);
    $round16 = function ($v, $def) use ($maxDim) {
        $v = (int)($v ?: $def);
        $v = max(256, min($maxDim, $v));
        return intdiv($v, 16) * 16;
    };
    $iparams = [
        'width'    => $round16($vparams['width']  ?? null, 704),
        'height'   => $round16($vparams['height'] ?? null, 480),
        'n_images' => 1,
        'format'   => 'png',
    ];
    // Same seed as the video when one was pinned, so the pair is reproducible.
    if (isset($vparams['seed']) && $vparams['seed'] !== '' && $vparams['seed'] !== null) {
        $iparams['seed'] = (int)$vparams['seed'];
    }

    updateJob($db, $jobKey, [
        'i2v_job_progress' => 1,
        'i2v_job_message'  => 'Rendering start frame with ' . $t2iLabel,
    ]);

    $post = [
        'provider' => $t2iCode,
        'prompt'   => (string)$job['i2v_job_prompt'],
        'params'   => json_encode($iparams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    if (!empty($job['i2v_job_negative_prompt'])) {
        $post['negative_prompt'] = (string)$job['i2v_job_negative_prompt'];
    }
    $ch = curl_init($t2iEndpoint . '/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $http >= 400) {
        bailJob($db, $jobKey, "start frame submit HTTP $http: " . ($cerr ?: substr((string)$resp, 0, 200)));
    }
    $dec = json_decode((string)$resp, true);
    $remoteId = is_array($dec) ? ($dec['job_id'] ?? null) : null;
    if (!$remoteId) bailJob($db, $jobKey, "image engine did not return job_id for the start frame");

    // Poll. A single Flux Schnell frame is seconds; the ceiling is generous
    // only because the image engine may have to load weights first.
    $deadline = time() + 1800;
    $done = null;
    while (time() < $deadline) {
        $cstmt = $db->prepare("SELECT count(*) FROM yy_i2v_job WHERE i2v_job_key=? AND i2v_job_status='cancelled'");
        $cstmt->execute([$jobKey]);
        if ((int)$cstmt->fetchColumn() > 0) { fwrite(STDERR, "cancelled by admin\n"); exit(0); }

        $ch = curl_init($t2iEndpoint . '/jobs/' . rawurlencode($remoteId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $r = curl_exec($ch);
        $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // 404 = the engine forgot the job (its JOBS map is in-memory, so a
        // restart wipes it). That never recovers — fail now instead of
        // spinning until the deadline.
        if ($c === 404) bailJob($db, $jobKey, "start frame lost — the image engine restarted mid-job");
        if ($r === false || $c >= 400) { fwrite(STDERR, "start frame poll HTTP $c; retrying\n"); sleep(3); continue; }
        $j = json_decode((string)$r, true) ?: [];
        $st = (string)($j['status'] ?? '');
        if ($st === 'failed')   bailJob($db, $jobKey, "start frame failed: " . (string)($j['error'] ?? 'image engine reported failed'));
        if ($st === 'complete') { $done = $j; break; }
        updateJob($db, $jobKey, [
            'i2v_job_progress' => 1,
            'i2v_job_message'  => 'Start frame — ' . (string)($j['message'] ?? 'generating'),
        ]);
        sleep(3);
    }
    if (!$done) bailJob($db, $jobKey, "start frame timed out");

    if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        bailJob($db, $jobKey, "cannot create uploads dir for the start frame");
    }
    $abs = $jobDir . '/frame_00.png';
    $fh  = fopen($abs . '.staging', 'wb');
    if (!$fh) bailJob($db, $jobKey, "cannot open start frame for writing");
    $ch = curl_init($t2iEndpoint . '/jobs/' . rawurlencode($remoteId) . '/file?index=0');
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 300]);
    $ok = curl_exec($ch);
    $c  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    fclose($fh);
    if (!$ok || $c >= 400) { @unlink($abs . '.staging'); bailJob($db, $jobKey, "start frame download HTTP $c"); }
    if (!@rename($abs . '.staging', $abs)) bailJob($db, $jobKey, "cannot rename the staged start frame");

    // Record it like an upload, flagged as generated so the admin can tell the
    // frame apart from one they supplied (and so delete still cleans it up).
    $rows = [[
        'path'       => $relBase . '/frame_00.png',
        'role'       => 'first',
        'size_bytes' => (int)(filesize($abs) ?: 0),
        'generated'  => true,
        'source'     => 'auto:' . $t2i['provider_model_id'],
    ]];
    $db->prepare("UPDATE yy_i2v_job SET i2v_job_input_images = ?::jsonb WHERE i2v_job_key = ?")
       ->execute([json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $jobKey]);
    updateJob($db, $jobKey, ['i2v_job_message' => 'Start frame ready (' . $t2iLabel . ')']);
    return $abs;
}

// Load the job.
$row = $db->prepare("SELECT * FROM yy_i2v_job WHERE i2v_job_key=?");
$row->execute([$jobKey]);
$job = $row->fetch(PDO::FETCH_ASSOC);
if (!$job) { fwrite(STDERR, "yy_i2v_job row $jobKey missing\n"); exit(2); }

// Load provider.
$pStmt = $db->prepare("SELECT * FROM yy_provider WHERE provider_key=?");
$pStmt->execute([(int)$job['provider_key']]);
$prov = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$prov) bailJob($db, $jobKey, "provider missing");

$settings   = is_string($prov['provider_settings']) ? (json_decode($prov['provider_settings'], true) ?: []) : ($prov['provider_settings'] ?: []);
$endpoint   = rtrim((string)($prov['provider_endpoint'] ?? ''), '/');
$engineCode = $settings['engine'] ?? $prov['provider_model_id'];
if ($endpoint === '') bailJob($db, $jobKey, "provider has no endpoint");

updateJob($db, $jobKey, [
    'i2v_job_status'  => 'running',
    'i2v_job_message' => 'Submitting to engine',
    'i2v_job_progress'=> 1,
]);

// Resolve input image files (dual host/container path). An empty list is a
// legitimate text-to-video job — the engine decides whether its checkpoint can
// run prompt-only, and returns 422 with a clear message when it cannot.
$inputImages = is_string($job['i2v_job_input_images']) ? (json_decode($job['i2v_job_input_images'], true) ?: []) : ($job['i2v_job_input_images'] ?: []);

$hostBase = '/opt/yada-www/public';
$contBase = dirname(__DIR__);
$fsBase   = is_dir($contBase) ? $contBase : $hostBase;
$resolved = [];
foreach ($inputImages as $img) {
    $rel = (string)($img['path'] ?? '');
    if ($rel === '') continue;
    $abs = $fsBase . $rel;
    if (!is_file($abs)) bailJob($db, $jobKey, "input image missing: $rel");
    $resolved[] = $abs;
}
if ($inputImages && !$resolved) bailJob($db, $jobKey, "no readable input files");

// ── Auto start frame ──────────────────────────────────────────────────
// Nothing was uploaded and this checkpoint can't run prompt-only: render a
// first frame from the same prompt on the image engine and animate that. This
// is what makes "an image is never required" true for the I2V-only services
// too — LTX skips it and does native text-to-video.  yadayah:autoframe-v1
if (!$resolved) {
    $t2vOk = engineSupportsT2v($endpoint, (string)$engineCode);
    if ($t2vOk === false) {
        $vparams = is_string($job['i2v_job_params'])
            ? (json_decode($job['i2v_job_params'], true) ?: [])
            : ($job['i2v_job_params'] ?: []);
        $uploadsBase = (is_dir($contBase) ? $contBase : $hostBase) . '/u/i2v-uploads/';
        $resolved[] = generateStartFrame($db, $jobKey, $job, $vparams,
                                         $uploadsBase . $jobKey,
                                         '/u/i2v-uploads/' . $jobKey);
    }
}

updateJob($db, $jobKey, ['i2v_job_message' => 'Submitting to engine', 'i2v_job_progress' => 1]);

// Submit to engine /generate as multipart.
$ch = curl_init($endpoint . '/generate');
$post = [
    'provider' => (string)$engineCode,
    'prompt'   => (string)$job['i2v_job_prompt'],
    'params'   => is_string($job['i2v_job_params']) ? $job['i2v_job_params'] : json_encode($job['i2v_job_params'] ?: new stdClass()),
];
if (!empty($job['i2v_job_negative_prompt'])) {
    $post['negative_prompt'] = $job['i2v_job_negative_prompt'];
}
foreach ($resolved as $i => $abs) {
    $post["images[$i]"] = new CURLFile($abs);
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_TIMEOUT        => 120,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);
if ($resp === false || $code >= 400) {
    bailJob($db, $jobKey, "submit HTTP $code: " . ($cerr ?: substr((string)$resp, 0, 200)));
}
$dec = json_decode((string)$resp, true);
$remoteJobId = is_array($dec) ? ($dec['job_id'] ?? null) : null;
if (!$remoteJobId) bailJob($db, $jobKey, "engine did not return job_id");

updateJob($db, $jobKey, ['i2v_job_message' => 'Engine running', 'i2v_job_progress' => 5]);

// Poll for progress. 2-hour ceiling; re-check cancellation every loop.
$deadline = time() + 7200;
while (time() < $deadline) {
    $cstmt = $db->prepare("SELECT count(*) FROM yy_i2v_job WHERE i2v_job_key=? AND i2v_job_status='cancelled'");
    $cstmt->execute([$jobKey]);
    if ((int)$cstmt->fetchColumn() > 0) {
        fwrite(STDERR, "cancelled by admin\n");
        exit(0);
    }
    $ch = curl_init($endpoint . '/jobs/' . rawurlencode($remoteJobId));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Same as the start-frame poll: a 404 means the engine restarted and lost
    // the job, which no amount of retrying fixes — don't sit here for 2 hours.
    if ($code === 404) bailJob($db, $jobKey, "job lost — the video engine restarted mid-job");
    if ($resp === false || $code >= 400) {
        fwrite(STDERR, "poll HTTP $code; retrying\n");
        sleep(5);
        continue;
    }
    $j = json_decode((string)$resp, true) ?: [];
    $st = (string)($j['status'] ?? '');
    if ($st === 'failed') {
        bailJob($db, $jobKey, (string)($j['error'] ?? 'engine reported failed'));
    }
    if ($st === 'complete') break;
    if ($st === 'running' || $st === 'pending') {
        // Preserve engine-reported fractional progress (column is
        // numeric(5,2) so values like 12.7 survive instead of being
        // truncated to 12). Cap with float arithmetic, not (int).
        $progRaw = (float)($j['progress'] ?? 5);
        $prog = max(5.0, min(95.0, $progRaw));
        $update = [
            'i2v_job_progress' => $prog,
            'i2v_job_message'  => (string)($j['message'] ?? 'Generating'),
        ];
        // Engine may also report eta_seconds / phase / step / total — pull
        // them into the message when present so the UI can show actionable
        // detail without a schema change per field.
        $extras = [];
        if (isset($j['eta_seconds']) && (int)$j['eta_seconds'] > 0) {
            $eta = (int)$j['eta_seconds'];
            $mm = intdiv($eta, 60); $ss = $eta % 60;
            $extras[] = 'ETA ~' . ($mm > 0 ? "{$mm}m {$ss}s" : "{$ss}s");
        }
        if (!empty($j['phase']))             $extras[] = 'phase=' . (string)$j['phase'];
        if (isset($j['step'], $j['total']))  $extras[] = "step {$j['step']}/{$j['total']}";
        if ($extras) {
            $update['i2v_job_message'] = $update['i2v_job_message'] . ' · ' . implode(' · ', $extras);
        }
        updateJob($db, $jobKey, $update);
    }
    sleep(3);
}

// Download the MP4 to a .staging sibling then atomically rename.
$videosHost = '/opt/yada-www/public/u/i2v-videos';
$videosCont = dirname(__DIR__) . '/u/i2v-videos';
$videosDir  = is_dir($contBase) ? $videosCont : $videosHost;
if (!is_dir($videosDir) && !@mkdir($videosDir, 0775, true) && !is_dir($videosDir)) {
    bailJob($db, $jobKey, "cannot create $videosDir");
}
$outName = sprintf('i2v_%d.mp4', $jobKey);
$outAbs  = $videosDir . '/' . $outName;
$outRel  = '/u/i2v-videos/' . $outName;

$fh = fopen($outAbs . '.staging', 'wb');
if (!$fh) bailJob($db, $jobKey, "cannot open $outAbs.staging");
$ch = curl_init($endpoint . '/jobs/' . rawurlencode($remoteJobId) . '/file');
// Surface the in-flight download as i2v_job_size_bytes (already a real
// schema column the UI reads in the expanded detail block). The UI polls
// list_jobs every 4 s, so we only need to flush on the first byte and
// every ~1 s after — anything more is wasted DB load.
$lastFlush = 0.0;
$progressCb = function ($curl, $dltot, $dlnow, $ultot, $ulnow) use (&$lastFlush, $db, $jobKey) {
    $now = microtime(true);
    if ($dlnow > 0 && ($now - $lastFlush) >= 1.0) {
        $lastFlush = $now;
        $totalKnown = $dltot > 0 ? (int)$dltot : 0;
        $bytesNow   = (int)$dlnow;
        $pct = ($totalKnown > 0) ? (96.0 + min(3.9, ($bytesNow / $totalKnown) * 3.9)) : 96.0;
        $msg = $totalKnown > 0
            ? sprintf('Downloading MP4 — %.1f MB / %.1f MB', $bytesNow / 1048576.0, $totalKnown / 1048576.0)
            : sprintf('Downloading MP4 — %.1f MB',           $bytesNow / 1048576.0);
        try {
            updateJob($db, $jobKey, [
                'i2v_job_size_bytes' => $bytesNow,
                'i2v_job_progress'   => $pct,
                'i2v_job_message'    => $msg,
            ]);
        } catch (Throwable $e) { /* db hiccup — don't abort the download */ }
    }
    return 0; // continue
};
curl_setopt_array($ch, [
    CURLOPT_FILE              => $fh,
    CURLOPT_TIMEOUT           => 600,
    CURLOPT_NOPROGRESS        => false,
    CURLOPT_XFERINFOFUNCTION  => $progressCb,
]);
$ok   = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
fclose($fh);
if (!$ok || $code >= 400) {
    @unlink($outAbs . '.staging');
    bailJob($db, $jobKey, "download HTTP $code");
}
if (!@rename($outAbs . '.staging', $outAbs)) {
    bailJob($db, $jobKey, "cannot rename staging into place");
}

// Probe duration with ffprobe if available.
$duration = null;
$ffprobe = trim((string)shell_exec('which ffprobe 2>/dev/null'));
if ($ffprobe !== '') {
    $out = shell_exec(escapeshellcmd($ffprobe) . ' -v error -show_entries format=duration -of default=nokey=1:noprint_wrappers=1 ' . escapeshellarg($outAbs) . ' 2>/dev/null');
    if ($out !== null && trim((string)$out) !== '') $duration = (float)trim((string)$out);
}

updateJob($db, $jobKey, [
    'i2v_job_status'          => 'complete',
    'i2v_job_progress'        => 100,
    'i2v_job_message'         => 'Done',
    'i2v_job_output_path'     => $outRel,
    'i2v_job_duration_secs'   => $duration,
    'i2v_job_size_bytes'      => filesize($outAbs) ?: null,
    'i2v_job_completed_dtime' => date('Y-m-d H:i:sO'),
]);
exit(0);
