<?php
/**
 * AI generation Audio build worker (yy_t2a_job).
 *
 * Spawned per yy_t2a_job by admin-ai-audio.php. POSTs multipart to the audio
 * engine's /generate, polls progress, downloads N takes to
 * /public/u/t2a-outputs/<key>/audio_NN.{wav|mp3|flac|ogg}.
 *
 * Usage:  php admin-ai-audio-build-worker.php <t2a_job_key>
 *
 * Note the generous timeout: MiniMax Music 3 renders a 5-minute song with an
 * 8B autoregressive LM, and a cold first run also pays a multi-GB weight
 * download, so this is far slower than the image side.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';

// How many times a job may be requeued purely because the GPU was busy.
// Each attempt waits out the engine's full lease window (~30 min).
const T2A_MAX_GPU_ATTEMPTS = 3;

$jobKey = (int)($argv[1] ?? 0);
if (!$jobKey) { fwrite(STDERR, "t2a_job_key required\n"); exit(2); }

$db = getDb();

$MAX_CONCURRENT = 1;
register_shutdown_function(function() use (&$db, $MAX_CONCURRENT) {
    try {
        if (!$db) $db = getDb();
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_t2a_job WHERE t2a_job_status='running'")->fetchColumn();
        if ($running >= $MAX_CONCURRENT) return;
        $next = (int)$db->query("
          SELECT t2a_job_key FROM yy_t2a_job
           WHERE t2a_job_status='pending' AND t2a_job_worker_pid IS NULL
           ORDER BY t2a_job_dtime ASC LIMIT 1
        ")->fetchColumn();
        if (!$next) return;
        $logFile = sys_get_temp_dir() . '/ai_audio_build_' . $next . '.log';
        $pid = spawnCappedWorker(__FILE__, [(string)$next], $logFile, [
            'cpu_secs' => 10800, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_t2a_job SET t2a_job_worker_pid=? WHERE t2a_job_key=?")
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
    $db->prepare("UPDATE yy_t2a_job SET " . implode(', ', $set) . ", t2a_job_revision_dtime=NOW() WHERE t2a_job_key=?")
       ->execute($params);
}

function bailJob(PDO $db, int $k, string $err): void {
    $short = trim(substr(str_replace(["\r", "\n"], ' ', $err), 0, 240));
    updateJob($db, $k, [
        't2a_job_status'          => 'failed',
        't2a_job_message'         => 'Failed: ' . $short,
        't2a_job_error'           => $err,
        't2a_job_completed_dtime' => date('Y-m-d H:i:sO'),
    ]);
    fwrite(STDERR, "FAIL: $err\n");
    exit(1);
}

$row = $db->prepare("SELECT * FROM yy_t2a_job WHERE t2a_job_key=?");
$row->execute([$jobKey]);
$job = $row->fetch(PDO::FETCH_ASSOC);
if (!$job) { fwrite(STDERR, "yy_t2a_job row $jobKey missing\n"); exit(2); }

$pStmt = $db->prepare("SELECT * FROM yy_provider WHERE provider_key=?");
$pStmt->execute([(int)$job['provider_key']]);
$prov = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$prov) bailJob($db, $jobKey, "provider missing");

$settings   = is_string($prov['provider_settings']) ? (json_decode($prov['provider_settings'], true) ?: []) : ($prov['provider_settings'] ?: []);
$endpoint   = rtrim((string)($prov['provider_endpoint'] ?? ''), '/');
$engineCode = $settings['engine'] ?? $prov['provider_model_id'];
if ($endpoint === '') bailJob($db, $jobKey, "provider has no endpoint");

updateJob($db, $jobKey, [
    't2a_job_status'   => 'running',
    't2a_job_message'  => 'Submitting to engine',
    't2a_job_progress' => 1,
]);

// Resolve reference clips — dual host/container path.
$inputAudio = is_string($job['t2a_job_input_audio']) ? (json_decode($job['t2a_job_input_audio'], true) ?: []) : ($job['t2a_job_input_audio'] ?: []);
$hostBase = '/opt/yada-www/public';
$contBase = dirname(__DIR__);
$fsBase   = is_dir($contBase) ? $contBase : $hostBase;

$refFiles = [];
foreach ($inputAudio as $clip) {
    $rel = (string)($clip['path'] ?? '');
    if ($rel === '') continue;
    $abs = $fsBase . $rel;
    if (!is_file($abs)) bailJob($db, $jobKey, "input file missing: $rel");
    $refFiles[] = $abs;
}

// Submit to engine /generate as multipart.
$ch = curl_init($endpoint . '/generate');
$post = [
    'provider' => (string)$engineCode,
    'prompt'   => (string)$job['t2a_job_prompt'],
    'params'   => is_string($job['t2a_job_params']) ? $job['t2a_job_params'] : json_encode($job['t2a_job_params'] ?: new stdClass()),
];
if (!empty($job['t2a_job_lyrics'])) {
    $post['lyrics'] = (string)$job['t2a_job_lyrics'];
}
if (!empty($job['t2a_job_negative_prompt'])) {
    $post['negative_prompt'] = (string)$job['t2a_job_negative_prompt'];
}
foreach ($refFiles as $i => $abs) {
    $post["ref_audio[$i]"] = new CURLFile($abs);
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
$expectedN   = is_array($dec) ? (int)($dec['n'] ?? 1) : 1;
if (!$remoteJobId) bailJob($db, $jobKey, "engine did not return job_id");

updateJob($db, $jobKey, ['t2a_job_message' => 'Engine running', 't2a_job_progress' => 5]);

// Poll for progress. 3h ceiling — a cold MiniMax run downloads ~57 GB first.
$deadline = time() + 10800;
$finalStatus = null;
while (time() < $deadline) {
    $cstmt = $db->prepare("SELECT count(*) FROM yy_t2a_job WHERE t2a_job_key=? AND t2a_job_status='cancelled'");
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
    if ($resp === false || $code >= 400) {
        fwrite(STDERR, "poll HTTP $code; retrying\n");
        sleep(5);
        continue;
    }
    $j = json_decode((string)$resp, true) ?: [];
    $st = (string)($j['status'] ?? '');
    if ($st === 'failed') {
        $engineErr = (string)($j['error'] ?? 'engine reported failed');
        // GPU contention is transient — the engine waited out its whole lease
        // window behind someone else's render. Put the job back in the queue
        // instead of making an operator notice and resubmit it. Bounded, so a
        // genuinely wedged GPU still comes to rest in 'failed'.
        if (strpos($engineErr, 'GPU_BUSY') !== false) {
            $attempts = (int)$job['t2a_job_attempts'] + 1;
            if ($attempts < T2A_MAX_GPU_ATTEMPTS) {
                updateJob($db, $jobKey, [
                    't2a_job_attempts'   => $attempts,
                    't2a_job_status'     => 'pending',
                    't2a_job_worker_pid' => null,
                    't2a_job_progress'   => 0,
                    't2a_job_message'    => sprintf(
                        'Waiting for a free GPU — requeued (attempt %d of %d)',
                        $attempts, T2A_MAX_GPU_ATTEMPTS),
                ]);
                fwrite(STDERR, "GPU busy; requeued as attempt $attempts\n");
                exit(0);   // shutdown handler promotes the next pending job
            }
            bailJob($db, $jobKey, "GPU stayed busy across "
                . T2A_MAX_GPU_ATTEMPTS . " attempts: $engineErr");
        }
        bailJob($db, $jobKey, $engineErr);
    }
    if ($st === 'complete') { $finalStatus = $j; break; }
    if ($st === 'running' || $st === 'pending') {
        updateJob($db, $jobKey, [
            't2a_job_progress' => max(5, min(95, (int)($j['progress'] ?? 5))),
            't2a_job_message'  => (string)($j['message'] ?? 'Generating'),
        ]);
    }
    sleep(3);
}
if (!$finalStatus) bailJob($db, $jobKey, "engine timed out");

// Download N takes.
$n     = (int)($finalStatus['n']     ?? $expectedN);
$files = (array)($finalStatus['files'] ?? []);

$outputsHost = '/opt/yada-www/public/u/t2a-outputs/' . $jobKey;
$outputsCont = dirname(__DIR__) . '/u/t2a-outputs/' . $jobKey;
$outDir      = is_dir($contBase) ? $outputsCont : $outputsHost;
if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    bailJob($db, $jobKey, "cannot create $outDir");
}

$outputs = [];
$totalSize = 0;
for ($i = 0; $i < $n; $i++) {
    $fileName  = (string)($files[$i] ?? sprintf('audio_%02d.wav', $i));
    $localPath = $outDir . '/' . $fileName;
    $relPath   = '/u/t2a-outputs/' . $jobKey . '/' . $fileName;

    $fh = fopen($localPath . '.staging', 'wb');
    if (!$fh) bailJob($db, $jobKey, "cannot open output $i");
    $ch = curl_init($endpoint . '/jobs/' . rawurlencode($remoteJobId) . '/file?index=' . $i);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 900]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    fclose($fh);
    if (!$ok || $code >= 400) {
        @unlink($localPath . '.staging');
        bailJob($db, $jobKey, "download $i HTTP $code");
    }
    if (!@rename($localPath . '.staging', $localPath)) {
        bailJob($db, $jobKey, "cannot rename staging for output $i");
    }
    $sz = (int)(filesize($localPath) ?: 0);
    // A "successful" render that is a few hundred bytes is a silent/empty file,
    // not a song — fail loudly rather than publishing a dud take.
    if ($sz < 8192) {
        bailJob($db, $jobKey, "output $i is only $sz bytes — engine produced no real audio");
    }
    $totalSize += $sz;
    $outputs[] = ['path' => $relPath, 'size_bytes' => $sz, 'index' => $i];
}

updateJob($db, $jobKey, [
    't2a_job_status'          => 'complete',
    't2a_job_progress'        => 100,
    't2a_job_message'         => sprintf('Done — %d take%s', $n, $n === 1 ? '' : 's'),
    't2a_job_outputs'         => json_encode($outputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    't2a_job_size_bytes'      => $totalSize,
    't2a_job_completed_dtime' => date('Y-m-d H:i:sO'),
]);
exit(0);
