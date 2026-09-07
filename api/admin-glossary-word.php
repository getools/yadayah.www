<?php
/**
 * Admin API for the Glossary → Words section (yy_glossary_word).
 *
 * Standalone glossary terms — term / definition / see-also. Separate from
 * admin-glossary.php (Hebrew letters, the "Web" section) and from
 * admin-word.php (the Strong's Hebrew lexicon).
 *
 * GET               — list all terms (?q= filters term/definition/see-also)
 * GET ?key=N        — single term
 * POST              — create (JSON body)
 * PUT ?key=N        — partial update; unsupplied fields are left alone
 * DELETE ?key=N     — delete
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db     = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$key    = (int)($_GET['key'] ?? 0);

const GW_COLS = 'glossary_word_key, glossary_word_term, glossary_word_definition,
                 glossary_word_see_also, glossary_word_sort, glossary_word_active_flag,
                 glossary_word_dtime, glossary_word_revision_dtime, glossary_word_revision_num';

// Manual sort first, then alphabetical — same order the list index supports.
const GW_ORDER = 'ORDER BY glossary_word_sort ASC, lower(glossary_word_term) ASC';

if ($method === 'GET' && !$key) {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $stmt = $db->prepare('SELECT ' . GW_COLS . ' FROM yy_glossary_word
            WHERE glossary_word_term ILIKE :q
               OR glossary_word_definition ILIKE :q
               OR glossary_word_see_also ILIKE :q
            ' . GW_ORDER);
        $stmt->execute([':q' => '%' . $q . '%']);
    } else {
        $stmt = $db->query('SELECT ' . GW_COLS . ' FROM yy_glossary_word ' . GW_ORDER);
    }
    jsonResponse(['words' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $key) {
    $stmt = $db->prepare('SELECT ' . GW_COLS . ' FROM yy_glossary_word WHERE glossary_word_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('Word not found', 404);
    jsonResponse($row);
}

/** Read the JSON body once; a malformed body is an empty array, not a fatal. */
function gwBody(): array {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

/** Postgres unique-violation on the term index → readable message. */
function gwDuplicateGuard(callable $fn) {
    try {
        return $fn();
    } catch (PDOException $e) {
        if (($e->errorInfo[0] ?? '') === '23505') errorResponse('That term already exists in the glossary.', 409);
        throw $e;
    }
}

if ($method === 'POST') {
    $data = gwBody();
    $term = trim((string)($data['glossary_word_term'] ?? ''));
    if ($term === '') errorResponse('Term is required');

    gwDuplicateGuard(function () use ($db, $data, $term) {
        $stmt = $db->prepare('INSERT INTO yy_glossary_word
            (glossary_word_term, glossary_word_definition, glossary_word_see_also,
             glossary_word_sort, glossary_word_active_flag)
            VALUES (?, ?, ?, ?, ?)
            RETURNING glossary_word_key');
        $stmt->execute([
            $term,
            trim((string)($data['glossary_word_definition'] ?? '')),
            trim((string)($data['glossary_word_see_also'] ?? '')),
            (int)($data['glossary_word_sort'] ?? 0),
            // PDO sends PHP booleans as '' for false on pgsql — cast to int.
            (int)(array_key_exists('glossary_word_active_flag', $data)
                ? (bool)$data['glossary_word_active_flag'] : true),
        ]);
        jsonResponse(['saved' => true, 'glossary_word_key' => (int)$stmt->fetchColumn()]);
    });
}

if ($method === 'PUT' && $key) {
    $data = gwBody();
    // Partial update: only columns actually present in the body are touched,
    // so a caller that sends one field never blanks the rest.
    $allowed = [
        'glossary_word_term'        => 'text',
        'glossary_word_definition'  => 'text',
        'glossary_word_see_also'    => 'text',
        'glossary_word_sort'        => 'int',
        'glossary_word_active_flag' => 'bool',
    ];
    $fields = [];
    $params = [];
    foreach ($allowed as $col => $type) {
        if (!array_key_exists($col, $data)) continue;
        if ($col === 'glossary_word_term' && trim((string)$data[$col]) === '') errorResponse('Term cannot be blank');
        $fields[] = "$col = ?";
        if ($type === 'int')       $params[] = (int)$data[$col];
        elseif ($type === 'bool')  $params[] = (int)(bool)$data[$col];
        else                       $params[] = trim((string)$data[$col]);
    }
    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;

    gwDuplicateGuard(function () use ($db, $fields, $params) {
        $db->prepare('UPDATE yy_glossary_word SET ' . implode(', ', $fields) . ' WHERE glossary_word_key = ?')
           ->execute($params);
        jsonResponse(['saved' => true]);
    });
}

if ($method === 'DELETE' && $key) {
    $stmt = $db->prepare('DELETE FROM yy_glossary_word WHERE glossary_word_key = ?');
    $stmt->execute([$key]);
    if (!$stmt->rowCount()) errorResponse('Word not found', 404);
    jsonResponse(['deleted' => true]);
}

errorResponse('Invalid request', 400);
