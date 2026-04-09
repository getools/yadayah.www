<?php
/**
 * OAuth login initiator. Redirects user to the OAuth provider's consent screen.
 * Usage: /api/oauth-login.php?provider=google
 */
require_once __DIR__ . '/config.php';

$provider = $_GET['provider'] ?? '';
$db = getDb();
if (session_status() === PHP_SESSION_NONE) session_start();

// Build base URL from current host (works on localhost and production)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'yadayah.com';
$baseUrl = $scheme . '://' . $host;

// Store return URL so callbacks redirect back to the originating page
$return = $_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($return) {
    $_SESSION['oauth_return'] = $return;
} elseif (empty($_SESSION['oauth_return'])) {
    $_SESSION['oauth_return'] = $baseUrl . '/chat';
}

// If link=1 and user is logged in, mark this as a linking flow
if (!empty($_GET['link']) && !empty($_SESSION['user_key'])) {
    $_SESSION['linking_provider'] = true;
}

if ($provider === 'google') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code IN ('oauth-google-client-id')");
    $stmt->execute();
    $settings = [];
    foreach ($stmt->fetchAll() as $r) $settings[$r['setting_code']] = $r['setting_value'];

    $clientId = $settings['oauth-google-client-id'] ?? '';
    if (!$clientId) { http_response_code(500); echo 'Google OAuth not configured'; exit; }
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $redirectUri = $baseUrl . '/api/oauth-callback.php?provider=google';
    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

if ($provider === 'facebook') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code = 'oauth-facebook-app-id'");
    $stmt->execute();
    $row = $stmt->fetch();
    $appId = $row['setting_value'] ?? '';
    if (!$appId) { http_response_code(500); echo 'Facebook OAuth not configured'; exit; }

    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $redirectUri = $baseUrl . '/api/oauth-callback.php?provider=facebook';
    $params = http_build_query([
        'client_id' => $appId,
        'redirect_uri' => $redirectUri,
        'scope' => 'email,public_profile',
        'state' => $state,
        'response_type' => 'code',
    ]);

    header('Location: https://www.facebook.com/v22.0/dialog/oauth?' . $params);
    exit;
}

if ($provider === 'microsoft') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code = 'oauth-microsoft-client-id'");
    $stmt->execute();
    $row = $stmt->fetch();
    $clientId = $row['setting_value'] ?? '';
    if (!$clientId) { http_response_code(500); echo 'Microsoft OAuth not configured'; exit; }

    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $redirectUri = $baseUrl . '/api/oauth-callback-microsoft.php';
    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile User.Read',
        'state' => $state,
        'response_mode' => 'query',
    ]);

    header('Location: https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?' . $params);
    exit;
}

if ($provider === 'yahoo') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code = 'oauth-yahoo-client-id'");
    $stmt->execute();
    $row = $stmt->fetch();
    $clientId = $row['setting_value'] ?? '';
    if (!$clientId) { http_response_code(500); echo 'Yahoo OAuth not configured'; exit; }

    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $redirectUri = $baseUrl . '/api/oauth-callback-yahoo.php';
    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
    ]);

    header('Location: https://api.login.yahoo.com/oauth2/request_auth?' . $params);
    exit;
}

if ($provider === 'x') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code = 'oauth-x-client-id'");
    $stmt->execute();
    $row = $stmt->fetch();
    $clientId = $row['setting_value'] ?? '';
    if (!$clientId) { http_response_code(500); echo 'X OAuth not configured'; exit; }

    $state = bin2hex(random_bytes(16));
    // PKCE: generate code verifier and challenge
    $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_code_verifier'] = $codeVerifier;

    $redirectUri = $baseUrl . '/api/oauth-callback-x.php';
    $params = http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'tweet.read users.read',
        'state' => $state,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
    ]);

    header('Location: https://x.com/i/oauth2/authorize?' . $params);
    exit;
}

http_response_code(400);
echo 'Unknown provider: ' . htmlspecialchars($provider);
