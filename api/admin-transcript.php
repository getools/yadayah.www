<?php
/**
 * Admin transcription API.
 *
 * GET ?item_key=N         — get transcript rows + active job status for an item
 * POST {action:start, item_key:N} — kick off a transcription job (background)
 * POST {action:cancel, item_key:N} — cancel running job
 * POST {action:save, item_key:N, rows:[{segment, text, sort}, ...]} — save edited transcript
 * DELETE ?item_key=N — clear transcript
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$userKey = $user['user_key'] ?? null;

function intervalToSeconds(string $interval): int {
    if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $interval, $m)) {
        return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)round((float)$m[3]);
    }
    if (is_numeric($interval)) return (int)$interval;
    return 0;
}

function secondsToInterval(int $secs): string {
    $h = (int)($secs / 3600);
    $m = (int)(($secs % 3600) / 60);
    $s = $secs % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

/**
 * Apply active corrections from yy_transcript_correction to a single text segment.
 * Higher correction_count entries win when multiple match (most-corrected first).
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
 * Detect single-word or short-phrase substitutions between $oldText and $newText
 * and either insert or bump correction_count on yy_transcript_correction.
 *
 * Heuristic: word-by-word diff; only learn from changes of <=3 words length to
 * avoid recording sentence-level rewrites.
 */
function autoLearnCorrections(PDO $db, string $oldText, string $newText): void {
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
        if (preg_match('/^\s*$/', $oldWords[$i])) continue; // skip whitespace tokens
        $a = trim($oldWords[$i], " \t\n\r.,;:!?\"'()[]");
        $b = trim($newWords[$i], " \t\n\r.,;:!?\"'()[]");
        if ($a === '' || $b === '' || $a === $b) continue;
        if (mb_strlen($a) < 2 || mb_strlen($b) < 2) continue; // skip 1-char noise
        if (mb_strlen($a) > 60 || mb_strlen($b) > 60) continue; // skip long fragments
        $upsert->execute([$a, $b]);
    }
}

// ── GET: load transcript + job status ──
if ($method === 'GET') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');

    // Get item info
    $itemStmt = $db->prepare("SELECT feed_item_key, feed_item_external_id, COALESCE(feed_item_title_override, feed_item_title_import) AS title, feed_item_type, feed_item_url, feed_item_duration_seconds, fi.feed_key, f.feed_site_code, f.feed_account_id, f.feed_api_key FROM yy_feed_item fi JOIN yy_feed f ON fi.feed_key = f.feed_key WHERE fi.feed_item_key = ?");
    $itemStmt->execute([$itemKey]);
    $item = $itemStmt->fetch();
    if (!$item) errorResponse('Item not found', 404);

    // Get transcript rows
    $rowsStmt = $db->prepare("
        SELECT feed_item_transcript_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort
        FROM yy_feed_item_transcript
        WHERE feed_item_key = ?
        ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
    ");
    $rowsStmt->execute([$itemKey]);
    $rows = $rowsStmt->fetchAll();

    // Convert intervals to display strings (HH:MM:SS)
    foreach ($rows as &$r) {
        $r['feed_item_transcript_segment'] = $r['feed_item_transcript_segment'] ?: '00:00:00';
    }
    unset($r);

    // Get latest job status
    $jobStmt = $db->prepare("
        SELECT feed_item_transcript_job_key, job_status, job_progress, job_message, job_error, job_dtime, job_completed_dtime
        FROM yy_feed_item_transcript_job
        WHERE feed_item_key = ?
        ORDER BY job_dtime DESC LIMIT 1
    ");
    $jobStmt->execute([$itemKey]);
    $job = $jobStmt->fetch();

    // Get current validation (one row per item via UNIQUE constraint)
    $valStmt = $db->prepare("
        SELECT v.validation_status, v.validation_note, v.validation_dtime, v.validation_user_key,
               v.validation_bookmark_seconds, u.user_code AS validation_user_code
        FROM yy_feed_item_transcript_validation v
        LEFT JOIN yy_user u ON u.user_key = v.validation_user_key
        WHERE v.feed_item_key = ?
    ");
    $valStmt->execute([$itemKey]);
    $validation = $valStmt->fetch() ?: null;

    jsonResponse([
        'item' => $item,
        'rows' => $rows,
        'job' => $job ?: null,
        'validation' => $validation,
    ]);
}

// ── POST: actions ──
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';
    $itemKey = (int)($data['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');

    if ($action === 'start') {
        // If there's already an active job for this item, attach to it instead
        // of spawning a duplicate. Prevents two workers racing on the same
        // item (clicking Transcribe in a second tab, double-clicking, etc.) —
        // both would hit yt-dlp / Whisper, wasting download bandwidth and
        // incurring the API cost twice.
        $existing = $db->prepare("SELECT feed_item_transcript_job_key, job_status, job_worker_pid FROM yy_feed_item_transcript_job WHERE feed_item_key = ? AND job_status IN ('pending', 'running') ORDER BY job_dtime DESC LIMIT 1");
        $existing->execute([$itemKey]);
        $row = $existing->fetch();
        if ($row) {
            // Verify the worker is actually alive — if the PID is dead or
            // recycled, treat the job as orphaned and fall through to a
            // fresh start instead of attaching to a ghost.
            $pid = (int)$row['job_worker_pid'];
            $alive = false;
            if ($pid > 0) {
                $cmdline = @file_get_contents("/proc/$pid/cmdline");
                $alive = ($cmdline && strpos($cmdline, 'transcript-worker') !== false);
            }
            if ($alive) {
                jsonResponse([
                    'job_key' => (int)$row['feed_item_transcript_job_key'],
                    'status' => $row['job_status'],
                    'worker_pid' => $pid,
                    'attached' => true,
                ]);
            }
            // Orphaned: row says running but no live worker. Flip to cancelled
            // so the new job below is the only active one.
            $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW(), job_message = 'Worker died — restarting' WHERE feed_item_transcript_job_key = ?")
               ->execute([(int)$row['feed_item_transcript_job_key']]);
        }

        // Create new job
        $jobStmt = $db->prepare("INSERT INTO yy_feed_item_transcript_job (feed_item_key, job_status, job_message, user_key) VALUES (?, 'pending', 'Queued for transcription', ?) RETURNING feed_item_transcript_job_key");
        $jobStmt->execute([$itemKey, $userKey]);
        $jobKey = (int)$jobStmt->fetchColumn();

        // Kick off the worker in the background, fully detached.
        // The trailing `echo $!` returns the worker's PID so we can kill it on cancel.
        $workerScript = __DIR__ . '/transcript-worker.php';
        $workerPid = 0;
        if (file_exists($workerScript)) {
            $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
            $cmd = "nohup php " . escapeshellarg($workerScript) . " " . escapeshellarg((string)$jobKey)
                 . " > " . escapeshellarg($logFile) . " 2>&1 < /dev/null & echo $!";
            $output = [];
            exec($cmd, $output);
            $workerPid = (int)($output[0] ?? 0);
            if ($workerPid > 0) {
                $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
                   ->execute([$workerPid, $jobKey]);
            }
        }

        jsonResponse(['job_key' => $jobKey, 'status' => 'pending', 'worker_pid' => $workerPid]);
    }

    if ($action === 'cancel') {
        // Look up active job + PID before flipping status, so we can kill the worker process.
        $stmt = $db->prepare("SELECT feed_item_transcript_job_key, job_worker_pid FROM yy_feed_item_transcript_job WHERE feed_item_key = ? AND job_status IN ('pending', 'running') ORDER BY job_dtime DESC LIMIT 1");
        $stmt->execute([$itemKey]);
        $row = $stmt->fetch();

        // Mark cancelled first — worker's next updateJob() will be no-op'd by the
        // job_status guard, so even if the kill races we won't overwrite final state.
        $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW(), job_message = 'Cancelled by user' WHERE feed_item_key = ? AND job_status IN ('pending', 'running')")
           ->execute([$itemKey]);

        $killed = false;
        if ($row && (int)$row['job_worker_pid'] > 0) {
            $pid = (int)$row['job_worker_pid'];
            // Verify the PID still belongs to OUR worker before killing (proc names contain
            // 'transcript-worker'). Avoids killing an unrelated PHP process if PID was reused.
            $procName = @file_get_contents("/proc/$pid/cmdline");
            if ($procName && strpos($procName, 'transcript-worker') !== false) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, 15); // SIGTERM
                } else {
                    @exec("kill -TERM " . (int)$pid . " 2>/dev/null");
                }
                $killed = true;
            }
        }
        jsonResponse(['cancelled' => true, 'killed_pid' => $killed ? $row['job_worker_pid'] : null]);
    }

    if ($action === 'save') {
        $rows = $data['rows'] ?? [];
        if (!is_array($rows)) errorResponse('rows must be an array');

        // Load existing rows for diff logging
        $existingStmt = $db->prepare("SELECT feed_item_transcript_segment, feed_item_transcript_text FROM yy_feed_item_transcript WHERE feed_item_key = ? ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
        $existingStmt->execute([$itemKey]);
        $existing = [];
        foreach ($existingStmt->fetchAll() as $r) {
            $existing[$r['feed_item_transcript_segment']] = $r['feed_item_transcript_text'];
        }

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")->execute([$itemKey]);
            $insStmt = $db->prepare("INSERT INTO yy_feed_item_transcript (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_revision_user_key) VALUES (?, ?::interval, ?, ?, ?)");
            $logStmt = $db->prepare("INSERT INTO yy_transcript_edit_log (feed_item_key, edit_segment, edit_original_text, edit_new_text, edit_action, edit_user_key) VALUES (?, ?::interval, ?, ?, ?, ?)");
            $sort = 0;
            $newSegments = [];
            foreach ($rows as $r) {
                $segment = trim($r['segment'] ?? '00:00:00');
                $text = trim($r['text'] ?? '');
                if (!$text) continue;
                $rowSort = isset($r['sort']) ? (int)$r['sort'] : $sort++;
                if (!preg_match('/^\d+:\d+:\d+(\.\d+)?$|^\d+$/', $segment)) $segment = '00:00:00';
                $textTrim = mb_substr($text, 0, 2000);
                $insStmt->execute([$itemKey, $segment, $textTrim, $rowSort, $userKey]);
                $newSegments[$segment] = $textTrim;

                // Log diff
                $oldText = $existing[$segment] ?? null;
                if ($oldText === null) {
                    $logStmt->execute([$itemKey, $segment, null, $textTrim, 'add', $userKey]);
                } elseif ($oldText !== $textTrim) {
                    $logStmt->execute([$itemKey, $segment, $oldText, $textTrim, 'edit', $userKey]);
                    autoLearnCorrections($db, $oldText, $textTrim);
                }
            }
            // Log deletions
            foreach ($existing as $oldSegment => $oldText) {
                if (!isset($newSegments[$oldSegment])) {
                    $logStmt->execute([$itemKey, $oldSegment, $oldText, null, 'delete', $userKey]);
                }
            }
            $db->commit();
            jsonResponse(['saved' => true, 'count' => count($rows)]);
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Save failed: ' . $e->getMessage());
        }
    }

    if ($action === 'save_validation') {
        $status = trim($data['status'] ?? 'Pending');
        $note   = trim($data['note'] ?? '');
        if (!in_array($status, ['Pending', 'Approved', 'Errors'], true)) {
            errorResponse('status must be Pending, Approved, or Errors');
        }
        // Approving the transcript clears any in-progress validation bookmark
        // — the user is done with this item, so the resume marker is moot.
        $clearBookmark = ($status === 'Approved');
        $db->prepare("
            INSERT INTO yy_feed_item_transcript_validation
                (feed_item_key, validation_status, validation_note, validation_dtime, validation_user_key, validation_bookmark_seconds)
            VALUES (?, ?, NULLIF(?, ''), NOW(), ?, NULL)
            ON CONFLICT (feed_item_key) DO UPDATE SET
                validation_status   = EXCLUDED.validation_status,
                validation_note     = EXCLUDED.validation_note,
                validation_dtime    = NOW(),
                validation_user_key = EXCLUDED.validation_user_key,
                validation_bookmark_seconds = CASE WHEN ?::boolean THEN NULL ELSE yy_feed_item_transcript_validation.validation_bookmark_seconds END
        ")->execute([$itemKey, $status, $note, $userKey, $clearBookmark ? 't' : 'f']);
        jsonResponse(['saved' => true]);
    }

    if ($action === 'save_bookmark') {
        // Lightweight write — auto-fired every ~15s while the user is reviewing
        // the video against the transcript. UPSERTs into the same validation row
        // so closing the popover and reopening lands the user back where they
        // were. Doesn't touch validation_status/note.
        $sec = max(0, (int)($data['seconds'] ?? 0));
        $db->prepare("
            INSERT INTO yy_feed_item_transcript_validation
                (feed_item_key, validation_status, validation_user_key, validation_bookmark_seconds)
            VALUES (?, 'Pending', ?, ?)
            ON CONFLICT (feed_item_key) DO UPDATE SET
                validation_bookmark_seconds = EXCLUDED.validation_bookmark_seconds,
                validation_user_key         = COALESCE(yy_feed_item_transcript_validation.validation_user_key, EXCLUDED.validation_user_key)
        ")->execute([$itemKey, $userKey, $sec]);
        jsonResponse(['saved' => true, 'seconds' => $sec]);
    }

    if ($action === 'apply_corrections_now') {
        // Re-apply current correction dictionary to existing transcript
        $rowsStmt = $db->prepare("SELECT feed_item_transcript_key, feed_item_transcript_text FROM yy_feed_item_transcript WHERE feed_item_key = ?");
        $rowsStmt->execute([$itemKey]);
        $changed = 0;
        $upd = $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_text = ?, feed_item_transcript_revision_dtime = NOW(), feed_item_transcript_revision_num = feed_item_transcript_revision_num + 1 WHERE feed_item_transcript_key = ?");
        foreach ($rowsStmt->fetchAll() as $row) {
            $newText = applyCorrectionDictionary($db, $row['feed_item_transcript_text']);
            if ($newText !== $row['feed_item_transcript_text']) {
                $upd->execute([mb_substr($newText, 0, 2000), $row['feed_item_transcript_key']]);
                $changed++;
            }
        }
        jsonResponse(['changed' => $changed]);
    }

    errorResponse('Unknown action');
}

// ── DELETE: clear transcript ──
if ($method === 'DELETE') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")->execute([$itemKey]);
    jsonResponse(['cleared' => true]);
}

errorResponse('Method not allowed', 405);
