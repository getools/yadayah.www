<?php
/**
 * Perry lexicon import — loads YY_Lexicon.csv into the Hebrew lexicon.
 *
 * CLI only.  DRY RUN BY DEFAULT; pass --apply to write.  Companion to
 * _word_harvest.php, which owns word_count_yy and yy_word_occurrence.
 *
 *   php _perry_import.php --csv=/root/YY_Lexicon.csv            # dry run
 *   php _perry_import.php --csv=/root/YY_Lexicon.csv --apply
 *   php _perry_import.php --reset --apply                       # undo an import
 *
 * The CSV has 2,933 rows but only 2,116 distinct Strong's numbers: Hebrew,
 * StrongsTransliteration and StrongsGloss never vary inside a Strong's group,
 * only CraigTransliteration / OccurrenceCount / Confidence / SampleCraigGloss.
 * So a group is ONE word with several spellings and several Perry glosses.
 *
 * Mapping (confirmed with the user before the first run)
 *   StrongsNumber           -> yy_word.word_strongs, via normalizeStrongs()
 *                              ('H1' -> 'H0001')
 *   StrongsHebrew           -> yy_word.word_hebrew, niqqud stripped; word_yt
 *                              then derives from it via trg_word_yt
 *   StrongsTransliteration  -> yy_word.word_pronunciation_strongs
 *   StrongsGloss            -> yy_word.word_definition_kirk + a yy_word_definition
 *                              row tagged 'kirk' (that source IS Strong's Dictionary)
 *   CraigTransliteration    -> yy_word_translit rows, one per distinct spelling;
 *                              the most-attested one mirrors to yy_word.word_translit
 *   SampleCraigGloss        -> yy_word.word_definition_perry (best row) + a
 *                              yy_word_definition row tagged 'perry' per distinct gloss
 *   everything else         -> yy_word_perry_import, the staging copy of the CSV
 *
 * word_count_yy is NOT set here — it is derived from yy_word_occurrence by
 * _word_harvest.php, and trg_recalc_word_count_yy zeroes it on the first
 * translit insert anyway.  Run `_word_harvest.php --index --apply` afterwards
 * to give these words real counts and drill-down.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/config.php';

$opts    = getopt('', ['csv::', 'apply', 'reset', 'limit::']);
$apply   = array_key_exists('apply', $opts);
$reset   = array_key_exists('reset', $opts);
$csvPath = (string)($opts['csv'] ?? __DIR__ . '/../YY_Lexicon.csv');
$limit   = isset($opts['limit']) ? (int)$opts['limit'] : 0;

$db = getDb();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }

/* ── source keys ────────────────────────────────────────────────────────── */
$srcKeys = [];
foreach ($db->query('SELECT word_source_code, word_source_key FROM yy_word_source')->fetchAll() as $r) {
    $srcKeys[$r['word_source_code']] = (int)$r['word_source_key'];
}
foreach (['perry', 'kirk'] as $need) {
    if (empty($srcKeys[$need])) {
        fwrite(STDERR, "Missing yy_word_source '$need' — run sql/perry_lexicon.sql first.\n");
        exit(1);
    }
}

/* ── --reset: remove a previous run ─────────────────────────────────────── */
if ($reset) {
    $n = (int)$db->query("SELECT count(*) FROM yy_word WHERE word_source_code = 'perry'")->fetchColumn();
    out("--reset: $n perry words would be deleted (with their translits, definitions and staging rows).");
    if (!$apply) { out('Dry run — nothing deleted. Add --apply.'); exit(0); }
    $db->beginTransaction();
    // yy_word_perry_import and yy_word_occurrence cascade; the rest do not.
    $db->exec("DELETE FROM yy_word_definition WHERE word_key IN (SELECT word_key FROM yy_word WHERE word_source_code='perry')");
    $db->exec("DELETE FROM yy_word_translit   WHERE word_key IN (SELECT word_key FROM yy_word WHERE word_source_code='perry')");
    $db->exec("DELETE FROM yy_word_pos_map    WHERE word_key IN (SELECT word_key FROM yy_word WHERE word_source_code='perry')");
    $db->exec("DELETE FROM yy_word WHERE word_source_code = 'perry'");
    $db->commit();
    out("Deleted $n perry words.");
    exit(0);
}

/* ── read the CSV ───────────────────────────────────────────────────────── */
if (!is_readable($csvPath)) { fwrite(STDERR, "Cannot read $csvPath\n"); exit(1); }
$fh = fopen($csvPath, 'r');
$head = fgetcsv($fh);
if ($head === false) { fwrite(STDERR, "Empty CSV\n"); exit(1); }
$head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);   // strip the BOM
$want = ['StrongsNumber','StrongsHebrew','StrongsTransliteration','StrongsGloss',
         'CraigTransliteration','OccurrenceCount','AvgMatchScore','Confidence',
         'Source','SampleCraigGloss','SampleVerses'];
if ($head !== $want) {
    fwrite(STDERR, "Unexpected CSV header:\n  got  " . implode(',', $head)
                 . "\n  want " . implode(',', $want) . "\n");
    exit(1);
}

/**
 * Hebrew points, exactly the set trg_word_yt strips, plus the combining
 * grapheme joiner the CSV carries on two rows.  Maqaf and space are word
 * structure, not pointing, so they stay.
 */
function stripPoints(string $s): string {
    return preg_replace('/[\x{0591}-\x{05BD}\x{05BF}\x{05C1}\x{05C2}\x{05C4}\x{05C5}\x{05C7}\x{034F}]/u', '', $s);
}

/**
 * The CSV clipped glosses at exactly 100 chars, mid-word.  Measure the RAW
 * value, not a trimmed one: 87 of them were cut on a space, so trimming first
 * makes them 99 and hides the truncation.
 */
const CSV_GLOSS_CAP = 100;
function clipped(string $raw): bool { return mb_strlen($raw) === CSV_GLOSS_CAP; }
function withEllipsis(string $raw): string { return clipped($raw) ? rtrim($raw) . '…' : trim($raw); }

$rows = [];
$rowNum = 0;
$bad = [];
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) === 1 && trim((string)$r[0]) === '') continue;
    $rowNum++;
    $d = array_combine($want, array_pad(array_slice($r, 0, count($want)), count($want), ''));
    $d['_row']     = $rowNum;
    $d['_strongs'] = normalizeStrongs($d['StrongsNumber']);
    if ($d['_strongs'] === false || $d['_strongs'] === null) { $bad[] = $d; continue; }
    $rows[] = $d;
    if ($limit && $rowNum >= $limit) break;
}
fclose($fh);
out(sprintf('CSV: %d rows read, %d rejected for an unparseable Strong\'s number.', count($rows), count($bad)));
foreach (array_slice($bad, 0, 5) as $b) out('  rejected: ' . $b['StrongsNumber']);

/* ── group into words ───────────────────────────────────────────────────── */
$groups = [];
foreach ($rows as $d) $groups[$d['_strongs']][] = $d;
ksort($groups);
out(sprintf('Grouped into %d words (%d spelling rows).', count($groups), count($rows)));

/** Rank a CSV row: most occurrences wins, High confidence breaks a tie. */
function rowRank(array $d): array {
    return [(int)$d['OccurrenceCount'], $d['Confidence'] === 'High' ? 1 : 0];
}

$plan = [];
$warnInconsistent = [];
foreach ($groups as $strongs => $rs) {
    // Hebrew/pronunciation/Strong's gloss are supposed to be constant per group.
    foreach (['StrongsHebrew', 'StrongsTransliteration', 'StrongsGloss'] as $c) {
        if (count(array_unique(array_column($rs, $c))) > 1) $warnInconsistent[] = "$strongs.$c";
    }
    $best = $rs[0];
    foreach ($rs as $d) if (rowRank($d) > rowRank($best)) $best = $d;

    // Spellings, deduped case-insensitively; keep the best-attested casing.
    $sp = [];
    foreach ($rs as $d) {
        $t = trim($d['CraigTransliteration']);
        if ($t === '') continue;
        $k = mb_strtolower($t);
        if (!isset($sp[$k]) || rowRank($d) > rowRank($sp[$k]['row'])) $sp[$k] = ['text' => $t, 'row' => $d];
    }
    uasort($sp, fn($a, $b) => rowRank($b['row']) <=> rowRank($a['row']));
    $spellings = array_values(array_map(fn($x) => $x['text'], $sp));

    // Perry glosses, deduped on normalised text, best row first.
    $gl = [];
    foreach ($rs as $d) {
        $g = trim($d['SampleCraigGloss']);
        if ($g === '') continue;
        $k = mb_strtolower(preg_replace('/\s+/', ' ', $g));
        // Keep the RAW value so withEllipsis() can still see the 100-char cut.
        if (!isset($gl[$k]) || rowRank($d) > rowRank($gl[$k]['row'])) $gl[$k] = ['text' => $d['SampleCraigGloss'], 'row' => $d];
    }
    uasort($gl, fn($a, $b) => rowRank($b['row']) <=> rowRank($a['row']));
    $glosses = array_values(array_map(fn($x) => withEllipsis($x['text']), $gl));

    $plan[$strongs] = [
        'strongs'   => $strongs,
        'hebrew'    => stripPoints(trim($best['StrongsHebrew'])),
        'pron'      => trim($best['StrongsTransliteration']),
        'glossKirk' => withEllipsis($best['StrongsGloss']),
        'spellings' => $spellings,
        'glosses'   => $glosses,
        'rows'      => $rs,
    ];
}
if ($warnInconsistent) {
    out(sprintf('⚠ %d group/column pairs disagree inside a Strong\'s group (best row wins): %s',
        count($warnInconsistent), implode(', ', array_slice($warnInconsistent, 0, 8))));
}

/* ── report ─────────────────────────────────────────────────────────────── */
$nSp   = array_sum(array_map(fn($p) => count($p['spellings']), $plan));
$nGl   = array_sum(array_map(fn($p) => count($p['glosses']),   $plan));
$nTrunc = 0; $nTruncK = 0;
foreach ($rows as $d) {
    if (clipped($d['SampleCraigGloss'])) $nTrunc++;
    if (clipped($d['StrongsGloss']))     $nTruncK++;
}
$existingStrongs = [];
foreach ($db->query("SELECT DISTINCT trim(word_strongs) AS s FROM yy_word WHERE word_strongs IS NOT NULL AND trim(word_strongs) <> ''")->fetchAll() as $r) {
    $existingStrongs[$r['s']] = true;
}
$dupStrongs = count(array_intersect_key($plan, $existingStrongs));

out('');
out('Would insert');
out(sprintf('  yy_word              %6d  (source perry, active)', count($plan)));
out(sprintf('  yy_word_translit     %6d  spellings', $nSp));
out(sprintf('  yy_word_definition   %6d  perry + %d kirk (Strong\'s gloss)', $nGl, count($plan)));
out(sprintf('  yy_word_perry_import %6d  staging rows (the CSV verbatim)', count($rows)));
out(sprintf('  %d words share a Strong\'s number with an existing word — expected, the full set was requested.', $dupStrongs));
out(sprintf('  %d Perry glosses and %d Strong\'s glosses are CSV-truncated; an ellipsis is appended and the staging row is flagged.', $nTrunc, $nTruncK));

$already = (int)$db->query("SELECT count(*) FROM yy_word WHERE word_source_code = 'perry'")->fetchColumn();
if ($already > 0) {
    fwrite(STDERR, "\n⚠ $already perry words already exist. Run with --reset --apply first, or this would double them.\n");
    exit(1);
}
if (!$apply) { out("\nDry run — nothing written. Add --apply."); exit(0); }

/* ── write ──────────────────────────────────────────────────────────────── */
$db->beginTransaction();
try {
    $insWord = $db->prepare(
        'INSERT INTO yy_word
            (word_source_code, word_strongs, word_hebrew, word_translit,
             word_definition_kirk, word_definition_perry,
             word_pronunciation_strongs, word_active_flag)
         VALUES (\'perry\', ?, ?, ?, ?, ?, ?, true)
         RETURNING word_key');
    $insTr = $db->prepare(
        'INSERT INTO yy_word_translit (word_key, word_translit_text, word_translit_sort)
         VALUES (?, ?, ?)');
    $insDef = $db->prepare(
        'INSERT INTO yy_word_definition (word_key, word_source_key, word_definition_text)
         VALUES (?, ?, ?)');
    $insStg = $db->prepare(
        'INSERT INTO yy_word_perry_import
            (word_key, word_perry_import_row, word_perry_import_strongs_raw,
             word_perry_import_strongs, word_perry_import_hebrew,
             word_perry_import_pronunciation, word_perry_import_gloss_strongs,
             word_perry_import_translit, word_perry_import_occurrences,
             word_perry_import_score, word_perry_import_confidence,
             word_perry_import_method, word_perry_import_gloss,
             word_perry_import_verses, word_perry_import_gloss_truncated,
             word_perry_import_gloss_strongs_truncated)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

    $nW = $nT = $nD = $nS = 0;
    foreach ($plan as $p) {
        $insWord->execute([
            $p['strongs'],
            $p['hebrew']    !== '' ? $p['hebrew']    : null,
            $p['spellings'] ? $p['spellings'][0]     : null,
            $p['glossKirk'] !== '' ? $p['glossKirk'] : null,
            $p['glosses']   ? $p['glosses'][0]       : null,
            $p['pron']      !== '' ? $p['pron']      : null,
        ]);
        $wk = (int)$insWord->fetchColumn();
        $nW++;

        foreach ($p['spellings'] as $i => $t) { $insTr->execute([$wk, $t, $i]); $nT++; }
        foreach ($p['glosses']   as $g)       { $insDef->execute([$wk, $srcKeys['perry'], $g]); $nD++; }
        if ($p['glossKirk'] !== '')           { $insDef->execute([$wk, $srcKeys['kirk'],  $p['glossKirk']]); $nD++; }

        foreach ($p['rows'] as $d) {
            $insStg->execute([
                $wk, $d['_row'], $d['StrongsNumber'], $d['_strongs'],
                $d['StrongsHebrew'], $d['StrongsTransliteration'], $d['StrongsGloss'],
                $d['CraigTransliteration'],
                $d['OccurrenceCount'] !== '' ? (int)$d['OccurrenceCount'] : null,
                $d['AvgMatchScore']   !== '' ? $d['AvgMatchScore']        : null,
                $d['Confidence'], $d['Source'], $d['SampleCraigGloss'], $d['SampleVerses'],
                (int)clipped($d['SampleCraigGloss']),
                (int)clipped($d['StrongsGloss']),
            ]);
            $nS++;
        }
    }
    $db->commit();
    out('');
    out(sprintf('Wrote %d words, %d spellings, %d definitions, %d staging rows.', $nW, $nT, $nD, $nS));
    out('⚠ word_count_yy is 0 for these until `php _word_harvest.php --index --apply` runs.');
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'FAILED, rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}
