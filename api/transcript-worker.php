<?php
/**
 * Transcription worker — runs in background, populates yy_feed_item_transcript.
 * Called as: php transcript-worker.php <job_key>
 *
 * Tries methods in order:
 *   1. yt-dlp auto-subs (if available)
 *   2. Existing .vtt/.srt file in /tmp/transcript_uploads/{item_key}.vtt
 *   3. Whisper local transcription (if installed)
 *   4. Whisper API via OpenAI (if OPENAI_API_KEY set)
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(1800); // 30 min max

if ($argc < 2) { fwrite(STDERR, "Usage: php transcript-worker.php <job_key>\n"); exit(1); }
$jobKey = (int)$argv[1];

require_once __DIR__ . '/config.php';
$db = getDb();

// Honor SIGTERM from admin-transcript.php's cancel handler. We don't try to
// flip the DB row here — admin-transcript.php already set 'cancelled' before
// sending the signal, so we just need to exit promptly.
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () { exit(0); });
    pcntl_signal(SIGINT,  function () { exit(0); });
}

function readEnv(string $name): string {
    $val = getenv($name);
    if ($val) return $val;
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, $name . '=') === 0) return trim(substr($line, strlen($name) + 1));
        }
    }
    return '';
}

function updateJob(PDO $db, int $jobKey, array $fields): void {
    $sets = [];
    $params = [];
    foreach ($fields as $k => $v) {
        $sets[] = "$k = ?";
        $params[] = $v;
    }
    $params[] = $jobKey;
    // The job_status != 'cancelled' guard ensures a slow-running worker can't
    // resurrect a job that the user already cancelled.
    $db->prepare("UPDATE yy_feed_item_transcript_job SET " . implode(', ', $sets)
        . " WHERE feed_item_transcript_job_key = ? AND job_status != 'cancelled'")
       ->execute($params);
}

/**
 * Returns true if this job has been cancelled. Worker should bail when this returns true.
 * Caches a stmt for cheap repeated polling.
 */
function isJobCancelled(PDO $db, int $jobKey): bool {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $db->prepare("SELECT job_status FROM yy_feed_item_transcript_job WHERE feed_item_transcript_job_key = ?");
    }
    $stmt->execute([$jobKey]);
    return $stmt->fetchColumn() === 'cancelled';
}

/**
 * Bail out cleanly: clean up temp files, exit. Job row is already 'cancelled'
 * (set by admin-transcript.php) and updateJob's guard prevents overwriting.
 */
function bailIfCancelled(PDO $db, int $jobKey, array $tempPaths = []): void {
    if (!isJobCancelled($db, $jobKey)) return;
    foreach ($tempPaths as $p) {
        if (is_file($p)) @unlink($p);
        if (is_dir($p)) {
            foreach (glob("$p/*") as $f) @unlink($f);
            @rmdir($p);
        }
    }
    exit(0);
}

// Load job + item info
$jobStmt = $db->prepare("
    SELECT j.feed_item_key, j.job_status, fi.feed_item_external_id, fi.feed_item_url, fi.feed_item_type,
           fi.feed_item_duration_seconds, fi.feed_key, f.feed_site_code, f.feed_account_id, f.feed_api_key
    FROM yy_feed_item_transcript_job j
    JOIN yy_feed_item fi ON j.feed_item_key = fi.feed_item_key
    JOIN yy_feed f ON fi.feed_key = f.feed_key
    WHERE j.feed_item_transcript_job_key = ?
");
$jobStmt->execute([$jobKey]);
$job = $jobStmt->fetch();
if (!$job) { fwrite(STDERR, "Job $jobKey not found\n"); exit(1); }
if ($job['job_status'] === 'cancelled') exit(0);

$itemKey = (int)$job['feed_item_key'];
$videoId = $job['feed_item_external_id'];
$videoUrl = $job['feed_item_url'];
$site = strtolower($job['feed_site_code']);

updateJob($db, $jobKey, ['job_status' => 'running', 'job_progress' => 5, 'job_message' => 'Starting transcription...']);

$rows = [];
$methodFailures = []; // collects "method_name: reason" so the final error is actionable

// ── Method 1: Pre-uploaded VTT/SRT file ──
$uploadDir = sys_get_temp_dir() . '/transcript_uploads';
$uploadVtt = "$uploadDir/{$itemKey}.vtt";
$uploadSrt = "$uploadDir/{$itemKey}.srt";
if (file_exists($uploadVtt)) {
    updateJob($db, $jobKey, ['job_progress' => 50, 'job_message' => 'Parsing uploaded VTT...']);
    $rows = parseVtt(file_get_contents($uploadVtt));
} elseif (file_exists($uploadSrt)) {
    updateJob($db, $jobKey, ['job_progress' => 50, 'job_message' => 'Parsing uploaded SRT...']);
    $rows = parseSrt(file_get_contents($uploadSrt));
}

if (!$rows && file_exists($uploadVtt)) $methodFailures[] = "uploaded_vtt: parsed 0 rows";
elseif (!$rows && file_exists($uploadSrt)) $methodFailures[] = "uploaded_srt: parsed 0 rows";

bailIfCancelled($db, $jobKey);

// Cookies file used to bypass YouTube's bot-detection. If present, both yt-dlp
// invocations below get `--cookies <file>`. Admin uploads/refreshes via admin-feeds.
// Path matches admin-cookies.php — /tmp is shared inside the same container.
$cookiesPath = '/tmp/youtube-cookies.txt';
$haveCookies = file_exists($cookiesPath) && filesize($cookiesPath) > 0;
$cookiesArg  = $haveCookies ? ' --cookies ' . escapeshellarg($cookiesPath) : '';
// Player client choice:
//   - With cookies: prefer `web` (full formats, accepts cookies). Other clients
//     fall back automatically. Avoids the `tv` client that yt-dlp picks as a
//     last resort, which only exposes images.
//   - Without cookies: try `ios` to evade bot-detection.
// PO Token provider (bgutil sidecar) defeats Botguard for the `web` client.
// Joined into the same --extractor-args so yt-dlp parses both keys.
$potUrl = getenv('POT_PROVIDER_URL') ?: '';
$potBgutil = $potUrl ? ';youtubepot-bgutilhttp:base_url=' . $potUrl : '';
$playerArg   = $haveCookies
    ? " --extractor-args 'youtube:player_client=web,mweb,web_safari" . $potBgutil . "'"
    : " --extractor-args 'youtube:player_client=ios" . $potBgutil . "'";

// YouTube's modern n-challenge requires a JS solver. Deno is installed in the
// container, and `--remote-components ejs:github` lets yt-dlp auto-fetch the
// challenge-solver lib from the yt-dlp/ejs releases. Without this, all formats
// after the n-param get stripped and only image storyboards are returned.
$remoteComponentsArg = ' --remote-components ejs:github';

// ── Method 2: yt-dlp auto-captions (host) ──
if (!$rows && $site === 'youtube') {
    updateJob($db, $jobKey, ['job_progress' => 15, 'job_message' => 'Fetching YouTube captions...']);
    $tmpFile = sys_get_temp_dir() . "/transcript_$jobKey";
    $ytDlp = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
    if (!$ytDlp) {
        $methodFailures[] = "yt_dlp_captions: yt-dlp binary not found in PATH";
    } elseif (!$videoId) {
        $methodFailures[] = "yt_dlp_captions: no videoId on item";
    } else {
        $cmd = escapeshellcmd($ytDlp) . $cookiesArg . $playerArg . $remoteComponentsArg . " --skip-download --write-auto-subs --sub-lang en --sub-format vtt --output " . escapeshellarg("$tmpFile.%(ext)s") . " " . escapeshellarg("https://www.youtube.com/watch?v=$videoId") . " 2>&1";
        $output = shell_exec($cmd);
        $vttFiles = glob("$tmpFile*.vtt");
        if ($vttFiles && file_exists($vttFiles[0])) {
            updateJob($db, $jobKey, ['job_progress' => 70, 'job_message' => 'Parsing captions...']);
            $rows = parseVtt(file_get_contents($vttFiles[0]));
            foreach ($vttFiles as $f) @unlink($f);
            if (!$rows) $methodFailures[] = "yt_dlp_captions: VTT parsed but produced 0 rows";
        } else {
            $methodFailures[] = "yt_dlp_captions: yt-dlp produced no VTT — " . substr(trim($output), 0, 300);
        }
    }
}

bailIfCancelled($db, $jobKey);

// ── Method 3: OpenAI Whisper API ──
if (!$rows) {
    $openaiKey = readEnv('OPENAI_API_KEY');
    if (!$openaiKey) {
        $methodFailures[] = "whisper_api: OPENAI_API_KEY not set in .env";
    } else {
        updateJob($db, $jobKey, ['job_progress' => 20, 'job_message' => 'Preparing audio...']);
        $audioPath = sys_get_temp_dir() . "/transcript_audio_$jobKey.mp3";
        $usedUploadedAudio = false;

        // Prefer admin-uploaded audio (from admin-transcript-upload.php) over yt-dlp.
        // This is the workaround when YouTube blocks the server's IP.
        $uploadedAudio = null;
        foreach (['mp3', 'm4a', 'opus', 'wav', 'ogg', 'aac', 'webm'] as $ext) {
            $cand = "$uploadDir/{$itemKey}.{$ext}";
            if (file_exists($cand) && filesize($cand) > 10000) { $uploadedAudio = $cand; break; }
        }
        if ($uploadedAudio) {
            $audioPath = $uploadedAudio;
            $usedUploadedAudio = true;
            updateJob($db, $jobKey, ['job_progress' => 30, 'job_message' => 'Using uploaded audio: ' . basename($uploadedAudio)]);
        }

        $ytDlp = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
        if (!$usedUploadedAudio && !$ytDlp) {
            $methodFailures[] = "whisper_api: yt-dlp binary not found (needed to download audio)";
        } elseif (!$usedUploadedAudio && !$videoUrl) {
            $methodFailures[] = "whisper_api: feed_item_url is empty";
        } else {
            // Skip yt-dlp download if we already have an uploaded audio file
            $dlOutput = '';
            if (!$usedUploadedAudio) {
                // --newline forces yt-dlp to emit one progress line per update
                // (instead of CR-overwriting the same line), so we can stream
                // and parse it. Map yt-dlp's 0-100% download progress into the
                // 30-65% slice of our overall job_progress.
                $cmd = escapeshellcmd($ytDlp) . $cookiesArg . $playerArg . $remoteComponentsArg
                     . " --newline -x --audio-format mp3 --audio-quality 5 --output "
                     . escapeshellarg($audioPath) . " " . escapeshellarg($videoUrl) . " 2>&1";
                $proc = popen($cmd, 'r');
                if ($proc) {
                    $lastReportedPct = -1;
                    while (($line = fgets($proc)) !== false) {
                        $dlOutput .= $line;
                        // [download]  23.5% of 12.30MiB at 1.40MiB/s ETA 00:08
                        if (preg_match('/^\[download\]\s+([\d.]+)%/', $line, $m)) {
                            $dlPct = (float)$m[1];
                            $jobPct = 30 + (int)round($dlPct * 0.35); // 30..65
                            if ($jobPct !== $lastReportedPct) {
                                $lastReportedPct = $jobPct;
                                $msg = 'Downloading audio… ' . number_format($dlPct, 0) . '%';
                                if (preg_match('/ETA\s+([\d:]+)/', $line, $eta)) {
                                    $msg .= ' (ETA ' . $eta[1] . ')';
                                }
                                updateJob($db, $jobKey, ['job_progress' => $jobPct, 'job_message' => $msg]);
                            }
                        }
                    }
                    pclose($proc);
                }
            }
            if (!file_exists($audioPath) || filesize($audioPath) <= 10000) {
                // Extract the actionable ERROR line if present; otherwise use last ~400 chars
                // (yt-dlp emits progress on early lines and the real failure on the last).
                $errLine = '';
                if ($dlOutput && preg_match('/^ERROR:.*$/m', $dlOutput, $m)) $errLine = $m[0];
                $tail = $errLine ?: substr(trim($dlOutput ?? ''), -400);

                $reason = 'audio download failed';
                $hint = '';
                $haystack = $dlOutput ?? '';
                if (stripos($haystack, "not a bot") !== false || stripos($haystack, 'confirm you') !== false) {
                    $reason = "YouTube bot-detection blocked this server's IP";
                    $hint = ' [Fix: supply --cookies-from-browser, route through a residential proxy, or upload audio/VTT manually to /tmp/transcript_uploads/' . $itemKey . '.vtt]';
                } elseif (stripos($haystack, 'live event will begin') !== false) {
                    $reason = 'video is a scheduled future live event — not yet available';
                } elseif (stripos($haystack, 'live event has ended') !== false) {
                    $reason = 'live event has ended but recording is not yet processed by YouTube';
                } elseif (stripos($haystack, 'private video') !== false) {
                    $reason = 'video is private';
                } elseif (stripos($haystack, 'video unavailable') !== false || stripos($haystack, 'has been removed') !== false) {
                    $reason = 'video is deleted or unavailable';
                } elseif (stripos($haystack, 'age-restricted') !== false || stripos($haystack, 'age restricted') !== false) {
                    $reason = 'video is age-restricted';
                }
                $methodFailures[] = "whisper_api: $reason$hint — " . $tail;
            } else {
                updateJob($db, $jobKey, ['job_progress' => 70, 'job_message' => 'Transcribing via Whisper API…']);
                $glossaryPrompt = buildGlossaryPrompt($db);
                $whisperErr = '';
                if (filesize($audioPath) > 24 * 1024 * 1024) {
                    $rows = whisperApiTranscribeChunked($db, $jobKey, $audioPath, $openaiKey, $glossaryPrompt, $whisperErr);
                } else {
                    $rows = whisperApiTranscribe($audioPath, $openaiKey, $glossaryPrompt, 0, $whisperErr);
                }
                // Only delete the audio if we downloaded it ourselves; preserve admin uploads
                if (!$usedUploadedAudio) @unlink($audioPath);
                if (!$rows) {
                    $methodFailures[] = "whisper_api: " . ($whisperErr ?: 'API returned no segments');
                }
                // Corrections applied below for any method that produced rows
            }
        }
    }
}

// Apply correction dictionary to whatever rows we got, regardless of source.
// This ensures uploaded VTT/SRT captions and yt-dlp captions both get the
// same Yahowah/Towrah-style normalization that Whisper output gets.
if ($rows) {
    updateJob($db, $jobKey, ['job_progress' => 85, 'job_message' => 'Applying corrections...']);
    $rows = applyCorrectionsToRows($db, $rows);
}

if (!$rows) {
    $detail = "Item: $itemKey ($videoId, $site, " . ($videoUrl ?: 'no url') . ")\n"
            . "Methods tried, in order:\n  - " . implode("\n  - ", $methodFailures ?: ['no methods attempted']);
    updateJob($db, $jobKey, [
        'job_status' => 'failed',
        'job_progress' => 0,
        'job_message' => 'Failed: ' . ($methodFailures[count($methodFailures)-1] ?? 'unknown'),
        'job_error' => $detail,
        'job_completed_dtime' => date('c'),
    ]);
    logMonitorEvent('transcript_worker', 'error',
        'Transcription failed for item ' . $itemKey,
        $detail);
    exit(1);
}

bailIfCancelled($db, $jobKey);

// ── Save rows ──
updateJob($db, $jobKey, ['job_progress' => 90, 'job_message' => 'Saving ' . count($rows) . ' segments...']);
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")->execute([$itemKey]);
    $insStmt = $db->prepare("INSERT INTO yy_feed_item_transcript (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort) VALUES (?, ?::interval, ?, ?)");
    $sort = 0;
    foreach ($rows as $r) {
        $insStmt->execute([$itemKey, $r['segment'], mb_substr($r['text'], 0, 2000), $sort++]);
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    updateJob($db, $jobKey, [
        'job_status' => 'failed',
        'job_message' => 'Save failed',
        'job_error' => $e->getMessage(),
        'job_completed_dtime' => date('c'),
    ]);
    exit(1);
}

updateJob($db, $jobKey, [
    'job_status' => 'complete',
    'job_progress' => 100,
    'job_message' => 'Done — ' . count($rows) . ' segments',
    'job_completed_dtime' => date('c'),
]);

// ─────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────

function parseVtt(string $content): array {
    $rows = [];
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $i = 0;
    while ($i < count($lines)) {
        $line = trim($lines[$i]);
        if (preg_match('/^(\d+:\d+:\d+\.\d+|\d+:\d+\.\d+)\s+-->/', $line, $m)) {
            $startTime = $m[1];
            // Normalize to HH:MM:SS
            if (substr_count($startTime, ':') === 1) $startTime = '00:' . $startTime;
            // Strip milliseconds for display
            $secs = parseTimeToSeconds($startTime);
            $segment = secsToInterval($secs);

            // Collect text lines until blank
            $text = [];
            $i++;
            while ($i < count($lines) && trim($lines[$i]) !== '') {
                $t = trim($lines[$i]);
                $t = preg_replace('/<[^>]*>/', '', $t); // strip VTT tags
                $t = html_entity_decode($t, ENT_QUOTES);
                if ($t !== '') $text[] = $t;
                $i++;
            }
            $textStr = implode(' ', $text);
            // Dedupe consecutive identical segments (YouTube auto-captions repeat)
            if ($textStr && (!end($rows) || $rows[count($rows)-1]['text'] !== $textStr)) {
                $rows[] = ['segment' => $segment, 'text' => $textStr];
            }
        }
        $i++;
    }
    return $rows;
}

function parseSrt(string $content): array {
    $rows = [];
    $blocks = preg_split('/\r?\n\r?\n/', $content);
    foreach ($blocks as $block) {
        $lines = preg_split('/\r?\n/', trim($block));
        if (count($lines) < 2) continue;
        // Find timestamp line
        foreach ($lines as $idx => $l) {
            if (preg_match('/^(\d+:\d+:\d+),\d+\s+-->/', $l, $m)) {
                $segment = $m[1];
                $text = implode(' ', array_slice($lines, $idx + 1));
                $text = preg_replace('/<[^>]*>/', '', $text);
                $text = html_entity_decode($text, ENT_QUOTES);
                if (trim($text) !== '') $rows[] = ['segment' => $segment, 'text' => trim($text)];
                break;
            }
        }
    }
    return $rows;
}

function parseTimeToSeconds(string $t): int {
    $parts = explode(':', $t);
    if (count($parts) === 3) return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)round((float)$parts[2]);
    if (count($parts) === 2) return (int)$parts[0] * 60 + (int)round((float)$parts[1]);
    return (int)round((float)$t);
}

function secsToInterval(int $secs): string {
    return sprintf('%02d:%02d:%02d', (int)($secs/3600), (int)(($secs%3600)/60), $secs%60);
}

function whisperApiTranscribe(string $audioPath, string $apiKey, string $prompt = '', int $offsetSecs = 0, ?string &$err = null): array {
    $fields = [
        'file' => new CURLFile($audioPath),
        'model' => 'whisper-1',
        'response_format' => 'verbose_json',
        'timestamp_granularities[]' => 'segment',
    ];
    if ($prompt !== '') $fields['prompt'] = $prompt;

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 600,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($code !== 200) {
        $err = "HTTP $code" . ($curlErr ? " ($curlErr)" : '') . ': ' . substr($resp ?? '', 0, 500);
        error_log("whisper API error $code: " . substr($resp ?? '', 0, 500));
        return [];
    }
    $data = json_decode($resp, true);
    $rows = [];
    foreach ($data['segments'] ?? [] as $seg) {
        $rows[] = ['segment' => secsToInterval((int)$seg['start'] + $offsetSecs), 'text' => trim($seg['text'])];
    }
    if (!$rows && isset($data['text'])) {
        // Some response shapes return only 'text' without segments
        $rows[] = ['segment' => secsToInterval($offsetSecs), 'text' => trim($data['text'])];
    }
    return $rows;
}

/**
 * Split audio into ~10-minute chunks via ffmpeg, transcribe each, stitch results
 * with timestamp offsets so segments line up to the original timeline.
 */
function whisperApiTranscribeChunked(PDO $db, int $jobKey, string $audioPath, string $apiKey, string $prompt, ?string &$err = null): array {
    $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
    if (!$ffmpeg) {
        $err = 'ffmpeg not available — cannot chunk audio over 25MB';
        return [];
    }
    $chunkDir = sys_get_temp_dir() . "/transcript_chunks_$jobKey";
    @mkdir($chunkDir, 0700, true);
    $chunkSecs = 600; // 10 min
    $cmd = escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($audioPath)
         . ' -f segment -segment_time ' . $chunkSecs
         . ' -c copy ' . escapeshellarg("$chunkDir/chunk_%03d.mp3") . ' 2>&1';
    shell_exec($cmd);

    $chunks = glob("$chunkDir/chunk_*.mp3");
    sort($chunks);
    if (!$chunks) {
        $err = 'ffmpeg produced no chunks';
        @rmdir($chunkDir);
        return [];
    }
    $allRows = [];
    foreach ($chunks as $idx => $chunkPath) {
        bailIfCancelled($db, $jobKey, [$chunkDir]);
        updateJob($db, $jobKey, ['job_progress' => 40 + (int)(40 * $idx / max(1, count($chunks))),
                                  'job_message' => 'Transcribing chunk ' . ($idx + 1) . '/' . count($chunks) . '...']);
        $chunkErr = '';
        $rows = whisperApiTranscribe($chunkPath, $apiKey, $prompt, $idx * $chunkSecs, $chunkErr);
        if (!$rows && $chunkErr) {
            $err = "chunk " . ($idx + 1) . " failed: $chunkErr";
            // continue rather than abort — partial transcript better than none
        }
        $allRows = array_merge($allRows, $rows);
        @unlink($chunkPath);
    }
    @rmdir($chunkDir);
    return $allRows;
}

/**
 * Build the Whisper `prompt` parameter from yy_transcript_glossary.
 * Whisper uses this as a hint for unusual vocabulary/spellings; the model only
 * looks at the last ~224 tokens (~890 chars), so prioritize by glossary_priority
 * then alphabetical and clip to fit.
 */
function buildGlossaryPrompt(PDO $db): string {
    $stmt = $db->query("SELECT glossary_term FROM yy_transcript_glossary WHERE glossary_active_flag = TRUE ORDER BY glossary_priority DESC, glossary_term");
    $terms = array_column($stmt->fetchAll(), 'glossary_term');
    if (!$terms) return '';
    // ~4 chars per token; budget ~860 chars to stay safely under 224 tokens.
    $prompt = '';
    foreach ($terms as $t) {
        $next = $prompt === '' ? $t : "$prompt, $t";
        if (strlen($next) > 860) break;
        $prompt = $next;
    }
    return $prompt;
}

/**
 * Run each transcribed segment through the active correction dictionary.
 */
function applyCorrectionsToRows(PDO $db, array $rows): array {
    $stmt = $db->query("SELECT correction_wrong, correction_right, correction_case_sensitive, correction_word_boundary FROM yy_transcript_correction WHERE correction_active_flag = TRUE ORDER BY correction_count DESC, length(correction_wrong) DESC");
    $corrections = $stmt->fetchAll();
    if (!$corrections) return $rows;
    foreach ($rows as &$r) {
        foreach ($corrections as $c) {
            $flags = $c['correction_case_sensitive'] ? 'u' : 'iu';
            $pattern = $c['correction_word_boundary']
                ? '/\b' . preg_quote($c['correction_wrong'], '/') . '\b/' . $flags
                : '/' . preg_quote($c['correction_wrong'], '/') . '/' . $flags;
            $r['text'] = preg_replace($pattern, $c['correction_right'], $r['text']);
        }
    }
    unset($r);
    return $rows;
}
