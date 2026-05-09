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
