<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) errorResponse('Invalid JSON');

$table = $input['table'] ?? '';
$keyCol = $input['key_col'] ?? '';
$sortCol = $input['sort_col'] ?? '';
$key1 = (int)($input['key1'] ?? 0);
$key2 = (int)($input['key2'] ?? 0);

if (!$key1 || !$key2) errorResponse('Two keys required');

// Whitelist allowed table/column combinations
$allowed = [
    'timeline'    => ['table' => 'yy_timeline',  'key' => 'timeline_key',    'sort' => 'timeline_sort'],
    'memorial'    => ['table' => 'yy_memorial',  'key' => 'memorial_key',     'sort' => 'memorial_sort'],
    'media'       => ['table' => 'yy_media',     'key' => 'media_key',        'sort' => 'media_sort'],
    'ask_qanda'   => ['table' => 'yy_ask_qanda', 'key' => 'ask_qanda_key',    'sort' => 'ask_qanda_sort'],
    'ticker'      => ['table' => 'yy_ticker',    'key' => 'ticker_key',      'sort' => 'ticker_sort'],
];

if (!isset($allowed[$table])) errorResponse('Invalid table');
$cfg = $allowed[$table];
if ($keyCol !== $cfg['key'] || $sortCol !== $cfg['sort']) errorResponse('Invalid columns');

$db = getDb();
setCurrentUser($db, $user['user_key']);

$tableName = $cfg['table'];

// Get current sort values
$sql = "SELECT {$cfg['key']}, {$cfg['sort']} FROM {$tableName} WHERE {$cfg['key']} IN (:k1, :k2)";
$stmt = $db->prepare($sql);
$stmt->execute(['k1' => $key1, 'k2' => $key2]);
$rows = $stmt->fetchAll();

if (count($rows) !== 2) errorResponse('Records not found');

$sort1 = null; $sort2 = null;
foreach ($rows as $row) {
    if ((int)$row[$cfg['key']] === $key1) $sort1 = (int)$row[$cfg['sort']];
    if ((int)$row[$cfg['key']] === $key2) $sort2 = (int)$row[$cfg['sort']];
}

if ($sort1 === null || $sort2 === null) errorResponse('Records not found');

// Swap sort values
$db->beginTransaction();
$upd = $db->prepare("UPDATE {$tableName} SET {$cfg['sort']} = :sort WHERE {$cfg['key']} = :key");
$upd->execute(['sort' => $sort2, 'key' => $key1]);
$upd->execute(['sort' => $sort1, 'key' => $key2]);
$db->commit();

jsonResponse(['swapped' => true, 'key1' => $key1, 'sort1' => $sort2, 'key2' => $key2, 'sort2' => $sort1]);
