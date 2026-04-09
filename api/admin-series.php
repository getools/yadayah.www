<?php
/**
 * Admin API for yy_series CRUD.
 * GET — list all series with volume counts
 * PUT ?key=N — update a series
 * POST — create a new series
 * DELETE ?key=N — delete a series (only if no volumes/paragraphs reference it)
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$key = (int)($_GET['key'] ?? 0);

if ($method === 'GET') {
    $stmt = $db->query("
        SELECT s.*,
               (SELECT COUNT(*) FROM yy_volume v WHERE v.series_key = s.series_key) AS volume_count,
               (SELECT COUNT(*) FROM yy_paragraph p WHERE p.series_key = s.series_key) AS paragraph_count
        FROM yy_series s
        ORDER BY s.series_sort, s.series_key
    ");
    jsonResponse(['series' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $label = trim($data['series_label'] ?? '');
    if (!$label) errorResponse('Series label is required');

    $stmt = $db->prepare("
        INSERT INTO yy_series (series_number, series_label, series_name, series_sort, series_summary, series_image)
        VALUES (?, ?, ?, ?, ?, ?) RETURNING series_key
    ");
    $stmt->execute([
        (int)($data['series_number'] ?? 0),
        $label,
        trim($data['series_name'] ?? '') ?: $label,
        (int)($data['series_sort'] ?? 0),
        trim($data['series_summary'] ?? '') ?: null,
        trim($data['series_image'] ?? '') ?: null,
    ]);
    $carouselCache = sys_get_temp_dir() . '/yada_series_carousel.json';
    if (file_exists($carouselCache)) @unlink($carouselCache);
    jsonResponse(['saved' => true, 'series_key' => $stmt->fetchColumn()]);
}

if ($method === 'PUT') {
    if (!$key) errorResponse('Series key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $fields = [];
    $params = [];

    foreach (['series_label', 'series_name', 'series_summary', 'series_image'] as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            $params[] = trim($data[$col] ?? '') ?: null;
        }
    }
    foreach (['series_number', 'series_sort'] as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            $params[] = (int)$data[$col];
        }
    }

    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;
    $db->prepare("UPDATE yy_series SET " . implode(', ', $fields) . " WHERE series_key = ?")->execute($params);
    $carouselCache = sys_get_temp_dir() . '/yada_series_carousel.json';
    if (file_exists($carouselCache)) @unlink($carouselCache);
    jsonResponse(['saved' => true]);
}

if ($method === 'DELETE') {
    if (!$key) errorResponse('Series key required');

    // Check for references
    $vols = $db->prepare("SELECT COUNT(*) FROM yy_volume WHERE series_key = ?");
    $vols->execute([$key]);
    if ((int)$vols->fetchColumn() > 0) errorResponse('Cannot delete: series has volumes');

    $paras = $db->prepare("SELECT COUNT(*) FROM yy_paragraph WHERE series_key = ?");
    $paras->execute([$key]);
    if ((int)$paras->fetchColumn() > 0) errorResponse('Cannot delete: series has paragraphs');

    $db->prepare("DELETE FROM yy_series WHERE series_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
