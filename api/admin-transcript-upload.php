<?php
/**
 * Audio + caption upload endpoint for transcript pipeline.
 *
 * GET ?item_key=N
 *   Returns current state: ephemeral caption file (if any), durable audio
 *   pointer (yy_feed_item.feed_item_audio_file), partial-recording state
 *   (resume_seconds + parts list).
 *
 * POST multipart {item_key, file [, partial=1, seconds=N] [, auto_transcribe=1]}
 *   - partial=1: append the file as the next recording part under
 *     /u/audio/parts/audio_{key}_part_{N}.webm; update
 *     yy_feed_item.feed_item_audio_resume_seconds to seconds.
 *   - default (no partial): single complete upload; replaces the durable
 *     audio file at /u/audio/audio_{key}_{ts}.{ext}, clears any partial state.
 *   - auto_transcribe=1: kick off the worker after the upload lands.
 *
 * POST {action: 'finalize', item_key, auto_transcribe?}
 *   Concatenates all parts via ffmpeg, transcodes to MP3, replaces
 *   feed_item_audio_file, clears resume state, deletes parts.
 *
 * DELETE ?item_key=N
 *   Wipes captions, parts, durable audio, and clears DB pointer +
 *   resume_seconds.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

$UPLOAD_DIR     = sys_get_temp_dir() . '/transcript_uploads';        // captions only
$AUDIO_DIR_ABS  = dirname(__DIR__) . '/u/audio';
$AUDIO_DIR_REL  = 'u/audio';
$PARTS_DIR_ABS  = $AUDIO_DIR_ABS . '/parts';
$AUDIO_EXT      = ['mp3', 'm4a', 'opus', 'wav', 'ogg', 'aac', 'webm'];
$CAPTION_EXT    = ['vtt', 'srt'];
$ALLOWED_EXT    = array_merge($AUDIO_EXT, $CAPTION_EXT);

if (!is_dir($UPLOAD_DIR))   @mkdir($UPLOAD_DIR, 0775, true);
if (!is_dir($AUDIO_DIR_ABS)) @mkdir($AUDIO_DIR_ABS, 0775, true);
if (!is_dir($PARTS_DIR_ABS)) @mkdir($PARTS_DIR_ABS, 0775, true);

function existingCaptionFiles(string $dir, int $itemKey): array {
    $files = [];
    foreach (glob("$dir/{$itemKey}.*") as $f) {
        if (is_file($f)) {
            $files[] = [
                'name' => basename($f),
                'size' => filesize($f),
                'modified_dtime' => date('c', filemtime($f)),
                'extension' => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
            ];
        }
    }
    return $files;
}

function durableAudioInfo(PDO $db, int $itemKey): ?array {
    $stmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
    $stmt->execute([$itemKey]);
    $rel = $stmt->fetchColumn();
    if (!$rel) return null;
    $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
    if (!is_file($abs)) return ['path' => $rel, 'missing' => true];
    return [
        'path' => $rel,
        'size' => filesize($abs),
        'modified_dtime' => date('c', filemtime($abs)),
        'extension' => strtolower(pathinfo($abs, PATHINFO_EXTENSION)),
    ];
}

/**
 * Validate an audio file using ffprobe. Returns:
 *   ['ok' => true,  'duration' => float, 'mean_db' => float|null]
 *   ['ok' => false, 'reason' => string]
 *
 * Catches the two ways a recording can be useless:
 *   - corrupt container (ffprobe returns non-zero or no audio stream)
 *   - silent stream (mean_volume below -70 dB → recording captured nothing,
 *     which usually means the user paused tab sharing or had wrong source)
 *
 * Both are auto-rejected at upload time so they never make it into a finalize.
 */
function validateAudioFile(string $absPath): array {
    if (!is_file($absPath) || filesize($absPath) < 256) {
        return ['ok' => false, 'reason' => 'file too small (' . (is_file($absPath) ? filesize($absPath) : 0) . ' bytes)'];
    }
    $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
    if (!$ffprobe) {
        // Best-effort: without ffprobe, accept the file. Better than blocking uploads.
        return ['ok' => true, 'duration' => 0.0, 'mean_db' => null];
    }
    $cmd = escapeshellcmd($ffprobe)
         . ' -v error -select_streams a:0 -show_entries stream=codec_type,duration'
         . ' -of default=noprint_wrappers=1:nokey=1 '
         . escapeshellarg($absPath) . ' 2>&1';
    $out = trim(shell_exec($cmd) ?: '');
    if (stripos($out, 'audio') === false) {
        return ['ok' => false, 'reason' => 'no audio stream: ' . substr($out, 0, 200)];
    }
    $lines = preg_split('/\r?\n/', $out);
    $duration = 0.0;
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l !== '' && $l !== 'audio' && is_numeric($l)) { $duration = (float)$l; break; }
    }
    // Silence check via volumedetect filter on the actual data
    $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
    $meanDb = null;
    if ($ffmpeg) {
        $cmd2 = escapeshellcmd($ffmpeg) . ' -nostdin -i ' . escapeshellarg($absPath)
              . ' -af volumedetect -vn -sn -dn -f null - 2>&1';
        $out2 = shell_exec($cmd2) ?: '';
        if (preg_match('/mean_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/', $out2, $m)) {
            $meanDb = (float)$m[1];
        }
    }
    if ($meanDb !== null && $meanDb < -70.0) {
        return ['ok' => false, 'reason' => 'silent recording (mean ' . $meanDb . ' dB) — tab share probably did not capture audio'];
    }
    return ['ok' => true, 'duration' => $duration, 'mean_db' => $meanDb];
}

function listParts(string $dir, int $itemKey): array {
    $parts = [];
    // Parts are usually .webm (from MediaRecorder) but the sync-check tool
    // creates .mp3 parts when truncating an existing complete recording.
    // ffmpeg's concat filter handles mixed input formats fine.
    foreach (glob("$dir/audio_{$itemKey}_part_*.{webm,mp3,m4a,opus,wav,ogg,aac}", GLOB_BRACE) as $f) {
        if (preg_match('/_part_(\d+)\.[a-z0-9]+$/i', $f, $m)) {
            $parts[(int)$m[1]] = $f;
        }
    }
    ksort($parts);
    return $parts; // [n => abs_path, ...]
}

function partialState(PDO $db, string $partsDir, int $itemKey): array {
    $parts = listParts($partsDir, $itemKey);
    $sizes = 0;
    foreach ($parts as $f) $sizes += filesize($f);
    $stmt = $db->prepare("SELECT feed_item_audio_resume_seconds FROM yy_feed_item WHERE feed_item_key = ?");
    $stmt->execute([$itemKey]);
    $resumeSec = $stmt->fetchColumn();
    return [
        'part_count' => count($parts),
        'parts_size' => $sizes,
        'resume_seconds' => $resumeSec === null || $resumeSec === false ? null : (int)$resumeSec,
    ];
}

function spawnTranscribeJob(PDO $db, int $itemKey, int $userKey): ?int {
    $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW() WHERE feed_item_key = ? AND job_status IN ('pending', 'running')")
       ->execute([$itemKey]);
    $jobStmt = $db->prepare("INSERT INTO yy_feed_item_transcript_job (feed_item_key, job_status, job_message, user_key) VALUES (?, 'pending', 'Auto-triggered after upload', ?) RETURNING feed_item_transcript_job_key");
    $jobStmt->execute([$itemKey, $userKey]);
    $jobKey = (int)$jobStmt->fetchColumn();
    $workerScript = __DIR__ . '/transcript-worker.php';
    if (file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
        $cmd = "nohup php " . escapeshellarg($workerScript) . " " . escapeshellarg((string)$jobKey)
             . " > " . escapeshellarg($logFile) . " 2>&1 < /dev/null & echo $!";
        $pidOut = [];
        exec($cmd, $pidOut);
        $pid = (int)($pidOut[0] ?? 0);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
               ->execute([$pid, $jobKey]);
        }
    }
    return $jobKey;
}

if ($method === 'GET') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $db = getDb();
    jsonResponse([
        'files' => existingCaptionFiles($UPLOAD_DIR, $itemKey),
        'audio' => durableAudioInfo($db, $itemKey),
        'partial' => partialState($db, $PARTS_DIR_ABS, $itemKey),
    ]);
}

if ($method === 'POST') {
    $db = getDb();
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // JSON body for non-upload actions (e.g. finalize)
    if (stripos($contentType, 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $data['action'] ?? '';
        $itemKey = (int)($data['item_key'] ?? 0);
        if (!$itemKey) errorResponse('item_key required');

        if ($action === 'finalize') {
            $parts = listParts($PARTS_DIR_ABS, $itemKey);
            if (!$parts) errorResponse('No parts to finalize');
            // AUTOMATED VALIDATION: probe each part before concat. Skip files
            // that fail (corrupt header, silent, etc.) — but delete them from
            // disk too so they don't keep blocking future finalize attempts.
            // Without this, a single bad part halts the entire batch.
            $validParts = [];
            $skipped = [];
            foreach ($parts as $idx => $f) {
                $check = validateAudioFile($f);
                if ($check['ok']) {
                    $validParts[$idx] = $f;
                } else {
                    $skipped[] = 'part_' . $idx . ' (' . $check['reason'] . ')';
                    @unlink($f);
                }
            }
            if (!$validParts) {
                logMonitorEvent('transcript_upload', 'error',
                    'Finalize for item ' . $itemKey . ' had only invalid parts: ' . implode('; ', $skipped),
                    'by user ' . ($user['user_code'] ?? '?'), false);
                errorResponse('All recorded parts are invalid (' . implode('; ', $skipped) . '). Please re-record.');
            }
            if ($skipped) {
                logMonitorEvent('transcript_upload', 'warning',
                    'Finalize for item ' . $itemKey . ' skipped invalid parts: ' . implode('; ', $skipped),
                    'by user ' . ($user['user_code'] ?? '?'), false);
            }
            $parts = $validParts;
            $stamp = time();
            $finalRel = $AUDIO_DIR_REL . "/audio_{$itemKey}_{$stamp}.mp3";
            $finalAbs = $AUDIO_DIR_ABS . "/audio_{$itemKey}_{$stamp}.mp3";

            $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
            if (!$ffmpeg) errorResponse('ffmpeg not available');

            // Seamless concat. We use ffmpeg's `concat` filter rather than the
            // demuxer + `-c copy` pair — the demuxer trusts the per-file
            // timestamps from MediaRecorder which can drift across boundaries
            // and produce audible pops. The filter decodes all parts into
            // audio sample space, joins them, and re-encodes to MP3 in one
            // pass. Result is identical to a single-pass recording.
            $inputArgs = '';
            $filterIn = '';
            foreach (array_values($parts) as $i => $f) {
                $inputArgs .= ' -i ' . escapeshellarg($f);
                $filterIn .= "[{$i}:a]";
            }
            $filter = $filterIn . 'concat=n=' . count($parts) . ':v=0:a=1[out]';
            // Total duration sum so the progress endpoint can compute fraction.
            // ffprobe is fast (just header read) so doing it on every part is cheap.
            $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
            $totalDur = 0.0;
            if ($ffprobe) {
                foreach ($parts as $f) {
                    $cmdP = escapeshellcmd($ffprobe)
                          . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
                          . escapeshellarg($f);
                    $totalDur += (float)trim(shell_exec($cmdP) ?: '0');
                }
            }
            $progressFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.progress';
            $durationFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.duration';
            @unlink($progressFile);
            @file_put_contents($durationFile, (string)$totalDur);
            $cmd = escapeshellcmd($ffmpeg) . ' -y' . $inputArgs
                 . ' -filter_complex ' . escapeshellarg($filter)
                 . ' -map ' . escapeshellarg('[out]')
                 . ' -codec:a libmp3lame -qscale:a 4 -ar 44100 -ac 2'
                 . ' -progress ' . escapeshellarg($progressFile)
                 . ' ' . escapeshellarg($finalAbs) . ' 2>&1';
            $out = shell_exec($cmd);
            @unlink($progressFile);
            @unlink($durationFile);
            if (!file_exists($finalAbs) || filesize($finalAbs) < 1000) {
                errorResponse('ffmpeg concat-filter failed: ' . substr(trim($out ?? ''), -300));
            }
            @chmod($finalAbs, 0664);

            // Wipe prior audio file (DB pointer)
            $prevStmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
            $prevStmt->execute([$itemKey]);
            $prev = $prevStmt->fetchColumn();
            if ($prev) {
                $prevAbs = dirname(__DIR__) . '/' . ltrim($prev, '/');
                if (is_file($prevAbs)) @unlink($prevAbs);
            }

            // Update DB: set new path, clear resume state
            $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ?, feed_item_audio_resume_seconds = NULL WHERE feed_item_key = ?")
               ->execute([$finalRel, $itemKey]);

            // Delete parts
            foreach ($parts as $f) @unlink($f);

            $jobKey = null;
            if (!empty($data['auto_transcribe'])) {
                $jobKey = spawnTranscribeJob($db, $itemKey, $user['user_key']);
            }
            logMonitorEvent('transcript_upload', 'info',
                'Finalized recording for item ' . $itemKey . ' (' . count($parts) . ' parts → '
                . round(filesize($finalAbs)/1024/1024, 2) . ' MB MP3)',
                'by user ' . ($user['user_code'] ?? '?'), true);
            jsonResponse([
                'ok' => true,
                'audio' => durableAudioInfo($db, $itemKey),
                'partial' => partialState($db, $PARTS_DIR_ABS, $itemKey),
                'transcribe_job_key' => $jobKey,
                'skipped_parts' => $skipped, // any parts auto-removed by validation
            ]);
        }
        errorResponse('Unknown action');
    }

    // Multipart upload
    $itemKey = (int)($_POST['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        errorResponse('No file uploaded (error code ' . ($_FILES['file']['error'] ?? '?') . ')');
    }
    $tmp = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'] ?? '';
    $size = $_FILES['file']['size'] ?? 0;
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT, true)) {
        errorResponse('File type ".' . $ext . '" not allowed. Accepted: ' . implode(', ', $ALLOWED_EXT));
    }
    if ($size > 500 * 1024 * 1024) errorResponse('File too large (>500MB)');

    $isAudio = in_array($ext, $AUDIO_EXT, true);
    $isPartial = !empty($_POST['partial']);
    $partialSeconds = (int)($_POST['seconds'] ?? 0);

    if ($isAudio && $isPartial) {
        // Append as next part. Browser captures webm so we save as .webm
        // regardless of inbound extension; ffmpeg will read it correctly.
        $existing = listParts($PARTS_DIR_ABS, $itemKey);
        $next = $existing ? max(array_keys($existing)) + 1 : 1;
        $partAbs = $PARTS_DIR_ABS . "/audio_{$itemKey}_part_{$next}.webm";
        if (!@move_uploaded_file($tmp, $partAbs)) errorResponse('Failed to write part: ' . $partAbs);
        @chmod($partAbs, 0664);
        // AUTOMATED VALIDATION: probe the file as soon as it lands. Reject
        // and remove it if the container is corrupt or the audio is silent
        // (mean volume < -70 dB → tab share missed the audio source). This
        // stops bad parts from accumulating and poisoning the finalize.
        $check = validateAudioFile($partAbs);
        if (!$check['ok']) {
            @unlink($partAbs);
            logMonitorEvent('transcript_upload', 'warning',
                'Rejected invalid part upload for item ' . $itemKey . ': ' . $check['reason'],
                'by user ' . ($user['user_code'] ?? '?'), false);
            errorResponse('Upload rejected — ' . $check['reason']);
        }
        // Never regress: a stale or out-of-order partial upload should not
        // overwrite a later position. Always take the MAX of the existing
        // value and the incoming one.
        $db->prepare("UPDATE yy_feed_item
                          SET feed_item_audio_resume_seconds = GREATEST(COALESCE(feed_item_audio_resume_seconds, 0), ?)
                        WHERE feed_item_key = ?")
           ->execute([$partialSeconds, $itemKey]);
        logMonitorEvent('transcript_upload', 'info',
            'Partial recording part ' . $next . ' for item ' . $itemKey . ' (' . $partialSeconds . 's, '
            . round($size/1024/1024, 2) . ' MB)',
            'by user ' . ($user['user_code'] ?? '?'), true);
        jsonResponse([
            'ok' => true,
            'partial' => partialState($db, $PARTS_DIR_ABS, $itemKey),
        ]);
    }

    if ($isAudio) {
        // Single-shot full upload — replaces existing audio + clears any partials
        $stamp = time();
        $relPath  = $AUDIO_DIR_REL . "/audio_{$itemKey}_{$stamp}.{$ext}";
        $absPath  = $AUDIO_DIR_ABS . "/audio_{$itemKey}_{$stamp}.{$ext}";

        $prevStmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
        $prevStmt->execute([$itemKey]);
        $prev = $prevStmt->fetchColumn();
        if ($prev) {
            $prevAbs = dirname(__DIR__) . '/' . ltrim($prev, '/');
            if (is_file($prevAbs)) @unlink($prevAbs);
        }
        if (!@move_uploaded_file($tmp, $absPath)) errorResponse('Failed to write ' . $absPath);
        @chmod($absPath, 0664);

        // Wipe any leftover parts (this upload supersedes them)
        foreach (listParts($PARTS_DIR_ABS, $itemKey) as $f) @unlink($f);

        $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ?, feed_item_audio_resume_seconds = NULL WHERE feed_item_key = ?")
           ->execute([$relPath, $itemKey]);
    } else {
        // Captions still ephemeral
        foreach (glob("$UPLOAD_DIR/{$itemKey}.*") as $old) @unlink($old);
        $dest = "$UPLOAD_DIR/{$itemKey}.{$ext}";
        if (!@move_uploaded_file($tmp, $dest)) errorResponse('Failed to write ' . $dest);
        @chmod($dest, 0664);
    }

    logMonitorEvent('transcript_upload', 'info',
        ($isAudio ? 'Audio' : 'Caption') . ' uploaded for item ' . $itemKey . ' (.' . $ext . ', ' . $size . ' bytes)',
        'by user ' . ($user['user_code'] ?? '?'), true);

    $jobKey = null;
    if (!empty($_POST['auto_transcribe'])) {
        $jobKey = spawnTranscribeJob($db, $itemKey, $user['user_key']);
    }

    jsonResponse([
        'ok' => true,
        'files' => existingCaptionFiles($UPLOAD_DIR, $itemKey),
        'audio' => durableAudioInfo($db, $itemKey),
        'partial' => partialState($db, $PARTS_DIR_ABS, $itemKey),
        'transcribe_job_key' => $jobKey,
    ]);
}

if ($method === 'DELETE') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $count = 0;
    foreach (glob("$UPLOAD_DIR/{$itemKey}.*") as $f) if (@unlink($f)) $count++;
    foreach (listParts($PARTS_DIR_ABS, $itemKey) as $f) if (@unlink($f)) $count++;
    $db = getDb();
    $prev = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
    $prev->execute([$itemKey]);
    $rel = $prev->fetchColumn();
    if ($rel) {
        $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
        if (is_file($abs) && @unlink($abs)) $count++;
    }
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = NULL, feed_item_audio_resume_seconds = NULL WHERE feed_item_key = ?")
       ->execute([$itemKey]);
    jsonResponse(['ok' => true, 'deleted' => $count]);
}

errorResponse('Method not allowed', 405);
