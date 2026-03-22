<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$stmt = $db->query("SELECT * FROM yy_resource WHERE resource_active_flag = true ORDER BY resource_sort ASC, resource_key ASC");
jsonResponse(['resources' => $stmt->fetchAll()]);
