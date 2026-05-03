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
        SELECT v.*, s.series_label,
               COALESCE((SELECT COUNT(*) FROM yy_paragraph p WHERE p.volume_key = v.volume_key AND p.paragraph_active_flag IS DISTINCT FROM FALSE), 0) AS paragraph_count_live,
               COALESCE((SELECT COUNT(*) FROM yy_translation t WHERE t.volume_key = v.volume_key), 0) AS translation_count_live
        FROM yy_volume v
        JOIN yy_series s ON s.series_key = v.series_key
        ORDER BY s.series_sort, v.volume_sort, v.volume_key
    ")->fetchAll();

    // Attach filesystem mtime for each artifact so the UI can show "last
    // generated X ago" and detect when a derived file (PDF, FLIP) is older
    // than its source (DOCX, or upstream PDF) — which means it needs a fresh
    // regeneration even if the row says "success".
    $publicRoot = dirname(__DIR__);
    foreach ($volumes as &$v) {
        $docxAbs = !empty($v['volume_docx'])      ? $publicRoot . '/u/books-word/' . $v['volume_docx']     : null;
        $pdfAbs  = !empty($v['volume_pdf'])       ? $publicRoot . '/pdf/'       . $v['volume_pdf']      : null;
        $flipAbs = !empty($v['volume_flip_code']) ? $publicRoot . '/flipbook/'  . $v['volume_flip_code'] : null;
        $v['volume_docx_mtime'] = ($docxAbs && is_file($docxAbs)) ? date('c', filemtime($docxAbs)) : null;
        $v['volume_pdf_mtime']  = ($pdfAbs  && is_file($pdfAbs))  ? date('c', filemtime($pdfAbs))  : null;
        $v['volume_flip_mtime'] = ($flipAbs && is_dir($flipAbs))  ? date('c', filemtime($flipAbs)) : null;
    }
    unset($v);

    jsonResponse(['series' => $series, 'volumes' => $volumes]);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'upload_docx') {
    if (!$key) errorResponse('Volume key required');
    $file = $_FILES['docx'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('No file uploaded');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'docx') errorResponse('Only .docx files are accepted');

    // Look up the volume to compose a stable filename
    $volStmt = $db->prepare("
        SELECT v.volume_key, v.volume_label, v.volume_name, v.volume_pdf, s.series_key,
               COALESCE(s.series_number, 0) AS series_number, COALESCE(v.volume_number, 0) AS volume_number
        FROM yy_volume v JOIN yy_series s ON s.series_key = v.series_key
        WHERE v.volume_key = ?
    ");
    $volStmt->execute([$key]);
    $vol = $volStmt->fetch();
    if (!$vol) errorResponse('Volume not found', 404);

    // Build a deterministic filename: prefer existing PDF basename, else compose
    $base = '';
    if (!empty($vol['volume_pdf'])) {
        $base = pathinfo($vol['volume_pdf'], PATHINFO_FILENAME);
    }
    if (!$base) {
        $sanitize = function($s) {
            $s = preg_replace('/[^A-Za-z0-9 _-]/', '', $s ?? '');
            return trim(preg_replace('/\s+/', '-', $s), '-');
        };
        $base = sprintf('YY-s%02dv%02d-%s', (int)$vol['series_number'], (int)$vol['volume_number'], $sanitize($vol['volume_label'] ?: $vol['volume_name'] ?: ''));
    }
    $docxName = $base . '.docx';
    $pdfName  = $base . '.pdf';

    // Storage paths (works in both Docker container and local dev)
    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
    $docxDir = $publicRoot . '/u/books-word';
    if (!is_dir($docxDir) && !@mkdir($docxDir, 0775, true)) errorResponse('Cannot create u/books-word directory');
    $docxPath = $docxDir . '/' . $docxName;
    if (!move_uploaded_file($file['tmp_name'], $docxPath)) errorResponse('Failed to save .docx file');

    // Persist source filename + flag BOTH pipelines as queued: the docx→pdf→
    // flipbook pipeline AND the paragraph/translation extraction pipeline.
    // The latter rebuilds yy_paragraph and yy_translation rows from the new
    // docx so the in-app reader, search, and translation links pick up
    // changes without requiring a manual local script run.
    $db->prepare("
        UPDATE yy_volume
           SET volume_docx = ?,
               volume_pipeline_status = 'queued',
               volume_pipeline_message = 'Awaiting host worker (libreoffice + fliphtml5)',
               volume_pipeline_retry_count = 0,
               volume_parse_status = 'queued',
               volume_parse_message = 'Awaiting host worker (paragraph + translation extraction)',
               volume_revision_dtime = NOW()
         WHERE volume_key = ?
    ")->execute([$docxName, $key]);

    // Drop a job file the host-side worker watches.
    // The worker runs LibreOffice (DOCX→PDF), then re-uploads to FlipHTML5,
    // then downloads the published flipbook .zip and extracts to /flipbook/<code>/.
    //
    // Path discipline: $publicRoot is /var/www/html inside the container,
    // which is bind-mounted to /opt/yada-www/public on the host. Anything we
    // write under $publicRoot/jobs lands in /opt/yada-www/public/jobs which
    // the host-side worker can read. (The previous $publicRoot . '/../jobs'
    // resolved to /var/www/jobs in-container — NOT bind-mounted, so the
    // worker never saw any of the queued jobs.)
    $jobsDir = $publicRoot . '/jobs/book-pipeline';
    if (!is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);
    $jobPayload = [
        'volume_key'  => (int)$key,
        'docx_name'   => $docxName,
        'pdf_name'    => $pdfName,
        'flip_code'   => null,  // Worker fills this in after upload
        'queued_at'   => date('c'),
    ];
    $jobFile = $jobsDir . '/' . sprintf('%010d', $key) . '_' . time() . '.json';
    @file_put_contents($jobFile, json_encode($jobPayload, JSON_PRETTY_PRINT));

    jsonResponse([
        'saved'       => true,
        'docx'        => $docxName,
        'pdf'         => $pdfName,
        'job_queued'  => basename($jobFile),
        'message'     => 'Uploaded. Conversion + FlipHTML5 build will run on the host (typically 1–3 minutes).',
    ]);
}

// Manual retry of a failed pipeline. Resets retry_count, flips status back
// to 'queued', and drops a fresh job file. Used by the Books admin UI when
// auto-retry exhausts its 3-attempt cap or when status is 'error' otherwise.
if ($method === 'POST' && (($_GET['action'] ?? '') === 'retry_pipeline'
                            || (json_decode(file_get_contents('php://input'), true)['action'] ?? '') === 'retry_pipeline')) {
    if (!$key) {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $key = (int)($body['volume_key'] ?? 0);
    }
    if (!$key) errorResponse('Volume key required');

    $volStmt = $db->prepare("SELECT volume_key, volume_docx, volume_pdf FROM yy_volume WHERE volume_key = ?");
    $volStmt->execute([$key]);
    $vol = $volStmt->fetch();
    if (!$vol)               errorResponse('Volume not found', 404);
    if (!$vol['volume_docx']) errorResponse('Volume has no docx — upload one before retrying');

    $db->prepare("
        UPDATE yy_volume
           SET volume_pipeline_status = 'queued',
               volume_pipeline_message = 'Manually requeued by admin',
               volume_pipeline_retry_count = 0,
               volume_revision_dtime = NOW()
         WHERE volume_key = ?
    ")->execute([$key]);

    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
    $jobsDir    = $publicRoot . '/jobs/book-pipeline';
    if (!is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);
    $pdfName = $vol['volume_pdf'] ?: (pathinfo($vol['volume_docx'], PATHINFO_FILENAME) . '.pdf');
    $jobPayload = [
        'volume_key'   => (int)$key,
        'docx_name'    => $vol['volume_docx'],
        'pdf_name'     => $pdfName,
        'flip_code'    => null,
        'queued_at'    => date('c'),
        'manual_retry' => true,
    ];
    $jobFile = $jobsDir . '/' . sprintf('%010d', $key) . '_retry_' . time() . '.json';
    @file_put_contents($jobFile, json_encode($jobPayload, JSON_PRETTY_PRINT));
    jsonResponse(['queued' => true, 'job' => basename($jobFile), 'message' => 'Pipeline requeued — runs on next host worker tick (≤1 min).']);
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
    foreach (['volume_active_flag', 'volume_search_flag', 'volume_parse_flag', 'volume_ask_yada_flag'] as $col) {
        if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = (bool)$data[$col]; }
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
