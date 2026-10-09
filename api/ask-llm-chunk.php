<?php
/**
 * Ask Yada (local LLM) — CLI indexer. Rebuilds yy_ask_chunk from the live sources.
 * Idempotent: unchanged chunks keep their embeddings; changed ones are queued for re-embedding.
 *
 *   docker exec yada-www-web-1 php /var/www/html/api/ask-llm-chunk.php [all|book|transcript|glossary] [source_key]
 */
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
ini_set('display_errors', '1');          // config.php hides CLI errors otherwise
require_once __DIR__ . '/_ask_llm.php';

$what = $argv[1] ?? 'all';
$only = isset($argv[2]) ? (int)$argv[2] : null;
$db = getDb();
$db->exec("SET statement_timeout = 0");
$t0 = microtime(true);

function report(string $label, array $tot): void {
    printf("%-12s inserted %6d  updated %6d  deleted %6d  unchanged %6d\n", $label, ...$tot);
}
function sumInto(array &$tot, array $n): void { foreach ($n as $i => $v) $tot[$i] += $v; }

if (in_array($what, ['all', 'book'], true)) {
    $tot = [0, 0, 0, 0];
    $vols = $only ? [$only] : $db->query("SELECT DISTINCT volume_key FROM yy_paragraph WHERE paragraph_active_flag ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($vols as $vk) {
        $db->beginTransaction();
        sumInto($tot, askLlmSyncChunks($db, 'book', (int)$vk, askLlmBuildBookChunks($db, (int)$vk)));
        $db->commit();
    }
    if (!$only) {   // volumes that lost all their paragraphs
        $n = $db->exec("DELETE FROM yy_ask_chunk c WHERE chunk_source_type = 'book'
                         AND NOT EXISTS (SELECT 1 FROM yy_paragraph p WHERE p.volume_key = c.chunk_source_key AND p.paragraph_active_flag)");
        $tot[2] += $n;
    }
    report('book', $tot);
}

if (in_array($what, ['all', 'transcript'], true)) {
    $tot = [0, 0, 0, 0];
    $items = $only ? [$only] : $db->query("SELECT DISTINCT t.feed_item_key FROM yy_feed_item_transcript t
                                             JOIN yy_feed_item f ON f.feed_item_key = t.feed_item_key AND f.feed_item_active_flag
                                            ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($items as $fk) {
        $db->beginTransaction();
        sumInto($tot, askLlmSyncChunks($db, 'transcript', (int)$fk, askLlmBuildTranscriptChunks($db, (int)$fk)));
        $db->commit();
    }
    if (!$only) {
        $n = $db->exec("DELETE FROM yy_ask_chunk c WHERE chunk_source_type = 'transcript'
                         AND NOT EXISTS (SELECT 1 FROM yy_feed_item f WHERE f.feed_item_key = c.chunk_source_key AND f.feed_item_active_flag
                                           AND EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = f.feed_item_key))");
        $tot[2] += $n;
    }
    report('transcript', $tot);
}

if (in_array($what, ['all', 'glossary'], true)) {
    $tot = [0, 0, 0, 0];
    $words = askLlmBuildGlossaryChunks($db);
    $db->beginTransaction();
    foreach ($words as $wk => $c) {
        if ($only && $wk !== $only) continue;
        sumInto($tot, askLlmSyncChunks($db, 'glossary', $wk, [$c]));
    }
    if (!$only && $words) {
        $tot[2] += $db->exec("DELETE FROM yy_ask_chunk WHERE chunk_source_type = 'glossary'
                               AND chunk_source_key NOT IN (" . implode(',', array_keys($words)) . ")");
    }
    $db->commit();
    report('glossary', $tot);
}

$s = $db->query("SELECT count(*) AS n, count(chunk_embedding) AS e FROM yy_ask_chunk")->fetch();
printf("total chunks %d, embedded %d, %.1fs\n", $s['n'], $s['e'], microtime(true) - $t0);
