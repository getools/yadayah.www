<?php
require_once __DIR__ . '/config.php';

$pg = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "
        SELECT m.id, m.translit, m.filename, m.series, m.volume, m.page, m.status,
               v.yy_volume_flip_code, v.yy_volume_name,
               s.yy_series_name
        FROM _translit_modifier_map m
        LEFT JOIN yy_volume v ON v.yy_series_key = m.series
            AND v.yy_volume_number = m.volume
        LEFT JOIN yy_series s ON s.yy_series_key = m.series
        ORDER BY m.series, m.volume, m.page, m.translit
    ";
    $stmt = $pg->query($sql);
    jsonResponse($stmt->fetchAll());

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        errorResponse('id is required');
    }
    $id = (int)$input['id'];
    // status: 'T', 'F', or null
    $status = isset($input['status']) ? $input['status'] : null;
    if ($status !== null && $status !== 'T' && $status !== 'F') {
        $status = null;
    }

    $stmt = $pg->prepare("UPDATE _translit_modifier_map SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    if ($stmt->rowCount() === 0) {
        errorResponse('Record not found', 404);
    }
    jsonResponse(['success' => true, 'id' => $id, 'status' => $status]);

} else {
    errorResponse('Method not allowed', 405);
}
