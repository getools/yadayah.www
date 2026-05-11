<?php
// One-off: replace the 'youtube' model rows in yy_feed_item_transcript_auto
// and yy_feed_item_transcript_autoclean for a given feed_item_key with
// captions pulled from a DIFFERENT YouTube URL (re-uploaded / live-stream
// archive / mirror channel).
//
// Usage (inside the web container):
//   docker exec yada-www-web-1 php /var/www/html/api/replace_youtube_from_alt.php <feed_item_key> <youtube_url>
//
// Does NOT touch yy_feed_item.feed_item_external_id — only the transcript
// rows. The DB still records the original video's ID for everything else.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // applyCorrectionDictionary

$itemKey = (int)($argv[1] ?? 0);
$srcUrl  = trim($argv[2] ?? '');
if (!$itemKey || !$srcUrl) {
    fwrite(STDERR, "usage: php replace_youtube_from_alt.php <feed_item_key> <youtube_url>\n");
    exit(1);
}

function logmsg(string $s): void { fwrite(STDERR, '[' . date('H:i:s') . '] ' . $s . "\n"); }

$db = getDb();

// --- pull VTT via yt-dlp using the standing cookie file ---
$ytdlp = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
if (!$ytdlp) { fwrite(STDERR, "yt-dlp not in PATH\n"); exit(2); }

// yt-dlp writes updated cookies back to the file on exit; if the shared
// /tmp/youtube-cookies.txt isn't writable by this process the run fails
// with PermissionError. Copy to a private writable file for this run so
// the shared file stays untouched (we don't want one CLI invocation to
// silently rewrite the file other admins are relying on anyway).
$tmpBase = sys_get_temp_dir() . "/yt_capreplace_{$itemKey}_" . posix_getpid();
$cookies = '/tmp/youtube-cookies.txt';
$cookieArg = '';
if (file_exists($cookies) && filesize($cookies) > 0) {
    $privateCookies = $tmpBase . '.cookies.txt';
    if (@copy($cookies, $privateCookies)) {
        @chmod($privateCookies, 0600);
        $cookieArg = ' --cookies ' . escapeshellarg($privateCookies);
    }
}
$cmd = escapeshellcmd($ytdlp) . $cookieArg
     . " --skip-download --write-subs --write-auto-subs --sub-lang en --sub-format vtt"
     . " --output " . escapeshellarg("$tmpBase.%(ext)s")
     . " " . escapeshellarg($srcUrl) . " 2>&1";

logmsg("running: yt-dlp on $srcUrl");
$output = shell_exec($cmd);
$vttFiles = glob("$tmpBase*.vtt");
if (!$vttFiles) {
    fwrite(STDERR, "no VTT produced. yt-dlp output:\n" . substr((string)$output, -2000) . "\n");
    exit(3);
}
$vttFile = $vttFiles[0];
logmsg("got VTT: $vttFile (" . filesize($vttFile) . " bytes)");

// --- parse VTT into [{segment, text}, …] ---
// WebVTT parser tuned for YouTube live-stream auto-captions, which use a
// rolling format: each cue's first text line repeats the LAST line of the
// previous cue, and the next line is the new content. Naively concatenating
// every text line in a cue produces duplicated phrases — instead we keep
// just the last non-empty line of each cue (the new words), then strip
// rolling-prefix overlap against the running buffer of emitted text.
function parseVtt(string $path): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $rawCues = [];
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        if (preg_match('/^(\d{2}:\d{2}:\d{2})\.\d{3}\s+-->\s+/', $line, $m)) {
            $start = $m[1];
            $textBuf = [];
            $i++;
            while ($i < $n && trim($lines[$i]) !== '') {
                $t = $lines[$i];
                // Drop inline word-level timing tags YouTube embeds, e.g. <00:00:01.234>
                $t = preg_replace('/<\d{2}:\d{2}:\d{2}\.\d{3}>/', '', $t);
                // Drop YouTube's <c.colorXXX>…</c> color wrappers + any other HTML
                $t = preg_replace('#</?[^>]+>#', '', $t);
                // Decode HTML entities (&gt;, &amp;, &#39;, etc.)
                $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $t = trim($t);
                if ($t !== '') $textBuf[] = $t;
                $i++;
            }
            if (!$textBuf) continue;
            // Rolling-caption strategy: take ONLY the last non-empty text
            // line of each cue. The earlier lines are the carry-over from
            // the previous cue (already emitted). If a cue contains a
            // single line we still take it — that's the whole new content.
            $rawCues[] = ['segment' => $start, 'text' => end($textBuf)];
        } else {
            $i++;
        }
    }

    // Second pass: collapse rolling-prefix overlap. For each cue, if its
    // text starts with the tail of the previous emitted text, trim the
    // overlap. Also skip cues that are pure substrings of the previous
    // emitted text (no new content).
    $rows = [];
    $tail = ''; // last emitted text, used to detect overlap
    foreach ($rawCues as $cue) {
        $t = $cue['text'];
        // Skip if this cue's text is wholly contained in the previous tail.
        if ($tail !== '' && strpos($tail, $t) !== false) continue;
        // Strip overlap: find the largest suffix of $tail that's a prefix of $t.
        if ($tail !== '') {
            $maxOverlap = min(mb_strlen($tail), mb_strlen($t));
            for ($k = $maxOverlap; $k > 0; $k--) {
                $tailSuffix = mb_substr($tail, mb_strlen($tail) - $k);
                $tHead      = mb_substr($t, 0, $k);
                if (strcasecmp($tailSuffix, $tHead) === 0) {
                    $t = trim(mb_substr($t, $k));
                    break;
                }
            }
        }
        if ($t === '') continue;
        $rows[] = ['segment' => $cue['segment'], 'text' => $t];
        $tail = $t;
    }
    return $rows;
}

$rows = parseVtt($vttFile);
@unlink($vttFile);
foreach (glob("$tmpBase*") as $stray) @unlink($stray);
if (!$rows) { fwrite(STDERR, "VTT parsed to zero rows\n"); exit(4); }
logmsg("parsed " . count($rows) . " caption rows");

// --- write _auto + _autoclean for model='youtube' ---
$model = 'youtube';
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM yy_feed_item_transcript_auto      WHERE feed_item_key = ? AND feed_item_transcript_auto_model      = ?")->execute([$itemKey, $model]);
    $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")->execute([$itemKey, $model]);
    $insAuto = $db->prepare("
        INSERT INTO yy_feed_item_transcript_auto
            (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_auto_model)
        VALUES (?, ?::interval, ?, ?, ?)
    ");
    $insClean = $db->prepare("
        INSERT INTO yy_feed_item_transcript_autoclean
            (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_autoclean_model)
        VALUES (?, ?::interval, ?, ?, ?)
    ");
    $sort = 0;
    foreach ($rows as $r) {
        $raw   = mb_substr($r['text'], 0, 2000);
        $clean = mb_substr(applyCorrectionDictionary($db, $raw), 0, 2000);
        $insAuto ->execute([$itemKey, $r['segment'], $raw,   $sort, $model]);
        $insClean->execute([$itemKey, $r['segment'], $clean, $sort, $model]);
        $sort++;
    }
    $db->commit();
    echo "done: wrote $sort row(s) for item $itemKey model=$model from $srcUrl\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "DB write failed: " . $e->getMessage() . "\n");
    exit(5);
}
