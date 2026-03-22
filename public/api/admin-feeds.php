<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();

$method = $_SERVER['REQUEST_METHOD'];

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

        jsonResponse($feed);
    }

    // All feeds
    $stmt = $db->query("
        SELECT f.*,
            (SELECT count(*) FROM yy_feed_schedule s WHERE s.feed_key = f.feed_key AND s.schedule_active_flag = true) as schedule_count,
            (SELECT max(s.schedule_last_run) FROM yy_feed_schedule s WHERE s.feed_key = f.feed_key) as last_run
        FROM yy_feed f
        ORDER BY f.feed_sort, f.feed_name
    ");
    jsonResponse($stmt->fetchAll());
}

// POST - create or update feed
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) errorResponse('Invalid JSON');

    $feedKey = $data['feed_key'] ?? null;

    $fields = [
        'feed_name', 'feed_site_code', 'feed_site_id', 'feed_site_api',
        'feed_type_code', 'feed_filter_positive', 'feed_filter_negative',
        'feed_api_endpoint', 'feed_page_url', 'feed_db_table', 'feed_thumb_dir',
        'feed_per_page', 'feed_paging_code', 'feed_active_flag', 'feed_sort'
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

    $endpoint = $feed['feed_api_endpoint'] ?? '';
    $site = strtolower($feed['feed_site_code'] ?? '');

    $matchedCount = 0;

    if ($site === 'youtube' && !$endpoint) {
        // Cache-based: clear cache files
        $cacheDir = sys_get_temp_dir() . '/yt_cache/';
        $cleared = 0;
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*.json') as $f) { unlink($f); $cleared++; }
        }
        $result['cache_cleared'] = $cleared;
    } elseif ($site === 'rumble') {
        // Clear rumble cache
        $cacheDir = sys_get_temp_dir() . '/rumble_stevens/';
        $cleared = 0;
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*.json') as $f) { unlink($f); $cleared++; }
        }
        $result['cache_cleared'] = $cleared;
    } elseif ($endpoint) {
        // For long-running syncs (Facebook), run CLI script in background
        $scriptPath = realpath(__DIR__ . '/..' . parse_url($endpoint, PHP_URL_PATH))
                   ?: realpath(__DIR__ . '/' . basename(parse_url($endpoint, PHP_URL_PATH)));
        if ($scriptPath && file_exists($scriptPath) && $site === 'facebook') {
            // Run in background via CLI (Facebook syncs take too long for HTTP)
            $logFile = sys_get_temp_dir() . '/sync_feed_' . $feedKey . '.log';
            $cmd = "php $scriptPath > $logFile 2>&1 &";
            exec($cmd);
            $result['sync_result'] = ['status' => 'started', 'message' => 'Sync running in background'];
        } else {
            // Call the sync endpoint internally via HTTP
            $sep = strpos($endpoint, '?') !== false ? '&' : '?';
            $syncUrl = 'http://localhost' . $endpoint . $sep . 'action=sync&key=yada2026sync';
            $ctx = stream_context_create(['http' => ['timeout' => 120]]);
            $response = @file_get_contents($syncUrl, false, $ctx);
            if ($response !== false) {
                $syncData = json_decode($response, true);
                $result['sync_result'] = $syncData;
                if (isset($syncData['inserted']) && isset($syncData['updated'])) {
                    $matchedCount = ($syncData['inserted'] ?? 0) + ($syncData['updated'] ?? 0);
                }
            } else {
                $result['error'] = 'Sync request failed';
            }
        }
    } else {
        $result['error'] = 'No sync method available for this feed';
    }

    // Count total records in DB table if available
    $dbTable = $feed['feed_db_table'] ?? '';
    if ($dbTable) {
        $allowed = ['yy_feed_invite', 'yy_feed_doyou', 'yy_blog', 'yy_music'];
        if (in_array($dbTable, $allowed)) {
            $activeCol = ($dbTable === 'yy_blog') ? 'feed_active_flag' : 'feed_active_flag';
            try {
                $countStmt = $db->query("SELECT count(*) FROM $dbTable WHERE $activeCol = true");
                $matchedCount = (int)$countStmt->fetchColumn();
            } catch (\Exception $e) {}
        }
    }

    $result['matched_count'] = $matchedCount;

    // Update feed and schedule
    $db->prepare("UPDATE yy_feed SET feed_last_count = ? WHERE feed_key = ?")->execute([$matchedCount, $feedKey]);
    $db->prepare("UPDATE yy_feed_schedule SET schedule_last_run = NOW(), schedule_last_count = ?, schedule_last_status = ? WHERE feed_key = ?")
        ->execute([$matchedCount, json_encode($result), $feedKey]);

    jsonResponse($result);
}

// DELETE
if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $feedKey = $data['feed_key'] ?? null;
    if (!$feedKey) errorResponse('feed_key required');

    $db->prepare("DELETE FROM yy_feed WHERE feed_key = ?")->execute([$feedKey]);
    jsonResponse(['deleted' => true]);
}
