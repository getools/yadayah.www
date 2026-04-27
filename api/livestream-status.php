<?php
/**
 * Public API: returns active livestream info.
 * A stream is considered live when feed_stream_flag = TRUE AND feed_stream_dtime > NOW() - 3 hours.
 *
 * GET — returns {live: true/false, feed_name, feed_site_code, feed_source_url}
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set 200 immediately so Apache doesn't default to 500 on fatal
http_response_code(200);

// Buffer output so headers can always be sent even after fatal errors
ob_start();

// Register shutdown function to catch fatal errors that bypass try/catch
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // Discard any partial output
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // If headers not sent yet, send proper JSON headers
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
        }
        error_log('livestream-status.php fatal shutdown: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        echo json_encode(['live' => false, 'error' => 'server_error']);
    } else {
        // Flush normal output
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }
});

// Send headers immediately — no session needed for this public endpoint
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    while (ob_get_level() > 0) { ob_end_flush(); }
    exit;
}

function getLivestreamDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // Check that the pgsql PDO driver is available before attempting connection
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PDO pgsql driver is not available');
        }
        $host = getenv('PG_HOST') ?: 'localhost';
        $port = getenv('PG_PORT') ?: '5433';
        $name = getenv('PG_DB')   ?: 'yada';
        $user = getenv('PG_USER') ?: 'postgres';
        $pass = getenv('PG_PASS') ?: 'yada_password';
        $dsn  = "pgsql:host=$host;port=$port;dbname=$name;connect_timeout=5";
        $pdo  = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function getLivestreamStatus(): array {
    $cacheFile = sys_get_temp_dir() . '/yada_livestream_status.json';
    $cacheTtl  = 30;

    if (@file_exists($cacheFile) && (time() - @filemtime($cacheFile)) < $cacheTtl) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
    }

    try {
        $db   = getLivestreamDb();
        $stmt = $db->query("
            SELECT feed_key, feed_name, feed_site_code, feed_source_url, feed_account_id, feed_stream_dtime
            FROM yy_feed
            WHERE feed_stream_flag = TRUE AND feed_active_flag = TRUE
              AND feed_stream_dtime IS NOT NULL AND feed_stream_dtime > NOW() - INTERVAL '3 hours'
            LIMIT 1
        ");

        $row = $stmt->fetch();

        if ($row) {
            $result = [
                'live'           => true,
                'feed_key'       => (int)$row['feed_key'],
                'feed_name'      => $row['feed_name'],
                'feed_site_code' => $row['feed_site_code'],
                'feed_source_url'=> $row['feed_source_url'],
                'feed_account_id'=> $row['feed_account_id'],
                'stream_since'   => $row['feed_stream_dtime'],
            ];
        } else {
            // Not live — still return feed info for admin controls
            $anyStmt = $db->query('SELECT feed_key, feed_name, feed_site_code, feed_source_url FROM yy_feed WHERE feed_stream_flag = TRUE AND feed_active_flag = TRUE LIMIT 1');
            $result  = ['live' => false];
            $anyStream = $anyStmt->fetch();
            if ($anyStream) {
                $result['feed_key']        = (int)$anyStream['feed_key'];
                $result['feed_name']       = $anyStream['feed_name'];
                $result['feed_site_code']  = $anyStream['feed_site_code'];
                $result['feed_source_url'] = $anyStream['feed_source_url'];
            }
        }
    } catch (Throwable $e) {
        error_log('livestream-status.php db error: ' . $e->getMessage());
        return ['live' => false, 'error' => 'db_error'];
    }

    $json = json_encode($result);
    if ($json !== false) {
        @file_put_contents($cacheFile, $json);
    }

    return $result;
}

try {
    $result = getLivestreamStatus();
    http_response_code(200);
    $output = json_encode($result);
    if ($output === false) {
        // json_encode failed (e.g. invalid UTF-8 in data)
        error_log('livestream-status.php json_encode failed: ' . json_last_error_msg());
        $output = json_encode(['live' => false, 'error' => 'encode_error']);
    }
    echo $output;
} catch (Throwable $e) {
    error_log('livestream-status.php fatal error: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['live' => false, 'error' => 'server_error']);
}
