<?php
/**
 * yy_word_strongs — the Strong's entries of a word (many per word).
 *
 * Shared by admin-glossary-lexicon.php (the Glossary editor), words.php (the
 * Word admin) and _perry_import.php, so there is one place that knows how a
 * word's Strong's rows and its mirror columns stay in step.
 *
 *   yy_word.word_strongs_key   the preferred row (composite FK: must be this word's)
 *   yy_word.word_strongs       MIRROR of the preferred row's code, read by the
 *                              public glossary, words.php, word-lookup.php and
 *                              the list sort. With no rows it keeps its old
 *                              meaning: NULL = not yet determined, '' = the word
 *                              has no Strong's entry.
 *
 * word_strongs_code is derived by the trg_word_strongs_code DB trigger from
 * language + number + suffix; nothing here writes it directly except as a
 * placeholder the trigger overwrites. Schema: sql/word_strongs.sql.
 */

/** Part-of-speech booleans on yy_word_strongs, in display order. */
const WORD_STRONGS_POS = [
    'word_strongs_pos_noun'                     => 'Noun',
    'word_strongs_pos_noun_proper'              => 'Proper noun',
    'word_strongs_pos_verb'                     => 'Verb',
    'word_strongs_pos_infinitive'               => 'Infinitive',
    'word_strongs_pos_participle'               => 'Participle',
    'word_strongs_pos_participle_interrogative' => 'Interrogative participle',
    'word_strongs_pos_participle_relative'      => 'Relative participle',
    'word_strongs_pos_adjective'                => 'Adjective',
    'word_strongs_pos_adverb'                   => 'Adverb',
    'word_strongs_pos_pronoun'                  => 'Pronoun',
    'word_strongs_pos_pronoun_demonstrative'    => 'Demonstrative pronoun',
    'word_strongs_pos_pronoun_indefinite'       => 'Indefinite pronoun',
    'word_strongs_pos_pronoun_interrogative'    => 'Interrogative pronoun',
    'word_strongs_pos_pronoun_personal'         => 'Personal pronoun',
    'word_strongs_pos_pronoun_relative'         => 'Relative pronoun',
    'word_strongs_pos_preposition'              => 'Preposition',
    'word_strongs_pos_conjunction'              => 'Conjunction',
    'word_strongs_pos_article'                  => 'Article',
    'word_strongs_pos_particle'                 => 'Particle',
    'word_strongs_pos_interjection'             => 'Interjection',
    'word_strongs_pos_substantive'              => 'Substantive',
    'word_strongs_pos_gentilic'                 => 'Gentilic',
    'word_strongs_pos_patronymic'               => 'Patronymic',
];

/** word_strongs_gender codes (CHECK constraint on the column). */
const WORD_STRONGS_GENDERS = [
    'm' => 'Masculine',
    'f' => 'Feminine',
    'n' => 'Neuter',
    'x' => 'Not applicable',
];

/** Text columns and their varchar limits (null = text, unlimited). */
const WORD_STRONGS_TEXT = [
    'word_strongs_original'      => 64,
    'word_strongs_translit'      => 64,
    'word_strongs_pronunciation' => 100,
    'word_strongs_phonetic'      => 100,
    'word_strongs_definition'    => null,
    'word_strongs_origin'        => 64,
];

/** A word's Strong's rows, the preferred one first. */
function wordStrongsRows(PDO $db, int $wordKey): array {
    $st = $db->prepare(
        'SELECT s.*
           FROM yy_word_strongs s
           JOIN yy_word w ON w.word_key = s.word_key
          WHERE s.word_key = ?
          ORDER BY (s.word_strongs_key = w.word_strongs_key) DESC NULLS LAST,
                   s.word_strongs_key'
    );
    $st->execute([$wordKey]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['word_strongs_key']    = (int)$r['word_strongs_key'];
        $r['word_strongs_number'] = (int)$r['word_strongs_number'];
        foreach (array_keys(WORD_STRONGS_POS) as $c) {
            $r[$c] = ($r[$c] === true || $r[$c] === 't' || $r[$c] === 1 || $r[$c] === '1');
        }
    }
    unset($r);
    return $rows;
}

/**
 * Point the word at its preferred row and mirror that row's code into
 * yy_word.word_strongs. With no preferred row the mirror takes $empty — NULL
 * (not yet determined) or '' (has no Strong's entry).
 *
 * Only writes when something differs: every UPDATE on yy_word adds a history row.
 */
function setWordStrongsPreferred(PDO $db, int $wordKey, ?int $prefKey, ?string $empty = null): ?string {
    $code = $empty;
    if ($prefKey) {
        $c = $db->prepare('SELECT word_strongs_code FROM yy_word_strongs WHERE word_strongs_key = ? AND word_key = ?');
        $c->execute([$prefKey, $wordKey]);
        $found = $c->fetchColumn();
        if ($found === false) { $prefKey = null; } else { $code = $found; }
    }
    $db->prepare(
        'UPDATE yy_word SET word_strongs_key = ?, word_strongs = ?
          WHERE word_key = ?
            AND (word_strongs_key, word_strongs) IS DISTINCT FROM (?::int, ?::varchar)'
    )->execute([$prefKey, $code, $wordKey, $prefKey, $code]);
    return $code;
}

/**
 * Validate one row from the editor into column => value. Throws
 * InvalidArgumentException with a message fit for the user.
 */
function wordStrongsClean(array $r, int $n): array {
    $label = "Strong's entry $n";
    $lang = strtoupper(trim((string)($r['word_strongs_language'] ?? '')));
    if ($lang !== 'H' && $lang !== 'G') {
        throw new InvalidArgumentException("$label: pick H (Hebrew) or G (Greek).");
    }
    $num = trim((string)($r['word_strongs_number'] ?? ''));
    if (!preg_match('/^[0-9]{1,4}$/', $num) || (int)$num < 1) {
        throw new InvalidArgumentException("$label: the number must be 1–4 digits.");
    }
    $suffix = strtolower(trim((string)($r['word_strongs_suffix'] ?? '')));
    if ($suffix !== '' && !preg_match('/^[a-z]$/', $suffix)) {
        throw new InvalidArgumentException("$label: the suffix must be a single letter (a, b, c…).");
    }
    $gender = strtolower(trim((string)($r['word_strongs_gender'] ?? '')));
    if ($gender !== '' && !isset(WORD_STRONGS_GENDERS[$gender])) {
        throw new InvalidArgumentException("$label: unknown gender '$gender'.");
    }

    $out = [
        'word_strongs_language' => $lang,
        'word_strongs_number'   => (int)$num,
        'word_strongs_suffix'   => $suffix !== '' ? $suffix : null,
        'word_strongs_gender'   => $gender !== '' ? $gender : null,
    ];
    foreach (WORD_STRONGS_TEXT as $col => $max) {
        $v = trim((string)($r[$col] ?? ''));
        if ($max !== null && mb_strlen($v) > $max) {
            throw new InvalidArgumentException("$label: " . str_replace('word_strongs_', '', $col)
                . " is limited to $max characters.");
        }
        $out[$col] = $v !== '' ? $v : null;
    }
    // PDO would bind PHP false as '' which Postgres rejects for boolean.
    foreach (array_keys(WORD_STRONGS_POS) as $col) {
        $out[$col] = (int)!empty($r[$col]);
    }
    return $out;
}

/**
 * Replace a word's Strong's entries with $rows and set the preferred one.
 *
 * Rows carrying a word_strongs_key of this word are UPDATEd in place (and only
 * when a value actually changed, so an unchanged save writes no history); rows
 * without one are inserted; this word's rows not sent are deleted. The preferred
 * row is the one flagged `preferred`, else the first.
 *
 * $empty is what word_strongs holds when no rows remain: 'none' → '' (has no
 * Strong's entry), anything else → NULL (not yet determined).
 *
 * Returns the mirrored code. Must run inside the caller's transaction.
 */
function saveWordStrongs(PDO $db, int $wordKey, array $rows, ?string $empty = null): ?string {
    $clean = [];
    $seen  = [];
    $n = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $n++;
        $c = wordStrongsClean($r, $n);
        $code = $c['word_strongs_language'] . str_pad((string)$c['word_strongs_number'], 4, '0', STR_PAD_LEFT)
              . ($c['word_strongs_suffix'] ?? '');
        if (isset($seen[$code])) {
            throw new InvalidArgumentException("$code is listed twice.");
        }
        $seen[$code] = true;
        $clean[] = ['key' => (int)($r['word_strongs_key'] ?? 0), 'preferred' => !empty($r['preferred']),
                    'cols' => $c];
    }

    $ex = $db->prepare('SELECT word_strongs_key FROM yy_word_strongs WHERE word_key = ?');
    $ex->execute([$wordKey]);
    $existing = array_map('intval', array_column($ex->fetchAll(), 'word_strongs_key'));

    // Deletes first, so a code moved from a dropped row to another row cannot
    // trip the (word_key, code) unique constraint.
    $keepKeys = array_filter(array_column($clean, 'key'), function ($k) use ($existing) {
        return in_array($k, $existing, true);
    });
    $drop = array_values(array_diff($existing, $keepKeys));
    if ($drop) {
        $in = implode(',', array_fill(0, count($drop), '?'));
        $db->prepare("DELETE FROM yy_word_strongs WHERE word_key = ? AND word_strongs_key IN ($in)")
           ->execute(array_merge([$wordKey], $drop));
    }

    $cols = array_keys($clean ? $clean[0]['cols'] : []);
    $prefKey = $firstKey = null;
    foreach ($clean as $row) {
        $vals = array_values($row['cols']);
        if ($row['key'] > 0 && in_array($row['key'], $existing, true)) {
            $k = $row['key'];
            $set  = implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols));
            $diff = '(' . implode(', ', $cols) . ') IS DISTINCT FROM ('
                  . implode(', ', array_map(function ($c) {
                        return strpos($c, 'word_strongs_pos_') === 0 ? '?::int::boolean' : '?';
                    }, $cols)) . ')';
            $db->prepare("UPDATE yy_word_strongs SET $set WHERE word_strongs_key = ? AND word_key = ? AND $diff")
               ->execute(array_merge($vals, [$k, $wordKey], $vals));
        } else {
            $ins = $db->prepare(
                'INSERT INTO yy_word_strongs (word_key, word_strongs_code, ' . implode(', ', $cols) . ')
                 VALUES (?, \'\', ' . implode(', ', array_fill(0, count($cols), '?')) . ')
                 RETURNING word_strongs_key'
            );
            $ins->execute(array_merge([$wordKey], $vals));
            $k = (int)$ins->fetchColumn();
        }
        if ($prefKey === null && $row['preferred']) $prefKey = $k;
        if ($firstKey === null) $firstKey = $k;
    }
    if ($prefKey === null) $prefKey = $firstKey;

    return setWordStrongsPreferred($db, $wordKey, $prefKey, $empty === 'none' ? '' : null);
}

/**
 * For writers that still set yy_word.word_strongs as a single string (the Word
 * admin, the Perry import): make that code the word's preferred Strong's row.
 *
 *   code already one of the word's rows → it becomes preferred
 *   else a preferred row exists         → that row is renumbered to the code
 *   else                                → a new row is added and preferred
 *   NULL / ''                           → the preferred row is removed; the next
 *                                         remaining row (if any) takes over, else
 *                                         the mirror keeps the NULL / ''.
 *
 * Call AFTER the caller's own write of word_strongs, inside its transaction.
 */
function syncWordStrongsFromCode(PDO $db, int $wordKey, ?string $code): void {
    $cur = $db->prepare('SELECT word_strongs_key, word_translit, word_hebrew FROM yy_word WHERE word_key = ?');
    $cur->execute([$wordKey]);
    $w = $cur->fetch();
    if (!$w) return;
    $prefKey = $w['word_strongs_key'] !== null ? (int)$w['word_strongs_key'] : null;

    if ($code === null || trim($code) === '') {
        if ($prefKey) {
            $db->prepare('DELETE FROM yy_word_strongs WHERE word_strongs_key = ? AND word_key = ?')
               ->execute([$prefKey, $wordKey]);
        }
        $next = $db->prepare('SELECT min(word_strongs_key) FROM yy_word_strongs WHERE word_key = ?');
        $next->execute([$wordKey]);
        $nk = $next->fetchColumn();
        setWordStrongsPreferred($db, $wordKey, $nk ? (int)$nk : null, $code === null ? null : '');
        return;
    }

    if (!preg_match('/^([HG])([0-9]{4})([a-z]?)$/', $code, $m)) return;   // callers normalise first
    [$lang, $num, $suffix] = [$m[1], (int)$m[2], $m[3] !== '' ? $m[3] : null];

    $has = $db->prepare('SELECT word_strongs_key FROM yy_word_strongs WHERE word_key = ? AND word_strongs_code = ?');
    $has->execute([$wordKey, $code]);
    $k = $has->fetchColumn();
    if ($k === false) {
        if ($prefKey) {
            $db->prepare('UPDATE yy_word_strongs
                             SET word_strongs_language = ?, word_strongs_number = ?, word_strongs_suffix = ?
                           WHERE word_strongs_key = ? AND word_key = ?')
               ->execute([$lang, $num, $suffix, $prefKey, $wordKey]);
            $k = $prefKey;
        } else {
            $ins = $db->prepare(
                "INSERT INTO yy_word_strongs
                    (word_key, word_strongs_code, word_strongs_language, word_strongs_number, word_strongs_suffix,
                     word_strongs_original, word_strongs_translit)
                 VALUES (?, '', ?, ?, ?, ?, ?) RETURNING word_strongs_key"
            );
            $ins->execute([$wordKey, $lang, $num, $suffix,
                           mb_substr(trim((string)$w['word_hebrew']), 0, 64) ?: null,
                           mb_substr(trim((string)$w['word_translit']), 0, 64) ?: null]);
            $k = $ins->fetchColumn();
        }
    }
    setWordStrongsPreferred($db, $wordKey, (int)$k);
}

/**
 * Copy every Strong's row of $fromKey onto $toKey, keeping which one is
 * preferred. Used by the copy-on-write fork.
 */
function copyWordStrongs(PDO $db, int $fromKey, int $toKey): void {
    $cols = 'word_strongs_number, word_strongs_language, word_strongs_suffix, '
          . implode(', ', array_keys(WORD_STRONGS_TEXT)) . ', word_strongs_gender, '
          . implode(', ', array_keys(WORD_STRONGS_POS));
    $pref = $db->prepare('SELECT word_strongs_key FROM yy_word WHERE word_key = ?');
    $pref->execute([$fromKey]);
    $fromPref = $pref->fetchColumn();

    $src = $db->prepare('SELECT word_strongs_key FROM yy_word_strongs WHERE word_key = ? ORDER BY word_strongs_key');
    $src->execute([$fromKey]);
    $ins = $db->prepare(
        "INSERT INTO yy_word_strongs (word_key, word_strongs_code, $cols)
         SELECT ?, '', $cols FROM yy_word_strongs WHERE word_strongs_key = ?
         RETURNING word_strongs_key"
    );
    $newPref = null;
    foreach ($src->fetchAll() as $r) {
        $ins->execute([$toKey, $r['word_strongs_key']]);
        $nk = (int)$ins->fetchColumn();
        if ($fromPref !== false && (int)$fromPref === (int)$r['word_strongs_key']) $newPref = $nk;
    }
    // The copy's word_strongs was copied verbatim, so only the pointer moves.
    if ($newPref) {
        $db->prepare('UPDATE yy_word SET word_strongs_key = ? WHERE word_key = ?')->execute([$newPref, $toKey]);
    }
}
