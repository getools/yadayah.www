<?php
/**
 * Harvest transliterated Hebrew words out of the books into yy_word.
 *
 *   php _word_harvest.php                     dry run — report only
 *   php _word_harvest.php --apply             write
 *   php _word_harvest.php --min=2             only add what is presented 2+ times
 *   php _word_harvest.php --recount-only      only refresh counts, add nothing
 *   php _word_harvest.php --index --apply     ALSO rebuild yy_word_occurrence
 *   php _word_harvest.php --no-books-coverage skip pass 3b (see below)
 *
 * Books coverage (pass 3b)
 *   word_source_code says where a word was first catalogued, NOT whether the
 *   books use it — so a kirk/perry word could occur thousands of times and
 *   still be invisible when the Words tab is filtered to Books. Pass 3b closes
 *   that: every distinct spelling occurring in the books gets its own 'books'
 *   row. On by default, so a post-parse refresh keeps it true and a future
 *   bulk import cannot silently re-open the gap.
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
 *   The books italicise transliterated Hebrew, but italics also mark book
 *   titles and English emphasis, so italics alone are not enough.  A word is
 *   only what the books PRESENT: italic, inside a parenthesis, as
 *   "word – meaning", "a / b – meaning", "(word word)" or "word | meaning".
 *   See glossEntries().  Each presented WHOLE is a word — a single word, or a
 *   phrase such as "wa ha nabʿym" — and so is each PART of a phrase ("wa",
 *   "ha", "nabʿym").  Anything presented at least --min times (default 1) and
 *   not already on file is added, source 'books'.
 *
 * Parsed references (--index)
 *   yy_gloss holds every presented occurrence with the meaning printed there;
 *   yy_word_gloss links each word to it as the whole ('W') or a part ('P').
 *   The meaning is the whole's alone.  A word with no default definition gets
 *   one (source YY) from its first whole reference in book order; an admin can
 *   change it afterwards.
 *
 * Counts
 *   word_count_yy is how many times the word appears IN ITALICS in the books,
 *   summed over every spelling it has — for a phrase, how many times it is
 *   presented.  Plain-text mentions do not count: "me" and "day" in running
 *   English are not the Hebrew words.
 *   Each yy_word_translit row also gets its own count.
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
/* Books coverage (pass 3b): give every spelling that actually occurs in the
   books its own 'books' row, even when another source already catalogues the
   word. On by default so a parse keeps it true; --no-books-coverage skips it. */
$COVERAGE = !in_array('--no-books-coverage', $args, true);
$MIN_PRESENTED = 1;
foreach ($args as $a) {
    if (preg_match('/^--min=(\d+)$/', $a, $m)) $MIN_PRESENTED = max(1, (int)$m[1]);
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

/**
 * The places the books PRESENT a foreign word — the only place a new word may
 * come from, and the only occurrences that count as a parsed reference.
 * Italics alone also catch book titles and English emphasis (Merriam-Webster,
 * Mein Kampf, e-tailer), so the word must be ITALIC and INSIDE A PARENTHESIS,
 * in one of the books' gloss forms:
 *   (wa ha nabʿym – those who …)          italic words right before a spaced dash
 *   (… obstacles and pisah – providing …) the "(" may open earlier, on English
 *   (… tahowr / tohorah – purifying …)    " / " joins alternatives: each counts
 *   (wa ha nabyʾ ha huwʾ)                 a parenthesis holding only italic words
 *   al-Shaitan | the Adversary            italic word(s) before " | ", with or
 *                                         without a parenthesis — outside one,
 *                                         no meaning is recorded (no end marker)
 *   Tauhid (the oneness of Allah)         italic word(s) then a short plain-English
 *                                         parenthesis — the parenthesis is the meaning
 *                                         (citations, titles and asides refused)
 *   (… from niyr and nuwr – the fiery …)  a plain "and"/"or" joins alternatives too
 *   “hamets – add yeast,”                 quotation marks stand in for the
 *                                         parenthesis; meaning ends at the ”
 *   sheman, meaning “olive oil.”          defined in a sentence: meaning / means /
 *                                         a comma, then the quotation is the meaning
 * Otherwise, outside a parenthesis a dash is a title and subtitle
 * ("Tea with Terrorists – Who They Are?"), so it does not count.
 *
 * $glued is the paragraph HTML with half-rings already pulled inside <i>.
 * Returns one entry per presented occurrence, in paragraph order:
 *   ['phrase' => as printed ("tahowr / tohorah"), 'gloss' => meaning|null,
 *    'units'  => [ ['text' => a whole word or phrase, 'lc' => lower-cased,
 *                   'parts' => [['text','lc'], …] when the whole is several
 *                              words, else [] ], … one per " / " alternative ]]
 * The meaning belongs to each WHOLE only; the parts of "wa ha nabʿym" are
 * words in their own right but do not take the phrase's meaning.
 * The meaning is the text after the dash/bar, up to the next ")" or "(" or the
 * next presented word; a bare "(italic words)" has none.
 */
function glossEntries(string $glued): array {
    // Keep only the italic markup: <i>word</i> in otherwise plain text.
    $s = preg_replace('/<i\b[^>]*>/i', "\x01", $glued);
    $s = preg_replace('/<\/i\s*>/i', "\x02", $s);
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8');
    $s = str_replace("\x02\x01", '', $s);                  // back-to-back runs are one run
    $s = str_replace(["\x01", "\x02"], ['<i>', '</i>'], $s);
    if (strpos($s, '<i>') === false) return [];

    // Parenthesis depth at a byte offset.  Paragraphs are split at page
    // breaks, so a parenthesis can open in the PREVIOUS paragraph: an
    // unmatched ")" here means the text before it was already inside one
    // ("…obstacles and pisah – providing … necessary).").  Start at the depth
    // those unmatched closers imply.
    preg_match_all('/[()]/', $s, $pm, PREG_OFFSET_CAPTURE);
    $parens = $pm[0];
    $start = 0; $run = 0;
    foreach ($parens as [$ch]) { $run += $ch === '(' ? 1 : -1; $start = max($start, -$run); }
    $depthAt = function (int $off) use ($parens, $start): int {
        $d = $start;
        foreach ($parens as [$ch, $at]) {
            if ($at >= $off) break;
            $d = $ch === '(' ? $d + 1 : max(0, $d - 1);
        }
        return $d;
    };

    // Italic runs joined by spaces (one phrase), " / " or a plain "and"/"or"
    // (alternatives: "niyr and nuwr – the fiery light" makes BOTH words).
    $runs  = '((?:<i>[^<]+<\/i>)(?:(?:\s*\/\s*|\s+(?:(?:and|or)\s+)?)<i>[^<]+<\/i>)*)';
    $heads = [];   // [offset, head, offset where the meaning starts | null, offset where it must end?]
    if (preg_match_all('/' . $runs . '\s*([\x{2013}\x{2014}|])\s/u', $s, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => [$all, $at]) {
            if ($depthAt($at) > 0) {
                $heads[] = [$at, $m[1][$i][0], $at + strlen($all)];
            } elseif ($m[2][$i][0] === '|') {
                // "word | meaning" is the books' Arabic (and Hebrew) gloss even
                // outside a parenthesis.  There the meaning runs straight on
                // into the sentence ("Halakhah | the Way has become a set of
                // laws …") with nothing marking its end, so the word counts but
                // no meaning is recorded for it.
                $heads[] = [$at, $m[1][$i][0], null];
            }
        }
    }
    if (preg_match_all('/\(\s*' . $runs . '\s*\)/u', $s, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$head, $at]) $heads[] = [$at, $head, null];
    }
    // "word (meaning)" — italic word(s) followed by a parenthesis of plain
    // English: "Tauhid (the oneness of Allah)", "Nabaʿym (Prophets)".  The same
    // shape also carries citations, titles and asides, which are refused:
    //   digits anywhere          towr (H8449), Quran 003.197 (It is), … (2002)
    //   Hebrew/Greek script      ʾisheh (אִשֶּׁה)
    //   a multi-word Title       New International Version (NIV)
    //   an aside                 naʾaph (which not-so-coincidently appears …)
    // What is left must be short: ≤12 words starting lower-case, or ≤8 words
    // starting with a capital.  The parenthesis is the meaning.
    if (preg_match_all('/' . $runs . '\s*\(([^()<]{2,200})\)/u', $s, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => [, $at]) {
            $head = $m[1][$i][0];
            [$body, $bodyAt] = $m[2][$i];
            $plainHead = trim(strip_tags($head));
            $body = trim($body);
            if (preg_match('/\d/', $plainHead . $body)) continue;
            if (preg_match('/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}\x{0590}-\x{05FF}]/u', $body)) continue;
            // A Title Has Every Main Word Capitalised ("Exegetical Dictionary of
            // the New Testament") — the small linking words don't count.
            $toks = array_filter(preg_split('/\s+/u', $plainHead), function ($t) {
                return !preg_match('/^(?:of|the|and|a|an|in|on|to|for)$/', $t);
            });
            if (count($toks) >= 2 && !array_filter($toks, function ($t) { return !preg_match('/^\p{Lu}/u', ltrim($t, "\u{02BE}\u{02BF}")); })) continue;
            if (preg_match('/^(?:see|cf|i\.e|e\.g|or|and|also|ibid|pl|sing|which|that|who|since|however|reading|once|scribed|copyright)\b/iu', $body)) continue;
            $n = count(preg_split('/\s+/u', $body));
            $short = preg_match('/^\p{Ll}/u', $body) ? $n <= 12 : (preg_match('/^\p{Lu}/u', $body) && $n <= 8);
            if (!$short) continue;
            $heads[] = [$at, $head, $bodyAt];
        }
    }
    // A quotation mark stands in for the parenthesis: “hamets – add yeast,”
    // “Bacha Bazi – Boy Play.”  The meaning ends at the closing quote.
    // (Inside a parenthesis the dash rule above has it already.)
    if (preg_match_all('/“\s*' . $runs . '\s*[\x{2013}\x{2014}]\s/u', $s, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $i => [$head, $at]) {
            if ($depthAt($at) > 0) continue;
            $from = $m[0][$i][1] + strlen($m[0][$i][0]);
            $end  = strpos($s, '”', $from);
            $heads[] = [$at, $head, $from, $end === false ? null : $end];
        }
    }
    // The word defined in a sentence: sheman, meaning “olive oil.” ·
    // balad means “country or nation” · stoicheo, “proceeding to march …”.
    // The quotation is the meaning.
    if (preg_match_all('/' . $runs . '\s*(?:,\s*)?(?:meaning:?|means:?|,)\s*“([^”]{2,300})”/u', $s, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $i => [$head, $at]) {
            [$q, $qAt] = $m[2][$i];
            $heads[] = [$at, $head, $qAt, $qAt + strlen($q)];
        }
    }
    usort($heads, function ($a, $b) { return $a[0] <=> $b[0]; });
    // One presentation per spot: the first rule to claim an offset keeps it.
    $byAt = [];
    foreach ($heads as $h) if (!isset($byAt[$h[0]])) $byAt[$h[0]] = $h;
    $heads = array_values($byAt);

    $out = [];
    foreach ($heads as $i => $h) {
        [$at, $head, $from] = $h;
        $gloss = null;
        if ($from !== null) {
            $to = strlen($s);
            if (isset($h[3])) $to = min($to, $h[3]);
            if (isset($heads[$i + 1])) $to = min($to, $heads[$i + 1][0]);
            foreach (['(', ')'] as $stop) {
                $p = strpos($s, $stop, $from);
                if ($p !== false) $to = min($to, $p);
            }
            $g = trim(strip_tags(substr($s, $from, max(0, $to - $from))));
            $g = preg_replace('/\s+/u', ' ', $g);
            $g = preg_replace('/(?:[\s,;:.]+(?:and|or)?)+$/u', '', $g);
            if ($g !== '') $gloss = mb_substr($g, 0, 1000);
        }
        $plainHead = trim(preg_replace('/\s+/u', ' ', strip_tags($head)));
        // "tahowr / tohorah" and "niyr and nuwr" are alternatives: each is a
        // whole word of its own.  "wa ha nabʿym" is ONE whole (a phrase) made
        // of parts wa, ha, nabʿym.  Only a PLAIN "and"/"or" between italic runs
        // separates; inside the italics it would be part of the phrase.
        $altHead = preg_replace('/<\/i>\s+(?:and|or)\s+<i>/u', '</i> / <i>', $head);
        $units = [];
        foreach (preg_split('/\s*\/\s*/u', trim(preg_replace('/\s+/u', ' ', strip_tags($altHead)))) as $alt) {
            if (!preg_match_all(TOKEN_RE, $alt, $t)) continue;
            $parts = [];
            foreach ($t[0] as $tok) {
                $tok = cleanToken($tok);
                if ($tok !== '') $parts[] = ['text' => $tok, 'lc' => mb_strtolower($tok)];
            }
            if (!$parts) continue;
            $text = implode(' ', array_column($parts, 'text'));
            $units[] = ['text' => $text, 'lc' => mb_strtolower($text), 'parts' => count($parts) > 1 ? $parts : []];
        }
        if ($units) $out[] = ['phrase' => $plainHead, 'gloss' => $gloss, 'units' => $units];
    }
    return $out;
}

/** English contraction — an apostrophe sitting inside the word (isn’t, o’clock). */
function isContraction(string $lc): bool {
    return (bool)preg_match('/[a-z][' . APOS . '][a-z]/u', $lc);
}

/* ═══ Pass 0 — load the lexicon ══════════════════════════════════════════
   Loaded BEFORE the scan so the scan can attribute each token to the words
   that spell it (the occurrence index needs that; the recount does not). */

$words = $db->query(
    "SELECT word_key, word_translit, word_count_yy, word_source_code, word_excluded_flag FROM yy_word"
)->fetchAll();

$spellings = [];   // word_key => [translit_key|'w' => text]
$known     = [];   // normalised spelling => word_key  (for de-duping candidates)
/* "Not a word" (admin Glossary): rows kept ONLY so they are never imported
   again. Their spellings stay in $known, so Pass 3 already skips them; Pass 3b
   also treats them as covered, and Pass 2b gives them no seeded definition. */
$excluded  = [];   // word_key => true

foreach ($words as $w) {
    if (!empty($w['word_excluded_flag'])) $excluded[(int)$w['word_key']] = true;
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
$italic  = [];   // lowercased token => times it appears in ITALICS (a single word's count)
$surface = [];   // lowercased token => [surface form => count], to pick casing
$glossed = [];   // lowercased whole or part => times it is PRESENTED (see glossEntries)
$shown   = [];   // lowercased whole or part => [form as presented => count]
$phraseCount = []; // lowercased multi-word whole => times presented (its word_count_yy)
$occRows = [];   // [word_key, paragraph_key, count] for yy_word_occurrence
$glossOcc   = []; // [gloss_key, paragraph_key, seq, phrase, meaning|null] for yy_gloss
$glossLinks = []; // [word_key, gloss_key, 'W'hole|'P'art, as printed] for yy_word_gloss
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

    // Every mention, italic or not.  Only used to show how common a new word
    // is and by Books coverage (pass 3b); word COUNTS come from italics below.
    if ($plain !== '' && preg_match_all(TOKEN_RE, $plain, $m)) {
        foreach ($m[0] as $tok) {
            $tok = cleanToken($tok);
            if ($tok === '') continue;
            $lc = mb_strtolower($tok);
            $corpus[$lc] = ($corpus[$lc] ?? 0) + 1;
            if (!isset($surface[$lc])) $surface[$lc] = [];
            $surface[$lc][$tok] = ($surface[$lc][$tok] ?? 0) + 1;
        }
    }

    if ($html !== '' && strpos($html, '<i') !== false) {
        // Keep only the italic tags: bold and span tags can sit between the
        // pieces of one word — <b><i>Shin</i></b>ʿ<b><i>ar</i></b> — and would
        // stop the half-ring glue below, splitting Shinʿar into "Shin" + "ar".
        $html = strip_tags($html, '<i>');
        // The books set half-rings outside the italic run, so a single word
        // arrives split three ways:  <i>ha Ba</i>ʿ<i>al</i>,  ʾ<i>ayl</i>,
        // <i>raʾa</i>ʾ.  Pull the half-ring back inside before reading the run
        // or the word is harvested as fragments ("ayl" separate from "ʾayl"),
        // which is what made italic counts exceed whole-corpus counts.
        // ⚠ No whitespace allowed around the ring when joining two runs: in
        // "<i>waʾamah</i> ʾ<i>atah</i>" the ring after the space STARTS the next
        // word, and joining across it fused "waʾamahʾatah".
        $hr    = '[' . HALFRING . APOS . ']';
        $glued = preg_replace('/<\/i>(' . $hr . ')<i[^>]*>/u', '$1', $html);
        $glued = preg_replace('/(' . $hr . ')(<i[^>]*>)/u', '$2$1', $glued);
        $glued = preg_replace('/(<\/i>)(' . $hr . ')/u', '$2$1', $glued);

        // A single word is counted only where it is ITALIC — a plain "me" or
        // "day" in running English is not an occurrence of the Hebrew word.
        if (preg_match_all('/<i\b[^>]*>(.*?)<\/i>/si', preg_replace('/<\/i><i[^>]*>/', '', $glued), $mm)) {
            foreach ($mm[1] as $run) {
                $txt = html_entity_decode($run, ENT_QUOTES, 'UTF-8');
                if (!preg_match_all(TOKEN_RE, $txt, $m2)) continue;
                foreach ($m2[0] as $tok) {
                    $tok = cleanToken($tok);
                    if ($tok === '') continue;
                    $lc = mb_strtolower($tok);
                    $italic[$lc] = ($italic[$lc] ?? 0) + 1;
                    if ($INDEX && isset($spellToWords[$lc])) {
                        foreach ($spellToWords[$lc] as $wk) $paraHits[$wk] = ($paraHits[$wk] ?? 0) + 1;
                    }
                }
            }
        }

        $seq = 0;
        foreach (glossEntries($glued) as $e) {
            $seq++;
            $gk = count($glossOcc) + 1;
            if ($INDEX) $glossOcc[] = [$gk, (int)$row[2], $seq, $e['phrase'], $e['gloss']];
            $linked = [];   // one link per (word, role) per occurrence
            foreach ($e['units'] as $u) {
                $presented = [['W', $u['text'], $u['lc']]];
                foreach ($u['parts'] as $p) $presented[] = ['P', $p['text'], $p['lc']];
                foreach ($presented as [$role, $text, $lc]) {
                    $glossed[$lc] = ($glossed[$lc] ?? 0) + 1;
                    $shown[$lc][$text] = ($shown[$lc][$text] ?? 0) + 1;
                    if ($INDEX && isset($spellToWords[$lc])) {
                        foreach ($spellToWords[$lc] as $wk) {
                            if (isset($linked[$wk . $role])) continue;
                            $linked[$wk . $role] = true;
                            $glossLinks[] = [$wk, $gk, $role, $text];
                        }
                    }
                }
                // A multi-word whole has no plain-text token count, so its
                // occurrences are its presentations.  Same paragraph index.
                if ($u['parts']) {
                    $phraseCount[$u['lc']] = ($phraseCount[$u['lc']] ?? 0) + 1;
                    if ($INDEX && isset($spellToWords[$u['lc']])) {
                        foreach ($spellToWords[$u['lc']] as $wk) $paraHits[$wk] = ($paraHits[$wk] ?? 0) + 1;
                    }
                }
            }
        }
    }

    // One row per (word, paragraph): a word spelled two ways in the same
    // paragraph sums into a single row, matching how word_count_yy adds up.
    foreach ($paraHits as $wk => $c) {
        $occRows[] = [$wk, (int)$row[2], $c];
    }
}

say(sprintf('  %s paragraphs, %s distinct tokens, %s distinct words/phrases presented',
    number_format($paras), number_format(count($corpus)), number_format(count($glossed))));

/* ═══ Pass 2 — refresh counts on the words already on file ═══════════════ */

say('');
say('Recounting existing words …');

// $words / $spellings / $known were loaded in Pass 0, before the scan.
$updWord     = $db->prepare('UPDATE yy_word SET word_count_yy = ? WHERE word_key = ?');
$updTranslit = $db->prepare('UPDATE yy_word_translit SET word_translit_count_yy = ? WHERE word_translit_key = ?');

// Begin a single transaction that spans both Pass 2 (word-count UPDATEs) and
// Pass 2b (occurrence-index TRUNCATE+INSERT).  Without this, a mid-run crash
// commits the individual UPDATEs (auto-commit) while the index rebuild never
// runs, leaving word_count_yy ahead of yy_word_occurrence and firing the
// mismatch warning on every subsequent refresh until the next complete run.
// With the transaction, any crash rolls both back together, leaving the DB in
// the consistent pre-run state so the next cycle can retry cleanly.
if ($APPLY && $INDEX) $db->beginTransaction();

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
        // A phrase ("wa ha nabʿym") is counted where it is presented; a single
        // word wherever it is italic.  Pass 1 indexed them the same way.
        $c  = strpos($lc, ' ') !== false ? ($phraseCount[$lc] ?? 0) : ($italic[$lc] ?? 0);
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
        say(sprintf('  ⚠ index total %s != recounted total %s — rolling back word counts and aborting',
            number_format($occTotal), number_format($sumCounts)));
        // The scan produced internally inconsistent counts (a code bug).
        // Roll back the word-count UPDATEs from Pass 2 so the DB stays
        // consistent (old counts + old index), then fail so the worker retries.
        if ($APPLY) $db->rollBack();
        exit(1);
    }

    /* Parsed references: every place the books PRESENT a word (glossEntries).
       yy_gloss holds the occurrence and the meaning printed there; each word
       links to it as the WHOLE ("wa ha nabʿym", or "tahowr" in "tahowr /
       tohorah") or as a PART ("wa", "ha", "nabʿym").  The meaning is the
       whole's only.  A word with no default definition yet gets one, as a YY
       definition, from the FIRST whole reference in book order that carries a
       meaning; after that an admin owns it and later runs leave it alone (the
       default row exists, so the word no longer qualifies). */
    $withGloss = 0;
    foreach ($glossOcc as $r) if ($r[4] !== null) $withGloss++;
    $wholeLinks = 0;
    foreach ($glossLinks as $r) if ($r[2] === 'W') $wholeLinks++;
    say(sprintf('  %s presented occurrences (%s with a meaning); %s word links (%s whole, %s part) across %s words',
        number_format(count($glossOcc)), number_format($withGloss), number_format(count($glossLinks)),
        number_format($wholeLinks), number_format(count($glossLinks) - $wholeLinks),
        number_format(count(array_unique(array_column($glossLinks, 0))))));

    $rank = [];   // paragraph_key => position in book order
    $i = 0;
    foreach ($db->query(
        'SELECT p.paragraph_key
           FROM yy_paragraph p
           LEFT JOIN yy_series  s ON s.series_key  = p.series_key
           LEFT JOIN yy_volume  v ON v.volume_key  = p.volume_key
           LEFT JOIN yy_chapter c ON c.chapter_key = p.chapter_key
          ORDER BY s.series_sort NULLS LAST, s.series_key, v.volume_sort NULLS LAST, v.volume_key,
                   c.chapter_sort NULLS FIRST, p.paragraph_page NULLS FIRST, p.paragraph_number NULLS FIRST, p.paragraph_key'
    )->fetchAll(PDO::FETCH_COLUMN) as $pk) $rank[(int)$pk] = $i++;

    $hasDefault = array_flip(array_map('intval', $db->query(
        "SELECT DISTINCT d.word_key FROM yy_word_definition d WHERE d.word_definition_default_flag
          UNION SELECT w.word_key FROM yy_word w WHERE coalesce(trim(w.word_definition_yy), '') <> ''"
    )->fetchAll(PDO::FETCH_COLUMN)));
    $firstDef = [];   // word_key => [rank, seq, meaning]
    foreach ($glossLinks as [$wk, $gk, $role]) {
        // Only the whole's meaning — "wa" never takes "wa ha nabʿym"'s.
        [, $pk, $seq, , $g] = $glossOcc[$gk - 1];
        if ($role !== 'W' || $g === null || isset($hasDefault[$wk]) || isset($excluded[$wk])) continue;
        $pos = [$rank[$pk] ?? PHP_INT_MAX, $seq];
        if (!isset($firstDef[$wk]) || $pos < [$firstDef[$wk][0], $firstDef[$wk][1]]) $firstDef[$wk] = [$pos[0], $pos[1], $g];
    }
    say(sprintf('  %s words get their first default definition from a reference', number_format(count($firstDef))));

    if ($INDEX && $APPLY) {
        try {
            // Full rebuild: a word whose spellings changed must not keep rows
            // from its old ones. TRUNCATE+INSERT share the outer transaction
            // (begun before Pass 2), so a failure rolls back both the
            // occurrence rows and the word-count UPDATEs atomically.
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

            // gloss_key is assigned here (1..n in scan order) so the links can
            // name it without a round trip; the whole table is rebuilt each run.
            $db->exec('TRUNCATE yy_word_gloss, yy_gloss');
            for ($i = 0; $i < count($glossOcc); $i += $chunk) {
                $slice = array_slice($glossOcc, $i, $chunk);
                $vals  = implode(',', array_fill(0, count($slice), '(?,?,?,?,?)'));
                $flat  = [];
                foreach ($slice as $r) array_push($flat, $r[0], $r[1], $r[2], $r[3], $r[4]);
                $db->prepare('INSERT INTO yy_gloss (gloss_key, paragraph_key, gloss_seq, gloss_phrase, gloss_text) VALUES ' . $vals)
                   ->execute($flat);
            }
            for ($i = 0; $i < count($glossLinks); $i += $chunk) {
                $slice = array_slice($glossLinks, $i, $chunk);
                $vals  = implode(',', array_fill(0, count($slice), '(?,?,?,?)'));
                $flat  = [];
                foreach ($slice as $r) array_push($flat, $r[0], $r[1], $r[2], $r[3]);
                $db->prepare('INSERT INTO yy_word_gloss (word_key, gloss_key, word_gloss_role, word_gloss_translit) VALUES ' . $vals)
                   ->execute($flat);
            }

            // word_source_key 2 = yy.  Mirrored into word_definition_yy the way
            // admin-glossary-lexicon.php mirrors a default into its source column.
            $insDef = $db->prepare(
                'INSERT INTO yy_word_definition (word_key, word_source_key, word_definition_text, word_definition_default_flag)
                 VALUES (?, 2, ?, true)');
            $mirror = $db->prepare("UPDATE yy_word SET word_definition_yy = ? WHERE word_key = ? AND coalesce(trim(word_definition_yy), '') = ''");
            foreach ($firstDef as $wk => [, , $g]) {
                $insDef->execute([$wk, $g]);
                $mirror->execute([$g, $wk]);
            }
            $db->commit();
            say('  written');
        } catch (\Exception $e) {
            $db->rollBack();
            say('  FAILED, rolled back word counts and occurrence index: ' . $e->getMessage());
            exit(1);
        }
    }
}

/* ═══ Pass 3 — candidates the lexicon does not have yet ═════════════════ */

$newRows = [];
if (!$RECOUNT) {
    say('');
    say(sprintf('Selecting candidates (presented at least %d time(s)) …', $MIN_PRESENTED));

    // A candidate is anything the books PRESENT (glossEntries): each whole —
    // a word, or a phrase such as "wa ha nabʿym" — and each part of a phrase.
    // Every word in it must still be word-shaped and not an English contraction.
    $rejected = ['shape' => 0, 'contraction' => 0, 'thin' => 0, 'known' => 0];

    foreach ($glossed as $lc => $n) {
        $bad = null;
        foreach (explode(' ', $lc) as $w) {
            if (!preg_match(TRANSLIT_RE, $w)) { $bad = 'shape'; break; }
            if (isContraction($w))            { $bad = 'contraction'; break; }
        }
        if ($bad !== null)       { $rejected[$bad]++; continue; }
        if ($n < $MIN_PRESENTED) { $rejected['thin']++; continue; }

        $k = normKey($lc);
        if ($k === '' || isset($known[$k])) { $rejected['known']++; continue; }

        // Keep the form the books present most often.
        $forms = $shown[$lc] ?? [$lc => 1];
        arsort($forms);

        $isPhrase = strpos($lc, ' ') !== false;
        $newRows[$k] = [
            'text'   => (string)array_key_first($forms),
            'count'  => $isPhrase ? ($phraseCount[$lc] ?? 0) : ($italic[$lc] ?? 0),
            'shown'  => $n,
            'phrase' => $isPhrase,
        ];
        $known[$k] = -1;   // block a second candidate that normalises the same
    }

    uasort($newRows, function ($a, $b) { return $b['shown'] <=> $a['shown']; });

    $phrases = count(array_filter($newRows, function ($r) { return $r['phrase']; }));
    say(sprintf('  %s candidates: %s words, %s phrases  (rejected: %s not word-shaped, %s contractions, %s presented too rarely, %s already on file)',
        number_format(count($newRows)), number_format(count($newRows) - $phrases), number_format($phrases),
        number_format($rejected['shape']), number_format($rejected['contraction']),
        number_format($rejected['thin']), number_format($rejected['known'])));

    $show = 40;
    foreach ($args as $a) if (preg_match('/^--top=(\d+)$/', $a, $m)) $show = (int)$m[1];

    say('');
    say('  ── new words and phrases, most often presented first ──');
    $i = 0;
    foreach ($newRows as $r) {
        say(sprintf('  %6s presented  %8s in books  %s  %s',
            number_format($r['shown']), number_format($r['count']), $r['phrase'] ? 'phrase' : 'word  ', $r['text']));
        if (++$i >= $show) break;
    }
}

/* ═══ Pass 3b — Books coverage ══════════════════════════════════════════
   word_source_code records WHERE A WORD WAS FIRST CATALOGUED, not whether the
   books use it. Pass 3 only ever creates a 'books' row for a spelling no word
   owns yet, so a word Strong's or Perry already had could occur thousands of
   times in the books and still have no Books entry — filtering the Words tab
   to Books hid it completely. 'mashal' was the case that surfaced this: 1,156
   occurrences across 35 volumes, catalogued under kirk and perry, absent from
   Books.

   The rule here is deliberately the LITERAL one the user chose: every distinct
   spelling that occurs in the books at all gets its own 'books' row. It is NOT
   the candidate test above.  Since 2026-09-30 "occurs" means occurs in
   ITALICS, the same rule as word_count_yy, so plain English no longer counts.

   ⚠ That means spellings which merely COLLIDE with common English get a Books
   entry too — 'by' (39,350), 'my' (15,325), 'man' (8,693), 'day' (7,182) are
   transliteration spellings whose counts come almost entirely from the English
   words. The stricter italic-ratio test would have excluded them (and 469
   others) but would also have dropped names the books usually set in plain
   text, Yahowah among them. The user chose coverage over precision; these rows
   are identifiable and removable by source + spelling if that is revisited.

   ⚠ word_hebrew is left NULL, as for every harvested word, so word_yt stays
   NULL and these rows do NOT reach the public glossary letter web (which
   filters LEFT(word_yt,1)). The noise above is therefore confined to the admin
   Words tab. Do not "helpfully" copy the Hebrew across from the source word —
   that would publish them.

   Matching is on the exact lower-cased spelling, not normKey(), because the
   question is which spelling the books actually print: 'any' and 'ʾany' are
   different spellings and each earns its own row. */

$booksGap = [];
if (!$RECOUNT && $COVERAGE) {
    say('');
    say('Books coverage — spellings that occur in the books with no Books row …');

    $booksHave = [];      // lower-cased spelling => already owned by a books row
    foreach ($words as $w) {
        // A "Not a word" spelling, from any source, must not spawn a Books row.
        if ((string)$w['word_source_code'] !== 'books' && !isset($excluded[(int)$w['word_key']])) continue;
        foreach ($spellings[(int)$w['word_key']] ?? [] as $text) {
            $lc = mb_strtolower(trim((string)$text));
            if ($lc !== '') $booksHave[$lc] = true;
        }
    }

    foreach ($words as $w) {
        if ((string)$w['word_source_code'] === 'books') continue;
        foreach ($spellings[(int)$w['word_key']] ?? [] as $text) {
            $lc = mb_strtolower(trim((string)$text));
            if ($lc === '' || isset($booksHave[$lc]) || isset($booksGap[$lc])) continue;
            // Italic occurrences, the same rule as word_count_yy: a spelling
            // seen only as plain English ("by", "my", "day") is not in the books.
            $c = $italic[$lc] ?? 0;
            if ($c <= 0) continue;                 // not in the books at all
            // Keep the casing the books themselves use most often.
            $forms = $surface[$lc] ?? [$lc => 1];
            arsort($forms);
            $booksGap[$lc] = ['text' => (string)array_key_first($forms), 'count' => $c];
        }
    }

    uasort($booksGap, function ($a, $b) { return $b['count'] <=> $a['count']; });
    say(sprintf('  %s spelling(s) need a Books row', number_format(count($booksGap))));

    $show = 20;
    foreach ($args as $a) if (preg_match('/^--top=(\d+)$/', $a, $m)) $show = (int)$m[1];
    $i = 0;
    foreach ($booksGap as $r) {
        say(sprintf('  %8s in books  %s', number_format($r['count']), $r['text']));
        if (++$i >= $show) break;
    }
}

/* ═══ Write ═════════════════════════════════════════════════════════════ */

if ($APPLY && !$RECOUNT && ($newRows || $booksGap)) {
    say('');
    say('Inserting …');
    $db->beginTransaction();
    try {
        // word_hebrew is left NULL: the books give the spelling, not the Hebrew.
        // trg_word_yt only fires when word_hebrew is set, so word_yt stays NULL
        // and the word will not surface on the public letter web until an admin
        // fills the Hebrew in — which is what the Words tab keyboard is for.
        // Language from the spelling alone, the same rule as the admin API's
        // applyDefaultLanguage(): al- → Arabic, a half-ring (ʾ ʿ) → Hebrew,
        // otherwise NULL (not yet classified).
        $insW = $db->prepare(
            "INSERT INTO yy_word (word_source_code, word_translit, word_count_yy, word_active_flag, word_language)
             VALUES (?, ?, ?, true,
                     CASE WHEN ?::text ILIKE 'al-%' THEN 'A'
                          WHEN ?::text ~ '[ʾʿ]'     THEN 'H' END)
             RETURNING word_key"
        );
        $insT = $db->prepare(
            'INSERT INTO yy_word_translit (word_key, word_translit_text, word_translit_sort, word_translit_count_yy)
             VALUES (?, ?, 0, ?)'
        );
        $n = 0;
        foreach ($newRows as $r) {
            $insW->execute(['books', $r['text'], $r['count'], $r['text'], $r['text']]);
            $wk = (int)$insW->fetchColumn();
            $insT->execute([$wk, $r['text'], $r['count']]);
            $n++;
        }
        // Pass 3b's rows go in the SAME transaction: both lists are 'books'
        // words born of the same scan, and a half-applied coverage pass would
        // leave the Books source inconsistent with the counts just written.
        $nCov = 0;
        foreach ($booksGap as $r) {
            $insW->execute(['books', $r['text'], $r['count'], $r['text'], $r['text']]);
            $wk = (int)$insW->fetchColumn();
            $insT->execute([$wk, $r['text'], $r['count']]);
            $nCov++;
        }
        $db->commit();
        say('  inserted ' . number_format($n) . ' words with word_source_code = books');
        if ($nCov) {
            say('  inserted ' . number_format($nCov) . ' Books-coverage rows for spellings other sources already held');
        }
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
