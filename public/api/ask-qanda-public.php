<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$stmt = $db->query("
    SELECT ask_qanda_key, ask_qanda_question, ask_qanda_answer
    FROM yy_ask_qanda
    WHERE ask_qanda_active_flag = true
    ORDER BY ask_qanda_sort, ask_qanda_key
");
jsonResponse(['items' => $stmt->fetchAll()]);
