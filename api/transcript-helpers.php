<?php
/**
 * Shared helpers for transcript editing flows. Used by:
 *   - admin-transcript.php       (manual per-row save → autoLearn)
 *   - admin-transcript-replace.php (bulk find/replace → autoLearn in regex mode)
 */

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
