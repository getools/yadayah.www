<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$stmt = $db->query('SELECT series_key, series_name, series_label, series_sort FROM yy_series ORDER BY series_sort');
jsonResponse($stmt->fetchAll());
