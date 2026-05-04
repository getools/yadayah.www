<?php
/**
 * Backfill embeddings for every active book paragraph that doesn't have one
 * yet. Idempotent — re-running is a no-op once everything is embedded; the
 * NOT EXISTS guard skips already-stored rows.
 *
 * Usage (inside web container, as root):
 *   docker exec yada-www-web-1 php /var/www/html/api/embed-paragraphs.php
 *
 * Picks 64 paragraphs at a time, sends one batched Voyage call per loop,
 * inserts the results, sleeps 200ms to stay polite. ~103k paragraphs ≈ 30
 * minutes wall time at this pace and ~$0.40 in API cost.
 */
ini_set('display_errors', '1');
ini_set('log_errors',     '1');
set_time_limit(0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ask-rag.php';

$db = getDb();

// Provider auto-selected by ask-rag.php (Voyage if VOYAGE_API_KEY set, else
// OpenAI text-embedding-3-small at 1024-d). _embedProvider() reads from
// process env or /var/www/html/.env, so we don't need to load the .env
// ourselves — just confirm a provider was found.
if (!_embedProvider()) {
    fwrite(STDERR, "No embedding provider key found in env or .env (need VOYAGE_API_KEY or OPENAI_API_KEY) — abort\n");
    exit(1);
}

// Defensive prelude: deactivate any junk paragraph rows the parser may have
// re-introduced (NULL / whitespace-only / <5 letters). parse_volume.py runs
// the same UPDATE after each per-volume insert, but doing it here too means
// a stale upstream parser can't poison Ask Yada retrieval — the next embed
// pass always cleans up first. Idempotent; touches 0 rows in steady state.
$junked = $db->exec("
    UPDATE yy_paragraph
       SET paragraph_active_flag = false
     WHERE paragraph_active_flag = true
       AND (
            paragraph_text_plain IS NULL
         OR trim(paragraph_text_plain) = ''
         OR length(regexp_replace(coalesce(paragraph_text_plain, ''),
                                  '[^a-zA-Z]', '', 'g')) < 5
       )
");
if ($junked > 0) fprintf(STDERR, "[embed-paragraphs] deactivated %d junk paragraphs first\n", $junked);

$BATCH = (int)(getenv('EMBED_BATCH') ?: 64);
$MIN_LEN = 30;  // skip near-empty paragraphs (TOC entries, page headers, etc.)
$MAX_LEN = 4000;

// Pull a page of un-embedded paragraphs. We re-query each iteration so any
// concurrent activity (live ask.php traffic embedding things, parser
// re-runs, etc.) is naturally handled. BATCH and MIN_LEN are inlined (cast
// to int) instead of bound — PDO + Postgres + parameterized LIMIT can
// silently misbehave under certain emulated-prepare modes; integers from
// our own code are safe to inline.
$selectSql = "
    SELECT p.paragraph_key, p.paragraph_text_plain, p.volume_key, p.series_key,
           p.paragraph_page, v.volume_label, s.series_label
    FROM yy_paragraph p
    JOIN yy_volume    v ON v.volume_key = p.volume_key
    JOIN yy_series    s ON s.series_key = p.series_key
    WHERE p.paragraph_active_flag = true
      AND v.volume_ask_yada_flag  = true
      AND length(coalesce(p.paragraph_text_plain, '')) >= " . (int)$MIN_LEN . "
      AND NOT EXISTS (
          SELECT 1 FROM yy_ask_embedding e
          WHERE e.source_type = 'paragraph'
            AND e.source_key  = p.paragraph_key
      )
    ORDER BY p.paragraph_key
    LIMIT " . (int)$BATCH . "
";

$totalRemaining = $db->query("
    SELECT COUNT(*)
    FROM yy_paragraph p
    JOIN yy_volume v ON v.volume_key = p.volume_key
    WHERE p.paragraph_active_flag = true
      AND v.volume_ask_yada_flag  = true
      AND length(coalesce(p.paragraph_text_plain, '')) >= $MIN_LEN
      AND NOT EXISTS (
          SELECT 1 FROM yy_ask_embedding e
          WHERE e.source_type = 'paragraph' AND e.source_key = p.paragraph_key
      )
")->fetchColumn();

fprintf(STDERR, "[embed-paragraphs] %d paragraphs to embed, batch size %d\n", $totalRemaining, $BATCH);

$done = 0; $failed = 0; $skipped = 0;
$startMs = (int)(microtime(true) * 1000);

while (true) {
    $rows = $db->query($selectSql)->fetchAll();
    if (!$rows) break;

    $texts = [];
    $valid = [];
    foreach ($rows as $r) {
        $t = trim($r['paragraph_text_plain'] ?? '');
        if (mb_strlen($t) < $MIN_LEN) { $skipped++; continue; }
        if (mb_strlen($t) > $MAX_LEN) $t = mb_substr($t, 0, $MAX_LEN);
        $valid[]  = $r;
        $texts[]  = $t;
    }
    if (!$texts) {
        fprintf(STDERR, "[embed-paragraphs] all of this batch were too short, advancing\n");
        // Mark them as embedded with a zero-vector? No — that would poison search.
        // Just stop: there shouldn't be many short rows once the head of the
        // queue is past. If we did hit them, the LIMIT would never advance.
        // Instead, INSERT a sentinel row so we don't re-fetch them.
        foreach ($rows as $r) {
            $db->prepare("
                INSERT INTO yy_ask_embedding (source_type, source_key, content_text, embedding, metadata, embedding_model)
                VALUES ('paragraph', ?, '', NULL, ?::jsonb, 'skipped-too-short')
                ON CONFLICT DO NOTHING
            ")->execute([$r['paragraph_key'], json_encode(['skipped' => 'too_short'])]);
        }
        continue;
    }

    $embeddings = generateEmbeddingsBatch($texts);
    if (!$embeddings || count($embeddings) !== count($valid)) {
        fwrite(STDERR, "[embed-paragraphs] batch failed (got " . (is_array($embeddings) ? count($embeddings) : 'null') . " of " . count($valid) . "), retrying after 5s\n");
        $failed += count($valid);
        sleep(5);
        continue;
    }

    foreach ($valid as $i => $r) {
        if (!$embeddings[$i]) { $failed++; continue; }
        storeEmbedding($db, 'paragraph', (int)$r['paragraph_key'], $texts[$i], $embeddings[$i], [
            'volume_key'   => (int)$r['volume_key'],
            'series_key'   => (int)$r['series_key'],
            'volume_label' => $r['volume_label'],
            'series_label' => $r['series_label'],
            'page'         => (int)$r['paragraph_page'],
        ]);
        $done++;
    }

    $elapsedSec = max(1, (int)((microtime(true) * 1000 - $startMs) / 1000));
    $rate = $done / $elapsedSec;
    $eta  = $rate > 0 ? (int)(($totalRemaining - $done) / $rate) : 0;
    fprintf(STDERR, "[embed-paragraphs] done=%d failed=%d skipped=%d  (%.1f/s, ETA %ds)\n", $done, $failed, $skipped, $rate, $eta);

    usleep(200000); // 200ms — Voyage is fine with 100+ rps, this is just polite
}

fprintf(STDERR, "[embed-paragraphs] FINISHED  done=%d failed=%d skipped=%d\n", $done, $failed, $skipped);
