<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resource-downloads.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$stmt = $db->query("SELECT * FROM yy_resource WHERE resource_active_flag = true ORDER BY resource_sort ASC, resource_key ASC");
$rows = $stmt->fetchAll();
// Only files that exist on the server are offered.
foreach ($rows as &$row) $row['resource_downloads'] = resourceDownloadsEnrich($row['resource_downloads'], true);
unset($row);
jsonResponse(['resources' => $rows]);
