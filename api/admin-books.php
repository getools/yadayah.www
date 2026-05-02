<?php
/**
 * Admin API for Books (Series + Volumes).
 * GET                — list all series with their volumes
 * GET ?scrolls=1     — list scrolls for dropdown
 * PUT ?key=N         — update a volume
 * PUT ?series_key=N  — update a series
 * POST               — create a new volume
 * POST ?action=add_series — create a new series
 * DELETE ?key=N      — delete a volume
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$key = (int)($_GET['key'] ?? 0);

if ($method === 'GET' && isset($_GET['scrolls'])) {
    $stmt = $db->query("SELECT yah_scroll_key, yah_scroll_label_yy FROM yah_scroll ORDER BY yah_scroll_label_yy");
    jsonResponse(['scrolls' => $stmt->fetchAll()]);
}

if ($method === 'GET') {
    $series = $db->query("
        SELECT s.*,
               (SELECT COUNT(*) FROM yy_volume v WHERE v.series_key = s.series_key) AS volume_count
        FROM yy_series s
        ORDER BY s.series_sort, s.series_key
    ")->fetchAll();

    $volumes = $db->query("
        SELECT v.*, s.series_label
        FROM yy_volume v
        JOIN yy_series s ON s.series_key = v.series_key
        ORDER BY s.series_sort, v.volume_sort, v.volume_key
    ")->fetchAll();

    jsonResponse(['series' => $series, 'volumes' => $volumes]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    if ($action === 'add_series') {
        $label = trim($data['series_label'] ?? '');
        if (!$label) errorResponse('Series label is required');
        $stmt = $db->prepare("
            INSERT INTO yy_series (series_label, series_name, series_number, series_sort, series_summary, series_image)
            VALUES (?, ?, ?, ?, ?, ?) RETURNING series_key
        ");
        $stmt->execute([
            $label,
            trim($data['series_name'] ?? '') ?: $label,
            (int)($data['series_number'] ?? 0),
            (int)($data['series_sort'] ?? 0),
            trim($data['series_summary'] ?? '') ?: null,
            trim($data['series_image'] ?? '') ?: null,
        ]);
        jsonResponse(['saved' => true, 'series_key' => $stmt->fetchColumn()]);
    }

    // Create volume
    $label = trim($data['volume_label'] ?? '');
    $seriesKey = (int)($data['series_key'] ?? 0);
    if (!$label) errorResponse('Volume label is required');
    if (!$seriesKey) errorResponse('Series is required');

    $stmt = $db->prepare("
        INSERT INTO yy_volume (series_key, volume_label, volume_name, volume_number, volume_sort,
                               volume_flip_code, volume_pdf, volume_page_count, volume_active_flag)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING volume_key
    ");
    $stmt->execute([
        $seriesKey,
        $label,
        trim($data['volume_name'] ?? '') ?: $label,
        (int)($data['volume_number'] ?? 0),
        (int)($data['volume_sort'] ?? 0),
        trim($data['volume_flip_code'] ?? '') ?: null,
        trim($data['volume_pdf'] ?? '') ?: null,
        (int)($data['volume_page_count'] ?? 0) ?: null,
        (bool)($data['volume_active_flag'] ?? true) ? 'true' : 'false',
    ]);
    jsonResponse(['saved' => true, 'volume_key' => $stmt->fetchColumn()]);
}

if ($method === 'PUT') {
    $seriesKey = (int)($_GET['series_key'] ?? 0);

    if ($seriesKey) {
        // Update series
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $fields = []; $params = [];
        foreach (['series_label', 'series_name', 'series_summary', 'series_image'] as $col) {
            if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = trim($data[$col] ?? '') ?: null; }
        }
        foreach (['series_number', 'series_sort'] as $col) {
            if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = (int)$data[$col]; }
        }
        if (empty($fields)) errorResponse('Nothing to update');
        $params[] = $seriesKey;
        $db->prepare("UPDATE yy_series SET " . implode(', ', $fields) . " WHERE series_key = ?")->execute($params);
        jsonResponse(['saved' => true]);
    }

    if (!$key) errorResponse('Volume key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $fields = []; $params = [];
    foreach (['volume_label', 'volume_name', 'volume_flip_code', 'volume_pdf'] as $col) {
        if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = trim($data[$col] ?? '') ?: null; }
    }
    foreach (['series_key', 'volume_number', 'volume_sort', 'volume_page_count'] as $col) {
        if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = (int)$data[$col]; }
    }
    if (array_key_exists('volume_active_flag', $data)) {
        $fields[] = "volume_active_flag = ?"; $params[] = (bool)$data['volume_active_flag'] ? 'true' : 'false';
    }

    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;
    $db->prepare("UPDATE yy_volume SET " . implode(', ', $fields) . " WHERE volume_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

if ($method === 'DELETE') {
    if (!$key) errorResponse('Volume key required');
    $paras = $db->prepare("SELECT COUNT(*) FROM yy_paragraph WHERE volume_key = ?");
    $paras->execute([$key]);
    if ((int)$paras->fetchColumn() > 0) errorResponse('Cannot delete: volume has paragraphs');

    $db->prepare("DELETE FROM yy_volume WHERE volume_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
