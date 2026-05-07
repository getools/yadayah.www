<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

// Only series that contain at least one search-eligible volume are returned —
// otherwise the dropdown shows series with no clickable books underneath.
$series = $pdo->query("
    SELECT s.series_key, s.series_label, s.series_name
    FROM yy_series s
    WHERE EXISTS (
        SELECT 1 FROM yy_volume v
        WHERE v.series_key = s.series_key
          AND v.volume_active_flag = TRUE
          AND v.volume_search_flag = TRUE
    )
    ORDER BY s.series_sort
")->fetchAll();

// volume_search_flag is the editorial 'include in book search' toggle
// (admin → Books → Search flag). Volumes with the flag off are excluded
// from the dropdown AND from the search hits themselves (see search.php).
$volumes = $pdo->query("
    SELECT volume_key, series_key, volume_label
    FROM yy_volume
    WHERE volume_active_flag = TRUE
      AND volume_search_flag = TRUE
    ORDER BY volume_sort
")->fetchAll();

jsonResponse(['series' => $series, 'volumes' => $volumes]);
