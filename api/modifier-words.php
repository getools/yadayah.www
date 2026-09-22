<?php
ini_set('memory_limit', '512M');
require_once __DIR__ . '/config.php';

$pg = getDb();

/**
 * The public slug of a volume's self-hosted flipbook, or null when there is no
 * bundle on disk. The directory strips apostrophe-ish glyphs while volume_code
 * keeps them (it matches the docx filename) — same strip as api/search.php.
 *
 * ⚠ NOT volume_flip_code: that is a legacy external FlipHTML5 id, and
 *   /<flip_code>/ now just redirects to the home page. Linking by it is what
 *   made every link on this page dead.
 */
function mwBookSlug(?string $volumeCode): ?string {
    static $seen = [];
    if (!$volumeCode) return null;
    if (array_key_exists($volumeCode, $seen)) return $seen[$volumeCode];

    $slug = preg_replace("/[\u{0027}\u{2018}\u{2019}\u{02BC}]/u", '', $volumeCode);
    $root = is_dir('/var/www/html') ? '/var/www/html' : dirname(__DIR__) . '/public';
    return $seen[$volumeCode] = ($slug !== '' && is_dir($root . '/' . $slug . '/text')) ? $slug : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "
        SELECT m.id, m.translit, m.filename, m.series, m.volume, m.page, m.status,
               v.volume_code,
               s.series_name
        FROM _translit_modifier_map m
        LEFT JOIN yy_volume v ON v.series_key = m.series
            AND v.volume_number = m.volume
        LEFT JOIN yy_series s ON s.series_key = m.series
        ORDER BY m.series, m.volume, m.page, m.translit
    ";
    $stmt = $pg->query($sql);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['book_slug'] = mwBookSlug($row['volume_code']);
    }
    unset($row);
    jsonResponse($rows);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    // Bulk update
    if (isset($input['bulk']) && is_array($input['bulk'])) {
        $stmt = $pg->prepare("UPDATE _translit_modifier_map SET status = ? WHERE id = ?");
        $count = 0;
        $pg->beginTransaction();
        foreach ($input['bulk'] as $item) {
            $id = (int)($item['id'] ?? 0);
            if (!$id) continue;
            $status = $item['status'] ?? null;
            if ($status !== null && $status !== 'T' && $status !== 'F') $status = null;
            $stmt->execute([$status, $id]);
            $count += $stmt->rowCount();
        }
        $pg->commit();
        jsonResponse(['success' => true, 'updated' => $count]);
    }

    // Single update
    if (!isset($input['id'])) {
        errorResponse('id is required');
    }
    $id = (int)$input['id'];
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
