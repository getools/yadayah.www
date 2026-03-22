<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$query = parse_url($uri, PHP_URL_QUERY);

// --- Check yy_redirect table ---
try {
    $host = getenv('PG_HOST') ?: 'localhost';
    $port = getenv('PG_PORT') ?: '5433';
    $name = getenv('PG_DB')   ?: 'yada';
    $user = getenv('PG_USER') ?: 'postgres';
    $pass = getenv('PG_PASS') ?: 'yada_password';
    $dsn = "pgsql:host=$host;port=$port;dbname=$name";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Check for active redirect
    $stmt = $pdo->prepare('SELECT redirect_target FROM yy_redirect WHERE redirect_request = :req AND redirect_active_flag = true LIMIT 1');
    $stmt->execute([':req' => $path]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['redirect_target']) {
        // Increment hit count
        $pdo->prepare('UPDATE yy_redirect SET redirect_hit_count = redirect_hit_count + 1 WHERE redirect_request = :req AND redirect_active_flag = true')
            ->execute([':req' => $path]);

        $target = $row['redirect_target'];
        if ($query) $target .= '?' . $query;
        header('Location: ' . $target, true, 301);
        exit;
    }
} catch (Exception $e) {
    // DB down — fall through to normal handling
}

// --- Case-insensitive redirect ---
$lower = strtolower($path);

if ($lower !== $path) {
    // Special case: Craig_Winn keeps its casing
    if ($lower === '/craig_winn') {
        $lower = '/Craig_Winn';
    }

    $docRoot = $_SERVER['DOCUMENT_ROOT'];
    if (file_exists($docRoot . $lower) || file_exists($docRoot . $lower . '.html')) {
        $redirect = $lower;
        if ($query) $redirect .= '?' . $query;
        header('Location: ' . $redirect, true, 301);
        exit;
    }
}

// --- 404: queue redirect request ---
// Skip logging for bots, scanners, and WordPress internals
$skipLog = preg_match('#^/(wp-|\.|\cgi-|tag/|category/|event/|wp-json/|checkout|mcp$|sse$|uploads$|upfile$)#i', $path)
    || preg_match('#\.(php|css|js|xml|txt|map|env|ico|png|jpg|jpeg|gif|svg|woff2?|ttf|eot)$#i', $path)
    || preg_match('#/feed/?$#i', $path);

try {
    if (isset($pdo) && !$skipLog) {
        $stmt = $pdo->prepare('INSERT INTO yy_redirect (redirect_request, redirect_queue_flag) VALUES (:req, true) ON CONFLICT (redirect_request) DO UPDATE SET redirect_hit_count = yy_redirect.redirect_hit_count + 1');
        $stmt->execute([':req' => $path]);
    }
} catch (Exception $e) {
    // ignore
}

http_response_code(404);
echo 'Not found';
