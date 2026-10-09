<?php
/**
 * Ask Yada (local LLM) — shared helpers.
 *
 * Used by api/ask-llm.php (worker + admin endpoint) and api/ask-llm-chunk.php (CLI indexer).
 * Schema: sql/ask_llm_tables.sql. Settings: yy_setting scope 'app', group 'ask-llm'.
 */

const ASK_LLM_CHUNK_TARGET = 1600;   // chars; a chunk closes at the first paragraph boundary past this
const ASK_LLM_CHUNK_MAX    = 2400;   // a single paragraph longer than this is split on sentences
const ASK_LLM_EMBED_DIM    = 1024;

function askLlmSetting(PDO $db, string $code, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $st = $db->query("SELECT setting_code, setting_value FROM yy_setting
                           WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm'");
        foreach ($st as $r) $cache[$r['setting_code']] = $r['setting_value'];
    }
    $v = $cache[$code] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function askLlmExcludedSeries(PDO $db): array {
    return array_values(array_filter(array_map('intval', explode(',', askLlmSetting($db, 'exclude-series', '') ?? ''))));
}

// ── Chunking ────────────────────────────────────────────────────────

/** Split one over-long text on sentence boundaries into pieces <= ASK_LLM_CHUNK_MAX. */
function askLlmSplitLong(string $text): array {
    if (mb_strlen($text) <= ASK_LLM_CHUNK_MAX) return [$text];
    $sentences = preg_split('/(?<=[.!?…])\s+/u', $text) ?: [$text];
    $out = [];
    $cur = '';
    foreach ($sentences as $s) {
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($s) + 1 > ASK_LLM_CHUNK_TARGET) {
            $out[] = $cur;
            $cur = '';
        }
        $cur = $cur === '' ? $s : "$cur $s";
        while (mb_strlen($cur) > ASK_LLM_CHUNK_MAX) {          // a "sentence" with no stops at all
            $out[] = mb_substr($cur, 0, ASK_LLM_CHUNK_TARGET);
            $cur = mb_substr($cur, ASK_LLM_CHUNK_TARGET);
        }
    }
    if ($cur !== '') $out[] = $cur;
    return $out;
}

/** Book chunks for one volume: consecutive paragraphs, never crossing a chapter. */
function askLlmBuildBookChunks(PDO $db, int $volumeKey): array {
    $st = $db->prepare("
        SELECT p.paragraph_key, p.series_key, p.chapter_key, p.paragraph_page, p.paragraph_text_plain,
               v.volume_label, s.series_label, c.chapter_number, c.chapter_name
          FROM yy_paragraph p
          JOIN yy_volume v ON v.volume_key = p.volume_key
          JOIN yy_series s ON s.series_key = p.series_key
          LEFT JOIN yy_chapter c ON c.chapter_key = p.chapter_key
         WHERE p.volume_key = ? AND p.paragraph_active_flag = true
         ORDER BY p.paragraph_key");
    $st->execute([$volumeKey]);

    $chunks = [];
    $buf = null;
    $flush = function () use (&$buf, &$chunks) {
        if ($buf && trim($buf['text']) !== '') $chunks[] = $buf;
        $buf = null;
    };
    foreach ($st as $p) {
        $text = trim(preg_replace('/\s+/u', ' ', $p['paragraph_text_plain'] ?? ''));
        if ($text === '') continue;
        $chapter = (int)($p['chapter_key'] ?? 0);
        if ($buf && $buf['chapter_key'] !== $chapter) $flush();
        foreach (askLlmSplitLong($text) as $piece) {
            if ($buf && mb_strlen($buf['text']) + mb_strlen($piece) > ASK_LLM_CHUNK_MAX) $flush();
            if (!$buf) {
                $chTitle = $p['chapter_name'] ? (' — ' . ($p['chapter_number'] ? "Ch {$p['chapter_number']}: " : '') . $p['chapter_name']) : '';
                $buf = [
                    'chapter_key' => $chapter,
                    'title'   => "{$p['series_label']} / {$p['volume_label']}{$chTitle}",
                    'text'    => '',
                    'locator' => [
                        'series_key'  => (int)$p['series_key'],
                        'volume_key'  => $volumeKey,
                        'chapter_key' => $chapter ?: null,
                        'page_from'   => $p['paragraph_page'] !== null ? (int)$p['paragraph_page'] : null,
                        'page_to'     => null,
                        'para_from'   => (int)$p['paragraph_key'],
                        'para_to'     => null,
                    ],
                ];
            }
            $buf['text'] .= ($buf['text'] === '' ? '' : "\n\n") . $piece;
            $buf['locator']['page_to'] = $p['paragraph_page'] !== null ? (int)$p['paragraph_page'] : $buf['locator']['page_to'];
            $buf['locator']['para_to'] = (int)$p['paragraph_key'];
            if (mb_strlen($buf['text']) >= ASK_LLM_CHUNK_TARGET) $flush();
        }
    }
    $flush();
    foreach ($chunks as &$c) unset($c['chapter_key']);
    return $chunks;
}

/** Transcript chunks for one video: consecutive cues, ~ASK_LLM_CHUNK_TARGET chars, with time range. */
function askLlmBuildTranscriptChunks(PDO $db, int $feedItemKey): array {
    $meta = $db->prepare("SELECT COALESCE(feed_item_title_override, feed_item_title_import) AS title,
                                 COALESCE(feed_item_publish_override_dtime, feed_item_publish_import_dtime) AS pub,
                                 feed_item_url, feed_item_restricted_flag
                            FROM yy_feed_item WHERE feed_item_key = ?");
    $meta->execute([$feedItemKey]);
    $m = $meta->fetch();
    if (!$m) return [];
    $date = $m['pub'] ? substr($m['pub'], 0, 10) : null;
    $title = 'Video: ' . ($m['title'] ?: "#$feedItemKey") . ($date ? " ($date)" : '');

    $st = $db->prepare("SELECT EXTRACT(EPOCH FROM feed_item_transcript_segment)::numeric(10,2) AS t,
                               feed_item_transcript_text AS txt
                          FROM yy_feed_item_transcript
                         WHERE feed_item_key = ?
                         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
    $st->execute([$feedItemKey]);

    $chunks = [];
    $buf = null;
    foreach ($st as $r) {
        $txt = trim(preg_replace('/\s+/u', ' ', $r['txt'] ?? ''));
        if ($txt === '') continue;
        foreach (askLlmSplitLong($txt) as $piece) {
            if (!$buf) {
                $buf = ['title' => $title, 'text' => '', 'locator' => [
                    'feed_item_key' => $feedItemKey, 'url' => $m['feed_item_url'], 'date' => $date,
                    'restricted' => (bool)$m['feed_item_restricted_flag'],
                    't_from' => (float)$r['t'], 't_to' => (float)$r['t'],
                ]];
            }
            $buf['text'] .= ($buf['text'] === '' ? '' : ' ') . $piece;
            $buf['locator']['t_to'] = (float)$r['t'];
            if (mb_strlen($buf['text']) >= ASK_LLM_CHUNK_TARGET) { $chunks[] = $buf; $buf = null; }
        }
    }
    if ($buf) $chunks[] = $buf;
    return $chunks;
}

/** Glossary: one chunk per public word that has any definition text. Returns [word_key => chunk]. */
function askLlmBuildGlossaryChunks(PDO $db): array {
    $defs = [];
    $st = $db->query("SELECT word_key, word_definition_text FROM yy_word_definition
                       WHERE word_definition_active_flag AND coalesce(trim(word_definition_text), '') <> ''
                       ORDER BY word_key, word_definition_default_flag DESC NULLS LAST, word_definition_key");
    foreach ($st as $r) $defs[(int)$r['word_key']][] = trim($r['word_definition_text']);

    $st = $db->query("SELECT word_key, word_yt, word_translit, word_hebrew, word_strongs, word_language,
                             word_definition_yy, word_definition_kirk, word_definition_perry, word_definition_external
                        FROM yy_word
                       WHERE word_active_flag AND word_master_word_key IS NULL AND NOT coalesce(word_excluded_flag, false)");
    $out = [];
    foreach ($st as $w) {
        $k = (int)$w['word_key'];
        $parts = [];
        foreach (array_merge($defs[$k] ?? [], [$w['word_definition_yy'], $w['word_definition_kirk'],
                                                $w['word_definition_perry'], $w['word_definition_external']]) as $d) {
            $d = trim(strip_tags((string)$d));
            if ($d !== '' && !in_array($d, $parts, true)) $parts[] = $d;
        }
        if (!$parts) continue;
        $head = trim($w['word_yt'] ?: $w['word_translit'] ?: '');
        if ($head === '') continue;
        $bits = array_filter([
            $w['word_hebrew'] ? "Hebrew: {$w['word_hebrew']}" : null,
            $w['word_translit'] && $w['word_translit'] !== $head ? "Transliteration: {$w['word_translit']}" : null,
            $w['word_strongs'] ? "Strong's: {$w['word_strongs']}" : null,
        ]);
        $text = $head . ($bits ? ' (' . implode('; ', $bits) . ')' : '') . "\n" . mb_substr(implode("\n", $parts), 0, 4000);
        $out[$k] = ['title' => "Glossary: $head", 'text' => $text,
                    'locator' => ['word_key' => $k, 'strongs' => $w['word_strongs'], 'language' => $w['word_language']]];
    }
    return $out;
}

/**
 * Reconcile one source's chunk list with yy_ask_chunk: unchanged rows are left alone
 * (keeping their embedding), changed rows are rewritten with the embedding cleared,
 * surplus rows are deleted. Returns [inserted, updated, deleted, unchanged].
 */
function askLlmSyncChunks(PDO $db, string $type, int $sourceKey, array $chunks): array {
    $st = $db->prepare("SELECT chunk_seq, ask_chunk_key, chunk_hash FROM yy_ask_chunk
                         WHERE chunk_source_type = ? AND chunk_source_key = ?");
    $st->execute([$type, $sourceKey]);
    $have = [];
    foreach ($st as $r) $have[(int)$r['chunk_seq']] = $r;

    $ins = $db->prepare("INSERT INTO yy_ask_chunk (chunk_source_type, chunk_source_key, chunk_seq, chunk_title, chunk_locator, chunk_text, chunk_hash)
                         VALUES (?, ?, ?, ?, ?::jsonb, ?, ?)");
    $upd = $db->prepare("UPDATE yy_ask_chunk SET chunk_title = ?, chunk_locator = ?::jsonb, chunk_text = ?, chunk_hash = ?,
                                chunk_embedding = NULL, chunk_embedding_model = NULL, chunk_embedding_dtime = NULL, chunk_dtime = now()
                          WHERE ask_chunk_key = ?");
    $n = [0, 0, 0, 0];
    foreach (array_values($chunks) as $seq => $c) {
        $hash = md5($c['title'] . "\n" . $c['text']);
        $loc = json_encode($c['locator'], JSON_UNESCAPED_UNICODE);
        if (!isset($have[$seq])) {
            $ins->execute([$type, $sourceKey, $seq, $c['title'], $loc, $c['text'], $hash]);
            $n[0]++;
        } elseif ($have[$seq]['chunk_hash'] !== $hash) {
            $upd->execute([$c['title'], $loc, $c['text'], $hash, $have[$seq]['ask_chunk_key']]);
            $n[1]++;
        } else {
            $n[3]++;
        }
        unset($have[$seq]);
    }
    if ($have) {
        $keys = array_map(fn($r) => (int)$r['ask_chunk_key'], $have);
        $db->exec("DELETE FROM yy_ask_chunk WHERE ask_chunk_key IN (" . implode(',', $keys) . ")");
        $n[2] += count($keys);
    }
    return $n;
}

// ── Retrieval ───────────────────────────────────────────────────────

function askLlmVectorLiteral(array $v): string {
    if (count($v) !== ASK_LLM_EMBED_DIM) throw new InvalidArgumentException('embedding must have ' . ASK_LLM_EMBED_DIM . ' dims');
    return '[' . implode(',', array_map(fn($x) => (string)(float)$x, $v)) . ']';
}

/**
 * Hybrid search: full-text + (optional) vector, fused by reciprocal rank, weighted by
 * yy_volume.volume_ask_rating for book chunks. Honors volume_ask_yada_flag, inactive
 * books, and the exclude-series setting — same gates as the current Ask Yada.
 *
 * $embedding: query vector from the worker (Qwen3-Embedding, 1024-d), or null for FTS only.
 */
function askLlmSearch(PDO $db, string $query, ?array $embedding, array $sources, int $limit): array {
    $sources = array_values(array_intersect($sources ?: ['book', 'transcript', 'glossary'], ['book', 'transcript', 'glossary']));
    if (!$sources) return [];
    $limit = max(1, min(40, $limit));
    $pool = $limit * 4;
    $excl = askLlmExcludedSeries($db);
    $srcIn = implode(',', array_map(fn($s) => $db->quote($s), $sources));
    $exclSql = $excl ? 'AND coalesce((c.chunk_locator->>\'series_key\')::int, 0) NOT IN (' . implode(',', $excl) . ')' : '';

    // Eligibility + weight, shared by both legs
    $gate = "
        LEFT JOIN yy_volume v ON c.chunk_source_type = 'book' AND v.volume_key = c.chunk_source_key
        WHERE c.chunk_source_type IN ($srcIn)
          AND (c.chunk_source_type <> 'book' OR (v.volume_ask_yada_flag AND v.volume_ask_rating > 0 AND v.volume_status <> 'I'))
          $exclSql";

    $ranked = [];   // ask_chunk_key => ['fts' => rank, 'vec' => rank]
    $q = trim($query);
    if ($q !== '') {
        $st = $db->prepare("
            SELECT c.ask_chunk_key FROM yy_ask_chunk c $gate
               AND c.chunk_tsv @@ websearch_to_tsquery('english', :q)
             ORDER BY ts_rank_cd(c.chunk_tsv, websearch_to_tsquery('english', :q)) DESC
             LIMIT $pool");
        $st->execute([':q' => $q]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i => $k) $ranked[(int)$k]['fts'] = $i + 1;

        // AND-style websearch query found little → OR the content words
        if (count($ranked) < $limit) {
            $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)), fn($w) => mb_strlen($w) > 2);
            if ($words) {
                $st = $db->prepare("
                    SELECT c.ask_chunk_key FROM yy_ask_chunk c $gate
                       AND c.chunk_tsv @@ websearch_to_tsquery('english', :q)
                     ORDER BY ts_rank_cd(c.chunk_tsv, websearch_to_tsquery('english', :q)) DESC
                     LIMIT $pool");
                $st->execute([':q' => implode(' or ', array_slice(array_unique($words), 0, 12))]);
                $base = count($ranked);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i => $k) {
                    if (!isset($ranked[(int)$k]['fts'])) $ranked[(int)$k]['fts'] = $base + $i + 1;
                }
            }
        }
    }
    if ($embedding) {
        $db->exec("SET hnsw.ef_search = " . max(40, $pool));
        $st = $db->prepare("
            SELECT c.ask_chunk_key FROM yy_ask_chunk c $gate
               AND c.chunk_embedding IS NOT NULL
             ORDER BY c.chunk_embedding <=> :v::vector
             LIMIT $pool");
        $st->execute([':v' => askLlmVectorLiteral($embedding)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i => $k) $ranked[(int)$k]['vec'] = $i + 1;
    }
    if (!$ranked) return [];

    $keys = implode(',', array_keys($ranked));
    $rows = $db->query("
        SELECT c.ask_chunk_key, c.chunk_source_type, c.chunk_source_key, c.chunk_seq, c.chunk_title,
               c.chunk_locator, c.chunk_text, coalesce(v.volume_ask_rating, 50) AS rating
          FROM yy_ask_chunk c
          LEFT JOIN yy_volume v ON c.chunk_source_type = 'book' AND v.volume_key = c.chunk_source_key
         WHERE c.ask_chunk_key IN ($keys)")->fetchAll();

    foreach ($rows as &$r) {
        $rk = $ranked[(int)$r['ask_chunk_key']];
        $rrf = (isset($rk['fts']) ? 1 / (60 + $rk['fts']) : 0) + (isset($rk['vec']) ? 1 / (60 + $rk['vec']) : 0);
        $r['score'] = round($rrf * ((int)$r['rating'] / 50.0) * 1000, 3);
        $r['chunk_locator'] = json_decode($r['chunk_locator'] ?? 'null', true);
        $r['ask_chunk_key'] = (int)$r['ask_chunk_key'];
        unset($r['rating']);
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($rows, 0, $limit);
}

/** Neighbouring chunks of the same source (to read around a hit). */
function askLlmContext(PDO $db, int $chunkKey, int $before, int $after): array {
    $st = $db->prepare("
        SELECT n.ask_chunk_key, n.chunk_source_type, n.chunk_seq, n.chunk_title, n.chunk_locator, n.chunk_text
          FROM yy_ask_chunk c
          JOIN yy_ask_chunk n ON n.chunk_source_type = c.chunk_source_type AND n.chunk_source_key = c.chunk_source_key
                             AND n.chunk_seq BETWEEN c.chunk_seq - ? AND c.chunk_seq + ?
         WHERE c.ask_chunk_key = ?
         ORDER BY n.chunk_seq");
    $st->execute([max(0, min(5, $before)), max(0, min(5, $after)), $chunkKey]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['chunk_locator'] = json_decode($r['chunk_locator'] ?? 'null', true);
    return $rows;
}

// ── Output ──────────────────────────────────────────────────────────

/**
 * Minimal, safe markdown → HTML for chat bubbles. Everything is escaped first; only
 * bold/italic, headings (as bold lines), bullet/numbered lists, paragraphs and links
 * to http(s) or site-relative URLs survive.
 */
function askLlmMarkdownToHtml(string $md): string {
    $md = str_replace("\r\n", "\n", trim($md));
    $inline = function (string $s): string {
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/u', function ($m) {
            return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
        }, $s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/u', '<em>$1</em>', $s);
        return $s;
    };
    $html = '';
    foreach (preg_split('/\n{2,}/', $md) as $block) {
        $lines = explode("\n", trim($block));
        if ($lines === [''] ) continue;
        if (preg_match('/^\s*([-*•]|\d+[.)])\s+/u', $lines[0])) {
            $ordered = (bool)preg_match('/^\s*\d+[.)]\s+/', $lines[0]);
            $items = [];
            foreach ($lines as $ln) {
                if (preg_match('/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/u', $ln, $m)) $items[] = $m[1];
                elseif ($items) $items[count($items) - 1] .= ' ' . trim($ln);
            }
            $tag = $ordered ? 'ol' : 'ul';
            $html .= "<$tag>" . implode('', array_map(fn($i) => '<li>' . $inline($i) . '</li>', $items)) . "</$tag>";
            continue;
        }
        $out = [];
        foreach ($lines as $ln) {
            if (preg_match('/^#{1,6}\s+(.*)$/u', $ln, $m)) $out[] = '<strong>' . $inline($m[1]) . '</strong>';
            else $out[] = $inline($ln);
        }
        $html .= '<p>' . implode('<br>', $out) . '</p>';
    }
    // Models separate list items with blank lines ("loose" lists); keep them one list
    return str_replace(['</ol><ol>', '</ul><ul>'], '', $html);
}
