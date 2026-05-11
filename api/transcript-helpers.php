<?php
/**
 * Shared helpers for transcript editing flows. Used by:
 *   - admin-transcript.php       (manual per-row save → autoLearn)
 *   - admin-transcript-replace.php (bulk find/replace → autoLearn in regex mode)
 *   - and the feed_item link helpers below
 */

/**
 * Return all feed_item_keys linked to $itemKey via yy_feed_item_link, EXCLUDING
 * $itemKey itself. By default includes pending links (confirmed_flag IS NULL)
 * AND confirmed links; explicitly-denied links (FALSE) are always excluded.
 *
 * Pass $confirmedOnly=true for callers that want strict membership only.
 *
 * Always returns an array of ints (possibly empty). Stable order (ascending).
 */
function getLinkedFeedItemKeys(PDO $db, int $itemKey, bool $confirmedOnly = false): array {
    $confirmFilter = $confirmedOnly
        ? "AND feed_item_link_confirmed_flag = TRUE"
        : "AND feed_item_link_confirmed_flag IS DISTINCT FROM FALSE";
    $stmt = $db->prepare("
        SELECT feed_item_key_b AS k FROM yy_feed_item_link
         WHERE feed_item_key_a = ? $confirmFilter
        UNION
        SELECT feed_item_key_a AS k FROM yy_feed_item_link
         WHERE feed_item_key_b = ? $confirmFilter
        ORDER BY k
    ");
    $stmt->execute([$itemKey, $itemKey]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[] = (int)$r['k'];
    return $out;
}

/**
 * Return [$itemKey, ...linked] for use directly in a `feed_item_key IN (...)` clause.
 * Convenience wrapper around getLinkedFeedItemKeys() that includes the source key.
 */
function getFeedItemKeyCluster(PDO $db, int $itemKey, bool $confirmedOnly = false): array {
    $linked = getLinkedFeedItemKeys($db, $itemKey, $confirmedOnly);
    array_unshift($linked, $itemKey);
    return array_values(array_unique($linked));
}

/**
 * Detect single-token substitutions between $oldText and $newText and
 * insert/bump rows in yy_transcript_correction so future fresh transcripts
 * benefit from the same fix.
 *
 * Skips:
 *  - structural changes (token-count mismatch — likely sentence rewrites, not corrections)
 *  - punctuation-only changes
 *  - very short (<2 chars) or very long (>60 chars) tokens
 *  - pure case changes (e.g. "yah" → "Yah")
 */
function autoLearnCorrections(PDO $db, string $oldText, string $newText): void {
    if ($oldText === $newText) return;

    $oldWords = preg_split('/(\s+)/u', $oldText, -1, PREG_SPLIT_DELIM_CAPTURE);
    $newWords = preg_split('/(\s+)/u', $newText, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (count($oldWords) !== count($newWords)) return; // structural change, skip

    $upsert = $db->prepare("
        INSERT INTO yy_transcript_correction (correction_wrong, correction_right)
        VALUES (?, ?)
        ON CONFLICT (correction_wrong, correction_right) DO UPDATE
            SET correction_count = yy_transcript_correction.correction_count + 1,
                correction_last_seen_dtime = NOW(),
                correction_active_flag = TRUE
    ");

    for ($i = 0; $i < count($oldWords); $i++) {
        if (preg_match('/^\s*$/', $oldWords[$i])) continue; // whitespace tokens
        $a = trim($oldWords[$i], " \t\n\r.,;:!?\"'()[]");
        $b = trim($newWords[$i], " \t\n\r.,;:!?\"'()[]");
        if ($a === '' || $b === '' || $a === $b) continue;
        if (mb_strlen($a) < 2 || mb_strlen($b) < 2) continue;
        if (mb_strlen($a) > 60 || mb_strlen($b) > 60) continue;
        // Skip pure case changes — capitalization preferences clutter the dictionary
        if (mb_strtolower($a) === mb_strtolower($b)) continue;
        $upsert->execute([$a, $b]);
    }
}

/**
 * Apply active corrections from yy_transcript_correction to a single text
 * segment. Higher correction_count entries win when multiple match
 * (most-corrected first). Originally lived inside admin-transcript.php;
 * lifted to this shared file so the transcript-worker (which writes
 * Whisper output) can also produce the "auto-fix" snapshot at run time.
 *
 * Cache is per-request: the worker processes one job per process so
 * caching across calls is fine; admin-transcript.php endpoints are also
 * single-request scoped.
 */
function applyCorrectionDictionary(PDO $db, string $text): string {
    static $cache = null;
    if ($cache === null) {
        $stmt = $db->query("SELECT correction_wrong, correction_right, correction_case_sensitive, correction_word_boundary FROM yy_transcript_correction WHERE correction_active_flag = TRUE ORDER BY correction_count DESC, length(correction_wrong) DESC");
        $cache = $stmt->fetchAll();
    }
    foreach ($cache as $c) {
        $wrong = $c['correction_wrong'];
        $right = $c['correction_right'];
        $flags = $c['correction_case_sensitive'] ? '' : 'i';
        if ($c['correction_word_boundary']) {
            $pattern = '/\b' . preg_quote($wrong, '/') . '\b/u' . $flags;
        } else {
            $pattern = '/' . preg_quote($wrong, '/') . '/u' . $flags;
        }
        $text = preg_replace($pattern, $right, $text);
    }
    return $text;
}

/**
 * Apply one find/replace pair to a single string.
 * Pair shape: ['wrong', 'right', 'case_sensitive', 'word_boundary', 'is_regex']
 */
function applyOneReplacement(string $text, array $rep): string {
    $wrong = (string)($rep['wrong'] ?? '');
    $right = (string)($rep['right'] ?? '');
    if ($wrong === '') return $text;
    $flags = empty($rep['case_sensitive']) ? 'i' : '';
    if (!empty($rep['is_regex'])) {
        // User-authored regex — slash-escape only the delimiter.
        $pattern = '/' . str_replace('/', '\\/', $wrong) . '/u' . $flags;
    } else {
        $escaped = preg_quote($wrong, '/');
        $pattern = !empty($rep['word_boundary'])
            ? '/\b' . $escaped . '\b/u' . $flags
            : '/' . $escaped . '/u' . $flags;
    }
    $result = @preg_replace($pattern, $right, $text);
    return $result === null ? $text : $result;
}

/**
 * Apply a set of find/replace pairs to a sequence of transcript rows, with
 * matches that span row boundaries collapsing the affected rows together.
 *
 * Two passes:
 *   1. Single-row pass — every replacement is applied to each row in
 *      isolation (fast path; catches every match that fits inside one row).
 *   2. Multi-row pass — for replacements whose `wrong` pattern could match
 *      across a space (literal wrongs that contain whitespace, or any
 *      regex), walk consecutive rows in windows up to $maxRowSpan and try
 *      the replacement against the space-joined window. If it changes the
 *      window, all $span rows collapse into one row at the FIRST row's
 *      segment timestamp.
 *
 * Returns a new array (possibly fewer rows than the input).
 *
 * $rows item shape: ['text' => string, 'segment' => string, 'sort' => int?]
 *
 * The $maxRowSpan default of 6 covers practical multi-word phrases (e.g.
 * "Yada Yahda" across two words, up to 5-6 word phrases). Larger windows
 * are unusual and cost more time at scan time.
 */
function applyReplacementsAcrossRows(array $rows, array $replacements, int $maxRowSpan = 6): array {
    if (!$rows || !$replacements) return $rows;

    // Pass 1: per-row substitutions (fast path, no merging).
    foreach ($rows as &$r) {
        foreach ($replacements as $rep) {
            $r['text'] = applyOneReplacement((string)$r['text'], $rep);
        }
    }
    unset($r);

    // Identify replacements that could span row boundaries.
    $spanningReps = array_values(array_filter($replacements, function($rep) {
        if (!empty($rep['is_regex'])) return true;            // any regex — conservatively eligible
        $w = (string)($rep['wrong'] ?? '');
        return $w !== '' && preg_match('/\s/u', $w) === 1;     // literal with whitespace
    }));
    if (!$spanningReps) return array_values($rows);

    // Pass 2: cross-row scan. Walk linearly; at each row try the largest
    // possible window first (so a longer match wins over a shorter false
    // positive within the same window).
    $result = [];
    $i = 0;
    $n = count($rows);
    while ($i < $n) {
        $merged = false;
        $maxSpan = min($maxRowSpan, $n - $i);
        for ($span = $maxSpan; $span >= 2 && !$merged; $span--) {
            $window = (string)$rows[$i]['text'];
            for ($k = 1; $k < $span; $k++) {
                $window .= ' ' . (string)$rows[$i + $k]['text'];
            }
            $applied = $window;
            foreach ($spanningReps as $rep) {
                $applied = applyOneReplacement($applied, $rep);
            }
            if ($applied !== $window) {
                $result[] = [
                    'segment' => $rows[$i]['segment'],
                    'sort'    => $rows[$i]['sort'] ?? null,
                    'text'    => $applied,
                ];
                $i += $span;
                $merged = true;
            }
        }
        if (!$merged) {
            $result[] = $rows[$i];
            $i++;
        }
    }
    return $result;
}

/**
 * Apply yy_transcript_correction to a row sequence, with cross-row merging.
 * Loads the active correction list once per call (no per-request cache;
 * the worker invokes this once per item).
 */
function applyCorrectionsAcrossRows(PDO $db, array $rows): array {
    if (!$rows) return $rows;
    $stmt = $db->query("SELECT correction_wrong, correction_right, correction_case_sensitive, correction_word_boundary FROM yy_transcript_correction WHERE correction_active_flag = TRUE ORDER BY correction_count DESC, length(correction_wrong) DESC");
    $corrections = $stmt->fetchAll();
    if (!$corrections) return $rows;
    $replacements = [];
    foreach ($corrections as $c) {
        $replacements[] = [
            'wrong'          => $c['correction_wrong'],
            'right'          => $c['correction_right'],
            'case_sensitive' => (bool)$c['correction_case_sensitive'],
            'word_boundary'  => (bool)$c['correction_word_boundary'],
            'is_regex'       => false,
        ];
    }
    return applyReplacementsAcrossRows($rows, $replacements);
}
