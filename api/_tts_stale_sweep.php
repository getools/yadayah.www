<?php
// Run: docker exec yada-www-web-1 php -d memory_limit=1G /var/www/html/api/_tts_stale_sweep.php
// READ-ONLY sweep: which TTS chapter audios no longer match the current book text?
// Fingerprint = the worker's .corpus-sig recipe (coalesce continuations, drop
// tables / skip-pages / back-matter, md5 of number|html). Mirrors _contraction_redo.php.
//   STALE  = sig on disk differs (or legacy dir with more parts than survivors)
//   NOSIG  = no fingerprint on disk; fallback: live markers point at paragraph
//            numbers/pages that no longer exist, or markers have NULL paragraph_key
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
$db = getDb();
$audioBase = is_dir('/var/www/html/u') ? '/var/www/html' : '/opt/yada-www/public';

$rows = $db->query("SELECT a.tts_audio_key, a.chapter_key, a.volume_key, a.tts_audio_status,
                           a.tts_audio_live_dtime IS NOT NULL AS is_live, a.tts_audio_message, v.volume_code, c.chapter_number
                      FROM yy_tts_audio a JOIN yy_volume v USING (volume_key) LEFT JOIN yy_chapter c USING (chapter_key)
                     WHERE a.tts_audio_active_flag AND a.chapter_key IS NOT NULL
                       AND a.tts_audio_status IN ('complete','paused','pending','failed')
                     ORDER BY v.volume_code, c.chapter_number")->fetchAll(PDO::FETCH_ASSOC);

$out = ['STALE' => [], 'NOSIG_DRIFT' => [], 'NOSIG_OK' => [], 'OK' => 0];
foreach ($rows as $a) {
    $ak = (int)$a['tts_audio_key']; $ck = (int)$a['chapter_key']; $vk = (int)$a['volume_key'];
    $skipRanges = [];
    $sr = $db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key = ?"); $sr->execute([$vk]);
    foreach (preg_split('/\s*,\s*/', (string)($sr->fetchColumn() ?: ''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $tok, $m)) $skipRanges[] = [(int)$m[1], (int)$m[2]];
        elseif (preg_match('/^\s*(\d+)\s*$/', $tok, $m))         $skipRanges[] = [(int)$m[1], (int)$m[1]];
    }
    $backMatterFrom = ttsBackMatterCutoff($db, $vk);
    $pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_text_html, paragraph_is_table, paragraph_is_continuation
                           FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
    $pst->execute([$ck]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);
    $merged = [];
    foreach ($prows as $r) {
        if (!empty($r['paragraph_is_continuation']) && $merged) {
            $h =& $merged[count($merged) - 1];
            $t = (string)($r['paragraph_text_html'] ?? '');
            if ($t !== '') $h['paragraph_text_html'] = rtrim((string)$h['paragraph_text_html']) . ' ' . ltrim($t);
            unset($h);
            continue;
        }
        $merged[] = $r;
    }
    $surv = array_values(array_filter($merged, function ($p) use ($skipRanges, $backMatterFrom) {
        if (!empty($p['paragraph_is_table'])) return false;
        if ($backMatterFrom !== null && (int)$p['paragraph_number'] >= $backMatterFrom) return false;
        $pg = (int)($p['paragraph_page'] ?? 0);
        foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return false;
        return true;
    }));
    $sig = md5(implode("\x1e", array_map(fn($p) => $p['paragraph_number'] . '|' . (string)($p['paragraph_text_html'] ?? ''), $surv)));
    $partsDir = $audioBase . '/u/tts-parts/' . $ak;
    $priorSig = is_file($partsDir . '/.corpus-sig') ? trim((string)file_get_contents($partsDir . '/.corpus-sig')) : '';
    $onDisk = count(glob($partsDir . '/p*.mp3') ?: []);
    $label = sprintf('%d %s ch%s %s%s', $ak, $a['volume_code'], $a['chapter_number'] ?? '?', $a['tts_audio_status'], $a['is_live'] ? ' live' : '');

    if ($priorSig !== '') {
        if ($priorSig !== $sig) $out['STALE'][] = $label; else $out['OK']++;
        continue;
    }
    if ($onDisk > count($surv)) { $out['STALE'][] = $label . ' (legacy parts>' . count($surv) . ')'; continue; }
    // No fingerprint: check live markers against current paragraphs.
    $pages = [];
    foreach ($prows as $r) $pages[(int)$r['paragraph_number']][] = (int)$r['paragraph_page'];
    $ms = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_key FROM yy_tts_audio_marker WHERE tts_audio_key = ?");
    $ms->execute([$ak]);
    $bad = 0; $nullk = 0; $n = 0;
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $n++;
        if ($m['paragraph_key'] === null) $nullk++;
        $num = (int)$m['paragraph_number'];
        if (!isset($pages[$num])) { $bad++; continue; }
        // marker page must be the paragraph's page or a following page it spills onto
        $pp = min($pages[$num]);
        if ((int)$m['paragraph_page'] < $pp || (int)$m['paragraph_page'] > $pp + 3) $bad++;
    }
    if ($bad || $nullk) $out['NOSIG_DRIFT'][] = "$label markers=$n bad=$bad nullkey=$nullk";
    else $out['NOSIG_OK'][] = "$label markers=$n";
}
printf("checked=%d OK=%d STALE=%d NOSIG_DRIFT=%d NOSIG_OK=%d\n", count($rows), $out['OK'], count($out['STALE']), count($out['NOSIG_DRIFT']), count($out['NOSIG_OK']));
foreach (['STALE', 'NOSIG_DRIFT', 'NOSIG_OK'] as $k) { echo "== $k\n"; foreach ($out[$k] as $l) echo "  $l\n"; }
