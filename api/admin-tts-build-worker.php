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

// Load paragraphs.
$pStmt = $db->prepare("SELECT paragraph_number, paragraph_text_html FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
$pStmt->execute([$chapterKey]);
$paragraphs = $pStmt->fetchAll();
$nPara = count($paragraphs);
if (!$nPara) bail($db, $audioKey, "no paragraphs for chapter_key=$chapterKey");

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
    if (strlen($ssml) > 9500) {
        // Over Azure's per-request limit — split into one synth call per segment instead.
        $bytes = '';
        foreach ($segs as $seg) {
            $oneSsml = wrapSsml(buildVoiceBlock($seg['text'], $cfg, $seg['category']));
            $err = '';
            $b = azureTtsSynthesize($oneSsml, $cfg, $err);
            if ($b === '') {
                $failures[] = "para {$p['paragraph_number']} seg: $err";
                $failureCount++;
                continue;
            }
            $bytes .= $b;
            $charsBilled += mb_strlen($seg['text']);
        }
        if ($bytes !== '') fwrite($fh, $bytes);
    } else {
        $err = '';
        $bytes = azureTtsSynthesize($ssml, $cfg, $err);
        if ($bytes === '') {
            $failures[] = "para {$p['paragraph_number']}: $err";
            $failureCount++;
            continue;
        }
        fwrite($fh, $bytes);
        foreach ($segs as $seg) $charsBilled += mb_strlen($seg['text']);
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
