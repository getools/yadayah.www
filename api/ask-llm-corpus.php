<?php
/**
 * Ask Yada (local LLM) — training-corpus export (CLI). Writes JSONL files for fine-tuning a model on
 * Yada's own words. Sources (user decision 2026-10-10): books, transcript speech attributed to Yada,
 * and Yada's own community posts + Chat messages. Nothing written by anyone else is exported as
 * Yada text.
 *
 *   docker exec yada-www-web-1 php /var/www/html/api/ask-llm-corpus.php /tmp/ask-llm-corpus [--dm-pairs]
 *
 * Files:
 *   books.jsonl        one record per chapter            {series, volume, chapter, text}
 *   transcripts.jsonl  one record per video, Yada only   {title, date, url, text}
 *   posts.jsonl        one record per community topic    {title, date, text}
 *   dms.jsonl          one record per Chat thread        {text}            (Yada's messages only, no names)
 *   qa_pairs.jsonl     {question, answer, source}  — curated Q&A, admin corrections, top-rated answers,
 *                      and public community exchanges where Yada replied to a member.
 *                      --dm-pairs also adds Chat exchanges (a member's private question + Yada's reply).
 *   manifest.json      counts, characters, approximate tokens
 *
 * Same eligibility as Ask Yada retrieval: volume_ask_yada_flag, rating > 0, not Inactive, exclude-series.
 */
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
ini_set('display_errors', '1');
ini_set('memory_limit', '2G');
require_once __DIR__ . '/_ask_llm.php';

$dir = rtrim($argv[1] ?? '/tmp/ask-llm-corpus', '/');
$dmPairs = in_array('--dm-pairs', $argv, true);
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { fwrite(STDERR, "cannot create $dir\n"); exit(1); }

$db = getDb();
$db->exec("SET statement_timeout = 0");
$yada = (int)askLlmSetting($db, 'yada-user-key', '0');
if (!$yada) { fwrite(STDERR, "setting ask-llm/yada-user-key is not set\n"); exit(1); }
$manifest = ['generated' => date('c'), 'yada_user_key' => $yada, 'files' => []];

function writer(string $dir, string $name): array {
    return ['fh' => fopen("$dir/$name", 'w'), 'name' => $name, 'n' => 0, 'chars' => 0];
}
function put(array &$w, array $rec, string $textKey = 'text'): void {
    fwrite($w['fh'], json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $w['n']++;
    $w['chars'] += mb_strlen($rec[$textKey] ?? (($rec['question'] ?? '') . ($rec['answer'] ?? '')));
}
function close(array &$w, array &$manifest): void {
    fclose($w['fh']);
    $manifest['files'][$w['name']] = ['records' => $w['n'], 'chars' => $w['chars'], 'approx_tokens' => (int)round($w['chars'] / 4)];
    fprintf(STDERR, "%-18s %7d records %12s chars  ~%s tokens\n", $w['name'], $w['n'], number_format($w['chars']), number_format($w['chars'] / 4));
}

// ── Books: one record per chapter ──
$w = writer($dir, 'books.jsonl');
$excl = askLlmExcludedSeries($db);
$st = $db->query("
    SELECT p.volume_key, p.chapter_key, s.series_label, v.volume_label, c.chapter_number, c.chapter_name,
           string_agg(p.paragraph_text_plain, E'\\n\\n' ORDER BY p.paragraph_key) AS text
      FROM yy_paragraph p
      JOIN yy_volume v ON v.volume_key = p.volume_key
      JOIN yy_series s ON s.series_key = p.series_key
      LEFT JOIN yy_chapter c ON c.chapter_key = p.chapter_key
     WHERE p.paragraph_active_flag AND coalesce(trim(p.paragraph_text_plain), '') <> ''
       AND v.volume_ask_yada_flag AND v.volume_ask_rating > 0 AND v.volume_status <> 'I'
       " . ($excl ? 'AND p.series_key NOT IN (' . implode(',', $excl) . ')' : '') . "
     GROUP BY 1, 2, 3, 4, 5, 6
     ORDER BY 1, min(p.paragraph_key)");
foreach ($st as $r) {
    put($w, ['series' => $r['series_label'], 'volume' => $r['volume_label'],
             'chapter' => trim(($r['chapter_number'] ? "Ch {$r['chapter_number']}: " : '') . ($r['chapter_name'] ?? '')),
             'text' => $r['text']]);
}
close($w, $manifest);

// ── Transcripts: Yada's own speech only, one record per video ──
$w = writer($dir, 'transcripts.jsonl');
$items = $db->query("SELECT DISTINCT t.feed_item_key FROM yy_feed_item_transcript t
                       JOIN yy_feed_item f ON f.feed_item_key = t.feed_item_key AND f.feed_item_active_flag
                      ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$rows = $db->prepare("SELECT feed_item_transcript_text AS txt, feed_item_transcript_speaker AS spk FROM yy_feed_item_transcript
                       WHERE feed_item_key = ? ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
$meta = $db->prepare("SELECT COALESCE(feed_item_title_override, feed_item_title_import) AS title,
                             COALESCE(feed_item_publish_override_dtime, feed_item_publish_import_dtime)::date AS date, feed_item_url AS url
                        FROM yy_feed_item WHERE feed_item_key = ?");
$skippedUnlabeled = 0;
foreach ($items as $fk) {
    $labels = askLlmYadaLabels($db, (int)$fk);
    $rows->execute([$fk]);
    $parts = [];
    $prev = null;
    $sawYada = false;
    foreach ($rows as $r) {
        $txt = trim(preg_replace('/\s+/u', ' ', $r['txt'] ?? ''));
        if ($txt === '') continue;
        $cls = askLlmSpeakerClass($r['spk'], $labels);
        if ($cls !== 'yada') { $prev = $cls; continue; }
        $sawYada = true;
        // a new paragraph wherever someone else spoke in between
        if ($prev !== 'yada' && $parts) $parts[] = "\n\n";
        elseif ($parts) $parts[] = ' ';
        $parts[] = $txt;
        $prev = 'yada';
    }
    if (!$sawYada) { $skippedUnlabeled++; continue; }
    $text = implode('', $parts);
    if (mb_strlen($text) < 200) continue;
    $meta->execute([$fk]);
    $m = $meta->fetch() ?: [];
    put($w, ['title' => $m['title'] ?? null, 'date' => $m['date'] ?? null, 'url' => $m['url'] ?? null, 'text' => $text]);
}
close($w, $manifest);
$manifest['transcripts_without_yada_speech'] = $skippedUnlabeled;

// ── Community posts: Yada's text per topic ──
$w = writer($dir, 'posts.jsonl');
foreach (askLlmBuildPostChunks($db, $yada) as $chunks) {
    put($w, ['title' => preg_replace('/^Community: /', '', $chunks[0]['title']), 'date' => $chunks[0]['locator']['date'] ?? null,
             'text' => implode("\n\n", array_column($chunks, 'text'))]);
}
close($w, $manifest);

// ── Chat messages: Yada's own messages per thread (no participant names) ──
$w = writer($dir, 'dms.jsonl');
foreach (askLlmBuildDmChunks($db, $yada) as $chunks) {
    put($w, ['text' => implode("\n\n", array_column($chunks, 'text'))]);
}
close($w, $manifest);

// ── Question → answer pairs ──
$w = writer($dir, 'qa_pairs.jsonl');
foreach ($db->query("SELECT ask_qanda_question q, ask_qanda_answer a FROM yy_ask_qanda WHERE ask_qanda_active_flag") as $r) {
    put($w, ['question' => askLlmPlain($r['q']), 'answer' => askLlmPlain($r['a']), 'source' => 'curated_qanda']);
}
foreach ($db->query("SELECT ask_log_question q, ask_log_corrected_answer a FROM yy_ask_session_log WHERE coalesce(ask_log_corrected_answer, '') <> ''") as $r) {
    put($w, ['question' => trim($r['q']), 'answer' => askLlmPlain($r['a']), 'source' => 'ask_yada_corrected']);
}
foreach ($db->query("SELECT question_text q, coalesce(nullif(review_corrected, ''), answer_text) a,
                            CASE WHEN coalesce(review_corrected, '') <> '' THEN 'local_llm_corrected' ELSE 'local_llm_rated_80plus' END s
                       FROM yy_ask_job WHERE job_status = 'done' AND (coalesce(review_corrected, '') <> '' OR review_rating >= 80)") as $r) {
    put($w, ['question' => trim($r['q']), 'answer' => trim($r['a']), 'source' => $r['s']]);
}
// Public community exchanges: the member message right before each Yada reply in the same topic
$st = $db->prepare("
    WITH msgs AS (
        SELECT t.topic_key, 0 AS ord, t.topic_dtime AS dt, t.user_key, coalesce(nullif(t.topic_body, ''), t.topic_body_html) AS body, t.topic_title AS title
          FROM yy_community_topic t WHERE t.topic_active_flag AND t.topic_delete_dtime IS NULL
        UNION ALL
        SELECT r.topic_key, 1, r.reply_dtime, r.user_key, coalesce(nullif(r.reply_body, ''), r.reply_body_html), NULL
          FROM yy_community_reply r WHERE r.reply_active_flag AND r.reply_delete_dtime IS NULL),
    seq AS (SELECT *, lag(user_key) OVER w AS prev_user, lag(body) OVER w AS prev_body
              FROM msgs WINDOW w AS (PARTITION BY topic_key ORDER BY dt, ord))
    SELECT prev_body, body FROM seq WHERE user_key = :y AND prev_user IS NOT NULL AND prev_user <> :y");
$st->execute([':y' => $yada]);
foreach ($st as $r) {
    $q = askLlmPlain($r['prev_body']); $a = askLlmPlain($r['body']);
    if (mb_strlen($q) >= 20 && mb_strlen($a) >= 60) put($w, ['question' => $q, 'answer' => $a, 'source' => 'community_reply']);
}
if ($dmPairs) {
    $st = $db->prepare("
        WITH seq AS (SELECT thread_key, user_key, message_body,
                            lag(user_key) OVER w AS prev_user, lag(message_body) OVER w AS prev_body
                       FROM yy_community_dm_message WHERE message_active_flag AND user_key IS NOT NULL
                     WINDOW w AS (PARTITION BY thread_key ORDER BY message_dtime, message_key))
        SELECT prev_body, message_body FROM seq WHERE user_key = :y AND prev_user IS NOT NULL AND prev_user <> :y");
    $st->execute([':y' => $yada]);
    foreach ($st as $r) {
        $q = askLlmPlain($r['prev_body']); $a = askLlmPlain($r['message_body']);
        if (mb_strlen($q) >= 20 && mb_strlen($a) >= 60) put($w, ['question' => $q, 'answer' => $a, 'source' => 'chat_reply']);
    }
}
close($w, $manifest);

$manifest['dm_pairs_included'] = $dmPairs;
$manifest['total_approx_tokens'] = array_sum(array_column($manifest['files'], 'approx_tokens'));
file_put_contents("$dir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fprintf(STDERR, "total ~%s tokens → %s\n", number_format($manifest['total_approx_tokens']), $dir);
