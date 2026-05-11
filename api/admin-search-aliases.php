<?php
/**
 * CRUD for yy_search_alias — manual term synonyms layered on top of the
 * automatic Hebrew consonant-skeleton fuzzy matching in search.php.
 *
 *   GET                       → list all aliases
 *   POST   { term, target }   → insert pair
 *   PUT    ?key=N { term, target }
 *   DELETE ?key=N
 *
 * Each row maps one query term → one alternate form that the search
 * code will also ILIKE against paragraph_text_plain. Add bidirectional
 * pairs (one row each direction) when you want symmetric matching.
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Sort: curated first, then by detection weight desc, then alphabetic.
    $rows = $db->query("
        SELECT alias_key, alias_term, alias_target,
               COALESCE(alias_weight, 1)        AS alias_weight,
               COALESCE(alias_session_count, 0) AS alias_session_count,
               COALESCE(alias_curated_flag, FALSE) AS alias_curated_flag,
               alias_auto_dtime
          FROM yy_search_alias
         ORDER BY alias_curated_flag DESC,
                  alias_weight DESC,
                  lower(alias_term),
                  alias_key
    ")->fetchAll();
    jsonResponse($rows);
}

// Accept an auto-detected alias — bumps weight to 10 and sets the
// curated flag so future auto-detections don't decrement or rewrite it.
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'accept') {
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('key required');
    $db->prepare("UPDATE yy_search_alias SET alias_curated_flag = TRUE, alias_weight = GREATEST(alias_weight, 10) WHERE alias_key = ?")
       ->execute([$key]);
    jsonResponse(['ok' => true]);
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $term   = trim((string)($body['alias_term']   ?? ''));
    $target = trim((string)($body['alias_target'] ?? ''));
    if ($term === '' || $target === '') errorResponse('alias_term and alias_target are required');
    if (mb_strlen($term) > 100 || mb_strlen($target) > 100) errorResponse('term/target capped at 100 chars');
    // Manually-added rows are curated by default with weight floor 10
    // — keeps them above auto-detected (weight starts at 1) and pins
    // them so the auto-promote upsert never touches them.
    $stmt = $db->prepare("
        INSERT INTO yy_search_alias (alias_term, alias_target, alias_weight, alias_curated_flag)
        VALUES (?, ?, 10, TRUE)
        ON CONFLICT (lower(alias_term), lower(alias_target)) DO UPDATE
            SET alias_curated_flag = TRUE,
                alias_weight       = GREATEST(yy_search_alias.alias_weight, 10)
        RETURNING alias_key
    ");
    $stmt->execute([$term, $target]);
    jsonResponse(['alias_key' => (int)$stmt->fetchColumn()], 201);
}

if ($method === 'PUT') {
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('key required');
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $term   = trim((string)($body['alias_term']   ?? ''));
    $target = trim((string)($body['alias_target'] ?? ''));
    if ($term === '' || $target === '') errorResponse('alias_term and alias_target are required');
    if (mb_strlen($term) > 100 || mb_strlen($target) > 100) errorResponse('term/target capped at 100 chars');
    $db->prepare("UPDATE yy_search_alias SET alias_term = ?, alias_target = ? WHERE alias_key = ?")
       ->execute([$term, $target, $key]);
    jsonResponse(['ok' => true]);
}

if ($method === 'DELETE') {
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('key required');
    $db->prepare("DELETE FROM yy_search_alias WHERE alias_key = ?")->execute([$key]);
    jsonResponse(['ok' => true]);
}

errorResponse('Method not allowed', 405);
