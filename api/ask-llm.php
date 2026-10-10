<?php
/**
 * Ask Yada (local LLM) — API.
 *
 * Worker actions (Puget box; header X-Ask-Token or Authorization: Bearer <yy_setting ask-llm/worker-token>):
 *   POST {action:'claim', worker, kinds?:['chat','eval'], models?:[…]}   → {job|null, history?, model, config}
 *   POST {action:'heartbeat', job_key, progress}                         → extends the 20-min lease
 *   POST {action:'release', job_key, progress?}                          → back to queue, attempt not counted
 *   POST {action:'search', query, embedding?:[1024], sources?:[…], limit?} → {results}
 *   POST {action:'context', chunk_key, before?, after?}                   → {chunks}
 *   POST {action:'complete', job_key, answer, meta?}                      → posts the chat reply (chat jobs)
 *   POST {action:'fail', job_key, error, retry?:bool}
 *   POST {action:'embed_pending', limit?}                                 → {items:[{key,text}]}
 *   POST {action:'embed_put', model, items:[{key, embedding}]}
 *
 * Admin actions (session, role admin):
 *   GET  ?action=stats | jobs | runs | eval_questions | compare&runs=1,2
 *   POST {action:'eval_build', n?, min_len?}   {action:'eval_toggle', eval_question_key, active}
 *        {action:'run_create', label, model, config?, notes?}   {action:'run_cancel', eval_run_key}
 *        {action:'review', ask_job_key, rating?, note?, corrected?}   {action:'job_retry'|'job_cancel', ask_job_key}
 *        {action:'search', …} (same as worker; FTS only unless an embedding is passed)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_ask_llm.php';
require_once __DIR__ . '/community-helpers.php';

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$action = $method === 'POST' ? ($input['action'] ?? '') : ($_GET['action'] ?? '');

// ── Auth ────────────────────────────────────────────────────────────
function askLlmIsWorker(PDO $db): bool {
    $tok = $_SERVER['HTTP_X_ASK_TOKEN'] ?? '';
    if ($tok === '') {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($h, 'Bearer ') === 0) $tok = trim(substr($h, 7));
    }
    $want = askLlmSetting($db, 'worker-token', '');
    return $tok !== '' && $want !== '' && hash_equals($want, $tok);
}
function askLlmAdminKey(PDO $db): ?int {
    $uk = (int)($_SESSION['user_key'] ?? 0);
    if (!$uk) return null;
    $st = $db->prepare("SELECT 1 FROM yy_user_role WHERE user_key = ? AND role_key = 2");
    $st->execute([$uk]);
    return $st->fetchColumn() ? $uk : null;
}

$isWorker = askLlmIsWorker($db);
$adminKey = $isWorker ? null : askLlmAdminKey($db);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (!$isWorker && !$adminKey) errorResponse('Not authorized', 401);

$WORKER_ACTIONS = ['claim', 'heartbeat', 'release', 'search', 'context', 'complete', 'fail', 'embed_pending', 'embed_put'];
if ($isWorker && !in_array($action, $WORKER_ACTIONS, true)) errorResponse('Unknown worker action', 400);

// ── Shared ──────────────────────────────────────────────────────────
if ($action === 'search') {
    $emb = $input['embedding'] ?? null;
    $results = askLlmSearch($db, (string)($input['query'] ?? ''), is_array($emb) && $emb ? $emb : null,
                            (array)($input['sources'] ?? []), (int)($input['limit'] ?? 12));
    jsonResponse(['results' => $results]);
}
if ($action === 'context') {
    jsonResponse(['chunks' => askLlmContext($db, (int)($input['chunk_key'] ?? 0), (int)($input['before'] ?? 1), (int)($input['after'] ?? 1))]);
}

// ── Worker ──────────────────────────────────────────────────────────
if ($isWorker) {
    $botKey = (int)askLlmSetting($db, 'bot-user-key', '0');

    if ($action === 'claim') {
        $worker = substr((string)($input['worker'] ?? 'worker'), 0, 60);
        $kinds = array_values(array_intersect((array)($input['kinds'] ?? ['chat', 'eval']), ['chat', 'eval'])) ?: ['chat', 'eval'];
        $models = array_values(array_filter(array_map('strval', (array)($input['models'] ?? []))));

        // Leases that expired three times are given up on
        $db->exec("UPDATE yy_ask_job SET job_status = 'failed', job_error = coalesce(job_error, 'lease expired after 3 attempts'),
                          job_finished_dtime = now()
                    WHERE job_status = 'running' AND job_lease_until < now() AND job_attempts >= 3");

        $kindIn = implode(',', array_map(fn($k) => $db->quote($k), $kinds));
        $modelSql = '';
        if ($models) {   // eval jobs are pinned to a model; only take those this worker has loaded
            $modelSql = "AND (job_kind = 'chat' OR job_model IN (" . implode(',', array_map(fn($m) => $db->quote($m), $models)) . "))";
        }
        $st = $db->prepare("
            UPDATE yy_ask_job SET job_status = 'running', job_worker = ?, job_attempts = job_attempts + 1,
                   job_started_dtime = now(), job_lease_until = now() + interval '20 minutes', job_progress = 'claimed', job_error = NULL
             WHERE ask_job_key = (
                   SELECT ask_job_key FROM yy_ask_job
                    WHERE (job_status = 'queued' OR (job_status = 'running' AND job_lease_until < now()))
                      AND job_kind IN ($kindIn) $modelSql
                    ORDER BY job_priority DESC, ask_job_key
                    LIMIT 1 FOR UPDATE SKIP LOCKED)
            RETURNING ask_job_key, job_kind, thread_key, message_key, user_key, question_text, eval_run_key, eval_question_key, job_model, job_attempts");
        $st->execute([$worker]);
        $job = $st->fetch();
        if (!$job) jsonResponse(['job' => null]);

        // Same admin-tunable guidance the current Ask Yada appends (custom prompt + learned corrections)
        require_once __DIR__ . '/ask-rag.php';
        $guidelines = trim((string)$db->query("SELECT setting_value FROM yy_setting
                                                WHERE setting_scope_code = 'app' AND setting_code = 'ask_custom_prompt'")->fetchColumn());
        $out = ['job' => $job, 'model' => $job['job_model'] ?: askLlmSetting($db, 'chat-model', 'gpt-oss:120b'), 'config' => new stdClass(),
                'guidelines' => $guidelines, 'learned' => getLearnedPromptAdditions($db)];
        if ($job['eval_run_key']) {
            $r = $db->prepare("SELECT run_model, run_config FROM yy_ask_eval_run WHERE eval_run_key = ?");
            $r->execute([$job['eval_run_key']]);
            if ($run = $r->fetch()) {
                $out['model'] = $run['run_model'];
                $out['config'] = json_decode($run['run_config'] ?: '{}', true) ?: new stdClass();
            }
        }
        if ($job['job_kind'] === 'chat' && $job['thread_key']) {
            // Conversation so far: member messages + the bot's real answers (not acks/system notes)
            $h = $db->prepare("
                SELECT m.message_key, m.user_key, m.message_body, m.message_dtime,
                       EXISTS (SELECT 1 FROM yy_ask_job j WHERE j.posted_message_key = m.message_key) AS is_answer
                  FROM yy_community_dm_message m
                 WHERE m.thread_key = ? AND m.message_active_flag AND m.user_key IS NOT NULL AND m.message_key <= ?
                 ORDER BY m.message_dtime DESC, m.message_key DESC
                 LIMIT 40");
            $h->execute([$job['thread_key'], max((int)$job['message_key'], 0) ?: PHP_INT_MAX]);
            $hist = [];
            foreach (array_reverse($h->fetchAll()) as $m) {
                if ((int)$m['user_key'] === $botKey && !$m['is_answer']) continue;
                $hist[] = ['role' => (int)$m['user_key'] === $botKey ? 'assistant' : 'user',
                           'content' => $m['message_body'], 'dtime' => $m['message_dtime']];
            }
            $out['history'] = array_slice($hist, -20);
            $u = $db->prepare("SELECT coalesce(user_display_name, user_name_display, user_name_first) FROM yy_user WHERE user_key = ?");
            $u->execute([$job['user_key']]);
            $out['member_name'] = $u->fetchColumn() ?: null;
        }
        jsonResponse($out);
    }

    $jobKey = (int)($input['job_key'] ?? 0);

    if ($action === 'heartbeat') {
        $st = $db->prepare("UPDATE yy_ask_job SET job_lease_until = now() + interval '20 minutes', job_progress = ?
                             WHERE ask_job_key = ? AND job_status = 'running'");
        $st->execute([mb_substr((string)($input['progress'] ?? ''), 0, 200), $jobKey]);
        jsonResponse(['ok' => $st->rowCount() > 0]);
    }

    if ($action === 'release') {   // worker can't run it right now (e.g. GPU busy): back to the queue, attempt not counted
        $st = $db->prepare("UPDATE yy_ask_job SET job_status = 'queued', job_attempts = greatest(job_attempts - 1, 0),
                                   job_lease_until = NULL, job_progress = ?
                             WHERE ask_job_key = ? AND job_status = 'running'");
        $st->execute([mb_substr((string)($input['progress'] ?? ''), 0, 200) ?: null, $jobKey]);
        jsonResponse(['ok' => $st->rowCount() > 0]);
    }

    if ($action === 'complete') {
        $answer = trim((string)($input['answer'] ?? ''));
        if ($answer === '') errorResponse('answer is required', 400);
        $meta = $input['meta'] ?? null;
        $st = $db->prepare("SELECT * FROM yy_ask_job WHERE ask_job_key = ? AND job_status = 'running' FOR UPDATE");
        $db->beginTransaction();
        $st->execute([$jobKey]);
        $job = $st->fetch();
        if (!$job) { $db->rollBack(); errorResponse('Job not running', 409); }

        $posted = null;
        if ($job['job_kind'] === 'chat' && $job['thread_key'] && $botKey) {
            $ins = $db->prepare("INSERT INTO yy_community_dm_message (thread_key, user_key, message_body, message_body_html)
                                 VALUES (?, ?, ?, ?) RETURNING message_key");
            $ins->execute([$job['thread_key'], $botKey, $answer, askLlmMarkdownToHtml($answer)]);
            $posted = (int)$ins->fetchColumn();
            $db->prepare("UPDATE yy_community_dm_thread SET last_message_dtime = now() WHERE thread_key = ?")->execute([$job['thread_key']]);
            $db->prepare("UPDATE yy_community_dm_participant SET last_read_dtime = now() WHERE thread_key = ? AND user_key = ?")
               ->execute([$job['thread_key'], $botKey]);
        }
        $db->prepare("UPDATE yy_ask_job SET job_status = 'done', answer_text = ?, answer_meta = ?::jsonb, posted_message_key = ?,
                             job_model = coalesce(?, job_model), job_progress = 'done', job_finished_dtime = now(), job_lease_until = NULL
                       WHERE ask_job_key = ?")
           ->execute([$answer, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $posted,
                      is_array($meta) ? ($meta['model'] ?? null) : null, $jobKey]);
        $db->commit();

        if ($posted && $job['user_key']) {
            notifyUser($db, (int)$job['user_key'], $botKey, 'dm', 'dm', (int)$job['thread_key'], null, 'answered your question');
            $pref = $db->prepare("SELECT user_email_on_dm FROM yy_user WHERE user_key = ?");
            $pref->execute([$job['user_key']]);
            if ($pref->fetchColumn()) {
                sendNotificationEmail($db, (int)$job['user_key'], 'Ask Yada answered your question',
                    '<p>Ask Yada has replied to your question:</p><blockquote>' . htmlspecialchars(mb_substr($job['question_text'], 0, 300)) . '</blockquote>'
                    . '<p><a href="https://yadayah.com/chat">Read the answer in Chat</a></p>');
            }
        }
        jsonResponse(['ok' => true, 'posted_message_key' => $posted]);
    }

    if ($action === 'fail') {
        $retry = !empty($input['retry']);
        $st = $db->prepare("UPDATE yy_ask_job
                               SET job_status = CASE WHEN ? AND job_attempts < 3 THEN 'queued' ELSE 'failed' END,
                                   job_error = ?, job_progress = NULL, job_lease_until = NULL,
                                   job_finished_dtime = CASE WHEN ? AND job_attempts < 3 THEN NULL ELSE now() END
                             WHERE ask_job_key = ? AND job_status = 'running'");
        $st->execute([$retry ? 't' : 'f', mb_substr((string)($input['error'] ?? 'unknown'), 0, 4000), $retry ? 't' : 'f', $jobKey]);
        jsonResponse(['ok' => $st->rowCount() > 0]);
    }

    if ($action === 'embed_pending') {
        $lim = max(1, min(512, (int)($input['limit'] ?? 128)));
        $rows = $db->query("SELECT ask_chunk_key AS key, chunk_title || E'\\n' || chunk_text AS text FROM yy_ask_chunk
                             WHERE chunk_embedding IS NULL ORDER BY ask_chunk_key LIMIT $lim")->fetchAll();
        $left = (int)$db->query("SELECT count(*) FROM yy_ask_chunk WHERE chunk_embedding IS NULL")->fetchColumn();
        jsonResponse(['items' => $rows, 'remaining' => $left]);
    }

    if ($action === 'embed_put') {
        $model = substr((string)($input['model'] ?? ''), 0, 60);
        if ($model === '') errorResponse('model is required', 400);
        $up = $db->prepare("UPDATE yy_ask_chunk SET chunk_embedding = ?::vector, chunk_embedding_model = ?, chunk_embedding_dtime = now()
                             WHERE ask_chunk_key = ?");
        $n = 0;
        $db->beginTransaction();
        foreach ((array)($input['items'] ?? []) as $it) {
            $up->execute([askLlmVectorLiteral((array)$it['embedding']), $model, (int)$it['key']]);
            $n += $up->rowCount();
        }
        $db->commit();
        jsonResponse(['ok' => true, 'updated' => $n]);
    }
}

// ── Admin ───────────────────────────────────────────────────────────
if ($action === 'stats') {
    // Transcripts split by speaker attribution (yada | unlabeled) so the admin sees what is actually Yada
    $chunks = $db->query("SELECT chunk_source_type || coalesce(':' || (chunk_locator->>'speaker'), '') AS type,
                                 count(*) AS chunks, count(chunk_embedding) AS embedded,
                                 count(DISTINCT chunk_source_key) AS sources, sum(length(chunk_text))::bigint AS chars
                            FROM yy_ask_chunk GROUP BY 1 ORDER BY 1")->fetchAll();
    $jobs = $db->query("SELECT job_kind, job_status, count(*) AS n FROM yy_ask_job GROUP BY 1, 2 ORDER BY 1, 2")->fetchAll();
    $settings = $db->query("SELECT setting_code, setting_value FROM yy_setting
                             WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code <> 'worker-token'
                             ORDER BY setting_sort")->fetchAll(PDO::FETCH_KEY_PAIR);
    jsonResponse(['chunks' => $chunks, 'jobs' => $jobs, 'settings' => $settings,
                  'eval_questions' => (int)$db->query("SELECT count(*) FROM yy_ask_eval_question WHERE eval_active_flag")->fetchColumn()]);
}

if ($action === 'jobs') {
    $kind = in_array($_GET['kind'] ?? '', ['chat', 'eval'], true) ? $_GET['kind'] : 'chat';
    $st = $db->prepare("
        SELECT j.ask_job_key, j.job_kind, j.job_status, j.thread_key, j.user_key,
               coalesce(u.user_display_name, u.user_name_display, u.user_code) AS user_name,
               j.question_text, j.job_model, j.job_attempts, j.job_progress, j.job_error, j.answer_text, j.answer_meta,
               j.review_rating, j.review_note, j.review_corrected, j.job_dtime, j.job_started_dtime, j.job_finished_dtime
          FROM yy_ask_job j LEFT JOIN yy_user u ON u.user_key = j.user_key
         WHERE j.job_kind = ?
         ORDER BY j.ask_job_key DESC LIMIT 200");
    $st->execute([$kind]);
    jsonResponse(['jobs' => $st->fetchAll()]);
}

if ($action === 'eval_questions') {
    jsonResponse(['questions' => $db->query("
        SELECT q.*, (SELECT count(*) FROM yy_ask_job j WHERE j.eval_question_key = q.eval_question_key AND j.job_status = 'done') AS answers
          FROM yy_ask_eval_question q ORDER BY q.eval_question_key")->fetchAll()]);
}

if ($action === 'runs') {
    jsonResponse(['runs' => $db->query("
        SELECT r.*, count(j.*) AS jobs,
               count(*) FILTER (WHERE j.job_status = 'done')   AS done,
               count(*) FILTER (WHERE j.job_status = 'failed') AS failed,
               count(j.review_rating) AS reviewed, round(avg(j.review_rating)) AS avg_rating,
               round(avg(EXTRACT(EPOCH FROM j.job_finished_dtime - j.job_started_dtime)) FILTER (WHERE j.job_status = 'done')) AS avg_secs
          FROM yy_ask_eval_run r LEFT JOIN yy_ask_job j ON j.eval_run_key = r.eval_run_key
         GROUP BY r.eval_run_key ORDER BY r.eval_run_key DESC")->fetchAll()]);
}

if ($action === 'compare') {
    $runs = array_values(array_filter(array_map('intval', explode(',', $_GET['runs'] ?? ''))));
    $qs = $db->query("SELECT eval_question_key, question_text, baseline_answer, baseline_model, baseline_rating, reference_answer, eval_tags
                        FROM yy_ask_eval_question WHERE eval_active_flag ORDER BY eval_question_key")->fetchAll();
    $answers = [];
    if ($runs) {
        $st = $db->query("SELECT ask_job_key, eval_run_key, eval_question_key, job_status, job_model, job_error, answer_text, answer_meta,
                                 review_rating, review_note, review_corrected,
                                 round(EXTRACT(EPOCH FROM job_finished_dtime - job_started_dtime)) AS secs
                            FROM yy_ask_job WHERE eval_run_key IN (" . implode(',', $runs) . ")");
        foreach ($st as $a) {
            $a['answer_meta'] = json_decode($a['answer_meta'] ?? 'null', true);
            $answers[(int)$a['eval_question_key']][(int)$a['eval_run_key']] = $a;
        }
    }
    foreach ($qs as &$q) $q['answers'] = $answers[(int)$q['eval_question_key']] ?? new stdClass();
    jsonResponse(['questions' => $qs]);
}

if ($method === 'POST' && $action === 'settings_save') {
    $EDITABLE = ['access', 'chat-model', 'daily-limit', 'ack-message', 'closed-message', 'limit-message',
                 'weight-book', 'weight-transcript-yada', 'weight-transcript-unknown', 'weight-post', 'weight-dm', 'weight-glossary'];
    $st = $db->prepare("UPDATE yy_setting SET setting_value = ?
                         WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code = ?
                           AND setting_value IS DISTINCT FROM ?");
    $n = 0;
    foreach ((array)($input['settings'] ?? []) as $code => $val) {
        if (!in_array($code, $EDITABLE, true)) continue;
        if ($code === 'access' && !in_array($val, ['off', 'admins', 'members'], true)) errorResponse('access must be off, admins or members', 400);
        if (strpos($code, 'weight-') === 0) {
            if (!is_numeric($val) || $val < 0 || $val > 5) errorResponse('weights must be numbers from 0 to 5', 400);
            $val = (string)round((float)$val, 2);
        }
        $st->execute([(string)$val, $code, (string)$val]);
        $n += $st->rowCount();
    }
    jsonResponse(['saved' => $n]);
}

if ($method === 'POST' && $action === 'eval_build') {
    // Seed from real Ask Yada history: every rated/corrected question first, then a random
    // sample of distinct, substantive questions that got an answer.
    $n = max(1, min(500, (int)($input['n'] ?? 150)));
    $minLen = max(10, (int)($input['min_len'] ?? 30));
    $st = $db->prepare("
        WITH cand AS (
            SELECT DISTINCT ON (lower(trim(l.ask_log_question)))
                   l.ask_session_log_key, trim(l.ask_log_question) AS q, l.ask_log_response, l.ask_log_model,
                   l.ask_log_rating, l.ask_log_corrected_answer,
                   (l.ask_log_rating IS NOT NULL OR l.ask_log_corrected_answer IS NOT NULL) AS curated
              FROM yy_ask_session_log l
              JOIN yy_ask_session s ON s.ask_session_key = l.ask_session_key
             WHERE l.ask_log_error IS NULL AND coalesce(l.ask_log_response, '') <> ''
               AND length(trim(l.ask_log_question)) >= :minlen
               AND s.ip_address NOT IN (SELECT ip_address FROM yy_ask_ip_ban)
               AND NOT EXISTS (SELECT 1 FROM yy_ask_eval_question e WHERE e.ask_session_log_key = l.ask_session_log_key)
             ORDER BY lower(trim(l.ask_log_question)), l.ask_log_rating DESC NULLS LAST, l.ask_session_log_key DESC)
        INSERT INTO yy_ask_eval_question (ask_session_log_key, question_text, baseline_answer, baseline_model, baseline_rating, reference_answer)
        SELECT ask_session_log_key, q, ask_log_response, ask_log_model, ask_log_rating, ask_log_corrected_answer
          FROM cand ORDER BY curated DESC, random() LIMIT :n");
    $st->execute([':minlen' => $minLen, ':n' => $n]);
    $added = $st->rowCount();
    // Curated Q&A pairs are gold references
    $added += $db->exec("INSERT INTO yy_ask_eval_question (question_text, reference_answer, eval_tags)
                         SELECT ask_qanda_question, ask_qanda_answer, 'qanda' FROM yy_ask_qanda q
                          WHERE ask_qanda_active_flag
                            AND NOT EXISTS (SELECT 1 FROM yy_ask_eval_question e WHERE e.question_text = q.ask_qanda_question)");
    jsonResponse(['added' => $added]);
}

if ($method === 'POST' && $action === 'eval_toggle') {
    $db->prepare("UPDATE yy_ask_eval_question SET eval_active_flag = ? WHERE eval_question_key = ?")
       ->execute([!empty($input['active']) ? 't' : 'f', (int)$input['eval_question_key']]);
    jsonResponse(['ok' => true]);
}

if ($method === 'POST' && $action === 'run_create') {
    $label = trim((string)($input['label'] ?? ''));
    $model = trim((string)($input['model'] ?? ''));
    if ($label === '' || $model === '') errorResponse('label and model are required', 400);
    $db->beginTransaction();
    $st = $db->prepare("INSERT INTO yy_ask_eval_run (run_label, run_model, run_config, run_notes, user_key)
                        VALUES (?, ?, ?::jsonb, ?, ?) RETURNING eval_run_key");
    $st->execute([$label, $model, json_encode($input['config'] ?? new stdClass()), $input['notes'] ?? null, $adminKey]);
    $runKey = (int)$st->fetchColumn();
    $st = $db->prepare("INSERT INTO yy_ask_job (job_kind, job_priority, question_text, eval_run_key, eval_question_key, job_model)
                        SELECT 'eval', 0, question_text, ?, eval_question_key, ? FROM yy_ask_eval_question WHERE eval_active_flag");
    $st->execute([$runKey, $model]);
    $n = $st->rowCount();
    $db->commit();
    jsonResponse(['eval_run_key' => $runKey, 'jobs' => $n]);
}

if ($method === 'POST' && $action === 'run_cancel') {
    $st = $db->prepare("UPDATE yy_ask_job SET job_status = 'cancelled', job_finished_dtime = now()
                         WHERE eval_run_key = ? AND job_status = 'queued'");
    $st->execute([(int)$input['eval_run_key']]);
    jsonResponse(['cancelled' => $st->rowCount()]);
}

if ($method === 'POST' && $action === 'review') {
    $rating = isset($input['rating']) && $input['rating'] !== '' && $input['rating'] !== null ? max(0, min(100, (int)$input['rating'])) : null;
    // Only fields actually supplied are written (never blank what wasn't sent)
    $sets = ['review_user_key = ?', 'review_dtime = now()'];
    $vals = [$adminKey];
    if (array_key_exists('rating', $input))    { $sets[] = 'review_rating = ?';    $vals[] = $rating; }
    if (array_key_exists('note', $input))      { $sets[] = 'review_note = ?';      $vals[] = $input['note'] !== '' ? $input['note'] : null; }
    if (array_key_exists('corrected', $input)) { $sets[] = 'review_corrected = ?'; $vals[] = $input['corrected'] !== '' ? $input['corrected'] : null; }
    $vals[] = (int)$input['ask_job_key'];
    $db->prepare("UPDATE yy_ask_job SET " . implode(', ', $sets) . " WHERE ask_job_key = ?")->execute($vals);
    jsonResponse(['ok' => true]);
}

if ($method === 'POST' && in_array($action, ['job_retry', 'job_cancel'], true)) {
    $st = $action === 'job_retry'
        ? $db->prepare("UPDATE yy_ask_job SET job_status = 'queued', job_attempts = 0, job_error = NULL, job_finished_dtime = NULL
                         WHERE ask_job_key = ? AND job_status IN ('failed', 'cancelled')")
        : $db->prepare("UPDATE yy_ask_job SET job_status = 'cancelled', job_finished_dtime = now()
                         WHERE ask_job_key = ? AND job_status = 'queued'");
    $st->execute([(int)$input['ask_job_key']]);
    jsonResponse(['ok' => $st->rowCount() > 0]);
}

errorResponse('Unknown action', 400);
