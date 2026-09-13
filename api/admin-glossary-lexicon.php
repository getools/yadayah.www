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
 *                              added).  word_definition_yy / _kirk / _external
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
 *                         plus per-column filters: ?f_strongs= ?f_translit= ?f_hebrew=
 *                         ?f_yt= ?f_def= ?f_count= (&f_count_op=gt|lt)
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
];

/** char(4) pads with spaces — always hand the client a trimmed value. */
const WORD_COLS = "w.word_key,
     NULLIF(trim(w.word_strongs), '') AS word_strongs,
     w.word_translit, w.word_hebrew, w.word_yt,
     w.word_count_yy, w.word_source_code, w.word_active_flag,
     w.word_definition_yy, w.word_definition_kirk, w.word_definition_external,
     w.word_pronunciation_strongs, w.word_pronunciation_yy,
     w.word_pronunciation_ipa, w.word_pronunciation_phonetic, w.word_gender_key,
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
        'source_counts'    => $counts,
        'default_def_cols' => DEFAULT_DEF_COLS,
    ]);
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
                d.word_definition_active_flag,
                s.word_source_code, s.word_source_label,
                p.word_pos_label
           FROM yy_word_definition d
           LEFT JOIN yy_word_source s ON s.word_source_key = d.word_source_key
           LEFT JOIN yy_word_pos    p ON p.word_pos_key    = d.word_pos_key
          WHERE d.word_key = ?
          ORDER BY s.word_source_sort, d.word_pos_key, d.word_definition_key'
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
                     OR w.word_pronunciation_strongs ILIKE :q OR w.word_pronunciation_yy ILIKE :q
                     OR w.word_pronunciation_ipa ILIKE :q OR w.word_pronunciation_phonetic ILIKE :q
                     OR EXISTS (SELECT 1 FROM yy_word_translit s
                                 WHERE s.word_key = w.word_key AND s.word_translit_text ILIKE :q))';
        $params[':q'] = '%' . $q . '%';
    }
    if ($source !== '') {
        $where[] = 'COALESCE(trim(w.word_source_code), \'\') = :source';
        $params[':source'] = $source;
    }
    if ($letter !== '') {
        // Index by the first YT letter, matching the public glossary's letter web.
        $where[] = 'LEFT(w.word_yt, 1) = :letter';
        $params[':letter'] = $letter;
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

    $strongs = normalizeStrongs($data['word_strongs'] ?? '');
    if ($strongs === false) {
        errorResponse("Strong's must be 1-4 digits, optionally with a language letter (e.g. 430, 0430 or H0430).");
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO yy_word
                (word_source_code, word_strongs, word_hebrew, word_translit,
                 word_definition_yy, word_definition_kirk, word_definition_external,
                 word_count_yy, word_active_flag,
                 word_pronunciation_strongs, word_pronunciation_yy,
                 word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            isset($data['word_count_yy']) && $data['word_count_yy'] !== '' ? (int)$data['word_count_yy'] : null,
            (int)(array_key_exists('word_active_flag', $data) ? (bool)$data['word_active_flag'] : true),
            trim((string)($data['word_pronunciation_strongs']  ?? '')) ?: null,
            trim((string)($data['word_pronunciation_yy']       ?? '')) ?: null,
            trim((string)($data['word_pronunciation_ipa']      ?? '')) ?: null,
            trim((string)($data['word_pronunciation_phonetic'] ?? '')) ?: null,
            ((int)($data['word_gender_key'] ?? 0)) ?: null,
        ]);
        $wordKey = (int)$stmt->fetchColumn();

        $list = $data['translits'] ?? [];
        if (!$list && $translit !== '') $list = [['word_translit_text' => $translit, 'preferred' => true]];
        $pref = saveTranslits($db, $wordKey, $list, $translit);
        if ($pref !== null) {
            $db->prepare('UPDATE yy_word SET word_translit = ? WHERE word_key = ?')->execute([$pref, $wordKey]);
        }
        applyExplicitYt($db, $wordKey, $data);
        if (array_key_exists('word_pos_keys', $data) && is_array($data['word_pos_keys'])) {
            savePartsOfSpeech($db, $wordKey, $data['word_pos_keys']);
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

    $exists = $db->prepare('SELECT word_translit FROM yy_word WHERE word_key = ?');
    $exists->execute([$key]);
    $current = $exists->fetch();
    if (!$current) errorResponse('Word not found', 404);

    // Partial update — only columns present in the body are touched, so a caller
    // that sends one field never blanks the rest.
    $allowed = [
        'word_strongs'             => 'strongs',
        'word_hebrew'              => 'text',
        'word_translit'            => 'text',
        'word_definition_yy'       => 'text',
        'word_definition_kirk'     => 'text',
        'word_definition_external' => 'text',
        'word_source_code'         => 'text',
        'word_count_yy'            => 'int',
        'word_active_flag'         => 'bool',
        // Pronunciations: Strong's / YY respellings, IPA, and the engine
        // respelling the TTS Phonetic channel uses.
        'word_pronunciation_strongs'  => 'text',
        'word_pronunciation_yy'       => 'text',
        'word_pronunciation_ipa'      => 'text',
        'word_pronunciation_phonetic' => 'text',
        // Nullable FK: '' and 0 both mean "unknown", i.e. store NULL.
        'word_gender_key'             => 'fkey',
    ];

    // The editor sends both word_translit and the full translits list on every
    // save. Assigning the column from both builds "SET word_translit = ?, …,
    // word_translit = ?", which Postgres rejects outright:
    //   ERROR: multiple assignments to same column "word_translit"
    // The spellings list wins, as the block below already intended, so drop the
    // scalar here rather than letting it reach the SET clause.
    $translitsSupplied = array_key_exists('translits', $data) && is_array($data['translits']);
    if ($translitsSupplied) unset($allowed['word_translit']);

    $db->beginTransaction();
    try {
        $fields = [];
        $params = [];
        foreach ($allowed as $col => $type) {
            if (!array_key_exists($col, $data)) continue;
            $val = $data[$col];
            if ($type === 'strongs') {
                $s = normalizeStrongs($val);
                if ($s === false) {
                    $db->rollBack();
                    errorResponse("Strong's must be 1-4 digits, optionally with a language letter (e.g. 430, 0430 or H0430).");
                }
                $params[] = $s;
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

        // A word_yt on its own is a real edit even though it is not in $allowed —
        // it is applied below, after the trigger has had its say.
        if (!$fields && !array_key_exists('word_yt', $data)
                     && !array_key_exists('word_pos_keys', $data)) {
            $db->rollBack();
            errorResponse('Nothing to update');
        }

        if ($fields) {
            $params[] = $key;
            $db->prepare('UPDATE yy_word SET ' . implode(', ', $fields) . ' WHERE word_key = ?')->execute($params);
        }
        applyExplicitYt($db, $key, $data);
        if (array_key_exists('word_pos_keys', $data) && is_array($data['word_pos_keys'])) {
            savePartsOfSpeech($db, $key, $data['word_pos_keys']);
        }
        $db->commit();
    } catch (\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Failed to save word: ' . $e->getMessage(), 500);
    }

    jsonResponse(['saved' => true]);
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
