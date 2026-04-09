<?php
/**
 * Admin API for yy_ticker CRUD.
 *
 * DB columns: ticker_key, ticker_heading, ticker_target (timestamptz),
 *             ticker_bg, ticker_color_*, ticker_sort, ticker_active_flag
 *
 * The form sends date + time + timezone separately; we combine into ticker_target.
 * On GET we extract date/time from the stored UTC timestamp (timezone shown as +00:00).
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

$key = isset($_GET['key']) ? (int)$_GET['key'] : null;

// ── GET ──
if ($method === 'GET') {
    if ($key) {
        $stmt = $db->prepare("SELECT ticker_key, ticker_heading, ticker_subheading, ticker_target, ticker_sort, ticker_active_flag FROM yy_ticker WHERE ticker_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Ticker not found', 404);
        // Split ticker_target into date / time for the form
        $dt = new DateTime($row['ticker_target']);
        $row['ticker_date']     = $dt->format('Y-m-d');
        $row['ticker_time']     = $dt->format('H:i');
        $row['ticker_timezone'] = '+00:00';
        jsonResponse($row);
    }
    $stmt = $db->query("SELECT ticker_key, ticker_heading, ticker_subheading, ticker_target, ticker_sort, ticker_active_flag FROM yy_ticker ORDER BY ticker_target");
    jsonResponse(['tickers' => $stmt->fetchAll()]);
}

// ── POST — create ──
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $target = buildTarget($data);
    // Use configured ticker-primary-background as default bg for new tickers
    $bgStmt = $db->prepare("SELECT setting_value FROM yy_setting WHERE setting_scope_code='page' AND setting_group_code='timeline' AND setting_code='ticker-primary-background'");
    $bgStmt->execute();
    $defaultBg = $bgStmt->fetchColumn() ?: '#021288';
    $stmt = $db->prepare("INSERT INTO yy_ticker (ticker_heading, ticker_subheading, ticker_target, ticker_bg, ticker_sort, ticker_active_flag, user_key) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['ticker_heading'] ?? ($data['ticker_header'] ?? ''),
        $data['ticker_subheading'] ?? '',
        $target,
        $defaultBg,
        (int)($data['ticker_sort'] ?? 0),
        isset($data['ticker_active_flag']) ? (bool)$data['ticker_active_flag'] : true,
        $user['user_key'] ?? null,
    ]);
    jsonResponse(['saved' => true, 'ticker_key' => $db->lastInsertId()]);
}

// ── PUT — update ──
if ($method === 'PUT') {
    if (!$key) errorResponse('key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $target = buildTarget($data);
    $stmt = $db->prepare("UPDATE yy_ticker SET ticker_heading = ?, ticker_subheading = ?, ticker_target = ?, ticker_sort = ?, ticker_active_flag = ?, user_key = ? WHERE ticker_key = ?");
    $stmt->execute([
        $data['ticker_heading'] ?? ($data['ticker_header'] ?? ''),
        $data['ticker_subheading'] ?? '',
        $target,
        (int)($data['ticker_sort'] ?? 0),
        isset($data['ticker_active_flag']) ? (bool)$data['ticker_active_flag'] : true,
        $user['user_key'] ?? null,
        $key,
    ]);
    jsonResponse(['saved' => true]);
}

// ── DELETE ──
if ($method === 'DELETE') {
    if (!$key) errorResponse('key required');
    $db->prepare("DELETE FROM yy_ticker WHERE ticker_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);

// Build a timestamptz string from form fields ticker_date, ticker_time, ticker_timezone
function buildTarget(array $data): string {
    $date = trim($data['ticker_date'] ?? '') ?: date('Y-m-d');
    $time = trim($data['ticker_time'] ?? '') ?: '00:00';
    $tz   = trim($data['ticker_timezone'] ?? '') ?: '+00:00';
    return $date . ' ' . $time . ':00 ' . $tz;
}
