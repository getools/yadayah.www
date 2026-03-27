<?php
require_once __DIR__ . '/config.php';

$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT ticker_key, ticker_heading, ticker_subheading, ticker_target, ticker_bg, ticker_color_heading, ticker_color_countdown, ticker_color_description, ticker_sort FROM yy_ticker WHERE ticker_active_flag = TRUE ORDER BY ticker_target");
    $tickers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['tickers' => $tickers, 'count' => count($tickers)]);
}

// Admin: save ticker
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    $key = $input['ticker_key'] ?? null;
    $heading = $input['ticker_heading'] ?? '';
    $target = $input['ticker_target'] ?? '';
    $bg = $input['ticker_bg'] ?? '#1a1a2e';
    $colorHeading = $input['ticker_color_heading'] ?? '#ffffff';
    $colorCountdown = $input['ticker_color_countdown'] ?? '#e5c86c';
    $colorDesc = $input['ticker_color_description'] ?? '#ffffff';
    $sort = (int)($input['ticker_sort'] ?? 0);
    $active = isset($input['ticker_active_flag']) ? (bool)$input['ticker_active_flag'] : true;

    if (!$heading || !$target) errorResponse('Heading and target date are required');

    if ($key) {
        $stmt = $db->prepare("UPDATE yy_ticker SET ticker_heading = ?, ticker_target = ?, ticker_bg = ?, ticker_color_heading = ?, ticker_color_countdown = ?, ticker_color_description = ?, ticker_sort = ?, ticker_active_flag = ?, user_key = ? WHERE ticker_key = ?");
        $stmt->execute([$heading, $target, $bg, $colorHeading, $colorCountdown, $colorDesc, $sort, $active, $user['user_key'], $key]);
        jsonResponse(['saved' => true, 'ticker_key' => $key]);
    } else {
        $stmt = $db->prepare("INSERT INTO yy_ticker (ticker_heading, ticker_target, ticker_bg, ticker_color_heading, ticker_color_countdown, ticker_color_description, ticker_sort, ticker_active_flag, user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$heading, $target, $bg, $colorHeading, $colorCountdown, $colorDesc, $sort, $active, $user['user_key']]);
        jsonResponse(['saved' => true, 'ticker_key' => $db->lastInsertId()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = requireAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $key = (int)($input['ticker_key'] ?? 0);
    if (!$key) errorResponse('ticker_key required');
    $stmt = $db->prepare("DELETE FROM yy_ticker WHERE ticker_key = ?");
    $stmt->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
