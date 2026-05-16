<?php
/**
 * Cascading dropdown source for the "Preview any voice" starting-point picker
 * on admin-tts.html. Four GET modes return the rows for each level; a fifth
 * returns the actual paragraph HTML so the UI can drop it into the preview
 * contenteditable.
 *
 *   ?mode=series                           -> [{key, number, label}]
 *   ?mode=volumes&series_key=N             -> [{key, number, label}]
 *   ?mode=chapters&volume_key=N            -> [{key, number, label}]
 *   ?mode=paragraphs&chapter_key=N         -> [{key, number, page, snippet}]
 *   ?mode=paragraph_text&paragraph_key=N   -> {key, html, plain}
 *
 * "label" is the human-friendly string the dropdown should show.
 * Inactive volumes and table paragraphs are filtered out — they would never
 * be useful starting points for a TTS read.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();

$mode = $_GET['mode'] ?? '';

if ($mode === 'series') {
    // Only return series that actually have at least one active volume so the
    // dropdown can't dead-end the user.
    $rows = $db->query("
        SELECT s.series_key, s.series_number, COALESCE(s.series_label, s.series_name) AS label
          FROM yy_series s
         WHERE EXISTS (SELECT 1 FROM yy_volume v WHERE v.series_key = s.series_key AND v.volume_active_flag = TRUE)
         ORDER BY s.series_sort, s.series_number
    ")->fetchAll();
    jsonResponse(['rows' => array_map(function ($r) {
        return [
            'key'    => (int)$r['series_key'],
            'number' => (int)$r['series_number'],
            'label'  => 's0' . $r['series_number'] . ' — ' . $r['label'],
        ];
    }, $rows)]);
}

if ($mode === 'volumes') {
    $seriesKey = (int)($_GET['series_key'] ?? 0);
    if (!$seriesKey) errorResponse('series_key required');
    $stmt = $db->prepare("
        SELECT volume_key, volume_number, volume_label
          FROM yy_volume
         WHERE series_key = ? AND volume_active_flag = TRUE
         ORDER BY volume_sort, volume_number
    ");
    $stmt->execute([$seriesKey]);
    jsonResponse(['rows' => array_map(function ($r) {
        return [
            'key'    => (int)$r['volume_key'],
            'number' => (int)$r['volume_number'],
            'label'  => 'v0' . $r['volume_number'] . ' — ' . ($r['volume_label'] ?: 'untitled'),
        ];
    }, $stmt->fetchAll())]);
}

if ($mode === 'chapters') {
    $volumeKey = (int)($_GET['volume_key'] ?? 0);
    if (!$volumeKey) errorResponse('volume_key required');
    $stmt = $db->prepare("
        SELECT chapter_key, chapter_number, chapter_name
          FROM yy_chapter
         WHERE volume_key = ?
         ORDER BY chapter_sort, chapter_number
    ");
    $stmt->execute([$volumeKey]);
    jsonResponse(['rows' => array_map(function ($r) {
        return [
            'key'    => (int)$r['chapter_key'],
            'number' => (int)$r['chapter_number'],
            'label'  => 'ch' . str_pad((string)$r['chapter_number'], 2, '0', STR_PAD_LEFT)
                      . ($r['chapter_name'] ? ' — ' . $r['chapter_name'] : ''),
        ];
    }, $stmt->fetchAll())]);
}

if ($mode === 'pages') {
    // Distinct content pages for a volume. paragraph_page is the footer
    // page number (same numbering shown in the flipbook), so it lines up
    // visually with what an operator sees on screen. Table-only pages are
    // excluded — the Paragraph picker would have nothing to offer there.
    $volumeKey = (int)($_GET['volume_key'] ?? 0);
    if (!$volumeKey) errorResponse('volume_key required');
    $stmt = $db->prepare("
        SELECT DISTINCT paragraph_page
          FROM yy_paragraph
         WHERE volume_key = ?
           AND paragraph_is_table = FALSE
           AND paragraph_page IS NOT NULL
         ORDER BY paragraph_page
    ");
    $stmt->execute([$volumeKey]);
    $rows = array_map(function ($r) {
        $pg = (int)$r['paragraph_page'];
        return ['key' => $pg, 'number' => $pg, 'label' => 'p.' . $pg];
    }, $stmt->fetchAll());
    jsonResponse(['rows' => $rows]);
}

if ($mode === 'paragraphs') {
    // Accept any combination of chapter_key, page, volume_key. At least one
    // scoping filter is required so we never accidentally return the whole
    // table. chapter_key and page are sibling filters — either or both
    // narrows the result; the volume_key is implied by chapter_key but is
    // also accepted directly when the operator picked Page first without a
    // chapter.
    $chapterKey = (int)($_GET['chapter_key'] ?? 0);
    $page       = (int)($_GET['page'] ?? 0);
    $volumeKey  = (int)($_GET['volume_key'] ?? 0);
    if (!$chapterKey && !$page) errorResponse('chapter_key or page required');
    $where = ['paragraph_is_table = FALSE'];
    $params = [];
    if ($chapterKey) { $where[] = 'chapter_key = ?'; $params[] = $chapterKey; }
    if ($page)       { $where[] = 'paragraph_page = ?'; $params[] = $page; }
    if ($volumeKey)  { $where[] = 'volume_key = ?'; $params[] = $volumeKey; }
    // Skip table paragraphs — they were excluded from the build worker and
    // would not make a sensible TTS starting point either.
    $stmt = $db->prepare("
        SELECT paragraph_key, paragraph_number, paragraph_page, paragraph_text_plain
          FROM yy_paragraph
         WHERE " . implode(' AND ', $where) . "
         ORDER BY paragraph_number
    ");
    $stmt->execute($params);
    jsonResponse(['rows' => array_map(function ($r) {
        $plain = (string)($r['paragraph_text_plain'] ?? '');
        $snippet = mb_substr($plain, 0, 70);
        if (mb_strlen($plain) > 70) $snippet .= '…';
        return [
            'key'    => (int)$r['paragraph_key'],
            'number' => (int)$r['paragraph_number'],
            'page'   => (int)($r['paragraph_page'] ?? 0),
            'label'  => '#' . $r['paragraph_number']
                      . ($r['paragraph_page'] ? ' (p.' . $r['paragraph_page'] . ')' : '')
                      . ($snippet ? ' — ' . $snippet : ''),
        ];
    }, $stmt->fetchAll())]);
}

if ($mode === 'paragraph_text') {
    $paraKey = (int)($_GET['paragraph_key'] ?? 0);
    if (!$paraKey) errorResponse('paragraph_key required');
    $stmt = $db->prepare("
        SELECT paragraph_key, paragraph_text_html, paragraph_text_plain
          FROM yy_paragraph
         WHERE paragraph_key = ?
    ");
    $stmt->execute([$paraKey]);
    $r = $stmt->fetch();
    if (!$r) errorResponse('paragraph not found');
    jsonResponse([
        'key'   => (int)$r['paragraph_key'],
        'html'  => (string)($r['paragraph_text_html'] ?? ''),
        'plain' => (string)($r['paragraph_text_plain'] ?? ''),
    ]);
}

errorResponse('Unknown mode: ' . $mode);
