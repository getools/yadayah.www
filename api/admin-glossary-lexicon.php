<?php
/**
 * Admin API for the Glossary → Words section (the Hebrew lexicon).
 *
 * Backs the Words tab on admin-glossary.html against yy_word and its
 * satellites.  Separate from:
 *   admin-glossary.php       — Hebrew letters (the "Web" section)
 *   admin-glossary-word.php  — yy_glossary_word, the old standalone term list
 *
 * Tables
 *   yy_word                    one row per word.  word_source_code tracks where
 *                              the row came from: 'kirk' (Strong's import),
 *                              'books' (harvested from yy_paragraph), 'yy' (hand
 *                              added), 'perry' (the YY_Lexicon.csv import).
 *                              word_definition_yy / _kirk / _external / _perry
 *                              hold the DEFAULT definition for each source.
 *   yy_word_translit           every spelling of the word.  The preferred one is
 *                              mirrored into yy_word.word_translit.
 *   yy_word_definition         every definition, tagged by source (word_source_key).
 *
 * ⚠ word_yt has a DB trigger, trg_word_yt, that derives it from word_hebrew on
 *   any statement whose SET list mentions word_hebrew.  The editor now lets YT be
 *   typed directly (it can hold half-rings, which have no Hebrew letter), so an
 *   explicitly supplied word_yt is re-applied after the main write — see
 *   applyExplicitYt().  The client keeps the two boxes in step with the same
 *   yy_letter map the trigger uses (see ?action=meta).
 *
 * GET ?action=meta      — sources, definition sources, Hebrew↔YT letter map
 * GET                   — paged list (?q= ?source= ?letter= ?limit= ?offset= ?sort= ?dir=)
 *                         ?source= takes one code or a comma-separated list.
 *                         plus per-column filters: ?f_strongs= ?f_translit= ?f_hebrew=
 *                         ?f_yt= ?f_def= ?f_count= (&f_count_op=gt|lt)
 *                         ?copied=yes|no filters on the YY-copy link.
 *                         ?language=A|G|H|L|none filters on word_language.
 * GET ?key=N            — one word with its translits and definitions
 * POST                  — create
 * PUT ?key=N            — partial update; unsupplied fields are left alone
 * DELETE ?key=N         — delete the word and its satellites
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$key    = (int)($_GET['key'] ?? 0);
$action = (string)($_GET['action'] ?? '');

/** Definition-source codes that have a matching default column on yy_word. */
const DEFAULT_DEF_COLS = [
    'yy'       => 'word_definition_yy',
    'kirk'     => 'word_definition_kirk',
    'external' => 'word_definition_external',
    'perry'    => 'word_definition_perry',
];

/** Trimmed, but NOT NULLIF'd: word_strongs distinguishes NULL (nobody has
 *  determined it yet) from '' (established that the word has no Strong's
 *  entry). trim(NULL) is NULL and trim('') is '', so both survive. */
const WORD_COLS = "w.word_key,
     trim(w.word_strongs) AS word_strongs,
     w.word_translit, w.word_hebrew, w.word_yt,
     w.word_count_yy, w.word_source_code, w.word_active_flag,
     w.word_definition_yy, w.word_definition_kirk, w.word_definition_external,
     w.word_definition_perry,
     w.word_pronunciation_strongs, w.word_pronunciation_yy,
     w.word_pronunciation_ipa, w.word_pronunciation_phonetic, w.word_gender_key,
     w.word_yy_copy_key, w.word_language,
     (SELECT array_agg(m.word_pos_key ORDER BY m.word_pos_key)
        FROM yy_word_pos_map m WHERE m.word_key = w.word_key) AS word_pos_keys";

/**
 * Postgres hands array_agg() back through PDO as the literal '{1,2,11}', not a
 * PHP array, so it would reach the client as a string and break any caller that
 * iterates it. Normalise to a plain list of ints (and [] for no rows).
 */
function pgIntArray($v): array {
    if (is_array($v)) return array_map('intval', $v);
    if ($v === null || $v === '') return [];
    $inner = trim((string)$v, '{}');
    if ($inner === '') return [];
    return array_map('intval', array_filter(explode(',', $inner), 'strlen'));
}

/**
 * A readable extract of a paragraph, cut around the first place one of $needles
 * appears rather than at the paragraph's start.  Falls back to the opening of
 * the paragraph when nothing matches (the index counted a folded form, e.g. a
 * possessive, that the raw text spells differently).
 *
 * Returns ['text' => …, 'match' => the spelling actually found or null].  The
 * match is handed to the flipbook as its `q=` search term so the viewer
 * highlights the same spelling the reader is looking at, not a spelling of the
 * word that does not appear on that page.
 */
function occSnippet(string $txt, array $needles, int $before = 140, int $len = 460): array {
    if ($txt === '') return ['text' => '', 'match' => null];

    $at = null;
    $hit = null;
    foreach ($needles as $n) {
        if ($n === '') continue;
        // Whole word only, so "ab" does not match inside "about".
        $re = '/(?<![\p{L}])' . preg_quote($n, '/') . '(?![\p{L}])/ui';
        if (preg_match($re, $txt, $m, PREG_OFFSET_CAPTURE)) {
            // PREG_OFFSET_CAPTURE counts BYTES; the cut below counts characters.
            $chars = mb_strlen(substr($txt, 0, $m[0][1]));
            if ($at === null || $chars < $at) { $at = $chars; $hit = $m[0][0]; }
        }
    }

    if ($at === null) {
        return [
            'text'  => mb_strlen($txt) > $len ? mb_substr($txt, 0, $len) . '…' : $txt,
            'match' => null,
        ];
    }

    $start = max(0, $at - $before);
    $out   = mb_substr($txt, $start, $len);
    if ($start > 0)                              $out = '…' . $out;
    if ($start + $len < mb_strlen($txt))         $out = $out . '…';
    return ['text' => $out, 'match' => $hit];
}

/**
 * The public slug of a volume's self-hosted flipbook, or null when there is no
 * bundle on disk.  The on-disk directory strips apostrophe-ish glyphs while
 * volume_code keeps them (it matches the docx filename) — same strip as
 * public/api/search.php.
 *
 * ⚠ Not volume_flip_code: that is a legacy external FlipHTML5 id and does not
 *   resolve (/<flip_code>/ redirects to the home page).
 */
function occBookSlug(?string $volumeCode): ?string {
    if (!$volumeCode) return null;
    $slug = preg_replace("/[\u{0027}\u{2018}\u{2019}\u{02BC}]/u", '', $volumeCode);
    if ($slug === '') return null;
    $root = is_dir('/var/www/html') ? '/var/www/html' : dirname(__DIR__) . '/public';
    return is_dir($root . '/' . $slug . '/text') ? $slug : null;
}

function lexBody(): array {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

/**
 * Hebrew-alphabet sort keys, built from the yy_letter map.
 *
 * Neither column sorts correctly on its own: word_hebrew in codepoint order puts
 * every final form BEFORE its base letter (ך U+05DA < כ U+05DB), and word_yt is
 * standard-alphabet letters standing in for Hebrew ones, so plain text order gives
 * a b c d e f g … instead of the alphabet a b g d h w z c x y k l m n f i p e q r s t.
 *
 * yy_letter already holds the pairing — letter_yt ↔ letter_hebrew, ordered by
 * letter_sort — and it is the same map the trg_word_yt trigger translates through.
 * Reading it here means the alphabet lives in ONE place: reorder the letters in
 * the Web tab and both columns follow, with no constant to keep in step.
 *
 * Each standard letter takes its letter_sort position; each Hebrew letter takes the
 * position of the standard letter it maps to, so finals (which share their base's
 * letter_yt) collapse onto the base. Positions become 'A','B','C',… — a single
 * ascending run, chosen so anything unmapped falls outside it and sorts last.
 *
 * Returns ['yt' => <sql expr>, 'hebrew' => <sql expr>], or nulls if the map is
 * unusable, in which case the caller falls back to plain text order.
 */
function alphabetSortExpr(PDO $db): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $rows = $db->query(
        "SELECT letter_yt, letter_hebrew
           FROM yy_letter
          WHERE COALESCE(letter_yt, '') <> '' AND COALESCE(letter_hebrew, '') <> ''
          ORDER BY letter_sort, letter_key"
    )->fetchAll();

    $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';   // ascending, and disjoint from the data
    $pos  = [];                              // letter_yt => key character
    $ytFrom = $ytTo = $hebFrom = $hebTo = '';

    foreach ($rows as $r) {
        $yt  = mb_strtolower(trim($r['letter_yt']));
        $heb = trim($r['letter_hebrew']);
        if ($yt === '' || $heb === '') continue;
        if (!isset($pos[$yt])) {
            // First sighting of this standard letter fixes its alphabet position.
            if (count($pos) >= strlen($pool)) return $cached = ['yt' => null, 'hebrew' => null];
            $pos[$yt] = $pool[count($pos)];
            $ytFrom  .= $yt;
            $ytTo    .= $pos[$yt];
        }
        // A final form repeats its base's letter_yt and so lands on the same key.
        $hebFrom .= $heb;
        $hebTo   .= $pos[$yt];
    }
    if (!$pos) return $cached = ['yt' => null, 'hebrew' => null];

    $q = static fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";

    // Pointing is not a letter, so it must not move a word's place in the
    // alphabet — strip the nonspacing marks before translating. Same set the
    // page's LX_HEB_MARKS drops; built with chr() so no combining mark has to
    // survive a trip through this file as a literal.
    $marks = "'[' || chr(1425) || '-' || chr(1469) || chr(1471) || chr(1473)"
           . " || chr(1474) || chr(1476) || chr(1477) || chr(1479) || ']'";
    $hebBare = "regexp_replace(btrim(w.word_hebrew), $marks, '', 'g')";

    return $cached = [
        'yt'     => 'translate(lower(w.word_yt), ' . $q($ytFrom) . ', ' . $q($ytTo) . ')',
        'hebrew' => 'translate(' . $hebBare . ', ' . $q($hebFrom) . ', ' . $q($hebTo) . ')',
    ];
}

/* ── Meta: everything the editor needs to render its pickers ─────────────── */
if ($method === 'GET' && $action === 'meta') {
    $sources = $db->query(
        'SELECT word_source_key, word_source_code, word_source_label
           FROM yy_word_source ORDER BY word_source_sort, word_source_key'
    )->fetchAll();

    $letters = $db->query(
        "SELECT letter_key, letter_label, letter_yt, letter_hebrew, letter_numeric_value, letter_sort
           FROM yy_letter
          WHERE letter_hebrew IS NOT NULL AND letter_hebrew <> ''
          ORDER BY letter_sort, letter_key"
    )->fetchAll();

    $pos = $db->query(
        'SELECT word_pos_key, word_pos_code, word_pos_label FROM yy_word_pos ORDER BY word_pos_key'
    )->fetchAll();

    // Gender is one-per-word; NULL on the word means unknown, so the picker
    // adds its own blank option rather than the lookup carrying one.
    $genders = $db->query(
        'SELECT word_gender_key, word_gender_code, word_gender_label
           FROM yy_word_gender ORDER BY word_gender_key'
    )->fetchAll();

    // Language is a char(1) code, so the picker keys off the code, not a key.
    $languages = $db->query(
        'SELECT word_language_code, word_language_label
           FROM yy_word_language ORDER BY word_language_sort, word_language_code'
    )->fetchAll();

    // Per-source row counts drive the filter chips.
    $counts = $db->query(
        'SELECT COALESCE(NULLIF(trim(word_source_code), \'\'), \'(none)\') AS code, count(*) AS n
           FROM yy_word GROUP BY 1 ORDER BY 2 DESC'
    )->fetchAll();

    jsonResponse([
        'sources'          => $sources,
        'letters'          => $letters,
        'pos'              => $pos,
        'genders'          => $genders,
        'languages'        => $languages,
        'source_counts'    => $counts,
        'default_def_cols' => DEFAULT_DEF_COLS,
    ]);
}

/* ── Where a word occurs: series → volume → chapter → page → paragraph ───
   One level per request, so opening a 25,000-hit word costs the same as any
   other.  Reads yy_word_occurrence (built by api/_word_harvest.php --index),
   joined through yy_paragraph so a re-parse can never serve a stale location.
   Totals equal yy_word.word_count_yy by construction.

   GET ?action=occurrences&key=N                                   → series
   GET ?action=occurrences&key=N&series=S                          → volumes
   GET ?action=occurrences&key=N&volume=V                          → chapters
   GET ?action=occurrences&key=N&volume=V&chapter=C                → pages
   GET ?action=occurrences&key=N&volume=V&chapter=C&page=P         → paragraphs
   chapter=0 addresses the paragraphs that belong to no chapter (front and
   back matter — 3,033 of them). ⚠ Must be tested BEFORE the single-word
   handler below, which also matches on `key`. */
if ($method === 'GET' && $action === 'occurrences') {
    if (!$key) errorResponse('key is required');

    $series  = isset($_GET['series'])  && $_GET['series']  !== '' ? (int)$_GET['series']  : null;
    $volume  = isset($_GET['volume'])  && $_GET['volume']  !== '' ? (int)$_GET['volume']  : null;
    // chapter=0 is meaningful (the no-chapter bucket), so only a missing
    // parameter counts as "not supplied".
    $chapter = array_key_exists('chapter', $_GET) && $_GET['chapter'] !== '' ? (int)$_GET['chapter'] : null;
    $page    = isset($_GET['page'])    && $_GET['page']    !== '' ? (int)$_GET['page']    : null;

    $base = 'FROM yy_word_occurrence o
             JOIN yy_paragraph p ON p.paragraph_key = o.paragraph_key
            WHERE o.word_key = :wk AND p.paragraph_active_flag IS NOT FALSE';

    /* ── Paragraphs on one page ── */
    if ($page !== null && $volume !== null && $chapter !== null) {
        // Every spelling of the word, longest first — the snippet has to be
        // cut around the match, not the start of the paragraph, or a word that
        // appears late in a long paragraph is nowhere to be seen in its own
        // occurrence list.
        $sp = $db->prepare(
            "SELECT DISTINCT s FROM (
                 SELECT word_translit_text AS s FROM yy_word_translit WHERE word_key = :k
                 UNION ALL
                 SELECT word_translit      AS s FROM yy_word          WHERE word_key = :k
             ) t WHERE s IS NOT NULL AND btrim(s) <> ''"
        );
        $sp->execute([':k' => $key]);
        $spellings = array_map('trim', array_column($sp->fetchAll(), 's'));
        usort($spellings, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });

        $sql = 'SELECT p.paragraph_key, p.paragraph_number, o.occurrence_count AS total,
                       p.paragraph_text_plain
                ' . $base . '
                  AND p.volume_key = :v AND p.paragraph_page = :pg
                  AND ' . ($chapter === 0 ? 'p.chapter_key IS NULL' : 'p.chapter_key = :c') . '
                ORDER BY p.paragraph_number, p.paragraph_key';
        $st = $db->prepare($sql);
        $bind = [':wk' => $key, ':v' => $volume, ':pg' => $page];
        if ($chapter !== 0) $bind[':c'] = $chapter;
        $st->execute($bind);

        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $txt = trim(preg_replace('/\s+/u', ' ', (string)$r['paragraph_text_plain']));
            $cut = occSnippet($txt, $spellings);
            $rows[] = [
                'key'     => (int)$r['paragraph_key'],
                'number'  => $r['paragraph_number'],
                'total'   => (int)$r['total'],
                'snippet' => $cut['text'],
                'match'   => $cut['match'],
            ];
        }
        // No 'preferred' here: $spellings is sorted longest-first for matching,
        // so it cannot name the preferred spelling. The client already holds it
        // (lxOccSpellings[word][0]) and uses it when a row has no 'match'.
        jsonResponse(['level' => 'paragraph', 'rows' => $rows]);
    }

    /* ── Pages in one chapter ── */
    if ($volume !== null && $chapter !== null) {
        $sql = 'SELECT p.paragraph_page AS page, sum(o.occurrence_count) AS total,
                       count(DISTINCT p.paragraph_key) AS paragraphs
                ' . $base . '
                  AND p.volume_key = :v
                  AND ' . ($chapter === 0 ? 'p.chapter_key IS NULL' : 'p.chapter_key = :c') . '
                GROUP BY p.paragraph_page
                ORDER BY p.paragraph_page';
        $st = $db->prepare($sql);
        $bind = [':wk' => $key, ':v' => $volume];
        if ($chapter !== 0) $bind[':c'] = $chapter;
        $st->execute($bind);

        $rows = array_map(function ($r) {
            return [
                'page'       => (int)$r['page'],
                'total'      => (int)$r['total'],
                'paragraphs' => (int)$r['paragraphs'],
            ];
        }, $st->fetchAll());
        jsonResponse(['level' => 'page', 'rows' => $rows]);
    }

    /* ── Chapters in one volume ── */
    if ($volume !== null) {
        // LEFT JOIN, not JOIN: 3,033 paragraphs carry no chapter_key and must
        // still be reachable, bucketed under key 0.
        $st = $db->prepare(
            'SELECT p.chapter_key, c.chapter_name, c.chapter_label, c.chapter_number,
                    sum(o.occurrence_count) AS total,
                    count(DISTINCT p.paragraph_page) AS pages
             FROM yy_word_occurrence o
             JOIN yy_paragraph p ON p.paragraph_key = o.paragraph_key
             LEFT JOIN yy_chapter c ON c.chapter_key = p.chapter_key
            WHERE o.word_key = :wk AND p.paragraph_active_flag IS NOT FALSE
              AND p.volume_key = :v
             GROUP BY p.chapter_key, c.chapter_name, c.chapter_label, c.chapter_number, c.chapter_sort
             ORDER BY c.chapter_sort NULLS FIRST, c.chapter_number NULLS FIRST, p.chapter_key'
        );
        $st->execute([':wk' => $key, ':v' => $volume]);

        $rows = array_map(function ($r) {
            $name = trim((string)($r['chapter_name'] ?: $r['chapter_label'] ?: ''));
            return [
                'key'    => $r['chapter_key'] === null ? 0 : (int)$r['chapter_key'],
                'number' => $r['chapter_number'],
                'label'  => $r['chapter_key'] === null
                    ? 'Front / back matter'
                    : (($r['chapter_number'] !== null ? 'Chapter ' . $r['chapter_number'] : 'Chapter')
                        . ($name !== '' ? ' — ' . $name : '')),
                'total'  => (int)$r['total'],
                'pages'  => (int)$r['pages'],
            ];
        }, $st->fetchAll());
        jsonResponse(['level' => 'chapter', 'rows' => $rows]);
    }

    /* ── Volumes in one series ── */
    if ($series !== null) {
        $st = $db->prepare(
            'SELECT p.volume_key, v.volume_label, v.volume_number, v.volume_code,
                    sum(o.occurrence_count) AS total,
                    count(DISTINCT p.chapter_key) AS chapters
             FROM yy_word_occurrence o
             JOIN yy_paragraph p ON p.paragraph_key = o.paragraph_key
             JOIN yy_volume    v ON v.volume_key    = p.volume_key
            WHERE o.word_key = :wk AND p.paragraph_active_flag IS NOT FALSE
              AND p.series_key = :s
             GROUP BY p.volume_key, v.volume_label, v.volume_number, v.volume_code, v.volume_sort
             ORDER BY v.volume_sort, v.volume_number, p.volume_key'
        );
        $st->execute([':wk' => $key, ':s' => $series]);

        $rows = array_map(function ($r) {
            $label = trim((string)$r['volume_label']);
            return [
                'key'       => (int)$r['volume_key'],
                'label'     => ($r['volume_number'] !== null ? 'Vol ' . $r['volume_number'] . ' — ' : '')
                               . ($label !== '' ? $label : 'Volume'),
                'total'     => (int)$r['total'],
                'chapters'  => (int)$r['chapters'],
                // Carried down the tree so page and paragraph rows can deep-link.
                'book_slug' => occBookSlug($r['volume_code']),
            ];
        }, $st->fetchAll());
        jsonResponse(['level' => 'volume', 'rows' => $rows]);
    }

    /* ── Series (the top level), plus the grand total ── */
    $st = $db->prepare(
        'SELECT p.series_key, s.series_label, s.series_name, s.series_number,
                sum(o.occurrence_count) AS total,
                count(DISTINCT p.volume_key) AS volumes
         FROM yy_word_occurrence o
         JOIN yy_paragraph p ON p.paragraph_key = o.paragraph_key
         JOIN yy_series    s ON s.series_key    = p.series_key
        WHERE o.word_key = :wk AND p.paragraph_active_flag IS NOT FALSE
         GROUP BY p.series_key, s.series_label, s.series_name, s.series_number, s.series_sort
         ORDER BY s.series_sort, s.series_number, p.series_key'
    );
    $st->execute([':wk' => $key]);

    $rows = [];
    $grand = 0;
    foreach ($st->fetchAll() as $r) {
        $label = trim((string)($r['series_label'] ?: $r['series_name'] ?: ''));
        $grand += (int)$r['total'];
        $rows[] = [
            'key'     => (int)$r['series_key'],
            'label'   => $label !== '' ? $label : 'Series ' . $r['series_number'],
            'total'   => (int)$r['total'],
            'volumes' => (int)$r['volumes'],
        ];
    }
    jsonResponse(['level' => 'series', 'rows' => $rows, 'total' => $grand]);
}

/* ── One word, with satellites ───────────────────────────────────────────── */
if ($method === 'GET' && $key) {
    $stmt = $db->prepare('SELECT ' . WORD_COLS . ' FROM yy_word w WHERE w.word_key = ?');
    $stmt->execute([$key]);
    $word = $stmt->fetch();
    if (!$word) errorResponse('Word not found', 404);

    $t = $db->prepare(
        'SELECT word_translit_key, word_translit_text, word_translit_sort, word_translit_count_yy
           FROM yy_word_translit WHERE word_key = ?
          ORDER BY word_translit_sort, word_translit_key'
    );
    $t->execute([$key]);
    $word['translits'] = $t->fetchAll();

    $d = $db->prepare(
        'SELECT d.word_definition_key, d.word_definition_text, d.word_source_key,
                d.word_pos_key, d.word_gender_key, d.word_definition_plural_flag,
                d.word_definition_active_flag, d.word_definition_default_flag,
                s.word_source_code, s.word_source_label,
                p.word_pos_label
           FROM yy_word_definition d
           LEFT JOIN yy_word_source s ON s.word_source_key = d.word_source_key
           LEFT JOIN yy_word_pos    p ON p.word_pos_key    = d.word_pos_key
          WHERE d.word_key = ?
          ORDER BY d.word_definition_default_flag DESC,
                   s.word_source_sort, d.word_pos_key, d.word_definition_key'
    );
    $d->execute([$key]);
    $word['definitions'] = $d->fetchAll();
    $word['word_pos_keys'] = pgIntArray($word['word_pos_keys'] ?? null);

    jsonResponse($word);
}

/* ── Paged list ──────────────────────────────────────────────────────────── */
if ($method === 'GET' && !$key) {
    $q      = trim((string)($_GET['q'] ?? ''));
    $source = trim((string)($_GET['source'] ?? ''));
    $letter = trim((string)($_GET['letter'] ?? ''));
    $limit  = min(500, max(1, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $where  = [];
    $params = [];

    if ($q !== '') {
        // Search the word itself and any of its alternate spellings/definitions.
        $where[] = '(w.word_strongs ILIKE :q OR w.word_hebrew ILIKE :q
                     OR w.word_yt ILIKE :q OR w.word_translit ILIKE :q
                     OR w.word_definition_yy ILIKE :q OR w.word_definition_kirk ILIKE :q
                     OR w.word_definition_perry ILIKE :q
                     OR w.word_pronunciation_strongs ILIKE :q OR w.word_pronunciation_yy ILIKE :q
                     OR w.word_pronunciation_ipa ILIKE :q OR w.word_pronunciation_phonetic ILIKE :q
                     OR EXISTS (SELECT 1 FROM yy_word_translit s
                                 WHERE s.word_key = w.word_key AND s.word_translit_text ILIKE :q))';
        $params[':q'] = '%' . $q . '%';
    }
    if ($source !== '') {
        /* The filter is a checkbox list, so several codes can arrive at once as
           a comma-separated list. A single code still works unchanged. */
        $codes = [];
        foreach (explode(',', $source) as $c) {
            $c = trim($c);
            if ($c !== '' && !in_array($c, $codes, true)) $codes[] = $c;
        }
        if ($codes) {
            $ph = [];
            foreach ($codes as $i => $c) {
                $ph[] = ':source' . $i;
                $params[':source' . $i] = $c;
            }
            $where[] = 'COALESCE(trim(w.word_source_code), \'\') IN (' . implode(', ', $ph) . ')';
        }
    }
    if ($letter !== '') {
        // Index by the first YT letter, matching the public glossary's letter web.
        $where[] = 'LEFT(w.word_yt, 1) = :letter';
        $params[':letter'] = $letter;
    }

    /* Copy-on-write link: 'yes' = outside-source words already forked to a YY
       copy, 'no' = not yet. Anything else means no filter. */
    $copied = trim((string)($_GET['copied'] ?? ''));
    if ($copied === 'yes')     $where[] = 'w.word_yy_copy_key IS NOT NULL';
    elseif ($copied === 'no')  $where[] = 'w.word_yy_copy_key IS NULL';

    /* Language: a code from yy_word_language, or 'none' for the rows that have
       not been classified yet. Validated against the lookup so a bad code
       cannot silently return everything. */
    $language = trim((string)($_GET['language'] ?? ''));
    if ($language === 'none') {
        $where[] = 'w.word_language IS NULL';
    } elseif ($language !== '') {
        $where[] = 'w.word_language = :language';
        $params[':language'] = mb_substr($language, 0, 1);
    }

    /* Per-column filters from the table's filter row. Each narrows independently
       of ?q=, so the search box and the column boxes compose. */
    $colFilters = [
        'f_strongs'  => "trim(w.word_strongs) ILIKE :f_strongs",
        'f_hebrew'   => 'w.word_hebrew ILIKE :f_hebrew',
        'f_yt'       => 'w.word_yt ILIKE :f_yt',
        'f_translit' => '(w.word_translit ILIKE :f_translit
                          OR EXISTS (SELECT 1 FROM yy_word_translit s
                                      WHERE s.word_key = w.word_key
                                        AND s.word_translit_text ILIKE :f_translit))',
        'f_def'      => '(w.word_definition_yy ILIKE :f_def
                          OR w.word_definition_kirk ILIKE :f_def
                          OR w.word_definition_external ILIKE :f_def
                          OR w.word_definition_perry ILIKE :f_def
                          OR EXISTS (SELECT 1 FROM yy_word_definition d
                                      WHERE d.word_key = w.word_key
                                        AND d.word_definition_text ILIKE :f_def))',
    ];
    foreach ($colFilters as $param => $clause) {
        $val = trim((string)($_GET[$param] ?? ''));
        if ($val === '') continue;
        $where[] = $clause;
        $params[':' . $param] = '%' . $val . '%';
    }

    // Count filter is numeric with a greater/less toggle, like the Word admin's.
    $fCount = trim((string)($_GET['f_count'] ?? ''));
    if ($fCount !== '' && is_numeric($fCount)) {
        $op = (($_GET['f_count_op'] ?? 'gt') === 'lt') ? '<=' : '>=';
        $where[] = 'COALESCE(w.word_count_yy, 0) ' . $op . ' :f_count';
        $params[':f_count'] = (int)$fCount;
    }

    $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // Sort expression per column; direction is applied below.
    $alpha = alphabetSortExpr($db);
    $sortMap = [
        'translit' => 'lower(w.word_translit)',
        'strongs'  => 'trim(w.word_strongs)',
        'count'    => 'w.word_count_yy',
        'language' => 'w.word_language',
        'hebrew'   => $alpha['hebrew'] ?? 'btrim(w.word_hebrew)',
        'yt'       => $alpha['yt'] ?? 'lower(w.word_yt)',
        'recent'   => 'w.word_key',
    ];
    $sortKey = (string)($_GET['sort'] ?? 'translit');
    if (!isset($sortMap[$sortKey])) $sortKey = 'translit';

    // Counts and "recent" read best biggest-first; everything else A→Z.
    $defaultDesc = ($sortKey === 'count' || $sortKey === 'recent');
    $dirParam    = strtolower((string)($_GET['dir'] ?? ''));
    $desc        = $dirParam === 'desc' ? true : ($dirParam === 'asc' ? false : $defaultDesc);

    // Preferred spelling breaks ties so paging is stable across identical keys.
    $order = $sortMap[$sortKey] . ($desc ? ' DESC' : ' ASC') . ' NULLS LAST,'
           . ' lower(w.word_translit) ASC NULLS LAST, w.word_key ASC';

    $countStmt = $db->prepare('SELECT count(*) FROM yy_word w' . $sql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare(
        'SELECT ' . WORD_COLS . ",
                (SELECT count(*) FROM yy_word_translit s WHERE s.word_key = w.word_key) AS translit_count,
                (SELECT string_agg(s.word_translit_text, ', ' ORDER BY s.word_translit_sort, s.word_translit_key)
                   FROM yy_word_translit s WHERE s.word_key = w.word_key) AS translits_display
           FROM yy_word w" . $sql . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['word_pos_keys'] = pgIntArray($row['word_pos_keys'] ?? null);
    }
    unset($row);

    jsonResponse([
        'words'  => $rows,
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
        'sort'   => $sortKey,
        'dir'    => $desc ? 'desc' : 'asc',
    ]);
}

/* ── Writes ──────────────────────────────────────────────────────────────── */

/**
 * Replace a word's alternate spellings, and mirror the preferred one into
 * yy_word.word_translit.  Returns the preferred spelling.
 *
 * $translits is a list of {word_translit_text, preferred?} — order is the
 * display order.  Existing counts are carried over by text so a re-save does
 * not throw away a harvested word_translit_count_yy.
 */
function saveTranslits(PDO $db, int $wordKey, array $translits, ?string $fallback): ?string {
    $prior = [];
    $ps = $db->prepare('SELECT word_translit_text, word_translit_count_yy FROM yy_word_translit WHERE word_key = ?');
    $ps->execute([$wordKey]);
    foreach ($ps->fetchAll() as $r) {
        $prior[mb_strtolower($r['word_translit_text'])] = $r['word_translit_count_yy'];
    }

    $db->prepare('DELETE FROM yy_word_translit WHERE word_key = ?')->execute([$wordKey]);

    $ins = $db->prepare(
        'INSERT INTO yy_word_translit (word_key, word_translit_text, word_translit_sort, word_translit_count_yy)
         VALUES (?, ?, ?, ?)'
    );

    $preferred = null;
    $sort = 0;
    $seen = [];
    foreach ($translits as $row) {
        $text = is_array($row) ? (string)($row['word_translit_text'] ?? '') : (string)$row;
        $text = trim($text);
        if ($text === '') continue;
        $lc = mb_strtolower($text);
        if (isset($seen[$lc])) continue;      // de-dupe, keep first
        $seen[$lc] = true;

        $ins->execute([$wordKey, $text, $sort, $prior[$lc] ?? null]);
        // First spelling is preferred by default; an explicit flag overrides it.
        if ($preferred === null || (is_array($row) && !empty($row['preferred']))) {
            $preferred = $text;
        }
        $sort++;
    }

    // No spellings supplied → keep whatever word_translit already held.
    return $preferred ?? ($fallback !== null && trim($fallback) !== '' ? trim($fallback) : null);
}

/**
 * Derive a word's language when nothing has been chosen for it.
 *
 *   Strong's G…            → G, Greek
 *   Strong's H… or Hebrew  → H, Hebrew
 *
 * Hebrew text on its own is enough; word_yt is not required, since trg_word_yt
 * derives YT from word_hebrew anyway and a word written in Hebrew is Hebrew
 * whether or not it has been given a Strong's number.
 *
 * Only ever fills a NULL — an explicit choice is never overwritten, so a word
 * deliberately marked Arabic stays Arabic even if it carries Hebrew text.
 *
 * Runs AFTER applyExplicitYt() so it reads the row's settled state rather than
 * whatever the caller happened to send.
 */
function applyDefaultLanguage(PDO $db, int $wordKey): void {
    $db->prepare(
        "UPDATE yy_word
            SET word_language = CASE
                    WHEN upper(left(trim(word_strongs), 1)) = 'G' THEN 'G'
                    ELSE 'H'
                END
          WHERE word_key = ?
            AND word_language IS NULL
            AND (upper(left(trim(word_strongs), 1)) IN ('H', 'G')
                 OR COALESCE(word_hebrew, '') <> '')"
    )->execute([$wordKey]);
}

/**
 * Fork a non-YY word into a new YY-sourced copy and link the original to it.
 *
 * Words that came from an outside source (Kirk/Strong's, Books, Perry) are not
 * edited in place: the first edit copies the whole record — spellings, parts of
 * speech and definitions included — onto a new yy_word row whose source is 'yy',
 * and stamps word_yy_copy_key on the ORIGINAL so the two stay associated and the
 * list can filter on whether a word has been copied yet.
 *
 * The copy is exact; the caller's edits are then applied to it by the normal
 * update path, so there is only one place that knows how to write a word.
 *
 * Returns the new word_key. Must run inside the caller's transaction.
 */
function forkWordToYy(PDO $db, int $origKey): int {
    /* word_key, user_key and the revision columns are assigned by the insert and
       its triggers; word_count_yy is harvested, so the copy starts with none;
       word_yy_copy_key is the link itself and belongs only to the original. */
    $copy = 'word_strongs, word_hebrew, word_yt, word_translit,
             word_flag_noun, word_flag_verb, word_flag_adjective, word_flag_adverb,
             word_flag_preposition, word_flag_conjunction, word_flag_subst, word_flag_pronoun,
             word_definition_kirk, word_definition_yy, word_definition_external, word_definition_perry,
             word_active_flag, word_pronunciation_strongs, word_pronunciation_yy,
             word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key';

    $ins = $db->prepare(
        "INSERT INTO yy_word (word_source_code, $copy)
         SELECT 'yy', $copy FROM yy_word WHERE word_key = ?
         RETURNING word_key"
    );
    $ins->execute([$origKey]);
    $newKey = (int)$ins->fetchColumn();
    if ($newKey <= 0) throw new \RuntimeException('Could not copy word ' . $origKey);

    // Spellings, parts of speech and definitions come along, minus their counts:
    // occurrences belong to the word that was actually harvested.
    $db->prepare(
        'INSERT INTO yy_word_translit (word_key, word_translit_text, word_translit_sort)
         SELECT ?, word_translit_text, word_translit_sort
           FROM yy_word_translit WHERE word_key = ?'
    )->execute([$newKey, $origKey]);

    $db->prepare(
        'INSERT INTO yy_word_pos_map (word_key, word_pos_key, word_pos_map_sort)
         SELECT ?, word_pos_key, word_pos_map_sort FROM yy_word_pos_map WHERE word_key = ?'
    )->execute([$newKey, $origKey]);

    $db->prepare(
        'INSERT INTO yy_word_definition
            (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text,
             word_definition_active_flag, word_definition_plural_flag, word_gender_code,
             word_definition_source_key, word_definition_default_flag)
         SELECT ?, word_source_key, word_gender_key, word_pos_key, word_definition_text,
                word_definition_active_flag, word_definition_plural_flag, word_gender_code,
                word_definition_source_key, word_definition_default_flag
           FROM yy_word_definition WHERE word_key = ?'
    )->execute([$newKey, $origKey]);

    // The link lives on the original, which is otherwise left exactly as it was.
    $db->prepare('UPDATE yy_word SET word_yy_copy_key = ? WHERE word_key = ?')
       ->execute([$newKey, $origKey]);

    return $newKey;
}

/**
 * Replace a word's definitions and pick the one that is its default.
 *
 * $rows is a list of {word_definition_key?, word_definition_text, word_source_key,
 * word_pos_key?, word_definition_active_flag?, default?}. Rows carrying a key are
 * UPDATEd in place so the definition keeps its identity and revision history;
 * rows without one are inserted; keys the editor no longer sends are DELETEd.
 * That is deliberately unlike saveTranslits(), which can afford to replace its
 * rows wholesale because a spelling has no history worth keeping.
 *
 * Returns the default's text and source key so the caller can mirror it into the
 * matching yy_word.word_definition_* column.
 */
function saveDefinitions(PDO $db, int $wordKey, array $rows): array {
    $ex = $db->prepare('SELECT word_definition_key FROM yy_word_definition WHERE word_key = ?');
    $ex->execute([$wordKey]);
    $existing = array_map('intval', array_column($ex->fetchAll(), 'word_definition_key'));

    /* Clear the old default BEFORE setting the new one: uq_word_definition_one_default
       is a partial unique index, so two true rows cannot coexist even mid-statement. */
    $db->prepare('UPDATE yy_word_definition SET word_definition_default_flag = false
                   WHERE word_key = ? AND word_definition_default_flag')->execute([$wordKey]);

    $upd = $db->prepare(
        'UPDATE yy_word_definition
            SET word_definition_text = ?, word_source_key = ?, word_pos_key = ?,
                word_definition_active_flag = ?
          WHERE word_definition_key = ? AND word_key = ?'
    );
    $ins = $db->prepare(
        'INSERT INTO yy_word_definition
            (word_key, word_source_key, word_pos_key, word_definition_text, word_definition_active_flag)
         VALUES (?, ?, ?, ?, ?) RETURNING word_definition_key'
    );

    $keep = [];
    $defaultKey = null;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $text = trim((string)($r['word_definition_text'] ?? ''));
        $srcKey = (int)($r['word_source_key'] ?? 0);
        // A blank definition or a missing source is an empty row, not an edit.
        if ($text === '' || $srcKey <= 0) continue;
        $pos    = (int)($r['word_pos_key'] ?? 0) ?: null;
        $active = (int)(bool)($r['word_definition_active_flag'] ?? true);
        $k      = (int)($r['word_definition_key'] ?? 0);

        if ($k > 0 && in_array($k, $existing, true)) {
            $upd->execute([$text, $srcKey, $pos, $active, $k, $wordKey]);
        } else {
            $ins->execute([$wordKey, $srcKey, $pos, $text, $active]);
            $k = (int)$ins->fetchColumn();
        }
        $keep[] = $k;
        if ($defaultKey === null && !empty($r['default'])) $defaultKey = $k;
    }

    $drop = array_values(array_diff($existing, $keep));
    if ($drop) {
        $in = implode(',', array_fill(0, count($drop), '?'));
        $db->prepare("DELETE FROM yy_word_definition
                       WHERE word_key = ? AND word_definition_key IN ($in)")
           ->execute(array_merge([$wordKey], $drop));
    }

    // A word that still has definitions always has exactly one default, so fall
    // back to the first surviving row when the editor marked none.
    if ($defaultKey === null && $keep) $defaultKey = $keep[0];
    if ($defaultKey === null) return ['text' => null, 'source_key' => 0];

    $db->prepare('UPDATE yy_word_definition SET word_definition_default_flag = true
                   WHERE word_definition_key = ?')->execute([$defaultKey]);

    $q = $db->prepare('SELECT word_definition_text, word_source_key
                         FROM yy_word_definition WHERE word_definition_key = ?');
    $q->execute([$defaultKey]);
    $row = $q->fetch() ?: [];
    return ['text' => $row['word_definition_text'] ?? null,
            'source_key' => (int)($row['word_source_key'] ?? 0)];
}

/**
 * Re-apply a caller-supplied word_yt after the main write.
 *
 * trg_word_yt is BEFORE INSERT OR UPDATE **OF word_hebrew**, so any statement
 * that mentions word_hebrew has word_yt overwritten from the Hebrew. That is the
 * right default, but YT is now directly editable and can hold characters Hebrew
 * has no letter for (the half-rings ʾ ʿ), so a typed value has to win.
 *
 * Reading the stored value back rather than predicting it means we defer to what
 * the trigger actually did, and the corrective UPDATE — which never mentions
 * word_hebrew, so it cannot re-fire the trigger — only runs when the two differ.
 * Callers that send no word_yt at all keep the derive-from-Hebrew behaviour.
 */
function applyExplicitYt(PDO $db, int $wordKey, array $data): void {
    if (!array_key_exists('word_yt', $data)) return;
    $want = trim((string)$data['word_yt']);
    $want = $want !== '' ? $want : null;

    $cur = $db->prepare('SELECT word_yt FROM yy_word WHERE word_key = ?');
    $cur->execute([$wordKey]);
    $have = $cur->fetchColumn();
    $have = ($have === false || $have === null || trim((string)$have) === '') ? null : trim((string)$have);

    if ($have === $want) return;
    $db->prepare('UPDATE yy_word SET word_yt = ? WHERE word_key = ?')->execute([$want, $wordKey]);
}

/**
 * Replace a word's parts of speech.
 *
 * $keys is a list of word_pos_key. Rows that are already correct are left
 * untouched rather than deleted and re-inserted: every write fires the rev
 * trigger, so a blind wipe-and-rewrite would add two history rows per part of
 * speech on every save even when nothing changed.
 */
function savePartsOfSpeech(PDO $db, int $wordKey, array $keys): void {
    $want = [];
    foreach ($keys as $k) {
        $k = (int)$k;
        if ($k > 0) $want[$k] = true;
    }

    $have = [];
    $st = $db->prepare('SELECT word_pos_key FROM yy_word_pos_map WHERE word_key = ?');
    $st->execute([$wordKey]);
    foreach ($st->fetchAll() as $r) $have[(int)$r['word_pos_key']] = true;

    $add = array_diff_key($want, $have);
    $del = array_diff_key($have, $want);

    if ($add) {
        // Guard against a key that is not in the lookup: the FK would throw
        // and lose the whole save.
        $ins = $db->prepare(
            'INSERT INTO yy_word_pos_map (word_key, word_pos_key)
             SELECT ?, ? WHERE EXISTS (SELECT 1 FROM yy_word_pos WHERE word_pos_key = ?)'
        );
        foreach (array_keys($add) as $k) $ins->execute([$wordKey, $k, $k]);
    }
    if ($del) {
        $ph = implode(',', array_fill(0, count($del), '?'));
        $db->prepare("DELETE FROM yy_word_pos_map WHERE word_key = ? AND word_pos_key IN ($ph)")
           ->execute(array_merge([$wordKey], array_keys($del)));
    }
}

if ($method === 'POST') {
    setCurrentUser($db, $user['user_key']);
    $data = lexBody();

    $translit = trim((string)($data['word_translit'] ?? ''));
    $hebrew   = trim((string)($data['word_hebrew'] ?? ''));
    if ($translit === '' && $hebrew === '') {
        errorResponse('Give the word a transliteration or Hebrew spelling.');
    }

    /* Same three states as the PUT: NULL = not yet determined, '' = established
       that the word has no Strong's entry, anything else must parse. A new word
       with nothing supplied is unknown, not "has none". */
    $rawStrongs = $data['word_strongs'] ?? null;
    if ($rawStrongs === null) {
        $strongs = null;
    } elseif (trim((string)$rawStrongs) === '') {
        $strongs = '';
    } else {
        $strongs = normalizeStrongs($rawStrongs);
        if ($strongs === false) {
            errorResponse("Strong's must be 1-4 digits, optionally with a G/H language letter and a trailing letter (e.g. 430, H0430, H0430a).");
        }
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO yy_word
                (word_source_code, word_strongs, word_hebrew, word_translit,
                 word_definition_yy, word_definition_kirk, word_definition_external,
                 word_definition_perry,
                 word_count_yy, word_active_flag,
                 word_pronunciation_strongs, word_pronunciation_yy,
                 word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key,
                 word_language)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING word_key'
        );
        $stmt->execute([
            trim((string)($data['word_source_code'] ?? 'yy')) ?: 'yy',
            $strongs,
            $hebrew !== '' ? $hebrew : null,
            $translit !== '' ? $translit : null,
            trim((string)($data['word_definition_yy'] ?? '')) ?: null,
            trim((string)($data['word_definition_kirk'] ?? '')) ?: null,
            trim((string)($data['word_definition_external'] ?? '')) ?: null,
            trim((string)($data['word_definition_perry'] ?? '')) ?: null,
            null,   // word_count_yy — derived by _word_harvest.php, never supplied

            (int)(array_key_exists('word_active_flag', $data) ? (bool)$data['word_active_flag'] : true),
            trim((string)($data['word_pronunciation_strongs']  ?? '')) ?: null,
            trim((string)($data['word_pronunciation_yy']       ?? '')) ?: null,
            trim((string)($data['word_pronunciation_ipa']      ?? '')) ?: null,
            trim((string)($data['word_pronunciation_phonetic'] ?? '')) ?: null,
            ((int)($data['word_gender_key'] ?? 0)) ?: null,
            trim((string)($data['word_language'] ?? '')) ?: null,
        ]);
        $wordKey = (int)$stmt->fetchColumn();

        $list = $data['translits'] ?? [];
        if (!$list && $translit !== '') $list = [['word_translit_text' => $translit, 'preferred' => true]];
        $pref = saveTranslits($db, $wordKey, $list, $translit);
        if ($pref !== null) {
            $db->prepare('UPDATE yy_word SET word_translit = ? WHERE word_key = ?')->execute([$pref, $wordKey]);
        }
        applyExplicitYt($db, $wordKey, $data);
        applyDefaultLanguage($db, $wordKey);
        if (array_key_exists('word_pos_keys', $data) && is_array($data['word_pos_keys'])) {
            savePartsOfSpeech($db, $wordKey, $data['word_pos_keys']);
        }
        // Definitions supplied on create go in as rows too, and the default is
        // mirrored back over whatever the word_definition_* column was seeded with.
        if (array_key_exists('definitions', $data) && is_array($data['definitions'])) {
            $def = saveDefinitions($db, $wordKey, $data['definitions']);
            if ($def['source_key'] > 0) {
                $sc = $db->prepare('SELECT word_source_code FROM yy_word_source WHERE word_source_key = ?');
                $sc->execute([$def['source_key']]);
                $srcCode = $sc->fetchColumn() ?: null;
                if ($srcCode !== null && isset(DEFAULT_DEF_COLS[$srcCode])) {
                    $db->prepare('UPDATE yy_word SET ' . DEFAULT_DEF_COLS[$srcCode] . ' = ? WHERE word_key = ?')
                       ->execute([$def['text'], $wordKey]);
                }
            }
        }
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        errorResponse('Failed to create word: ' . $e->getMessage(), 500);
    }

    jsonResponse(['saved' => true, 'word_key' => $wordKey], 201);
}

if ($method === 'PUT' && $key) {
    setCurrentUser($db, $user['user_key']);
    $data = lexBody();

    $exists = $db->prepare(
        'SELECT word_translit, word_source_code, word_yy_copy_key FROM yy_word WHERE word_key = ?'
    );
    $exists->execute([$key]);
    $current = $exists->fetch();
    if (!$current) errorResponse('Word not found', 404);

    /* Copy-on-write: a word from an outside source is never edited in place.
       The first edit forks it to a YY copy and the original keeps a link; later
       edits are routed to that copy, so a word forks exactly once. Everything
       below then runs against $key unchanged — the copy is just another word. */
    $forkedFrom = null;
    if (trim((string)$current['word_source_code']) !== 'yy') {
        $forkedFrom = $key;
        if (!empty($current['word_yy_copy_key'])) {
            $key = (int)$current['word_yy_copy_key'];
        } else {
            $db->beginTransaction();
            try {
                $key = forkWordToYy($db, $key);
                $db->commit();
            } catch (\Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                errorResponse('Could not create an editable copy: ' . $e->getMessage(), 500);
            }
        }
        $exists->execute([$key]);
        $current = $exists->fetch();
        if (!$current) errorResponse('Editable copy not found', 404);

        /* The panel still had the ORIGINAL's source selected when it hit Save, so
           letting that through would push the copy straight back to 'perry'. The
           copy is YY by definition. */
        $data['word_source_code'] = 'yy';
    }

    // Partial update — only columns present in the body are touched, so a caller
    // that sends one field never blanks the rest.
    $allowed = [
        'word_strongs'             => 'strongs',
        'word_hebrew'              => 'text',
        'word_translit'            => 'text',
        'word_definition_yy'       => 'text',
        'word_definition_kirk'     => 'text',
        'word_definition_external' => 'text',
        'word_definition_perry'    => 'text',
        'word_source_code'         => 'text',
        // word_count_yy is deliberately absent: it is DERIVED from
        // yy_word_occurrence by _word_harvest.php (direct UPDATE), so a PUT
        // must not be able to overwrite a harvested count with a typed one.
        'word_active_flag'         => 'bool',
        // Pronunciations: Strong's / YY respellings, IPA, and the engine
        // respelling the TTS Phonetic channel uses.
        'word_pronunciation_strongs'  => 'text',
        'word_pronunciation_yy'       => 'text',
        'word_pronunciation_ipa'      => 'text',
        'word_pronunciation_phonetic' => 'text',
        // Nullable FK: '' and 0 both mean "unknown", i.e. store NULL.
        'word_gender_key'             => 'fkey',
        // char(1) FK into yy_word_language; '' means "not classified" -> NULL.
        'word_language'               => 'text',
    ];

    // The editor sends both word_translit and the full translits list on every
    // save. Assigning the column from both builds "SET word_translit = ?, …,
    // word_translit = ?", which Postgres rejects outright:
    //   ERROR: multiple assignments to same column "word_translit"
    // The spellings list wins, as the block below already intended, so drop the
    // scalar here rather than letting it reach the SET clause.
    $translitsSupplied = array_key_exists('translits', $data) && is_array($data['translits']);
    if ($translitsSupplied) unset($allowed['word_translit']);

    // Same collision for definitions: the default definition is mirrored into
    // its source's word_definition_* column below, so a scalar of the same name
    // sent alongside the list would assign that column twice. The list wins.
    $defsSupplied = array_key_exists('definitions', $data) && is_array($data['definitions']);
    if ($defsSupplied) {
        foreach (DEFAULT_DEF_COLS as $ddCol) unset($allowed[$ddCol]);
    }

    $db->beginTransaction();
    try {
        $fields = [];
        $params = [];
        foreach ($allowed as $col => $type) {
            if (!array_key_exists($col, $data)) continue;
            $val = $data[$col];
            if ($type === 'strongs') {
                /* Three states, and normalizeStrongs() only knows two: it maps
                   '' to null, which would lose the difference between "not yet
                   determined" (NULL) and "has none" (''). So handle both empties
                   here and only hand it a value that should parse. */
                if ($val === null) {
                    $params[] = null;                    // unknown
                } elseif (trim((string)$val) === '') {
                    $params[] = '';                      // confirmed: no Strong's
                } else {
                    $s = normalizeStrongs($val);
                    if ($s === false) {
                        $db->rollBack();
                        errorResponse("Strong's must be 1-4 digits, optionally with a G/H language letter and a trailing letter (e.g. 430, H0430, H0430a).");
                    }
                    $params[] = $s;
                }
            } elseif ($type === 'fkey') {
                $params[] = ($val === '' || $val === null || (int)$val === 0) ? null : (int)$val;
            } elseif ($type === 'int') {
                $params[] = ($val === '' || $val === null) ? null : (int)$val;
            } elseif ($type === 'bool') {
                $params[] = (int)(bool)$val;
            } else {
                $t = trim((string)$val);
                $params[] = $t !== '' ? $t : null;
            }
            $fields[] = "$col = ?";
        }

        // Spellings are replaced wholesale when supplied; the preferred one wins
        // over any word_translit sent alongside it (unset from $allowed above).
        if ($translitsSupplied) {
            $pref = saveTranslits($db, $key, $data['translits'], $current['word_translit']);
            $fields[] = 'word_translit = ?';
            $params[] = $pref;
        }

        /* Definitions are replaced as a set, then the default's text is mirrored
           into that source's column so the public glossary keeps reading what it
           always has. A default from a source with no column (books) mirrors
           nowhere, which is correct — there is no column to hold it. */
        if ($defsSupplied) {
            $def = saveDefinitions($db, $key, $data['definitions']);
            $srcCode = null;
            if ($def['source_key'] > 0) {
                $sc = $db->prepare('SELECT word_source_code FROM yy_word_source WHERE word_source_key = ?');
                $sc->execute([$def['source_key']]);
                $srcCode = $sc->fetchColumn() ?: null;
            }
            if ($srcCode !== null && isset(DEFAULT_DEF_COLS[$srcCode])) {
                $fields[] = DEFAULT_DEF_COLS[$srcCode] . ' = ?';
                $params[] = $def['text'];
            }
        }

        // A word_yt on its own is a real edit even though it is not in $allowed —
        // it is applied below, after the trigger has had its say.
        if (!$fields && !$defsSupplied
                     && !array_key_exists('word_yt', $data)
                     && !array_key_exists('word_pos_keys', $data)) {
            $db->rollBack();
            errorResponse('Nothing to update');
        }

        if ($fields) {
            $params[] = $key;
            $db->prepare('UPDATE yy_word SET ' . implode(', ', $fields) . ' WHERE word_key = ?')->execute($params);
        }
        applyExplicitYt($db, $key, $data);
        applyDefaultLanguage($db, $key);
        if (array_key_exists('word_pos_keys', $data) && is_array($data['word_pos_keys'])) {
            savePartsOfSpeech($db, $key, $data['word_pos_keys']);
        }
        $db->commit();
    } catch (\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Failed to save word: ' . $e->getMessage(), 500);
    }

    // word_key is the row the edit actually landed on — the YY copy when the
    // request targeted an outside-source word, so the editor can follow it.
    jsonResponse(['saved' => true, 'word_key' => $key, 'forked_from' => $forkedFrom]);
}

if ($method === 'DELETE' && $key) {
    setCurrentUser($db, $user['user_key']);
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM yy_word_definition WHERE word_key = ?')->execute([$key]);
        $db->prepare('DELETE FROM yy_word_translit   WHERE word_key = ?')->execute([$key]);
        $db->prepare('DELETE FROM yy_word_pos_map    WHERE word_key = ?')->execute([$key]);
        $db->prepare('DELETE FROM yy_word_twot       WHERE word_key = ?')->execute([$key]);
        $db->prepare('DELETE FROM yy_word_translation WHERE word_key = ?')->execute([$key]);
        $db->prepare('UPDATE yy_word_import SET word_key = NULL WHERE word_key = ?')->execute([$key]);
        $stmt = $db->prepare('DELETE FROM yy_word WHERE word_key = ?');
        $stmt->execute([$key]);
        if (!$stmt->rowCount()) { $db->rollBack(); errorResponse('Word not found', 404); }
        $db->commit();
    } catch (\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Failed to delete word: ' . $e->getMessage(), 500);
    }
    jsonResponse(['deleted' => true]);
}

errorResponse('Invalid request', 400);
