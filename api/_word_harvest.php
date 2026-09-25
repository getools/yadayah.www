<?php
/**
 * Harvest transliterated Hebrew words out of the books into yy_word.
 *
 *   php _word_harvest.php                     dry run — report only
 *   php _word_harvest.php --apply             write
 *   php _word_harvest.php --min=3 --ratio=0.3 tune the candidate filter
 *   php _word_harvest.php --recount-only      only refresh counts, add nothing
 *   php _word_harvest.php --index --apply     ALSO rebuild yy_word_occurrence
 *
 * The occurrence index
 *   --index records, per paragraph, how many times each word's spellings occur
 *   there, into yy_word_occurrence.  It is what the Glossary Words tab drills
 *   into when you click a word's count (series → volume → chapter → page →
 *   paragraph).  It comes out of the SAME token pass as word_count_yy, so the
 *   drill-down totals and the count column agree by construction.
 *   ⚠ Words INSERTED by a harvest run are not in the index until you re-run
 *     with --index; the run says so when it happens.
 *
 * How a word is recognised
 *   The books italicise transliterated Hebrew and set the Hebrew itself in the
 *   "Yada Towrah" font.  Italics are also used for book titles and for ordinary
 *   English emphasis, so an italic run alone is not enough.  Two extra signals
 *   separate the lexicon from the noise:
 *
 *     half-ring   a token carrying ʿ or ʾ is Hebrew with near-certainty
 *     ratio       italic hits ÷ total corpus hits.  A transliteration is
 *                 almost always italicised; an English word set in italics for
 *                 emphasis is overwhelmingly used in plain text as well.
 *
 * Counts
 *   word_count_yy is how many times the word appears in the books, summed over
 *   every spelling it has.  Each yy_word_translit row also gets its own count.
 *
 * ⚠ yy_word carries the trg_yy_word_rev audit trigger — every UPDATE writes a
 *   yy_word_rev row, so only rows whose value actually changes are written.
 */
ini_set('memory_limit', '3G');
require_once __DIR__ . '/config.php';

$args    = $_SERVER['argv'];
$APPLY   = in_array('--apply', $args, true);
$RECOUNT = in_array('--recount-only', $args, true);
$INDEX   = in_array('--index', $args, true);
$MIN_ITALIC = 3;
$MIN_RATIO  = 0.30;
foreach ($args as $a) {
    if (preg_match('/^--min=(\d+)$/', $a, $m))          $MIN_ITALIC = (int)$m[1];
    if (preg_match('/^--ratio=([0-9.]+)$/', $a, $m))    $MIN_RATIO  = (float)$m[1];
}

$db = getDb();
$t0 = microtime(true);

function say(string $s): void { echo $s . "\n"; }

/* The real half-rings — ʿ ayin and ʾ aleph.  These, and only these, mark a
   token as Hebrew with near-certainty.  The curly quotes below are ENGLISH
   punctuation in these books (God’s, isn’t); treating them as half-rings
   pulled every possessive and contraction into the lexicon. */
const HALFRING = '\x{02BF}\x{02BE}';
const APOS     = '\x{2018}\x{2019}\x{05F3}\'';
/* A transliteration is Latin letters plus half-rings, apostrophes and hyphens. */
const TRANSLIT_RE = '/^[a-z' . HALFRING . APOS . '\-]{2,30}$/ui';
const TOKEN_RE    = '/[a-zA-Z' . HALFRING . APOS . '\-]+/u';

/** Lowercase, and drop half-rings/apostrophes — the key used to match spellings. */
function normKey(string $s): string {
    return preg_replace('/[' . HALFRING . APOS . '\-]/u', '', mb_strtolower($s));
}

/**
 * Fold a raw token to the form we count.  "Yahowah’s" is an occurrence of
 * Yahowah, not a word of its own, and a stray leading/trailing hyphen is
 * line-break debris.
 */
function cleanToken(string $t): string {
    $t = preg_replace('/[' . APOS . ']s$/ui', '', $t);
    return trim($t, "-\u{2018}\u{2019}");
}

/** English contraction — an apostrophe sitting inside the word (isn’t, o’clock). */
function isContraction(string $lc): bool {
    return (bool)preg_match('/[a-z][' . APOS . '][a-z]/u', $lc);
}

/* ═══ Pass 0 — load the lexicon ══════════════════════════════════════════
   Loaded BEFORE the scan so the scan can attribute each token to the words
   that spell it (the occurrence index needs that; the recount does not). */

$words = $db->query(
    "SELECT word_key, word_translit, word_count_yy, word_source_code FROM yy_word"
)->fetchAll();

$spellings = [];   // word_key => [translit_key|'w' => text]
$known     = [];   // normalised spelling => word_key  (for de-duping candidates)

foreach ($words as $w) {
    $spellings[$w['word_key']] = [];
    if (trim((string)$w['word_translit']) !== '') {
        $spellings[$w['word_key']]['w'] = trim($w['word_translit']);
    }
}
foreach ($db->query('SELECT word_translit_key, word_key, word_translit_text FROM yy_word_translit')->fetchAll() as $t) {
    if (!isset($spellings[$t['word_key']])) $spellings[$t['word_key']] = [];
    $spellings[$t['word_key']][(int)$t['word_translit_key']] = $t['word_translit_text'];
}
foreach ($spellings as $wk => $list) {
    foreach ($list as $text) {
        $k = normKey($text);
        if ($k !== '') $known[$k] = $wk;
    }
}

// Exact lowercased spelling => every word_key that uses it. A list, not a
// scalar: 52 spellings are shared by more than one word (homographs such as
// 'owr and ra'ah), and each of those words counts the occurrence.
$spellToWords = [];
foreach ($spellings as $wk => $list) {
    foreach ($list as $text) {
        $lc = mb_strtolower(trim($text));
        if ($lc === '') continue;
        if (!isset($spellToWords[$lc])) $spellToWords[$lc] = [];
        if (!in_array((int)$wk, $spellToWords[$lc], true)) $spellToWords[$lc][] = (int)$wk;
    }
}

/* ═══ Pass 1 — tally every token in the books, and separately in italics ═══ */

say('Scanning yy_paragraph …' . ($INDEX ? ' (building occurrence index)' : ''));

$corpus  = [];   // lowercased token => times it appears anywhere in the books
$italic  = [];   // lowercased token => times it appears inside an italic run
$surface = [];   // lowercased token => [surface form => count], to pick casing
$occRows = [];   // [word_key, paragraph_key, count] for yy_word_occurrence
$paras   = 0;

$stmt = $db->query(
    'SELECT paragraph_text_plain, paragraph_text_html, paragraph_key
       FROM yy_paragraph
      WHERE paragraph_active_flag IS NOT FALSE'
);

while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $paras++;
    $plain = (string)$row[0];
    $html  = (string)$row[1];
    $paraHits = [];   // word_key => occurrences in THIS paragraph

    if ($plain !== '' && preg_match_all(TOKEN_RE, $plain, $m)) {
        foreach ($m[0] as $tok) {
            $tok = cleanToken($tok);
            if ($tok === '') continue;
            $lc = mb_strtolower($tok);
            $corpus[$lc] = ($corpus[$lc] ?? 0) + 1;
            if (!isset($surface[$lc])) $surface[$lc] = [];
            $surface[$lc][$tok] = ($surface[$lc][$tok] ?? 0) + 1;

            if ($INDEX && isset($spellToWords[$lc])) {
                foreach ($spellToWords[$lc] as $wk) {
                    $paraHits[$wk] = ($paraHits[$wk] ?? 0) + 1;
                }
            }
        }
    }

    // One row per (word, paragraph): a word spelled two ways in the same
    // paragraph sums into a single row, matching how word_count_yy adds up.
    foreach ($paraHits as $wk => $c) {
        $occRows[] = [$wk, (int)$row[2], $c];
    }

    if ($html !== '' && strpos($html, '<i') !== false) {
        // The books set half-rings outside the italic run, so a single word
        // arrives split three ways:  <i>ha Ba</i>ʿ<i>al</i>,  ʾ<i>ayl</i>,
        // <i>raʾa</i>ʾ.  Pull the half-ring back inside before reading the run
        // or the word is harvested as fragments ("ayl" separate from "ʾayl"),
        // which is what made italic counts exceed whole-corpus counts.
        $hr    = '[' . HALFRING . APOS . ']';
        $glued = preg_replace('/<\/i>\s*(' . $hr . ')\s*<i[^>]*>/u', '$1', $html);
        $glued = preg_replace('/(' . $hr . ')(<i[^>]*>)/u', '$2$1', $glued);
        $glued = preg_replace('/(<\/i>)(' . $hr . ')/u', '$2$1', $glued);
        if (preg_match_all('/<i\b[^>]*>(.*?)<\/i>/si', $glued, $mm)) {
            foreach ($mm[1] as $run) {
                $txt = html_entity_decode(strip_tags($run), ENT_QUOTES, 'UTF-8');
                if (preg_match_all(TOKEN_RE, $txt, $m2)) {
                    foreach ($m2[0] as $tok) {
                        $tok = cleanToken($tok);
                        if ($tok === '') continue;
                        $lc = mb_strtolower($tok);
                        $italic[$lc] = ($italic[$lc] ?? 0) + 1;
                    }
                }
            }
        }
    }
}

say(sprintf('  %s paragraphs, %s distinct tokens, %s seen in italics',
    number_format($paras), number_format(count($corpus)), number_format(count($italic))));

/* ═══ Pass 2 — refresh counts on the words already on file ═══════════════ */

say('');
say('Recounting existing words …');

// $words / $spellings / $known were loaded in Pass 0, before the scan.
$updWord     = $db->prepare('UPDATE yy_word SET word_count_yy = ? WHERE word_key = ?');
$updTranslit = $db->prepare('UPDATE yy_word_translit SET word_translit_count_yy = ? WHERE word_translit_key = ?');

$wordChanged = 0; $translitChanged = 0; $wordTotals = [];

foreach ($words as $w) {
    $wk = (int)$w['word_key'];
    $sum = 0;
    // The preferred spelling lives BOTH on yy_word.word_translit and as its own
    // yy_word_translit row, so the total must be summed over DISTINCT spellings
    // — adding every entry counts the preferred one twice.
    $counted = [];
    // What the DATABASE will leave in word_count_yy the moment we touch any of
    // this word's yy_word_translit rows: trg_translit_count_recalc fires and
    // rewrites it as a PLAIN SUM over the translit rows. That is a different
    // rule from ours — it cannot see the preferred spelling (which lives on
    // yy_word, not in the rows) and it does not de-duplicate — so the two
    // answers diverge whenever a spelling is stored only on yy_word, or twice.
    // Track it so the write below can tell what the row really holds now.
    $triggerSum = 0;
    $touchedTranslit = false;
    foreach ($spellings[$wk] ?? [] as $tk => $text) {
        $lc = mb_strtolower(trim($text));
        $c  = $corpus[$lc] ?? 0;
        if (!isset($counted[$lc])) { $sum += $c; $counted[$lc] = true; }
        if ($tk !== 'w') {
            $translitChanged++;
            $triggerSum += $c;
            if ($APPLY) { $updTranslit->execute([$c, (int)$tk]); $touchedTranslit = true; }
        }
    }
    $wordTotals[$wk] = $sum;
    // ⚠ Compare against what the row holds AFTER those translit writes, not the
    // value loaded before the scan. The trigger has already overwritten
    // word_count_yy with $triggerSum, so skipping the write when the PRE-scan
    // value happened to match would leave the TRIGGER's answer standing instead
    // of ours — and the occurrence index, which is built from the same spelling
    // map this loop sums, would then disagree with the count column it hangs
    // off. That is exactly how 'kebes' (word 7813) came to show 0 against an
    // index of 54: its only translit row is the two-in-one string
    // 'kebes, kebesah', which matches no token, so the trigger computed 0 while
    // the preferred spelling 'kebes' genuinely occurs 54 times.
    $current = $touchedTranslit ? $triggerSum : (int)$w['word_count_yy'];
    if ($current !== $sum) {
        $wordChanged++;
        if ($APPLY) $updWord->execute([$sum, $wk]);
    }
}

arsort($wordTotals);
say(sprintf('  %s words rechecked, %s counts changed, %s spelling counts written',
    number_format(count($words)), number_format($wordChanged), number_format($translitChanged)));

/* ═══ Pass 2b — rebuild the occurrence index ════════════════════════════ */

if ($INDEX) {
    say('');
    say('Occurrence index …');
    $occTotal = 0;
    foreach ($occRows as $r) $occTotal += $r[2];
    say(sprintf('  %s (word, paragraph) rows covering %s occurrences',
        number_format(count($occRows)), number_format($occTotal)));

    // The index must agree with the counts it was computed from.
    $sumCounts = 0;
    foreach ($wordTotals as $t) $sumCounts += $t;
    if ($occTotal !== $sumCounts) {
        say(sprintf('  ⚠ index total %s != recounted total %s — NOT writing',
            number_format($occTotal), number_format($sumCounts)));
        $INDEX = false;
    }

    if ($INDEX && $APPLY) {
        $db->beginTransaction();
        try {
            // Full rebuild: a word whose spellings changed must not keep rows
            // from its old ones. Transactional, so a failure leaves the old
            // index in place rather than an empty one.
            $db->exec('TRUNCATE yy_word_occurrence');
            $chunk = 500;
            for ($i = 0; $i < count($occRows); $i += $chunk) {
                $slice = array_slice($occRows, $i, $chunk);
                $vals  = implode(',', array_fill(0, count($slice), '(?,?,?)'));
                $flat  = [];
                foreach ($slice as $r) { $flat[] = $r[0]; $flat[] = $r[1]; $flat[] = $r[2]; }
                $db->prepare('INSERT INTO yy_word_occurrence (word_key, paragraph_key, occurrence_count) VALUES ' . $vals)
                   ->execute($flat);
            }
            $db->commit();
            say('  written');
        } catch (\Exception $e) {
            $db->rollBack();
            say('  FAILED, rolled back: ' . $e->getMessage());
            exit(1);
        }
    }
}

/* ═══ Pass 3 — candidates the lexicon does not have yet ═════════════════ */

$newRows = [];
if (!$RECOUNT) {
    say('');
    say(sprintf('Selecting candidates (italic hits >= %d, ratio >= %.2f, or half-ring) …', $MIN_ITALIC, $MIN_RATIO));

    $rejected = ['shape' => 0, 'thin' => 0, 'ratio' => 0, 'known' => 0, 'contraction' => 0, 'fragment' => 0];

    foreach ($italic as $lc => $ital) {
        if (!preg_match(TRANSLIT_RE, $lc))  { $rejected['shape']++; continue; }
        if (isContraction($lc))             { $rejected['contraction']++; continue; }
        if ($ital < $MIN_ITALIC)            { $rejected['thin']++;  continue; }

        // Plain text contains the italic text, so a real word can never be seen
        // more often in italics than in the corpus. When it is, the italic token
        // is a fragment of a word the plain text spells whole — drop it.
        $total = $corpus[$lc] ?? 0;
        if ($ital > $total) { $rejected['fragment']++; continue; }

        $ratio    = $total > 0 ? $ital / $total : 0.0;
        $halfring = (bool)preg_match('/[' . HALFRING . ']/u', $lc);

        if (!$halfring && $ratio < $MIN_RATIO) { $rejected['ratio']++; continue; }

        $k = normKey($lc);
        if ($k === '' || isset($known[$k]))    { $rejected['known']++; continue; }

        // Keep the casing the books use most often for this word.
        $forms = $surface[$lc] ?? [$lc => 1];
        arsort($forms);
        $best = (string)array_key_first($forms);

        $newRows[$k] = [
            'text'  => $best,
            'count' => $total,
            'ital'  => $ital,
            'ratio' => $ratio,
            'hr'    => $halfring,
        ];
        $known[$k] = -1;   // block a second candidate that normalises the same
    }

    uasort($newRows, function ($a, $b) { return $b['count'] <=> $a['count']; });

    say(sprintf('  %s candidates  (rejected: %s not word-shaped, %s contractions, %s fragments, %s under %d italic hits, %s below ratio, %s already on file)',
        number_format(count($newRows)), number_format($rejected['shape']), number_format($rejected['contraction']), number_format($rejected['fragment']),
        number_format($rejected['thin']), $MIN_ITALIC, number_format($rejected['ratio']),
        number_format($rejected['known'])));

    $show = 40;
    foreach ($args as $a) if (preg_match('/^--top=(\d+)$/', $a, $m)) $show = (int)$m[1];

    say('');
    say('  ── new words, most frequent first ──');
    $i = 0;
    foreach ($newRows as $r) {
        say(sprintf('  %8s in books  %6s italic  ratio %.2f %s  %s',
            number_format($r['count']), number_format($r['ital']), $r['ratio'], $r['hr'] ? 'HR' : '  ', $r['text']));
        if (++$i >= $show) break;
    }
}

/* ═══ Write ═════════════════════════════════════════════════════════════ */

if ($APPLY && !$RECOUNT && $newRows) {
    say('');
    say('Inserting …');
    $db->beginTransaction();
    try {
        // word_hebrew is left NULL: the books give the spelling, not the Hebrew.
        // trg_word_yt only fires when word_hebrew is set, so word_yt stays NULL
        // and the word will not surface on the public letter web until an admin
        // fills the Hebrew in — which is what the Words tab keyboard is for.
        $insW = $db->prepare(
            'INSERT INTO yy_word (word_source_code, word_translit, word_count_yy, word_active_flag)
             VALUES (?, ?, ?, true) RETURNING word_key'
        );
        $insT = $db->prepare(
            'INSERT INTO yy_word_translit (word_key, word_translit_text, word_translit_sort, word_translit_count_yy)
             VALUES (?, ?, 0, ?)'
        );
        $n = 0;
        foreach ($newRows as $r) {
            $insW->execute(['books', $r['text'], $r['count']]);
            $wk = (int)$insW->fetchColumn();
            $insT->execute([$wk, $r['text'], $r['count']]);
            $n++;
        }
        $db->commit();
        say('  inserted ' . number_format($n) . ' words with word_source_code = books');
        // The scan attributed tokens using the spelling map loaded BEFORE these
        // words existed, so they have no occurrence rows yet.
        say('  ⚠ re-run with --index --apply to index the new words');
    } catch (\Exception $e) {
        $db->rollBack();
        say('  FAILED, rolled back: ' . $e->getMessage());
        exit(1);
    }
}

say('');
say($APPLY ? 'APPLIED.' : 'DRY RUN — nothing written. Re-run with --apply to write.');
say(sprintf('%.1fs', microtime(true) - $t0));
