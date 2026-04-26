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

    // Check for attack-flagged redirect first
    $atkStmt = $pdo->prepare('SELECT 1 FROM yy_redirect WHERE redirect_request = :req AND redirect_attack_flag = TRUE LIMIT 1');
    $atkStmt->execute([':req' => $path]);
    if ($atkStmt->fetchColumn()) {
        // Ban this IP via honeypot
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ip) {
            $pdo->prepare("
                INSERT INTO yy_ip_ban (ban_ip, ban_reason, ban_uri, ban_ua, ban_expires_dtime)
                VALUES (?, 'attack_redirect', ?, ?, NOW() + INTERVAL '48 hours')
                ON CONFLICT (ban_ip) DO UPDATE SET
                    ban_hit_count = yy_ip_ban.ban_hit_count + 1,
                    ban_uri = EXCLUDED.ban_uri,
                    ban_ua = EXCLUDED.ban_ua,
                    ban_expires_dtime = GREATEST(yy_ip_ban.ban_expires_dtime, NOW() + INTERVAL '48 hours'),
                    ban_last_dtime = NOW()
            ")->execute([$ip, substr($uri, 0, 500), substr($ua, 0, 500)]);
        }
        $pdo->prepare('UPDATE yy_redirect SET redirect_hit_count = redirect_hit_count + 1 WHERE redirect_request = :req AND redirect_attack_flag = TRUE')
            ->execute([':req' => $path]);
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403</title></head><body><h1>Forbidden</h1></body></html>';
        exit;
    }

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

// --- Page alias redirects ---
$aliasPath = trim($path, '/');
$aliasCacheFile = sys_get_temp_dir() . '/yada_page_aliases.json';
$aliases = null;
if (file_exists($aliasCacheFile)) {
    $aliases = json_decode(file_get_contents($aliasCacheFile), true);
}
if (!$aliases && isset($pdo)) {
    // Build from DB
    try {
        $stmt = $pdo->query("SELECT a.alias_path, p.page_code FROM yy_page_alias a JOIN yy_page p ON a.page_key = p.page_key WHERE a.alias_active_flag = TRUE AND p.page_active_flag = TRUE");
        $aliases = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aliases[$row['alias_path']] = $row['page_code'];
        }
        file_put_contents($aliasCacheFile, json_encode($aliases));
    } catch (Exception $e) {}
}
if ($aliases && isset($aliases[$aliasPath])) {
    $target = '/' . $aliases[$aliasPath];
    if ($query) $target .= '?' . $query;
    header('Location: ' . $target, true, 301);
    exit;
}
// Also check case-insensitive alias
if ($aliases) {
    $lowerAlias = strtolower($aliasPath);
    foreach ($aliases as $ap => $pc) {
        if (strtolower($ap) === $lowerAlias) {
            $target = '/' . $pc;
            if ($query) $target .= '?' . $query;
            header('Location: ' . $target, true, 301);
            exit;
        }
    }
}

// --- Dynamic page from yy_page ---
$pageSlug = trim($path, '/');
if ($pageSlug && isset($pdo)) {
    try {
        $pgStmt = $pdo->prepare("SELECT * FROM yy_page WHERE (page_code = ? OR page_url = ? OR page_url = ?) AND page_active_flag = TRUE LIMIT 1");
        $pgStmt->execute([$pageSlug, $pageSlug, '/' . $pageSlug]);
        $pageRow = $pgStmt->fetch(PDO::FETCH_ASSOC);
        if ($pageRow && ($pageRow['page_heading'] || $pageRow['page_body'])) {
            // Render dynamic page
            require __DIR__ . '/page-render.php';
            renderDynamicPage($pageRow);
            exit;
        }
    } catch (Exception $e) {}
}

// --- Case-insensitive redirect ---
$lower = strtolower($path);

if ($lower !== $path) {
    // Special cases: preserve mixed-case filenames
    if ($lower === '/craig_winn') {
        $lower = '/Craig_Winn';
    } elseif ($lower === '/doyouyada') {
        $lower = '/DoYouYada';
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
        $stmt = $pdo->prepare('INSERT INTO yy_redirect (redirect_request, redirect_hit_count) VALUES (:req, 1) ON CONFLICT (redirect_request) DO UPDATE SET redirect_hit_count = yy_redirect.redirect_hit_count + 1');
        $stmt->execute([':req' => $path]);
    }
} catch (Exception $e) {
    // ignore
}

header('Location: /', true, 302);
exit;
