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

/**
 * Build the 'whisper-1-word-join' _auto rows for a feed_item from existing
 * whisper-1-word + reference-model (default 'youtube') _auto data. The
 * resulting rows are per-phrase (not per-word) but keep whisper-1-word's
 * word-precise start timestamps, with punctuation copied off the matched
 * reference tokens. Returns the number of rows written (0 if the inputs
 * are missing).
 *
 * Algorithm: for each reference row at time T with next ref at T', restrict
 * the candidate whisper words to those with start times in
 *   [T - SLACK_BEFORE, T' + SLACK_AFTER]
 * Then greedy-match the reference row's tokens in order. The claimed range
 * is [first matched whisper idx .. last matched]. The first match gets the
 * row's segment timestamp. See build_whisper_word_join.php for the CLI
 * wrapper.
 */
function buildWhisperWordJoin(PDO $db, int $itemKey, string $refModel = 'youtube'): int {
    $loadAuto = function (string $model) use ($db, $itemKey): array {
        $st = $db->prepare("
            SELECT feed_item_transcript_segment::text AS segment,
                   feed_item_transcript_text          AS text,
                   feed_item_transcript_sort          AS sort
              FROM yy_feed_item_transcript_auto
             WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?
             ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
        ");
        $st->execute([$itemKey, $model]);
        return $st->fetchAll();
    };
    $wordRows = $loadAuto('whisper-1-word');
    $refRows  = $loadAuto($refModel);
    if (!$wordRows || !$refRows) return 0;

    $norm = function (string $s): string {
        return trim(mb_strtolower($s), " \t\n\r.,;:!?\"()[]<>");
    };
    $trailPunct = function (string $s): string {
        return preg_match('/([.,;:!?]+)$/u', $s, $m) ? $m[1] : '';
    };
    $secs = function (string $hms): float {
        if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $hms, $m)) {
            return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (float)$m[3];
        }
        return 0.0;
    };

    $times = array_map(function ($r) use ($secs) { return $secs((string)$r['segment']); }, $wordRows);
    $nw = count($wordRows);
    $firstIdxAtOrAfter = function (float $T, int $startIdx) use ($times, $nw): int {
        for ($i = $startIdx; $i < $nw; $i++) if ($times[$i] >= $T) return $i;
        return $nw;
    };
    $nr = count($refRows);

    // ── Phase 1: pick an anchor whisper index for each reference row ──
    // The anchor is the FIRST whisper word that "belongs" to that ref row.
    // Prefer content alignment: scan the ref row's tokens in order and look
    // for the first one that matches a nearby whisper word (within a time
    // window around the ref row's timestamp). If no content match exists,
    // fall back to the first whisper word at or after the ref row's start
    // time. Anchors are strictly increasing so neighbouring ref rows
    // partition the whisper word stream cleanly.
    //
    // This is "fuzzy" by design — when whisper and youtube disagree on a
    // word, the row's boundary still gets set; only the OUTPUT TEXT comes
    // from whisper. The goal is one join row per ref row, not perfect
    // token-level alignment.
    $ANCHOR_TIME_SLACK = 4.0;    // ± seconds either side of ref T to search
    $anchors = array_fill(0, $nr, -1);
    $prevAnchor = -1;
    foreach ($refRows as $yi => $refRow) {
        if (!preg_match_all('/\S+/u', (string)$refRow['text'], $mm)) continue;
        $refTokens = $mm[0];
        $T = $secs((string)$refRow['segment']);
        $minIdx = $prevAnchor + 1;
        // Search up to (ref T + slack) so the anchor doesn't wander into the
        // next ref row's territory. The next ref row will set its own anchor.
        $maxIdx = $firstIdxAtOrAfter($T + $ANCHOR_TIME_SLACK, $minIdx);
        if ($maxIdx <= $minIdx) {
            // No whisper words in this time window; fall back to the very
            // next whisper word (if any).
            $maxIdx = min($nw, $minIdx + 1);
        }

        // Try content match first — first ref token that matches any
        // whisper word in [minIdx, maxIdx).
        $anchor = -1;
        foreach ($refTokens as $tok) {
            $rn = $norm($tok);
            if ($rn === '') continue;
            for ($i = $minIdx; $i < $maxIdx; $i++) {
                if ($norm((string)$wordRows[$i]['text']) === $rn) {
                    $anchor = $i;
                    break 2;
                }
            }
        }
        // Time fallback: first whisper word at or after the ref row's start.
        if ($anchor < 0) {
            $tIdx = $firstIdxAtOrAfter($T, $minIdx);
            if ($tIdx < $nw) $anchor = $tIdx;
        }
        if ($anchor < 0) break;  // past end of whisper — remaining ref rows have no words
        // First ref row's anchor pulled to whisper[0] so no leading words
        // get orphaned.
        if ($yi === 0 && $anchor > 0) $anchor = 0;

        $anchors[$yi] = $anchor;
        $prevAnchor = $anchor;
    }

    // ── Phase 2: each ref row's group = [anchor_i .. next_anchor - 1] ──
    // Stitch whisper words back together with punctuation transferred from
    // matched ref tokens.
    $result = [];
    for ($yi = 0; $yi < $nr; $yi++) {
        $start = $anchors[$yi];
        if ($start < 0) continue;
        // Find the next defined anchor strictly greater than $start
        $end = $nw - 1;
        for ($j = $yi + 1; $j < $nr; $j++) {
            if ($anchors[$j] > $start) { $end = $anchors[$j] - 1; break; }
        }
        if ($start > $end) continue;

        // Re-run a per-token greedy match within [start..end] purely for
        // punctuation transfer onto matched whisper words.
        preg_match_all('/\S+/u', (string)$refRows[$yi]['text'], $mm);
        $refTokens = $mm[0] ?? [];
        $alignMap = [];
        $localWs = $start;
        foreach ($refTokens as $rti => $tok) {
            $rn = $norm($tok);
            if ($rn === '') continue;
            for ($i = $localWs; $i <= $end; $i++) {
                if ($norm((string)$wordRows[$i]['text']) === $rn) {
                    $alignMap[$i] = $rti;
                    $localWs = $i + 1;
                    break;
                }
            }
        }

        $parts = [];
        for ($i = $start; $i <= $end; $i++) {
            $w = (string)$wordRows[$i]['text'];
            if (isset($alignMap[$i])) {
                $p = $trailPunct($refTokens[$alignMap[$i]]);
                if ($p !== '' && !preg_match('/[.,;:!?]$/u', $w)) $w .= $p;
            }
            $parts[] = $w;
        }
        $result[] = [
            'segment' => $wordRows[$start]['segment'],
            'text'    => implode(' ', $parts),
        ];
    }

    $model = 'whisper-1-word-join';
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM yy_feed_item_transcript_auto      WHERE feed_item_key = ? AND feed_item_transcript_auto_model      = ?")->execute([$itemKey, $model]);
        $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")->execute([$itemKey, $model]);
        $ins = $db->prepare("
            INSERT INTO yy_feed_item_transcript_auto
                (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_auto_model)
            VALUES (?, ?::interval, ?, ?, ?)
        ");
        $sort = 0;
        foreach ($result as $r) {
            $ins->execute([$itemKey, $r['segment'], mb_substr($r['text'], 0, 2000), $sort, $model]);
            $sort++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return 0;
    }
    return count($result);
}
