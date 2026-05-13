<?php
/**
 * Worker process spawned by admin-tts-build.php to generate a chapter
 * audio file. Runs through yy_paragraph rows for the chosen chapter,
 * classifies each paragraph_text_html into (voice, text) segments, calls
 * Azure TTS per paragraph, concatenates the MP3 bytes, and writes the
 * final file under /opt/yada-www/public/u/tts-audio/<volume>/ch<chN>.mp3.
 *
 * Updates yy_tts_audio.{tts_audio_status,tts_audio_progress,tts_audio_message}
 * each paragraph. Honors cancellation by re-checking tts_audio_status before
 * each paragraph synth.
 *
 * Usage:  php admin-tts-build-worker.php <tts_audio_key>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
require_once __DIR__ . '/spawn-helpers.php';

$audioKey = (int)($argv[1] ?? 0);
if (!$audioKey) {
    fwrite(STDERR, "tts_audio_key required\n");
    exit(2);
}

$db = getDb();

// ── Queue promotion ────────────────────────────────────────────────────
// Concurrency limit: at most 2 chapter builds may run at a time. When
// this worker exits (success, failure, or unexpected crash), promote the
// next pending row that has no worker_pid yet. Registered as a shutdown
// hook so even fatal errors / out-of-memory exits trigger the promote.
$MAX_CONCURRENT_BUILDS = 2;
register_shutdown_function(function() use (&$db, $MAX_CONCURRENT_BUILDS) {
    try {
        if (!$db) $db = getDb();
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status = 'running'")->fetchColumn();
        if ($running >= $MAX_CONCURRENT_BUILDS) return;
        $nextKey = (int)$db->query("
            SELECT tts_audio_key FROM yy_tts_audio
             WHERE tts_audio_status = 'pending'
               AND tts_audio_worker_pid IS NULL
             ORDER BY tts_audio_dtime ASC
             LIMIT 1
        ")->fetchColumn();
        if (!$nextKey) return;
        $logFile = sys_get_temp_dir() . '/tts_build_' . $nextKey . '.log';
        $pid = spawnCappedWorker(__FILE__, [(string)$nextKey], $logFile, [
            'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
               ->execute([$pid, $nextKey]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "promote-next failed: " . $e->getMessage() . "\n");
    }
});

function updateAudio(PDO $db, int $audioKey, array $fields): void {
    if (!$fields) return;
    $set = [];
    $params = [];
    foreach ($fields as $col => $val) {
        $set[] = "$col = ?";
        $params[] = $val;
    }
    $params[] = $audioKey;
    $db->prepare("UPDATE yy_tts_audio SET " . implode(', ', $set) . ", tts_audio_revision_dtime = NOW() WHERE tts_audio_key = ?")
       ->execute($params);
}

function bail(PDO $db, int $audioKey, string $err): void {
    updateAudio($db, $audioKey, [
        'tts_audio_status'          => 'failed',
        'tts_audio_error'           => $err,
        'tts_audio_completed_dtime' => date('Y-m-d H:i:sO'),
    ]);
    fwrite(STDERR, "FAIL: $err\n");
    exit(1);
}

// Mark running.
$row = $db->prepare("SELECT * FROM yy_tts_audio WHERE tts_audio_key = ?");
$row->execute([$audioKey]);
$job = $row->fetch();
if (!$job) { fwrite(STDERR, "tts_audio row $audioKey missing\n"); exit(2); }
updateAudio($db, $audioKey, [
    'tts_audio_status'  => 'running',
    'tts_audio_message' => 'Loading paragraphs',
    'tts_audio_progress'=> 1,
]);

$ttsKey     = (int)$job['tts_key'];
$volumeKey  = (int)$job['volume_key'];
$chapterKey = (int)$job['chapter_key'];
$settings   = json_decode($job['tts_audio_settings'] ?? 'null', true) ?: [];

// Build a config struct compatible with admin-tts-helpers — we splice the
// per-build snapshot in over the saved defaults so concurrent admin edits
// to yy_tts_category_voice don't disturb the in-flight run.
$cfg = loadTtsConfig($db, $ttsKey);
if (!$cfg['system']) bail($db, $audioKey, "tts_key $ttsKey not found");
if (!empty($settings['categories'])) {
    foreach ($settings['categories'] as $cat => $snap) {
        $cfg['categories'][$cat] = [
            'tts_voice_code'         => $snap['voice_code']   ?? ($cfg['categories'][$cat]['tts_voice_code'] ?? 'en-US-BrianMultilingualNeural'),
            'tts_voice_style'        => $snap['style']        ?? null,
            'tts_voice_style_degree' => $snap['style_degree'] ?? 1.0,
            'tts_voice_rate_pct'     => (int)($snap['rate_pct'] ?? 0),
            'tts_voice_pitch_st'     => (int)($snap['pitch_st'] ?? 0),
            'tts_voice_volume'       => (int)($snap['volume']   ?? 100),
        ];
    }
}
if (!empty($settings['output_format'])) {
    $cfg['system']['tts_output_format'] = $settings['output_format'];
}

// Snapshot every setting in effect right now into tts_audio_settings so a
// future re-render or audit can know exactly which voice + tunes + pauses
// produced this MP3. Concurrent admin edits to yy_tts_tune /
// yy_tts_pause / yy_tts_category_voice after this point won't be
// reflected in the snapshot — by design.
$snapshot = [
    'snapshot_dtime' => date('c'),
    'output_format'  => $cfg['system']['tts_output_format'] ?? null,
    'region'         => $cfg['system']['tts_region']        ?? null,
    'categories'     => array_values(array_map(function($code, $row) {
        return [
            'category'     => $code,
            'voice_code'   => $row['tts_voice_code']         ?? null,
            'style'        => $row['tts_voice_style']        ?? null,
            'style_degree' => $row['tts_voice_style_degree'] ?? 1.0,
            'rate_pct'     => (int)($row['tts_voice_rate_pct']  ?? 0),
            'pitch_st'     => (int)($row['tts_voice_pitch_st']  ?? 0),
            'volume'       => (int)($row['tts_voice_volume']    ?? 100),
        ];
    }, array_keys($cfg['categories'] ?? []), $cfg['categories'] ?? [])),
    'tunes' => array_values(array_map(function($t) {
        return [
            'print'         => $t['tts_tune_print']         ?? '',
            'phonetic'      => $t['tts_tune_phonetic']      ?? '',
            'phonetic_type' => $t['tts_tune_phonetic_type'] ?? 'sub',
            'note'          => $t['tts_tune_note']          ?? '',
            'active'        => !empty($t['tts_tune_active_flag']),
        ];
    }, $cfg['tunes'] ?? [])),
    'pauses' => array_values(array_map(function($p) {
        return [
            'search' => $p['tts_pause_search'] ?? '',
            'ms'     => (int)($p['tts_pause_ms'] ?? 300),
            'note'   => $p['tts_pause_note']   ?? '',
            'active' => !empty($p['tts_pause_active_flag']),
        ];
    }, $cfg['pauses'] ?? [])),
];
$db->prepare("UPDATE yy_tts_audio SET tts_audio_settings = ?::jsonb WHERE tts_audio_key = ?")
   ->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $audioKey]);

// Look up the volume's URL-safe slug (volume_code) — included in the
// filename so files are self-identifying without needing the DB.
$vcRow = $db->prepare("SELECT volume_code FROM yy_volume WHERE volume_key = ?");
$vcRow->execute([$volumeKey]);
$volumeSlug = (string)($vcRow->fetchColumn() ?: '');
if ($volumeSlug === '') $volumeSlug = (string)$volumeKey;

// Flat output: every chapter is a sibling file under /u/tts-audio/.
// Filename pattern:  {volume_code}-ch{NN}.{ext}
// Avoids per-volume subdirectories (simpler permissions, simpler cleanup).
$outDirHost      = '/opt/yada-www/public/u/tts-audio';
$outDirContainer = dirname(__DIR__) . '/u/tts-audio';
$outDir = is_dir(dirname(__DIR__)) ? $outDirContainer : $outDirHost;
if (!is_dir($outDir)) @mkdir($outDir, 0775, true);

// Chapter row for filename naming.
$chRow = $db->prepare("SELECT chapter_number FROM yy_chapter WHERE chapter_key = ?");
$chRow->execute([$chapterKey]);
$chNum = (int)($chRow->fetchColumn() ?: 0);
$ext = (strpos($cfg['system']['tts_output_format'], 'mp3') !== false) ? 'mp3'
     : ((strpos($cfg['system']['tts_output_format'], 'opus') !== false) ? 'opus'
     : ((strpos($cfg['system']['tts_output_format'], 'pcm') !== false) ? 'wav' : 'mp3'));
$baseName  = sprintf('%s-ch%02d.%s', $volumeSlug, $chNum, $ext);
$finalPath = $outDir . '/' . $baseName;
$relPath   = '/u/tts-audio/' . $baseName;

// Load paragraphs. paragraph_page is included so we can emit a row in
// yy_tts_audio_marker tying each paragraph's audio offset to its page
// — flipbook-tts.js uses these to auto-turn pages as playback advances.
$pStmt = $db->prepare("SELECT paragraph_key, paragraph_number, paragraph_page, paragraph_text_html, paragraph_text_plain FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
$pStmt->execute([$chapterKey]);
$paragraphs = $pStmt->fetchAll();
$nPara = count($paragraphs);
if (!$nPara) bail($db, $audioKey, "no paragraphs for chapter_key=$chapterKey");

// Clear any stale markers for this audio row — easier than upserting
// across schema changes and the table is small.
$db->prepare("DELETE FROM yy_tts_audio_marker WHERE tts_audio_key = ?")->execute([$audioKey]);
$insertMarker = $db->prepare("
    INSERT INTO yy_tts_audio_marker (tts_audio_key, paragraph_key, paragraph_page, paragraph_number, tts_audio_marker_offset_ms, tts_audio_marker_byte_offset)
    VALUES (?, ?, ?, ?, ?, ?)
    ON CONFLICT (tts_audio_key, paragraph_number, paragraph_page) DO UPDATE
        SET tts_audio_marker_offset_ms   = EXCLUDED.tts_audio_marker_offset_ms,
            tts_audio_marker_byte_offset = EXCLUDED.tts_audio_marker_byte_offset,
            paragraph_key                = EXCLUDED.paragraph_key
");

// ffprobe path — used to measure each paragraph's mp3 chunk duration
// so we know its offset into the cumulative file. ffprobe isn't strictly
// required (we can fall back to a character-count estimate) but it's
// always installed in the prod container and gives sample-accurate
// offsets that align with what the browser sees.
$ffprobeBin = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
$tmpDir = sys_get_temp_dir();
function probeDurationMs(string $bin, string $bytes, string $tmpDir): int {
    if ($bin === '' || $bytes === '') return 0;
    $tmp = tempnam($tmpDir, 'tts-chunk-');
    file_put_contents($tmp, $bytes);
    $cmd = escapeshellcmd($bin) . ' -v error -show_entries format=duration -of default=nokey=1:noprint_wrappers=1 ' . escapeshellarg($tmp) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    @unlink($tmp);
    $sec = $out !== null ? (float)trim($out) : 0.0;
    return (int)round($sec * 1000);
}
$cumulativeMs    = 0;
$cumulativeBytes = 0;

// Azure TTS retry wrapper. The 429 (rate-limited) and 5xx (server-side)
// responses are retriable — we sleep with exponential backoff and try
// again, up to 6 attempts (cumulative max wait ≈ 63s per paragraph).
// Other 4xx errors (bad SSML, auth, etc.) are permanent so we bail
// immediately and let the caller log a per-paragraph failure.
function azureTtsSynthesizeRetry(string $ssml, array $cfg, ?string &$err = null, int $maxAttempts = 6): string {
    $attempt = 0;
    $delay   = 1;        // seconds
    while ($attempt < $maxAttempts) {
        $bytes = azureTtsSynthesize($ssml, $cfg, $err);
        if ($bytes !== '') return $bytes;
        $shouldRetry = false;
        if ($err === null || $err === '') {
            $shouldRetry = true;          // empty body, no HTTP code — treat as transient
        } else if (preg_match('/HTTP (429|5\d\d)/', $err)) {
            $shouldRetry = true;          // rate-limit / server error
        } else if (preg_match('/HTTP 0\b/', $err)) {
            $shouldRetry = true;          // curl-level error (timeout / dns)
        }
        if (!$shouldRetry) return '';
        $attempt++;
        if ($attempt >= $maxAttempts) break;
        // Echo to STDERR so the operator can watch progress in worker logs.
        fwrite(STDERR, "azure retry $attempt after {$delay}s — $err\n");
        sleep($delay);
        $delay = min($delay * 2, 30);
    }
    return '';
}

// Page-break-within-paragraph detection. Reads text/page-NNN.json from
// the flipbook bundle to locate where in the paragraph a page break
// occurs (when the paragraph spans into the next page). The build
// worker stores an extra marker for each crossed page so playback
// auto-turns mid-paragraph rather than only at the next paragraph's
// boundary.
$bundleDir = '/opt/yada-www/public/' . $volumeSlug;
if (!is_dir($bundleDir)) {
    $bundleDir = dirname(__DIR__) . '/' . $volumeSlug;
}
$pageTextCache = [];

// Normalize text for fuzzy match — same strip-set the search code uses
// (curly apostrophes, half-rings, en/em-dashes), plus whitespace collapse.
function ttsNormalizeForMatch(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\x{02BF}\x{02BE}\x{02BC}\x{02BB}\x{02B9}\x{02BA}\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}\x{0027}]/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim((string)$s);
}

// Returns the concatenated text of a page from text/page-NNN.json
// (whitespace-separated). Empty string if the file is missing.
function ttsLoadPageText(string $bundleDir, int $page, array &$cache): string {
    if (isset($cache[$page])) return $cache[$page];
    $f = sprintf('%s/text/page-%03d.json', $bundleDir, $page);
    if (!is_file($f)) return $cache[$page] = '';
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j) || empty($j['spans'])) return $cache[$page] = '';
    $buf = '';
    foreach ($j['spans'] as $sp) {
        if (isset($sp[4])) $buf .= $sp[4] . ' ';
    }
    return $cache[$page] = $buf;
}

// findPageBreakRatios: for a paragraph that may span multiple pages,
// returns a map of [k_offset => ratio_in_paragraph] indicating that
// page (P_start + k) gets the chars from ratio*len onward. Allows up
// to ~300 chars of page-header noise (chapter title, page number)
// before the matched suffix starts on the next page. Returns [] when
// the paragraph fits on a single page or no good match was found.
function ttsFindPageBreakRatios(string $paraText, array $nextPageTexts): array {
    $out = [];
    $p = ttsNormalizeForMatch($paraText);
    $plen = mb_strlen($p);
    if ($plen < 30) return $out;
    foreach ($nextPageTexts as $k => $pageText) {
        $g = ttsNormalizeForMatch($pageText);
        if ($g === '') break;
        $bestRatio = -1.0;
        // Shrink the suffix length geometrically until we either match or
        // hit the floor. Floor at 25 chars to avoid spurious matches on
        // common short phrases.
        $minL = 25;
        for ($L = $plen; $L >= $minL; ) {
            $suffix = mb_substr($p, -$L);
            $pos = mb_strpos($g, $suffix);
            if ($pos !== false && $pos < 400) {
                $bestRatio = ($plen - $L) / $plen;
                break;
            }
            $next = (int)floor($L * 0.85);
            if ($next === $L) $next--;
            $L = $next;
        }
        if ($bestRatio < 0) break;   // paragraph doesn't extend further
        $out[$k] = $bestRatio;
    }
    return $out;
}

updateAudio($db, $audioKey, [
    'tts_audio_message'        => "Synthesizing $nPara paragraphs",
    'tts_audio_paragraph_count'=> $nPara,
]);

// Open output file for streaming concat.
$fh = fopen($finalPath, 'wb');
if (!$fh) bail($db, $audioKey, "cannot open $finalPath for write");

$charsBilled = 0;
$failureCount = 0;
$failures = [];

foreach ($paragraphs as $idx => $p) {
    // Cancellation check.
    if (($idx % 5) === 0) {
        $statusCheck = $db->prepare("SELECT tts_audio_status FROM yy_tts_audio WHERE tts_audio_key = ?");
        $statusCheck->execute([$audioKey]);
        $cur = $statusCheck->fetchColumn();
        if ($cur === 'failed') {
            fclose($fh);
            @unlink($finalPath);
            fwrite(STDERR, "cancelled\n");
            exit(0);
        }
    }

    // Apply per-font filtering (skip + pause-on-switch) BEFORE
    // segmenting. The filter strips <span data-font="…"> tags either
    // way; skipped-font content is dropped; pause-marked fonts get a
    // PAUSE placeholder inserted that placeholdersToBreaks rewrites
    // into <break time="Nms"/> further down the pipeline.
    $rawHtml = (string)$p['paragraph_text_html'];
    $rawHtml = preprocessFontFilter($rawHtml, $cfg['fonts'] ?? []);
    $segs = segmentParagraph($rawHtml);
    if (!$segs) continue;

    $blocks = '';
    foreach ($segs as $seg) {
        $blocks .= buildVoiceBlock($seg['text'], $cfg, $seg['category']);
    }
    if ($blocks === '') continue;
    $ssml = wrapSsml($blocks);
    $paraBytes = '';
    if (strlen($ssml) > 9500) {
        // Over Azure's per-request limit — split into one synth call per segment instead.
        foreach ($segs as $seg) {
            $oneSsml = wrapSsml(buildVoiceBlock($seg['text'], $cfg, $seg['category']));
            $err = '';
            $b = azureTtsSynthesizeRetry($oneSsml, $cfg, $err);
            if ($b === '') {
                $failures[] = "para {$p['paragraph_number']} seg: $err";
                $failureCount++;
                continue;
            }
            $paraBytes .= $b;
            $charsBilled += mb_strlen($seg['text']);
        }
    } else {
        $err = '';
        $b = azureTtsSynthesizeRetry($ssml, $cfg, $err);
        if ($b === '') {
            $failures[] = "para {$p['paragraph_number']}: $err";
            $failureCount++;
            continue;
        }
        $paraBytes = $b;
        foreach ($segs as $seg) $charsBilled += mb_strlen($seg['text']);
    }
    if ($paraBytes === '') continue;

    // Write this paragraph's marker BEFORE appending its bytes to the
    // output file, so the offset captures the position at which the
    // paragraph's audio starts in the concatenated file. ffprobe's
    // per-chunk read is roughly 5-15ms per paragraph — small compared
    // to Azure's network call so it doesn't materially slow the build.
    $paraStartMs    = $cumulativeMs;
    $paraStartBytes = $cumulativeBytes;
    $paraStartPage  = $p['paragraph_page'] !== null ? (int)$p['paragraph_page'] : null;
    try {
        $insertMarker->execute([
            $audioKey,
            $p['paragraph_key'] !== null ? (int)$p['paragraph_key'] : null,
            $paraStartPage,
            (int)$p['paragraph_number'],
            $paraStartMs,
            $paraStartBytes,
        ]);
    } catch (Exception $e) { /* don't fail the build if marker write fails */ }

    fwrite($fh, $paraBytes);
    $cumulativeBytes += strlen($paraBytes);
    $paragraphMs      = probeDurationMs($ffprobeBin, $paraBytes, $tmpDir);
    $cumulativeMs    += $paragraphMs;

    // Page-break-within-paragraph markers. Look ahead up to 5 pages —
    // far more than any real paragraph spans — and emit one marker per
    // crossed page boundary at the interpolated time offset.
    if ($paraStartPage !== null && $paragraphMs > 0) {
        $paraTextPlain = (string)($p['paragraph_text_plain'] ?? '');
        if ($paraTextPlain !== '') {
            $nextPageTexts = [];
            for ($k = 1; $k <= 5; $k++) {
                $pt = ttsLoadPageText($bundleDir, $paraStartPage + $k, $pageTextCache);
                if ($pt === '') break;
                $nextPageTexts[$k] = $pt;
            }
            if ($nextPageTexts) {
                $ratios = ttsFindPageBreakRatios($paraTextPlain, $nextPageTexts);
                foreach ($ratios as $kOffset => $ratio) {
                    $brkMs = (int)round($paraStartMs + $ratio * $paragraphMs);
                    try {
                        $insertMarker->execute([
                            $audioKey,
                            $p['paragraph_key'] !== null ? (int)$p['paragraph_key'] : null,
                            $paraStartPage + $kOffset,
                            (int)$p['paragraph_number'],
                            $brkMs,
                            null,
                        ]);
                    } catch (Exception $e) { /* skip */ }
                }
            }
        }
    }

    if (($idx % 5) === 0 || $idx === $nPara - 1) {
        $pct = (int)floor(($idx + 1) / max(1, $nPara) * 95) + 2;
        updateAudio($db, $audioKey, [
            'tts_audio_progress'      => min(99, $pct),
            'tts_audio_message'       => sprintf('Paragraph %d / %d (%d fails)', $idx + 1, $nPara, $failureCount),
            'tts_audio_chars_billed'  => $charsBilled,
        ]);
    }
}
fclose($fh);

$finalSize = filesize($finalPath);
if (!$finalSize) bail($db, $audioKey, "output file is empty (every paragraph failed); first errs: " . implode(' | ', array_slice($failures, 0, 3)));

// Probe duration with ffprobe if available.
$duration = null;
$ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
if ($ffprobe) {
    $out = shell_exec(escapeshellcmd($ffprobe) . ' -v error -show_entries format=duration -of default=nokey=1:noprint_wrappers=1 ' . escapeshellarg($finalPath) . ' 2>/dev/null');
    if ($out) $duration = (int)round((float)trim($out));
}

updateAudio($db, $audioKey, [
    'tts_audio_status'          => 'complete',
    'tts_audio_progress'        => 100,
    'tts_audio_message'         => $failureCount ? "Done with $failureCount paragraph failure(s)" : 'Done',
    'tts_audio_path'            => $relPath,
    'tts_audio_size_bytes'      => $finalSize,
    'tts_audio_duration_secs'   => $duration,
    'tts_audio_completed_dtime' => date('Y-m-d H:i:sO'),
    'tts_audio_error'           => $failures ? implode(' | ', array_slice($failures, 0, 5)) : null,
]);

// Refresh the volume's bundled mp3.zip so the flipbook's "Download MP3"
// button always serves the latest chapter set. Best-effort — a zip
// failure is logged but doesn't fail the build.
try {
    if (!rebuildVolumeMp3Zip($db, $volumeKey)) {
        fwrite(STDERR, "rebuildVolumeMp3Zip returned false for volume $volumeKey\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "rebuildVolumeMp3Zip failed: " . $e->getMessage() . "\n");
}

exit(0);

/* ── segmentation ───────────────────────────────────────────────────
 * Walk paragraph_text_html and classify text into:
 *   main             — plain body text
 *   translation      — inside <b>
 *   word_definition  — inside ( ) (parenthesized definition block)
 *
 * Both '(' and ')' belong to the word_definition segment. Bible/Islam
 * detection is reserved for a future pattern-matching pass — for now
 * paragraphs are classified only by the HTML structure.
 */
function segmentParagraph(string $html): array {
    $segments = [];
    $cur = ['category' => null, 'text' => ''];

    $boldDepth = 0;
    $italicDepth = 0;
    $parenDepth = 0;
    $i = 0; $n = strlen($html);
    while ($i < $n) {
        $ch = $html[$i];
        if ($ch === '<') {
            $end = strpos($html, '>', $i);
            if ($end === false) break;
            $tag = strtolower(substr($html, $i + 1, $end - $i - 1));
            $closing = (strlen($tag) > 0 && $tag[0] === '/');
            $name = $closing ? substr($tag, 1) : $tag;
            $name = preg_split('/[\s>\/]/', $name, 2)[0];
            if ($name === 'b' || $name === 'strong') {
                $closing ? ($boldDepth > 0 && $boldDepth--) : $boldDepth++;
            } elseif ($name === 'i' || $name === 'em') {
                $closing ? ($italicDepth > 0 && $italicDepth--) : $italicDepth++;
            }
            $i = $end + 1;
            continue;
        }
        // Plain character (entity or literal). Decode entities one at a time.
        $piece = $ch;
        if ($ch === '&') {
            $semi = strpos($html, ';', $i);
            if ($semi !== false && $semi - $i <= 8) {
                $piece = html_entity_decode(substr($html, $i, $semi - $i + 1), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $i = $semi + 1;
            } else {
                $i++;
            }
        } else {
            $i++;
        }
        foreach (mb_str_split($piece) as $c) {
            if ($c === '(') { $parenDepth++; $cat = 'word_definition'; }
            elseif ($c === ')' && $parenDepth > 0) { $cat = 'word_definition'; $parenDepth--; }
            elseif ($parenDepth > 0) { $cat = 'word_definition'; }
            elseif ($boldDepth  > 0) { $cat = 'translation'; }
            else                     { $cat = 'main'; }
            if ($cat !== $cur['category']) {
                if (trim($cur['text']) !== '') $segments[] = $cur;
                $cur = ['category' => $cat, 'text' => ''];
            }
            $cur['text'] .= $c;
        }
    }
    if (trim($cur['text']) !== '') $segments[] = $cur;

    // Merge adjacent same-category, drop whitespace-only segments after merge,
    // and trim outer whitespace.
    $merged = [];
    foreach ($segments as $s) {
        if ($merged && end($merged)['category'] === $s['category']) {
            $merged[count($merged) - 1]['text'] .= $s['text'];
        } else {
            $merged[] = $s;
        }
    }
    foreach ($merged as &$s) {
        $s['text'] = preg_replace('/\s+/u', ' ', $s['text']);
        $s['text'] = trim($s['text']);
    }
    unset($s);
    return array_values(array_filter($merged, fn($s) => $s['text'] !== ''));
}
