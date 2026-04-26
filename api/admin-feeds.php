<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();

$method = $_SERVER['REQUEST_METHOD'];

// GET all page feed mappings
if ($method === 'GET' && !empty($_GET['all_page_feeds'])) {
    $stmt = $db->query("
        SELECT pf.*, p.page_code, p.page_title
        FROM yy_feed_page pf
        JOIN yy_page p ON p.page_key = pf.page_key
        ORDER BY p.page_code, pf.feed_page_sort
    ");
    jsonResponse(['page_feeds' => $stmt->fetchAll()]);
}

// GET page feeds for a specific feed (must be before main GET handler)
if ($method === 'GET' && !empty($_GET['page_feeds'])) {
    $feedKey = (int)($_GET['feed_key'] ?? 0);
    $pages = $db->query("SELECT page_key, page_code, page_title FROM yy_page ORDER BY page_code")->fetchAll();
    if (!$feedKey) {
        jsonResponse(['page_feeds' => [], 'pages' => $pages]);
    }
    $stmt = $db->prepare("
        SELECT pf.*, p.page_code, p.page_title
        FROM yy_feed_page pf
        JOIN yy_page p ON p.page_key = pf.page_key
        WHERE pf.feed_key = ?
        ORDER BY pf.feed_page_sort
    ");
    $stmt->execute([$feedKey]);
    jsonResponse(['page_feeds' => $stmt->fetchAll(), 'pages' => $pages]);
}

// GET - list all feeds with their schedules
if ($method === 'GET') {
    $feedKey = $_GET['feed_key'] ?? null;

    if ($feedKey) {
        // Single feed with schedules
        $stmt = $db->prepare("SELECT * FROM yy_feed WHERE feed_key = ?");
        $stmt->execute([$feedKey]);
        $feed = $stmt->fetch();
        if (!$feed) errorResponse('Feed not found', 404);

        $schedStmt = $db->prepare("SELECT * FROM yy_feed_schedule WHERE feed_key = ? ORDER BY schedule_day_of_week, schedule_time");
        $schedStmt->execute([$feedKey]);
        $feed['schedules'] = $schedStmt->fetchAll();

        // Include feed_page rows
        $fpStmt = $db->prepare("
            SELECT fp.*, p.page_code, p.page_title
            FROM yy_feed_page fp
            JOIN yy_page p ON p.page_key = fp.page_key
            WHERE fp.feed_key = ?
            ORDER BY fp.feed_page_sort
        ");
        $fpStmt->execute([$feedKey]);
        $feed['feed_pages'] = $fpStmt->fetchAll();

        jsonResponse($feed);
    }

    // All feeds with first page's filters
    $stmt = $db->query("
        SELECT f.*,
            (SELECT count(*) FROM yy_feed_schedule s WHERE s.feed_key = f.feed_key AND s.schedule_active_flag = true) as schedule_count,
            (SELECT max(s.schedule_last_run) FROM yy_feed_schedule s WHERE s.feed_key = f.feed_key) as last_run,
            (SELECT count(*) FROM yy_feed_page fp WHERE fp.feed_key = f.feed_key) as page_count,
            (SELECT fp.feed_page_filter_include FROM yy_feed_page fp WHERE fp.feed_key = f.feed_key ORDER BY fp.feed_page_sort LIMIT 1) as feed_page_filter_include,
            (SELECT fp.feed_page_filter_exclude FROM yy_feed_page fp WHERE fp.feed_key = f.feed_key ORDER BY fp.feed_page_sort LIMIT 1) as feed_page_filter_exclude
        FROM yy_feed f
        ORDER BY f.feed_name
    ");
    jsonResponse($stmt->fetchAll());
}

// POST - create or update
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) errorResponse('Invalid JSON');

    // Page feed mapping create/update
    if (!empty($data['feed_page_action'])) {
        $action = $data['feed_page_action'];

        if ($action === 'create') {
            $db->prepare("INSERT INTO yy_feed_page (page_key, feed_key, feed_page_filter_include, feed_page_filter_exclude, feed_page_listing_type, feed_page_filter_orientation, feed_page_sort) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   (int)$data['page_key'],
                   (int)$data['feed_key'],
                   $data['feed_page_filter_include'] ?? null,
                   $data['feed_page_filter_exclude'] ?? null,
                   $data['feed_page_listing_type'] ?? null,
                   $data['feed_page_filter_orientation'] ?? null,
                   (int)($data['feed_page_sort'] ?? 0),
               ]);
            jsonResponse(['created' => true]);
        }

        if ($action === 'update') {
            $db->prepare("UPDATE yy_feed_page SET feed_key = ?, page_key = ?, feed_page_filter_include = ?, feed_page_filter_exclude = ?, feed_page_listing_type = ?, feed_page_filter_orientation = ?, feed_page_sort = ?, feed_page_revision_dtime = NOW() WHERE feed_page_key = ?")
               ->execute([
                   (int)$data['feed_key'],
                   (int)$data['page_key'],
                   $data['feed_page_filter_include'] ?? null,
                   $data['feed_page_filter_exclude'] ?? null,
                   $data['feed_page_listing_type'] ?? null,
                   $data['feed_page_filter_orientation'] ?? null,
                   (int)($data['feed_page_sort'] ?? 0),
                   (int)$data['feed_page_key'],
               ]);
            jsonResponse(['updated' => true]);
        }

        errorResponse('Invalid feed_page_action');
    }

    $feedKey = $data['feed_key'] ?? null;

    $fields = [
        'feed_name', 'feed_site_code', 'feed_account_id', 'feed_source_url', 'feed_api_key', 'feed_active_flag'
    ];

    if ($feedKey) {
        // Update
        $sets = [];
        $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $vals[] = $data[$f];
            }
        }
        if ($sets) {
            $vals[] = $feedKey;
            $db->prepare("UPDATE yy_feed SET " . implode(', ', $sets) . " WHERE feed_key = ?")->execute($vals);
        }

        // Update schedules if provided
        if (isset($data['schedules'])) {
            $db->prepare("DELETE FROM yy_feed_schedule WHERE feed_key = ?")->execute([$feedKey]);
            foreach ($data['schedules'] as $sched) {
                $db->prepare("INSERT INTO yy_feed_schedule (feed_key, schedule_day_of_week, schedule_time, schedule_interval_minutes, schedule_active_flag) VALUES (?, ?, ?, ?, ?)")
                    ->execute([
                        $feedKey,
                        $sched['schedule_day_of_week'] ?? null,
                        $sched['schedule_time'] ?? null,
                        $sched['schedule_interval_minutes'] ?? null,
                        $sched['schedule_active_flag'] ?? true
                    ]);
            }
        }

        jsonResponse(['updated' => true, 'feed_key' => $feedKey]);
    } else {
        // Insert
        $cols = [];
        $placeholders = [];
        $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $cols[] = $f;
                $placeholders[] = '?';
                $vals[] = $data[$f];
            }
        }
        $db->prepare("INSERT INTO yy_feed (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")")->execute($vals);
        $newKey = $db->lastInsertId('yy_feed_feed_key_seq');

        // Insert schedules if provided
        if (isset($data['schedules'])) {
            foreach ($data['schedules'] as $sched) {
                $db->prepare("INSERT INTO yy_feed_schedule (feed_key, schedule_day_of_week, schedule_time, schedule_interval_minutes, schedule_active_flag) VALUES (?, ?, ?, ?, ?)")
                    ->execute([
                        $newKey,
                        $sched['schedule_day_of_week'] ?? null,
                        $sched['schedule_time'] ?? null,
                        $sched['schedule_interval_minutes'] ?? null,
                        $sched['schedule_active_flag'] ?? true
                    ]);
            }
        }

        jsonResponse(['created' => true, 'feed_key' => $newKey]);
    }
}

// Extract inserted+updated counts from sync response (handles flat or nested results)
function parseSyncCounts(array $data): int {
    if (isset($data['inserted']) || isset($data['updated'])) {
        return ($data['inserted'] ?? 0) + ($data['updated'] ?? 0);
    }
    // YouTube returns {results: [{inserted, updated}, ...]}
    if (isset($data['results']) && is_array($data['results'])) {
        $total = 0;
        foreach ($data['results'] as $r) {
            $total += ($r['inserted'] ?? 0) + ($r['updated'] ?? 0);
        }
        return $total;
    }
    return 0;
}

// SYNC - force refresh a feed
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $feedKey = $data['feed_key'] ?? null;
    if (!$feedKey) errorResponse('feed_key required');

    $stmt = $db->prepare("SELECT * FROM yy_feed WHERE feed_key = ?");
    $stmt->execute([$feedKey]);
    $feed = $stmt->fetch();
    if (!$feed) errorResponse('Feed not found', 404);

    $result = ['feed' => $feed['feed_name'], 'action' => 'sync'];

    $site = strtolower($feed['feed_site_code'] ?? '');

    // Map sites to their sync scripts
    $syncScripts = [
        'youtube'  => __DIR__ . '/sync-youtube.php',
        'rumble'   => __DIR__ . '/sync-rumble.php',
        'facebook' => __DIR__ . '/sync-facebook.php',
    ];

    $matchedCount = 0;
    $syncScript = $syncScripts[$site] ?? null;

    if ($syncScript && file_exists($syncScript)) {
        if ($site === 'facebook') {
            // Facebook is long-running — run in background
            $logFile = sys_get_temp_dir() . '/sync_feed_' . $feedKey . '.log';
            $cmd = "php " . escapeshellarg($syncScript) . " > " . escapeshellarg($logFile) . " 2>&1 &";
            exec($cmd);
            $result['sync_result'] = ['status' => 'started', 'message' => 'Sync running in background'];
        } else {
            // Run sync script via CLI, capture JSON output
            $output = [];
            $rc = 0;
            exec("php " . escapeshellarg($syncScript) . " 2>/dev/null", $output, $rc);
            // Sync scripts print human-readable lines to stdout; the last line or
            // a line containing JSON has the counts. Parse counts from output.
            $fullOutput = implode("\n", $output);
            $inserted = 0; $updated = 0; $found = 0;
            if (preg_match('/found[=:\s]+(\d+)/i', $fullOutput, $m)) $found = (int)$m[1];
            if (preg_match_all('/inserted[=:\s]+(\d+)/i', $fullOutput, $m)) $inserted = array_sum(array_map('intval', $m[1]));
            if (preg_match_all('/updated[=:\s]+(\d+)/i',  $fullOutput, $m)) $updated  = array_sum(array_map('intval', $m[1]));
            $matchedCount = $inserted + $updated;
            $result['sync_result'] = ['found' => $found, 'inserted' => $inserted, 'updated' => $updated, 'rc' => $rc];
        }
    } else {
        $result['error'] = 'No sync script for site: ' . $site;
    }

    $result['matched_count'] = $matchedCount;

    // Update schedule
    $db->prepare("UPDATE yy_feed_schedule SET schedule_last_run = NOW(), schedule_last_count = ?, schedule_last_status = ? WHERE feed_key = ?")
        ->execute([$matchedCount, json_encode($result), $feedKey]);

    jsonResponse($result);
}

// DELETE
if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Delete page-feed mapping
    if (!empty($data['feed_page_key'])) {
        $db->prepare("DELETE FROM yy_feed_page WHERE feed_page_key = ?")->execute([$data['feed_page_key']]);
        jsonResponse(['deleted' => true]);
    }

    // Delete feed
    $feedKey = $data['feed_key'] ?? null;
    if (!$feedKey) errorResponse('feed_key required');
    $db->prepare("DELETE FROM yy_feed_page WHERE feed_key = ?")->execute([$feedKey]);
    $db->prepare("DELETE FROM yy_feed_schedule WHERE feed_key = ?")->execute([$feedKey]);
    $db->prepare("DELETE FROM yy_feed WHERE feed_key = ?")->execute([$feedKey]);
    jsonResponse(['deleted' => true]);
}

