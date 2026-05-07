<?php
/**
 * Returns book status the desktop uploader needs to decide what to upload.
 *
 * Auth: bearer token in `X-Author-Token` header. The token is stored in
 * yy_setting (scope='config', code='author-upload-token'). Constant-time
 * comparison via hash_equals; no session cookies, no CSRF token required.
 *
 * Response shape (one row per active YY volume):
 *   {
 *     "books": [
 *       {
 *         "volume_key":           6,
 *         "volume_code":          "YY-s02v03-Yada-Yahowah-Beyth-In-the-Family",
 *         "volume_label":         "Beyth — In the Family",
 *         "volume_docx":          "YY-s02v03-Yada-Yahowah-Beyth-In-the-Family.docx",
 *         "volume_pdf":           "YY-s02v03-Yada-Yahowah-Beyth-In-the-Family.pdf",
 *         "docx_mtime":           "2026-05-02T17:21:00+00:00",
 *         "pdf_mtime":            "2026-05-05T21:16:14+00:00",
 *         "pipeline_status":      "success",
 *         "pipeline_message":     "PDF + FlipHTML5 + offline package complete",
 *         "flip_code":            "47848914"
 *       },
 *       ...
 *     ]
 *   }
 *
 * pipeline_status mirrors yy_volume.volume_pipeline_status so the GUI can
 * show whether the last pipeline run finished or is still in flight.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();

// ── Bearer token check ────────────────────────────────────────────
$presented = $_SERVER['HTTP_X_AUTHOR_TOKEN'] ?? '';
$row = $db->query("
    SELECT setting_value
    FROM yy_setting
    WHERE setting_scope_code = 'config'
      AND setting_code       = 'author-upload-token'
    LIMIT 1
")->fetch();
$expected = $row && !empty($row['setting_value']) ? $row['setting_value'] : '';
if ($expected === '' || $presented === '' || !hash_equals($expected, $presented)) {
    errorResponse('Unauthorized', 401);
}

// ── Status query ──────────────────────────────────────────────────
$stmt = $db->query("
    SELECT volume_key,
           volume_code,
           volume_label,
           volume_docx,
           volume_pdf,
           volume_pipeline_status,
           volume_pipeline_message,
           volume_flip_code
    FROM yy_volume
    WHERE volume_active_flag = TRUE
      AND volume_code IS NOT NULL
      AND volume_code <> ''
    ORDER BY volume_sort, volume_key
");
$rows = $stmt->fetchAll();

// File mtimes are read off the filesystem rather than the DB so the
// uploader sees ground truth, not whatever the last pipeline run wrote
// to volume_docx_mtime/volume_pdf_mtime (which can lag).
$docxDir = '/var/www/html/u/books-word';
$pdfDir  = '/var/www/html/pdf';

$out = [];
foreach ($rows as $r) {
    $docxName = $r['volume_docx'] ?: ($r['volume_code'] . '.docx');
    $pdfName  = $r['volume_pdf']  ?: ($r['volume_code'] . '.pdf');
    $docxAbs  = $docxDir . '/' . $docxName;
    $pdfAbs   = $pdfDir  . '/' . $pdfName;
    $out[] = [
        'volume_key'       => (int)$r['volume_key'],
        'volume_code'      => $r['volume_code'],
        'volume_label'     => $r['volume_label'],
        'volume_docx'      => $docxName,
        'volume_pdf'       => $pdfName,
        'docx_mtime'       => is_file($docxAbs) ? gmdate('c', filemtime($docxAbs)) : null,
        'pdf_mtime'        => is_file($pdfAbs)  ? gmdate('c', filemtime($pdfAbs))  : null,
        'pipeline_status'  => $r['volume_pipeline_status'],
        'pipeline_message' => $r['volume_pipeline_message'],
        'flip_code'        => $r['volume_flip_code'],
    ];
}

jsonResponse(['books' => $out]);
