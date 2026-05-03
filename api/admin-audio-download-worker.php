<?php
/**
 * Background worker: runs yt-dlp -x --audio-format mp3 against a feed item's
 * URL, saves the MP3 to /u/audio/audio_{key}_{ts}.mp3, and updates
 * yy_feed_item.feed_item_audio_file. Streams progress into the status JSON
 * file so the admin UI can poll.
 *
 * Spawned via nohup from admin-audio-download.php. Don't invoke directly.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }
$itemKey = (int)($argv[1] ?? 0);
if (!$itemKey) { fwrite(STDERR, "item_key required\n"); exit(1); }

require_once __DIR__ . '/config.php';
$db = getDb();
$statusFile = sys_get_temp_dir() . "/audio_dl_{$itemKey}.json";

function setStatus(string $f, array $patch): void {
    $cur = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : [];
    foreach ($patch as $k => $v) $cur[$k] = $v;
    $cur['updated'] = date('c');
    @file_put_contents($f, json_encode($cur, JSON_PRETTY_PRINT));
}

// Resolve URL + capture any current audio so we can clean it up after success
$stmt = $db->prepare("SELECT feed_item_url, feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
$stmt->execute([$itemKey]);
$row = $stmt->fetch();
if (!$row) { setStatus($statusFile, ['state' => 'error', 'message' => 'feed_item not found']); exit(1); }
$url = $row['feed_item_url'];
$priorAudio = $row['feed_item_audio_file'];
if (!$url) { setStatus($statusFile, ['state' => 'error', 'message' => 'no URL on feed_item']); exit(1); }

$ytDlp = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
if (!$ytDlp) { setStatus($statusFile, ['state' => 'error', 'message' => 'yt-dlp not installed on host PATH']); exit(1); }

// Output paths — durable storage under /public/u/audio/
$audioDirAbs = dirname(__DIR__) . '/public/u/audio';
if (is_dir('/var/www/html/u/audio')) $audioDirAbs = '/var/www/html/u/audio';
@mkdir($audioDirAbs, 0775, true);
$stamp = time();
$basename = "audio_{$itemKey}_{$stamp}.mp3";
$absPath = "$audioDirAbs/$basename";
$relPath = "u/audio/$basename";

// Cookies + extractor args (mirrors transcript-worker.php so the same bypasses
// for YouTube bot detection apply here).
$cookiesFile = '/var/www/html/data/youtube-cookies.txt';
if (!is_file($cookiesFile)) $cookiesFile = dirname(__DIR__) . '/data/youtube-cookies.txt';
$cookiesArg = is_file($cookiesFile) ? ' --cookies ' . escapeshellarg($cookiesFile) : '';
$playerArg = ' --extractor-args ' . escapeshellarg('youtube:player_client=web,web_safari,android,ios');
$remoteComponentsArg = ' --extractor-args ' . escapeshellarg('youtube:remote-components=ejs:github');

setStatus($statusFile, ['state' => 'running', 'progress' => 5, 'message' => 'Starting yt-dlp…', 'output_path' => $relPath]);

// Run yt-dlp with --newline so we can stream progress per line.
$cmd = escapeshellcmd($ytDlp) . $cookiesArg . $playerArg . $remoteComponentsArg
     . ' --newline -x --audio-format mp3 --audio-quality 5 --no-playlist'
     . ' --output ' . escapeshellarg($absPath)
     . ' ' . escapeshellarg($url) . ' 2>&1';

$dlOutput = '';
$proc = popen($cmd, 'r');
if (!$proc) { setStatus($statusFile, ['state' => 'error', 'message' => 'popen() failed']); exit(1); }
$lastPct = -1;
while (($line = fgets($proc)) !== false) {
    $dlOutput .= $line;
    if (preg_match('/^\[download\]\s+([\d.]+)%/', $line, $m)) {
        $pct = (float)$m[1];
        // Map 0-100% download → 5-90% overall (10% reserved for finalize)
        $overall = 5 + (int)round($pct * 0.85);
        if ($overall !== $lastPct) {
            $lastPct = $overall;
            $msg = 'Downloading… ' . number_format($pct, 0) . '%';
            if (preg_match('/ETA\s+([\d:]+)/', $line, $eta)) $msg .= ' (ETA ' . $eta[1] . ')';
            setStatus($statusFile, ['progress' => $overall, 'message' => $msg]);
        }
    } elseif (stripos($line, '[ExtractAudio]') !== false) {
        setStatus($statusFile, ['progress' => 92, 'message' => 'Extracting MP3…']);
    }
}
$rc = pclose($proc);

if (!is_file($absPath) || filesize($absPath) <= 10000) {
    // Pull the actionable error line if present
    $errLine = '';
    if (preg_match('/^ERROR:.*$/m', $dlOutput, $m)) $errLine = $m[0];
    $tail = $errLine ?: substr(trim($dlOutput), -500);
    $reason = 'audio download failed';
    if (stripos($dlOutput, 'not a bot') !== false || stripos($dlOutput, 'confirm you') !== false) {
        $reason = 'YouTube bot-detection — refresh /admin-cookies.html with a fresh cookies file';
    } elseif (stripos($dlOutput, 'live event will begin') !== false) {
        $reason = 'video is a scheduled future live event — not yet available';
    } elseif (stripos($dlOutput, 'Private video') !== false) {
        $reason = 'video is private';
    } elseif (stripos($dlOutput, 'Video unavailable') !== false) {
        $reason = 'video unavailable (deleted / region-blocked)';
    }
    setStatus($statusFile, ['state' => 'error', 'progress' => 0, 'message' => $reason, 'detail' => $tail, 'rc' => $rc]);
    @unlink($absPath);
    exit(1);
}

// Success — chmod, swap into DB, optionally clean up the prior file
@chmod($absPath, 0664);
setStatus($statusFile, ['progress' => 95, 'message' => 'Updating database…']);

$db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ?, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")
   ->execute([$relPath, $itemKey]);

// Best-effort cleanup of the prior file (only if it's not the same one and lives inside /u/audio)
if ($priorAudio && $priorAudio !== $relPath && strpos($priorAudio, 'u/audio/') === 0) {
    $priorAbs = dirname(__DIR__) . '/public/' . $priorAudio;
    if (is_dir('/var/www/html/u/audio') && strpos($priorAudio, 'u/audio/') === 0) {
        $priorAbs = '/var/www/html/' . $priorAudio;
    }
    if (is_file($priorAbs)) @unlink($priorAbs);
}

$sizeMb = round(filesize($absPath) / 1024 / 1024, 1);
setStatus($statusFile, [
    'state'    => 'success',
    'progress' => 100,
    'message'  => "Saved {$sizeMb} MB MP3",
    'audio_file' => $relPath,
    'size_bytes' => filesize($absPath),
    'finished' => date('c'),
]);
exit(0);
