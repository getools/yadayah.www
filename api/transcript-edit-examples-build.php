<?php
/**
 * Build yy_transcript_edit_example — what human editors actually change
 * (2026-10-13), used as in-context examples by the Layer-3 LLM reconcile so it
 * keeps learning from every manual edit (llmReconcileTranscript → cfEditExamples).
 *
 * Source: yy_transcript_edit_log rows made by a person (edit_user_key <> 0,
 * not part of a bulk find/replace batch, action 'edit'). Each before→after line
 * pair is word-diffed; every contiguous replaced/deleted span becomes a CHANGE
 * UNIT (from_text → to_text, case kept, punctuation-only changes dropped).
 * Units are aggregated across the corpus; only patterns seen in >= 2 different
 * videos are kept, and units that only swap common short words ("a" → "the")
 * are excluded — those are context-specific and must not become rules (the
 * learned-dictionary corruption lesson). Full rebuild, idempotent; run nightly.
 *
 *   php transcript-edit-examples-build.php [--min-items=2] [--dry-run]
 */
require_once __DIR__ . '/config.php';

$minItems = 2; $dry = in_array('--dry-run', $argv, true);
foreach ($argv as $a) if (strncmp($a, '--min-items=', 12) === 0) $minItems = max(1, (int)substr($a, 12));
$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

const EE_STOP = ['a'=>1,'an'=>1,'the'=>1,'and'=>1,'or'=>1,'but'=>1,'of'=>1,'to'=>1,'in'=>1,'on'=>1,'at'=>1,'for'=>1,'is'=>1,'it'=>1,
    'that'=>1,'this'=>1,'was'=>1,'were'=>1,'be'=>1,'are'=>1,'i'=>1,'you'=>1,'he'=>1,'she'=>1,'we'=>1,'they'=>1,'so'=>1,'uh'=>1,'um'=>1,
    'with'=>1,'as'=>1,'by'=>1,'from'=>1,'not'=>1,'no'=>1,'yes'=>1,'yeah'=>1,'if'=>1,'do'=>1,'did'=>1,'have'=>1,'has'=>1,'had'=>1,
    'there'=>1,'their'=>1,'them'=>1,'then'=>1,'than'=>1,'what'=>1,'which'=>1,'who'=>1,'its'=>1,"it's"=>1,'oh'=>1,'well'=>1,'just'=>1];

const EE_COMMON = ['when'=>1,'good'=>1,'all'=>1,'right'=>1,'very'=>1,'because'=>1,'now'=>1,'here'=>1,'how'=>1,'why'=>1,'where'=>1,
    'okay'=>1,'ok'=>1,'yes'=>1,'well'=>1,'look'=>1,'see'=>1,'let'=>1,'one'=>1,'two'=>1,'first'=>1,'some'=>1,'any'=>1,'all'=>1,
    'every'=>1,'our'=>1,'my'=>1,'your'=>1,'his'=>1,'her'=>1,'me'=>1,'us'=>1,'him'=>1,'would'=>1,'could'=>1,'should'=>1,'can'=>1,
    'will'=>1,'more'=>1,'most'=>1,'only'=>1,'even'=>1,'also'=>1,'about'=>1,'after'=>1,'before'=>1,'into'=>1,'over'=>1,'out'=>1,'up'=>1];
/** Words with surrounding punctuation stripped (case kept). */
function eeWords(string $s): array {
    $out = [];
    foreach (preg_split('/\s+/u', trim($s)) as $w) {
        $w = preg_replace("/^[^\\p{L}\\p{N}']+|[^\\p{L}\\p{N}']+$/u", '', str_replace(['’', '‘'], "'", $w));
        if ($w !== '' && $w !== null) $out[] = $w;
    }
    return $out;
}
/** LCS-based diff → list of [fromWords[], toWords[]] change spans (replace/delete/insert). */
function eeSpans(array $a, array $b): array {
    $n = count($a); $m = count($b);
    if ($n > 80 || $m > 80) return [];
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) for ($j = $m - 1; $j >= 0; $j--)
        $L[$i][$j] = ($a[$i] === $b[$j]) ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
    $spans = []; $i = 0; $j = 0; $fa = []; $fb = [];
    $flush = function () use (&$spans, &$fa, &$fb) { if ($fa || $fb) $spans[] = [$fa, $fb]; $fa = []; $fb = []; };
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) { $flush(); $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $fa[] = $a[$i++]; }
        else { $fb[] = $b[$j++]; }
    }
    while ($i < $n) $fa[] = $a[$i++];
    while ($j < $m) $fb[] = $b[$j++];
    $flush();
    return $spans;
}

$q = $db->query("SELECT feed_item_key, edit_original_text o, edit_new_text n, edit_dtime
                   FROM yy_transcript_edit_log
                  WHERE COALESCE(edit_user_key, 0) <> 0 AND edit_batch_key IS NULL AND edit_action = 'edit'
                    AND edit_original_text IS NOT NULL AND edit_new_text IS NOT NULL
                    AND edit_original_text <> edit_new_text");
$agg = []; $pairs = 0;
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $a = eeWords((string)$r['o']); $b = eeWords((string)$r['n']);
    if (!$a || $a === $b) continue;                       // punctuation-only change
    $pairs++;
    foreach (eeSpans($a, $b) as [$fa, $fb]) {
        if (!$fa) continue;                               // pure insertion: no retrievable key
        if (count($fa) > 6 || count($fb) > 8) continue;   // a rewrite, not a correction
        $fromL = array_map('mb_strtolower', $fa);
        $content = array_filter($fromL, fn($w) => !isset(EE_STOP[$w]) && mb_strlen($w) >= 3);
        $toContent = array_filter(array_map('mb_strtolower', $fb), fn($w) => !isset(EE_STOP[$w]) && mb_strlen($w) >= 3);
        if (!$content && !$toContent) continue;           // only common short words swapped
        $from = implode(' ', $fa); $to = implode(' ', $fb);
        $k = $from . "\x1f" . $to;
        if (!isset($agg[$k])) $agg[$k] = ['from' => $from, 'to' => $to, 'keys' => array_values(array_unique($fromL)),
                                           'n' => 0, 'items' => [], 'last' => '', 'eo' => '', 'en' => ''];
        $g = &$agg[$k];
        $g['n']++; $g['items'][(int)$r['feed_item_key']] = 1;
        if ($r['edit_dtime'] > $g['last']) $g['last'] = $r['edit_dtime'];
        if ($g['eo'] === '' || mb_strlen((string)$r['o']) < mb_strlen($g['eo'])) { $g['eo'] = mb_substr((string)$r['o'], 0, 200); $g['en'] = mb_substr((string)$r['n'], 0, 200); }
        unset($g);
    }
}
// Capitalisation-only changes of ordinary words ("when" → "When", "that's" →
// "That's") just mean "sentence start" — context, not a preference. Keep
// case-only changes that look like names/terms ("netanyahu" → "Netanyahu").
$caseOnlyCommon = function (array $g): bool {
    if (mb_strtolower($g['from']) !== mb_strtolower($g['to'])) return false;
    foreach (preg_split('/\s+/u', mb_strtolower($g['from'])) as $w) {
        if (!isset(EE_COMMON[$w]) && !isset(EE_STOP[$w]) && strpos($w, "'") === false) return false;
    }
    return true;
};
// Kept: seen in >= $minItems videos, or a strong single-video habit (>= 4x,
// not case-only) — a careful reviewer fixing the same term over and over.
$keep = array_filter($agg, fn($g) => !$caseOnlyCommon($g)
    && (count($g['items']) >= $minItems || ($g['n'] >= 4 && mb_strtolower($g['from']) !== mb_strtolower($g['to']))));
// A "from" that editors changed to several DIFFERENT things is ambiguous —
// keep only its dominant correction (>= 60% of that from's occurrences).
$byFrom = [];
foreach ($keep as $k => $g) $byFrom[mb_strtolower($g['from'])][] = $k;
foreach ($byFrom as $ks) {
    if (count($ks) < 2) continue;
    $tot = array_sum(array_map(fn($k) => $keep[$k]['n'], $ks));
    usort($ks, fn($x, $y) => $keep[$y]['n'] <=> $keep[$x]['n']);
    foreach ($ks as $i => $k) if ($i > 0 || $keep[$k]['n'] < 0.6 * $tot) unset($keep[$k]);
}
printf("%d human edit pairs → %d change patterns → %d kept (>= %d videos, unambiguous)\n", $pairs, count($agg), count($keep), $minItems);
uasort($keep, fn($x, $y) => count($y['items']) <=> count($x['items']));
foreach (array_slice($keep, 0, 25) as $g) printf("  %3d videos %4dx  %s  →  %s\n", count($g['items']), $g['n'], $g['from'], $g['to'] === '' ? '(deleted)' : $g['to']);
if ($dry) exit(0);

$db->exec("CREATE TABLE IF NOT EXISTS yy_transcript_edit_example (
              example_key serial PRIMARY KEY, from_text text NOT NULL, to_text text NOT NULL,
              key_tokens text[] NOT NULL, edit_count int NOT NULL, item_count int NOT NULL,
              last_dtime timestamptz, example_before text, example_after text)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_transcript_edit_example_keys ON yy_transcript_edit_example USING gin (key_tokens)");
$db->beginTransaction();
$db->exec("DELETE FROM yy_transcript_edit_example");
$ins = $db->prepare("INSERT INTO yy_transcript_edit_example (from_text, to_text, key_tokens, edit_count, item_count, last_dtime, example_before, example_after)
                     VALUES (?, ?, ?::text[], ?, ?, ?, ?, ?)");
$arr = fn(array $a) => '{' . implode(',', array_map(fn($x) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $x) . '"', $a)) . '}';
foreach ($keep as $g) $ins->execute([$g['from'], $g['to'], $arr($g['keys']), $g['n'], count($g['items']), $g['last'] ?: null, $g['eo'], $g['en']]);
$db->commit();
echo "wrote " . count($keep) . " rows to yy_transcript_edit_example\n";
