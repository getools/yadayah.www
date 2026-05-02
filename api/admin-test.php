<?php
/**
 * Admin API for yy_test CRUD and log viewing.
 * GET                  — list all tests with last-run info
 * GET ?logs=1&key=N    — get logs for a test (paginated)
 * GET ?logs=1           — get recent logs across all tests
 * PUT ?key=N           — update a test
 * POST                 — create a new test
 * POST ?action=run     — run a specific test now
 * POST ?action=run_all — run all active tests now
 * DELETE ?key=N        — delete a test and its logs
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$key = (int)($_GET['key'] ?? 0);

// GET logs
if ($method === 'GET' && isset($_GET['logs'])) {
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    if ($key) {
        $stmt = $db->prepare("
            SELECT l.*, t.test_title
            FROM yy_test_log l
            JOIN yy_test t ON t.test_key = l.test_key
            WHERE l.test_key = ?
            ORDER BY l.test_log_dtime DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$key, $limit, $offset]);
        $total = $db->prepare("SELECT COUNT(*) FROM yy_test_log WHERE test_key = ?");
        $total->execute([$key]);
    } else {
        $stmt = $db->prepare("
            SELECT l.*, t.test_title
            FROM yy_test_log l
            JOIN yy_test t ON t.test_key = l.test_key
            ORDER BY l.test_log_dtime DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $total = $db->query("SELECT COUNT(*) FROM yy_test_log");
    }

    jsonResponse([
        'logs' => $stmt->fetchAll(),
        'total' => (int)$total->fetchColumn(),
    ]);
}

// GET all tests
if ($method === 'GET') {
    $stmt = $db->query("
        SELECT t.*,
               (SELECT COUNT(*) FROM yy_test_log l WHERE l.test_key = t.test_key) AS log_count,
               (SELECT COUNT(*) FROM yy_test_log l WHERE l.test_key = t.test_key AND l.test_log_status = 'fail') AS fail_count
        FROM yy_test t
        ORDER BY t.test_sort, t.test_key
    ");
    jsonResponse(['tests' => $stmt->fetchAll()]);
}

// POST — create or run
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    if ($action === 'run' || $action === 'run_all') {
        $testKey = (int)($data['test_key'] ?? 0);
        ob_start();
        $_GET['key'] = 'yada2026test';
        if ($testKey) $_GET['test_key'] = $testKey;
        include __DIR__ . '/cron-test.php';
        $output = ob_get_clean();
        // Response was already sent by cron-test.php via jsonResponse
        exit;
    }

    // Create new test
    $title = trim($data['test_title'] ?? '');
    if (!$title) errorResponse('Test title is required');

    $stmt = $db->prepare("
        INSERT INTO yy_test (test_title, test_summary, test_type, test_config, test_active_flag,
                             test_schedule_interval_minutes, test_schedule_time, test_schedule_day_of_week, test_sort)
        VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?)
        RETURNING test_key
    ");
    $stmt->execute([
        $title,
        trim($data['test_summary'] ?? '') ?: null,
        trim($data['test_type'] ?? 'url_check'),
        json_encode($data['test_config'] ?? new \stdClass()),
        (bool)($data['test_active_flag'] ?? true),
        (int)($data['test_schedule_interval_minutes'] ?? 0) ?: null,
        $data['test_schedule_time'] ?? null,
        isset($data['test_schedule_day_of_week']) && $data['test_schedule_day_of_week'] !== '' ? (int)$data['test_schedule_day_of_week'] : null,
        (int)($data['test_sort'] ?? 0),
    ]);
    jsonResponse(['saved' => true, 'test_key' => $stmt->fetchColumn()]);
}

// PUT — update
if ($method === 'PUT') {
    if (!$key) errorResponse('Test key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $fields = [];
    $params = [];

    foreach (['test_title', 'test_summary', 'test_type'] as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            $params[] = trim($data[$col] ?? '') ?: null;
        }
    }
    if (array_key_exists('test_config', $data)) {
        $fields[] = "test_config = ?::jsonb";
        $params[] = json_encode($data['test_config'] ?? new \stdClass());
    }
    if (array_key_exists('test_active_flag', $data)) {
        $fields[] = "test_active_flag = ?";
        $params[] = (bool)$data['test_active_flag'] ? 'true' : 'false';
    }
    if (array_key_exists('test_schedule_interval_minutes', $data)) {
        $fields[] = "test_schedule_interval_minutes = ?";
        $params[] = (int)$data['test_schedule_interval_minutes'] ?: null;
    }
    if (array_key_exists('test_schedule_time', $data)) {
        $fields[] = "test_schedule_time = ?";
        $params[] = $data['test_schedule_time'] ?: null;
    }
    if (array_key_exists('test_schedule_day_of_week', $data)) {
        $fields[] = "test_schedule_day_of_week = ?";
        $params[] = ($data['test_schedule_day_of_week'] !== '' && $data['test_schedule_day_of_week'] !== null) ? (int)$data['test_schedule_day_of_week'] : null;
    }
    if (array_key_exists('test_sort', $data)) {
        $fields[] = "test_sort = ?";
        $params[] = (int)$data['test_sort'];
    }

    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;
    $db->prepare("UPDATE yy_test SET " . implode(', ', $fields) . " WHERE test_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

// DELETE
if ($method === 'DELETE') {
    if (!$key) errorResponse('Test key required');
    $db->prepare("DELETE FROM yy_test WHERE test_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
