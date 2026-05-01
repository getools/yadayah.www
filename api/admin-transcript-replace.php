<?php
/**
 * Bulk search/replace across all transcript rows.
 *
 * POST {action:'preview', find, replace, case_sensitive, word_boundary, item_key?}
 *   → returns total match count + up to 50 sample rows showing old vs new
 *
 * POST {action:'apply',   find, replace, case_sensitive, word_boundary, item_key?}
 *   → runs the replacement, logs each row's diff to yy_transcript_edit_log,
 *     bumps yy_transcript_correction (auto-learn), returns affected row count
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // autoLearnCorrections()
$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']); // for revision triggers
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') errorResponse('POST only', 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$find = (string)($data['find'] ?? '');
$replace = (string)($data['replace'] ?? '');
$caseSensitive = !empty($data['case_sensitive']);
$wordBoundary  = !empty($data['word_boundary']);
$isRegex       = !empty($data['regex']);
$itemKey = isset($data['item_key']) && $data['item_key'] !== null
    ? (int)$data['item_key'] : null;

if ($find === '') errorResponse('find string is required');
if (mb_strlen($find) > 500) errorResponse('find string too long');
if (mb_strlen($replace) > 500) errorResponse('replace string too long');

function posixRegexEscape(string $s): string {
    return preg_replace('/([.\^\$\*\+\?\(\)\[\]\{\}\|\\\\])/', '\\\\$1', $s);
}

$flags = $caseSensitive ? 'g' : 'gi';

if ($isRegex) {
    // User-authored POSIX-extended regex. Pass through verbatim and allow
    // backreferences (\1..\9) in the replacement.
    $pattern = $find;
    $safeReplace = $replace;
    // Validate by compiling against a tiny throwaway string. Postgres raises
    // a user-friendly error message we can surface.
    try {
        $test = $db->prepare("SELECT regexp_replace('test', ?, ?, ?)");
        $test->execute([$pattern, $safeReplace, $flags]);
        $test->fetchColumn();
    } catch (Exception $e) {
        $msg = $e->getMessage();
        // Postgres prefixes errors with SQLSTATE codes — strip noise
        if (preg_match('/ERROR:\s*(.+?)(?:\n|$)/', $msg, $m)) $msg = trim($m[1]);
        errorResponse('Invalid regex: ' . $msg);
    }
} else {
    // Literal find/replace. Escape regex metacharacters in `find` and any
    // backslashes in `replace` so users typing `\1` don't accidentally invoke
    // backreferences.
    $escapedFind = posixRegexEscape($find);
    $pattern = $wordBoundary ? '\m' . $escapedFind . '\M' : $escapedFind;
    $safeReplace = str_replace('\\', '\\\\', $replace);
}

$where = "feed_item_transcript_text ~" . ($caseSensitive ? '' : '*') . " ?";
$params = [$pattern];
if ($itemKey !== null) {
    $where .= " AND feed_item_key = ?";
    $params[] = $itemKey;
}

if ($action === 'preview') {
    // Total match count
    $cnt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript WHERE $where");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $sampleStmt = $db->prepare("
        SELECT t.feed_item_transcript_key,
               t.feed_item_transcript_segment::text AS segment,
               t.feed_item_transcript_text AS old_text,
               regexp_replace(t.feed_item_transcript_text, ?, ?, ?) AS new_text,
               t.feed_item_key,
               COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title
          FROM yy_feed_item_transcript t
          JOIN yy_feed_item fi ON fi.feed_item_key = t.feed_item_key
         WHERE $where
         ORDER BY t.feed_item_key, t.feed_item_transcript_sort, t.feed_item_transcript_segment
         LIMIT 50
    ");
    $sampleStmt->execute(array_merge([$pattern, $safeReplace, $flags], $params));
    $samples = $sampleStmt->fetchAll();

    jsonResponse([
        'total' => $total,
        'samples' => $samples,
        'pattern' => $pattern,
        'flags' => $flags,
    ]);
}

if ($action === 'apply') {
    $db->beginTransaction();
    try {
        // Fetch all matching rows so we can log each diff
        $rowsStmt = $db->prepare("
            SELECT feed_item_transcript_key, feed_item_key, feed_item_transcript_segment::text AS segment,
                   feed_item_transcript_text AS old_text,
                   regexp_replace(feed_item_transcript_text, ?, ?, ?) AS new_text
              FROM yy_feed_item_transcript
             WHERE $where
        ");
        $rowsStmt->execute(array_merge([$pattern, $safeReplace, $flags], $params));
        $rows = $rowsStmt->fetchAll();

        if (!$rows) {
            $db->rollBack();
            jsonResponse(['changed' => 0, 'message' => 'No rows matched.']);
        }

        // Record the batch up front so each edit_log row can link back to it
        $batchStmt = $db->prepare("
            INSERT INTO yy_transcript_bulk_replace
                (bulk_replace_find, bulk_replace_replace, bulk_replace_case_sensitive,
                 bulk_replace_word_boundary, bulk_replace_is_regex, bulk_replace_user_key)
            VALUES (?, ?, ?, ?, ?, ?)
            RETURNING bulk_replace_key
        ");
        $batchStmt->execute([$find, $replace, $caseSensitive ? 't' : 'f',
            $wordBoundary ? 't' : 'f', $isRegex ? 't' : 'f', $user['user_key']]);
        $batchKey = (int)$batchStmt->fetchColumn();

        // Log each diff and update the row
        $logStmt = $db->prepare("
            INSERT INTO yy_transcript_edit_log
                (feed_item_key, edit_segment, edit_original_text, edit_new_text,
                 edit_action, edit_user_key, edit_batch_key)
            VALUES (?, ?::interval, ?, ?, 'bulk_replace', ?, ?)
        ");
        $updStmt = $db->prepare("
            UPDATE yy_feed_item_transcript
               SET feed_item_transcript_text = ?,
                   feed_item_transcript_revision_dtime = NOW()
             WHERE feed_item_transcript_key = ?
        ");
        $changed = 0;
        foreach ($rows as $r) {
            if ($r['old_text'] === $r['new_text']) continue;
            $newClipped = mb_substr($r['new_text'], 0, 2000);
            $logStmt->execute([
                $r['feed_item_key'], $r['segment'], $r['old_text'], $newClipped,
                $user['user_key'], $batchKey
            ]);
            $updStmt->execute([$newClipped, $r['feed_item_transcript_key']]);
            $changed++;
        }
        $db->prepare("UPDATE yy_transcript_bulk_replace SET bulk_replace_count = ? WHERE bulk_replace_key = ?")
           ->execute([$changed, $batchKey]);

        // Auto-learn into yy_transcript_correction. Two paths:
        //   - Literal find/replace: store the (find, replace) pair as-is. One row,
        //     count = number of rows changed.
        //   - Regex/wildcard: store the actual concrete substitutions per row by
        //     diffing old_text vs new_text token-by-token. Many rows possible.
        // Pure case changes are skipped in both paths.
        $autoLearned = false;
        $isCaseOnly = mb_strtolower($find) === mb_strtolower($replace);
        if ($isCaseOnly) {
            // skip — case-only doesn't belong in the dictionary
        } elseif ($isRegex) {
            // Per-row token diff captures the literal matched→replacement pairs
            foreach ($rows as $r) {
                if ($r['old_text'] === $r['new_text']) continue;
                autoLearnCorrections($db, $r['old_text'], $r['new_text']);
            }
            $autoLearned = true;
        } else {
            $db->prepare("
                INSERT INTO yy_transcript_correction
                    (correction_wrong, correction_right, correction_count,
                     correction_active_flag, correction_case_sensitive, correction_word_boundary,
                     correction_first_seen_dtime, correction_last_seen_dtime)
                VALUES (?, ?, ?, TRUE, ?, ?, NOW(), NOW())
                ON CONFLICT (correction_wrong, correction_right) DO UPDATE
                    SET correction_count = yy_transcript_correction.correction_count + EXCLUDED.correction_count,
                        correction_last_seen_dtime = NOW(),
                        correction_active_flag = TRUE
            ")->execute([$find, $replace, $changed, $caseSensitive ? 't' : 'f', $wordBoundary ? 't' : 'f']);
            $autoLearned = true;
        }

        $db->commit();
        jsonResponse([
            'changed' => $changed,
            'logged' => $changed,
            'auto_learned' => $autoLearned,
            'batch_key' => $batchKey,
            'message' => 'Updated ' . $changed . ' row(s)'
                . ($isCaseOnly ? '. (Case-only change not added to dictionary.)'
                   : ($isRegex   ? '; concrete substitutions added to correction dictionary.'
                                 : '; pattern added to correction dictionary.')),
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Apply failed: ' . $e->getMessage());
    }
}

// Undo: revert the most recent non-undone bulk replace (or a specific batch_key)
if ($action === 'undo') {
    $batchKey = isset($data['batch_key']) ? (int)$data['batch_key'] : 0;
    $db->beginTransaction();
    try {
        if ($batchKey) {
            $bStmt = $db->prepare("SELECT * FROM yy_transcript_bulk_replace WHERE bulk_replace_key = ? AND bulk_replace_undone_flag = FALSE FOR UPDATE");
            $bStmt->execute([$batchKey]);
        } else {
            $bStmt = $db->prepare("SELECT * FROM yy_transcript_bulk_replace WHERE bulk_replace_undone_flag = FALSE ORDER BY bulk_replace_dtime DESC LIMIT 1 FOR UPDATE");
            $bStmt->execute();
        }
        $batch = $bStmt->fetch();
        if (!$batch) { $db->rollBack(); jsonResponse(['ok' => false, 'message' => 'Nothing to undo.']); }

        // Revert each row to its pre-batch text, but only if the row's CURRENT
        // text still matches the batch's recorded `edit_new_text`. If a user
        // manually edited that row after the bulk replace, leave it alone.
        $reverted = $db->prepare("
            UPDATE yy_feed_item_transcript t
               SET feed_item_transcript_text = log.edit_original_text,
                   feed_item_transcript_revision_dtime = NOW()
              FROM yy_transcript_edit_log log
             WHERE log.edit_batch_key = ?
               AND t.feed_item_key = log.feed_item_key
               AND t.feed_item_transcript_segment = log.edit_segment
               AND t.feed_item_transcript_text = log.edit_new_text
            RETURNING t.feed_item_transcript_key
        ");
        $reverted->execute([$batch['bulk_replace_key']]);
        $revertedCount = $reverted->rowCount();

        $db->prepare("UPDATE yy_transcript_bulk_replace SET bulk_replace_undone_flag = TRUE, bulk_replace_undone_dtime = NOW(), bulk_replace_undone_user_key = ? WHERE bulk_replace_key = ?")
           ->execute([$user['user_key'], $batch['bulk_replace_key']]);

        $skipped = (int)$batch['bulk_replace_count'] - $revertedCount;
        $db->commit();
        jsonResponse([
            'ok' => true,
            'reverted' => $revertedCount,
            'skipped' => max(0, $skipped),
            'batch' => $batch,
            'message' => 'Reverted ' . $revertedCount . ' row(s)'
                . ($skipped > 0 ? ' (' . $skipped . ' skipped — modified after bulk replace)' : '')
                . '. The pattern "' . substr($batch['bulk_replace_find'], 0, 40) . '" → "'
                . substr($batch['bulk_replace_replace'], 0, 40) . '" can now be redone.',
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Undo failed: ' . $e->getMessage());
    }
}

// Redo: re-apply the most recent undone bulk replace (or a specific one)
if ($action === 'redo') {
    $batchKey = isset($data['batch_key']) ? (int)$data['batch_key'] : 0;
    $db->beginTransaction();
    try {
        if ($batchKey) {
            $bStmt = $db->prepare("SELECT * FROM yy_transcript_bulk_replace WHERE bulk_replace_key = ? AND bulk_replace_undone_flag = TRUE FOR UPDATE");
            $bStmt->execute([$batchKey]);
        } else {
            $bStmt = $db->prepare("SELECT * FROM yy_transcript_bulk_replace WHERE bulk_replace_undone_flag = TRUE ORDER BY bulk_replace_undone_dtime DESC LIMIT 1 FOR UPDATE");
            $bStmt->execute();
        }
        $batch = $bStmt->fetch();
        if (!$batch) { $db->rollBack(); jsonResponse(['ok' => false, 'message' => 'Nothing to redo.']); }

        // Re-apply each row: if its current text matches edit_original_text, set it back to edit_new_text.
        $reapplied = $db->prepare("
            UPDATE yy_feed_item_transcript t
               SET feed_item_transcript_text = log.edit_new_text,
                   feed_item_transcript_revision_dtime = NOW()
              FROM yy_transcript_edit_log log
             WHERE log.edit_batch_key = ?
               AND t.feed_item_key = log.feed_item_key
               AND t.feed_item_transcript_segment = log.edit_segment
               AND t.feed_item_transcript_text = log.edit_original_text
            RETURNING t.feed_item_transcript_key
        ");
        $reapplied->execute([$batch['bulk_replace_key']]);
        $reappliedCount = $reapplied->rowCount();

        $db->prepare("UPDATE yy_transcript_bulk_replace SET bulk_replace_undone_flag = FALSE, bulk_replace_undone_dtime = NULL, bulk_replace_undone_user_key = NULL WHERE bulk_replace_key = ?")
           ->execute([$batch['bulk_replace_key']]);

        $skipped = (int)$batch['bulk_replace_count'] - $reappliedCount;
        $db->commit();
        jsonResponse([
            'ok' => true,
            'reapplied' => $reappliedCount,
            'skipped' => max(0, $skipped),
            'batch' => $batch,
            'message' => 'Re-applied to ' . $reappliedCount . ' row(s)'
                . ($skipped > 0 ? ' (' . $skipped . ' skipped — diverged from original state)' : '') . '.',
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        errorResponse('Redo failed: ' . $e->getMessage());
    }
}

// History: returns recent bulk-replace operations for the modal's status pills
if ($action === 'history') {
    $stmt = $db->query("
        SELECT b.bulk_replace_key AS batch_key,
               b.bulk_replace_find AS find,
               b.bulk_replace_replace AS replace,
               b.bulk_replace_count AS count,
               b.bulk_replace_undone_flag AS undone,
               b.bulk_replace_dtime AS applied_dtime,
               b.bulk_replace_undone_dtime AS undone_dtime,
               u1.user_code AS applied_by,
               u2.user_code AS undone_by
          FROM yy_transcript_bulk_replace b
          LEFT JOIN yy_user u1 ON u1.user_key = b.bulk_replace_user_key
          LEFT JOIN yy_user u2 ON u2.user_key = b.bulk_replace_undone_user_key
         ORDER BY b.bulk_replace_dtime DESC
         LIMIT 20
    ");
    $items = $stmt->fetchAll();
    // Identify the next-undo and next-redo targets
    $nextUndo = null; $nextRedo = null;
    foreach ($items as $i) {
        if (!$i['undone'] && !$nextUndo) $nextUndo = $i;
        if ($i['undone'] && !$nextRedo) $nextRedo = $i;
    }
    jsonResponse(['items' => $items, 'next_undo' => $nextUndo, 'next_redo' => $nextRedo]);
}

errorResponse('Unknown action');
