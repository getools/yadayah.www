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
