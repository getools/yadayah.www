<?php
/**
 * Ask Yada RAG helpers — embedding generation and similarity search.
 *
 * Uses Voyage AI embeddings (voyage-3-lite, 1024 dimensions) via the Anthropic-compatible API.
 * Falls back to keyword-based search if embedding fails.
 */

/**
 * Generate an embedding vector for a text string using Voyage AI.
 */
function generateEmbedding(string $text, string $apiKey): ?array {
    $text = mb_substr(trim($text), 0, 4000); // Voyage limit
    if (!$text || !$apiKey) return null;

    $ch = curl_init('https://api.voyageai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'voyage-3-lite',
            'input' => [$text],
            'input_type' => 'document',
        ]),
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $data = json_decode($response, true);
    return $data['data'][0]['embedding'] ?? null;
}

/**
 * Generate embeddings for up to 128 texts in one call. Returns an array of
 * embeddings aligned to the input order, or null on failure. Voyage caps at
 * 128 inputs / 320k tokens per request — caller must chunk.
 */
function generateEmbeddingsBatch(array $texts, string $apiKey, int $timeout = 60): ?array {
    if (!$texts || !$apiKey) return null;
    $payload = array_map(function($t) { return mb_substr(trim($t), 0, 4000); }, $texts);
    $ch = curl_init('https://api.voyageai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'voyage-3-lite',
            'input' => $payload,
            'input_type' => 'document',
        ]),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($httpCode !== 200 || !$response) {
        fwrite(STDERR, "[voyage] http={$httpCode} err={$err} body=" . substr((string)$response, 0, 300) . "\n");
        return null;
    }
    $data = json_decode($response, true);
    if (!isset($data['data']) || !is_array($data['data'])) return null;
    // Voyage returns items with an "index" field — sort to align with input order.
    usort($data['data'], function($a, $b) { return ($a['index'] ?? 0) <=> ($b['index'] ?? 0); });
    return array_map(function($d) { return $d['embedding'] ?? null; }, $data['data']);
}

/**
 * Store an embedding in yy_ask_embedding.
 */
function storeEmbedding(PDO $db, string $sourceType, ?int $sourceKey, string $text, array $embedding, array $metadata = []): void {
    $vecStr = '[' . implode(',', $embedding) . ']';
    $stmt = $db->prepare("
        INSERT INTO yy_ask_embedding (source_type, source_key, content_text, embedding, metadata, embedding_model)
        VALUES (?, ?, ?, ?::vector, ?::jsonb, 'voyage-3-lite')
        ON CONFLICT DO NOTHING
    ");
    $stmt->execute([$sourceType, $sourceKey, $text, $vecStr, json_encode($metadata)]);
}

/**
 * Find similar Q&A pairs using vector similarity search.
 * Returns array of {content_text, metadata, similarity}.
 */
function searchSimilar(PDO $db, array $queryEmbedding, int $limit = 5, string $sourceType = null): array {
    $vecStr = '[' . implode(',', $queryEmbedding) . ']';
    $where = "embedding IS NOT NULL";
    $params = [$vecStr, $limit];
    if ($sourceType) {
        $where .= " AND source_type = ?";
        $params[] = $sourceType;
    }
    $stmt = $db->prepare("
        SELECT content_text, metadata, 1 - (embedding <=> ?::vector) AS similarity
        FROM yy_ask_embedding
        WHERE {$where}
        ORDER BY embedding <=> ?::vector
        LIMIT ?
    ");
    // Need to pass vecStr twice for the ORDER BY
    $params2 = [$vecStr, $vecStr, $limit];
    if ($sourceType) $params2 = [$vecStr, $vecStr, $limit, $sourceType];

    // Rebuild properly
    $sql = "SELECT content_text, metadata, 1 - (embedding <=> ?::vector) AS similarity FROM yy_ask_embedding WHERE embedding IS NOT NULL";
    $bindParams = [$vecStr];
    if ($sourceType) {
        $sql .= " AND source_type = ?";
        $bindParams[] = $sourceType;
    }
    $sql .= " ORDER BY embedding <=> ?::vector LIMIT ?";
    $bindParams[] = $vecStr;
    $bindParams[] = $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($bindParams);
    return $stmt->fetchAll();
}

/**
 * Find book paragraphs semantically similar to the question via vector
 * search, weighted by yy_volume.volume_ask_rating. Returns rows shaped like
 * the keyword-search rows so ask.php can mix them into the same context
 * block: {paragraph_text_plain, volume_label, series_label, paragraph_page,
 * rank}. The `rank` is cosine_similarity * (volume_ask_rating / 50.0) so a
 * 100-rated book's content out-weighs a 50-rated book's 2:1, identical to
 * the keyword path's weighting.
 */
function searchSimilarParagraphs(PDO $db, array $queryEmbedding, int $limit = 10): array {
    if (!$queryEmbedding) return [];
    $vecStr = '[' . implode(',', $queryEmbedding) . ']';
    $stmt = $db->prepare("
        SELECT p.paragraph_text_plain,
               v.volume_label,
               s.series_label,
               p.paragraph_page,
               (1 - (e.embedding <=> ?::vector)) * (v.volume_ask_rating / 50.0) AS rank
        FROM yy_ask_embedding e
        JOIN yy_paragraph p ON p.paragraph_key = e.source_key
        JOIN yy_volume    v ON v.volume_key   = p.volume_key
        JOIN yy_series    s ON s.series_key   = p.series_key
        WHERE e.source_type = 'paragraph'
          AND e.embedding IS NOT NULL
          AND p.paragraph_active_flag = true
          AND v.volume_ask_yada_flag = true
          AND v.volume_ask_rating > 0
        ORDER BY e.embedding <=> ?::vector
        LIMIT ?
    ");
    $stmt->execute([$vecStr, $vecStr, $limit * 3]); // over-fetch then re-rank
    $rows = $stmt->fetchAll();
    // The ORDER BY is by raw distance (no rating weight) so the LIMIT is
    // taken pre-weighting. Re-sort by the weighted rank we computed and
    // truncate to the caller's limit. 3x oversample is enough that a
    // rating=10 book can't push a rating=100 book out of the top N just
    // because its raw embedding distance was slightly closer.
    usort($rows, function($a, $b) { return ($b['rank'] <=> $a['rank']); });
    return array_slice($rows, 0, $limit);
}

/**
 * Get active learned corrections/guidelines to inject into the system prompt.
 */
function getLearnedPromptAdditions(PDO $db): string {
    $stmt = $db->query("
        SELECT learned_type, learned_question, learned_answer
        FROM yy_ask_learned
        WHERE learned_active_flag = TRUE
        ORDER BY learned_priority DESC, learned_dtime DESC
        LIMIT 50
    ");
    $rows = $stmt->fetchAll();
    if (!$rows) return '';

    $block = "--- LEARNED CORRECTIONS AND GUIDELINES ---\n";
    $block .= "These are verified corrections from moderators. Follow these EXACTLY when relevant:\n\n";
    foreach ($rows as $r) {
        if ($r['learned_question']) {
            $block .= "Q: " . trim($r['learned_question']) . "\n";
        }
        $block .= "CORRECT ANSWER: " . trim($r['learned_answer']) . "\n\n";
    }
    return $block;
}

/**
 * After a response is generated, embed the Q&A pair for future retrieval.
 * Called asynchronously after the response is sent.
 */
function embedQAPair(PDO $db, int $logKey, string $question, string $answer): void {
    // Load Voyage API key
    $apiKey = getenv('VOYAGE_API_KEY') ?: '';
    if (!$apiKey) {
        // Try from DB settings
        $stmt = $db->query("SELECT setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_code = 'voyage-api-key'");
        $apiKey = $stmt->fetchColumn() ?: '';
    }
    if (!$apiKey) return;

    $combinedText = "Q: " . $question . "\nA: " . mb_substr($answer, 0, 2000);
    $embedding = generateEmbedding($combinedText, $apiKey);
    if ($embedding) {
        storeEmbedding($db, 'qa_pair', $logKey, $combinedText, $embedding, [
            'question' => $question,
            'answer_preview' => mb_substr($answer, 0, 500),
        ]);
    }
}
