<?php
// One-off: re-run Whisper on a single feed_item and write the raw output to
// yy_feed_item_transcript_auto (with model='whisper-1-segment'), then apply the correction dictionary and
// write the cleaned version to yy_feed_item_transcript_autoclean.
//
// LIVE TABLE (yy_feed_item_transcript) IS NOT TOUCHED — current human edits
// are preserved. The two new tables become parallel append-only siblings.
//
// Usage on the prod box (inside the web container):
//   docker exec yada-www-web-1 php /tmp/backfill_whisper_autoclean.php <feed_item_key>
//
// Self-contained Whisper logic (chunked) so the existing worker doesn't have
// to be refactored for this one-off. Mirrors transcript-worker.php's flow.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // applyCorrectionDictionary

ini_set('memory_limit', '1G');

$itemKey = (int)($argv[1] ?? 0);
if (!$itemKey) { fwrite(STDERR, "feed_item_key required\n"); exit(1); }

function logmsg(string $s): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $s . "\n"); }

$db = getDb();

// --- look up item ---
$itemStmt = $db->prepare("
    SELECT fi.feed_item_key, fi.feed_item_external_id, fi.feed_item_url,
           fi.feed_item_duration_seconds, f.feed_site_code
      FROM yy_feed_item fi JOIN yy_feed f ON f.feed_key = fi.feed_key
     WHERE fi.feed_item_key = ?
");
$itemStmt->execute([$itemKey]);
$item = $itemStmt->fetch();
if (!$item) { fwrite(STDERR, "no such feed_item_key\n"); exit(2); }
$videoDur = (int)$item['feed_item_duration_seconds'];
logmsg("item $itemKey · {$item['feed_site_code']} · " . ($item['feed_item_external_id'] ?: '(no ext id)') . " · {$videoDur}s");

// --- locate audio ---
function readEnv(string $key): ?string {
    static $env = null;
    if ($env === null) {
        $env = [];
        $envPath = __DIR__ . '/../.env';
        if (file_exists($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line[0] === '#' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    return $env[$key] ?? getenv($key) ?: null;
}

$openaiKey = readEnv('OPENAI_API_KEY');
if (!$openaiKey) { fwrite(STDERR, "OPENAI_API_KEY not in .env\n"); exit(3); }

$audioPath = null;
// Optional explicit override via second CLI arg.
if (isset($argv[2]) && $argv[2] !== '' && file_exists($argv[2])) {
    $audioPath = $argv[2];
}
if (!$audioPath) {
    foreach (['mp3','m4a','opus','wav','ogg','aac'] as $ext) {
        $p = "/tmp/transcript_uploads/$itemKey.$ext";
        if (file_exists($p) && filesize($p) > 10000) { $audioPath = $p; break; }
    }
}
if (!$audioPath) {
    // Standard production location for admin-uploaded audio.
    foreach (glob("/var/www/html/u/audio/audio_{$itemKey}_*.mp3") ?: [] as $p) {
        if (file_exists($p) && filesize($p) > 10000) { $audioPath = $p; break; }
    }
}
if (!$audioPath) {
    // yt-dlp download
    if (($item['feed_site_code'] ?? '') !== 'YouTube' || !$item['feed_item_url']) {
        fwrite(STDERR, "no uploaded audio and not a YouTube item\n"); exit(4);
    }
    $audioPath = "/tmp/backfill_whisper_$itemKey.mp3";
    $ytdlp = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
    if (!$ytdlp) { fwrite(STDERR, "yt-dlp not installed\n"); exit(5); }
    logmsg("downloading audio via yt-dlp → $audioPath");
    $cookieArg = '';
    foreach (['/opt/yada-www/cookies.txt', '/tmp/youtube_cookies.txt'] as $c) {
        if (file_exists($c)) { $cookieArg = '--cookies ' . escapeshellarg($c) . ' '; break; }
    }
    $cmd = "$ytdlp $cookieArg-x --audio-format mp3 --audio-quality 5 -o "
         . escapeshellarg($audioPath) . " " . escapeshellarg($item['feed_item_url']) . " 2>&1";
    $out = shell_exec($cmd);
    if (!file_exists($audioPath) || filesize($audioPath) < 10000) {
        fwrite(STDERR, "yt-dlp failed:\n" . substr((string)$out, -1500) . "\n"); exit(6);
    }
    logmsg("audio downloaded · " . round(filesize($audioPath) / 1024 / 1024, 1) . " MB");
} else {
    logmsg("using uploaded audio: $audioPath · " . round(filesize($audioPath) / 1024 / 1024, 1) . " MB");
}

// --- chunk via ffmpeg ---
$chunkDir = "/tmp/backfill_chunks_$itemKey";
shell_exec('rm -rf ' . escapeshellarg($chunkDir));
mkdir($chunkDir, 0700, true);
$chunkSecs = 600;
$ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
if (!$ffmpeg) { fwrite(STDERR, "ffmpeg not installed\n"); exit(7); }
logmsg("chunking with ffmpeg ({$chunkSecs}s segments)");
$cmd = escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($audioPath)
     . ' -f segment -segment_time ' . $chunkSecs
     . ' -c copy ' . escapeshellarg("$chunkDir/chunk_%03d.mp3") . ' 2>&1';
shell_exec($cmd);
$chunks = glob("$chunkDir/chunk_*.mp3");
sort($chunks);
if (!$chunks) { fwrite(STDERR, "ffmpeg produced no chunks\n"); exit(8); }
logmsg("chunked into " . count($chunks) . " files");

// --- Whisper API call per chunk ---
function whisperChunk(string $path, string $key, int $offset, ?string &$err): array {
    $fields = [
        'file' => new CURLFile($path),
        'model' => 'whisper-1',
        'response_format' => 'verbose_json',
        'timestamp_granularities[]' => 'segment',
    ];
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT => 600,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) { $err = "HTTP $code: " . substr((string)$resp, 0, 300); return []; }
    $data = json_decode($resp, true);
    $rows = [];
    foreach ($data['segments'] ?? [] as $seg) {
        $t = trim($seg['text'] ?? '');
        if ($t === '') continue;
        $secs = (int)$seg['start'] + $offset;
        $rows[] = ['segment' => sprintf('%02d:%02d:%02d', (int)($secs/3600), (int)(($secs%3600)/60), $secs%60), 'text' => $t];
    }
    return $rows;
}

$allRows = [];
foreach ($chunks as $idx => $chunkPath) {
    $err = '';
    logmsg("chunk " . ($idx + 1) . "/" . count($chunks) . " · " . round(filesize($chunkPath)/1024/1024,1) . " MB");
    $rows = whisperChunk($chunkPath, $openaiKey, $idx * $chunkSecs, $err);
    if (!$rows) {
        logmsg("  ! chunk failed: $err");
    } else {
        logmsg("  → " . count($rows) . " segments");
        $allRows = array_merge($allRows, $rows);
    }
    @unlink($chunkPath);
}
@rmdir($chunkDir);
if (!$allRows) { fwrite(STDERR, "no segments returned across any chunk\n"); exit(9); }
logmsg("total segments: " . count($allRows));

// --- write to _auto (with model column) and _autoclean ---
$model = 'whisper-1-segment';  // segment-level whisper-1 (the only mode this one-off script invokes)
$db->beginTransaction();
$db->prepare("DELETE FROM yy_feed_item_transcript_auto      WHERE feed_item_key = ?")->execute([$itemKey]);
$db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ?")->execute([$itemKey]);
$insW = $db->prepare("
    INSERT INTO yy_feed_item_transcript_auto
        (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_auto_model)
    VALUES (?, ?::interval, ?, ?, ?)
");
$insA = $db->prepare("
    INSERT INTO yy_feed_item_transcript_autoclean
        (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_autoclean_model)
    VALUES (?, ?::interval, ?, ?, ?)
");
// Write _auto rows raw (one per Whisper segment).
$sort = 0;
foreach ($allRows as $r) {
    $insW->execute([$itemKey, $r['segment'], mb_substr($r['text'], 0, 2000), $sort, $model]);
    $sort++;
}
// _autoclean uses cross-row correction matching so phrases that span row
// boundaries collapse onto the first row's segment.
$cleanedRows = applyCorrectionsAcrossRows($db, $allRows);
$cleanSort = 0;
foreach ($cleanedRows as $r) {
    $insA->execute([$itemKey, $r['segment'], mb_substr($r['text'], 0, 2000), $cleanSort, $model]);
    $cleanSort++;
}
$db->commit();
logmsg("wrote $sort _auto and $cleanSort _autoclean row(s) (model=$model)");

// Cleanup downloaded audio (but NOT user-uploaded audio)
if (strpos($audioPath, '/tmp/backfill_whisper_') === 0) @unlink($audioPath);
echo "done\n";
