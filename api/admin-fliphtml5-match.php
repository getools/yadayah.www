<?php
/**
 * FlipHTML5 auto-match endpoint.
 *
 * GET  → counts of matched / unmatched volumes + last match-run summary +
 *        per-volume list of unmatched volumes (with PDF URLs the admin can
 *        download to upload to FlipHTML5).
 * POST → trigger /opt/yada-www/cron-fliphtml5-match.sh in the background.
 *        Returns immediately; the next GET will reflect the new state.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();

$statusFile = '/var/www/html/jobs/fliphtml5/match-status.json';
$triggerFile = '/var/www/html/jobs/fliphtml5/match-trigger.req';
@mkdir('/var/www/html/jobs/fliphtml5', 0775, true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = file_exists($statusFile) ? json_decode(@file_get_contents($statusFile), true) : null;

    // Unmatched volumes — i.e. have a docx + pdf but no flip_code yet.
    $stmt = $db->query("
        SELECT v.volume_key, v.volume_code, v.volume_label,
               v.volume_pdf, v.volume_docx,
               s.series_label
          FROM yy_volume v
          LEFT JOIN yy_series s ON s.series_key = v.series_key
         WHERE v.volume_pdf IS NOT NULL
           AND v.volume_pdf <> ''
           AND (v.volume_flip_code IS NULL OR v.volume_flip_code = '')
         ORDER BY COALESCE(s.series_sort, 9999), v.volume_key
    ");
    $unmatched = $stmt->fetchAll();

    $matched = (int)$db->query("
        SELECT COUNT(*) FROM yy_volume
         WHERE volume_flip_code IS NOT NULL AND volume_flip_code <> ''
    ")->fetchColumn();

    $totalWithDocx = (int)$db->query("
        SELECT COUNT(*) FROM yy_volume WHERE volume_pdf IS NOT NULL AND volume_pdf <> ''
    ")->fetchColumn();

    jsonResponse([
        'matched'         => $matched,
        'unmatched_count' => count($unmatched),
        'total'           => $totalWithDocx,
        'unmatched'       => $unmatched,
        'last_run'        => $status,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Drop a trigger file. A small host-side watcher (added below) picks it
    // up and runs the matcher. We do it this way rather than exec() because
    // the web container can't run docker exec from inside itself.
    @file_put_contents($triggerFile, gmdate('c') . "\n");
    @chmod($triggerFile, 0664);
    jsonResponse(['triggered' => true, 'note' => 'Match run will start within ~30s; refresh status to see results.']);
}

errorResponse('Method not allowed', 405);
