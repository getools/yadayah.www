<?php
/**
 * X (Twitter) OAuth 2.0 callback handler with PKCE.
 * Exchanges code for tokens, creates/finds user, sets session.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';
$_oauthReturn = $_SESSION['oauth_return'] ?? '/community';

if ($error) {
    unset($_SESSION['oauth_return']);
    header('Location: ' . $_oauthReturn . '?error=' . urlencode($error));
    exit;
}

if (!$state || $state !== ($_SESSION['oauth_state'] ?? '')) {
    unset($_SESSION['oauth_return']);
    header('Location: ' . $_oauthReturn . '?error=invalid_state');
    exit;
}
$codeVerifier = $_SESSION['oauth_code_verifier'] ?? '';
unset($_SESSION['oauth_state'], $_SESSION['oauth_code_verifier']);

$db = getDb();

$stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code LIKE 'oauth-x-%'");
$stmt->execute();
$settings = [];
foreach ($stmt->fetchAll() as $r) $settings[$r['setting_code']] = $r['setting_value'];

$clientId = $settings['oauth-x-client-id'] ?? '';
$clientSecret = $settings['oauth-x-client-secret'] ?? '';
$redirectUri = 'https://yadayah.com/api/oauth-callback-x.php';

// Exchange code for tokens using Basic auth
$authHeader = 'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret);
$tokenResp = @file_get_contents('https://api.x.com/2/oauth2/token', false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => $authHeader . "\r\nContent-Type: application/x-www-form-urlencoded",
        'content' => http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]),
    ],
]));

if (!$tokenResp) {
    header('Location: ' . $_oauthReturn . '?error=x_token_exchange_failed');
    exit;
}

$tokens = json_decode($tokenResp, true);
$accessToken = $tokens['access_token'] ?? '';

if (!$accessToken) {
    header('Location: ' . $_oauthReturn . '?error=x_no_access_token');
    exit;
}

// Get user info
$userInfoResp = @file_get_contents('https://api.x.com/2/users/me?user.fields=id,name,username,profile_image_url', false, stream_context_create([
    'http' => [
        'header' => 'Authorization: Bearer ' . $accessToken,
    ],
]));

if (!$userInfoResp) {
    header('Location: ' . $_oauthReturn . '?error=x_userinfo_failed');
    exit;
}

$userData = json_decode($userInfoResp, true);
$userInfo = $userData['data'] ?? [];
$oauthId = $userInfo['id'] ?? '';
$name = $userInfo['name'] ?? '';
$username = $userInfo['username'] ?? '';
$avatar = $userInfo['profile_image_url'] ?? '';
// Get higher res avatar
if ($avatar) $avatar = str_replace('_normal', '_200x200', $avatar);

if (!$oauthId) {
    header('Location: ' . $_oauthReturn . '?error=x_no_user_id');
    exit;
}

// Find or create user
$stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_oauth_provider = 'x' AND user_oauth_id = ?");
$stmt->execute([$oauthId]);
$existing = $stmt->fetchColumn();

if ($existing) {
    $userKey = $existing;
    $db->prepare("UPDATE yy_user SET user_display_name = ?, user_avatar = ? WHERE user_key = ?")
       ->execute([$name, $avatar, $userKey]);
} else {
    // X doesn't provide email via OAuth 2.0 free tier
    $stmt = $db->prepare("INSERT INTO yy_user (user_code, user_display_name, user_avatar, user_handle, user_oauth_provider, user_oauth_id, user_active_flag, user_verified) VALUES (?, ?, ?, ?, 'x', ?, TRUE, TRUE) RETURNING user_key");
    $stmt->execute(['x:' . $oauthId, $name, $avatar, $username, $oauthId]);
    $userKey = $stmt->fetchColumn();
    $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, 1)")->execute([$userKey]);
}

// Merge mode
if (!empty($_SESSION['merge_via_oauth']) && !empty($_SESSION['user_key'])) {
    unset($_SESSION['merge_via_oauth']);
    if ((int)$userKey === (int)$_SESSION['user_key']) { header('Location: /community#merge-error&reason=same'); exit; }
    $u = $db->prepare("SELECT user_display_name, user_email FROM yy_user WHERE user_key = ?"); $u->execute([$userKey]); $info = $u->fetch();
    $_SESSION['merge_target'] = ['user_key' => (int)$userKey, 'display_name' => $info['user_display_name'] ?? $name, 'email' => $info['user_email'] ?? '', 'token' => bin2hex(random_bytes(16)), 'expires' => time() + 600];
    header('Location: /community#merge-confirm'); exit;
}

$_SESSION['user_key'] = $userKey;
$_SESSION['user_display_name'] = $name;
$_SESSION['user_avatar'] = $avatar;
$_SESSION['oauth_provider'] = 'x';

$return = $_SESSION['oauth_return'] ?? '/community';
unset($_SESSION['oauth_return']);
header('Location: ' . $return);
exit;
