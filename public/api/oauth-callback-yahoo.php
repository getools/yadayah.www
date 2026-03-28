<?php
/**
 * Yahoo OAuth callback handler.
 * Exchanges code for tokens, creates/finds user, sets session.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oauth-helpers.php';
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
unset($_SESSION['oauth_state']);

$db = getDb();

$stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code LIKE 'oauth-yahoo-%'");
$stmt->execute();
$settings = [];
foreach ($stmt->fetchAll() as $r) $settings[$r['setting_code']] = $r['setting_value'];

$clientId = $settings['oauth-yahoo-client-id'] ?? '';
$clientSecret = $settings['oauth-yahoo-client-secret'] ?? '';
$redirectUri = 'https://yadayah.com/api/oauth-callback-yahoo.php';

// Exchange code for tokens using Basic auth
$authHeader = 'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret);
$tokenResp = @file_get_contents('https://api.login.yahoo.com/oauth2/get_token', false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => $authHeader . "\r\nContent-Type: application/x-www-form-urlencoded",
        'content' => http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]),
    ],
]));

if (!$tokenResp) {
    header('Location: ' . $_oauthReturn . '?error=yahoo_token_exchange_failed');
    exit;
}

$tokens = json_decode($tokenResp, true);
$accessToken = $tokens['access_token'] ?? '';
$idToken = $tokens['id_token'] ?? '';

if (!$accessToken) {
    header('Location: ' . $_oauthReturn . '?error=yahoo_no_access_token');
    exit;
}

// Get user info from Yahoo's OpenID userinfo endpoint
$userInfoResp = @file_get_contents('https://api.login.yahoo.com/openid/v1/userinfo', false, stream_context_create([
    'http' => [
        'header' => 'Authorization: Bearer ' . $accessToken,
    ],
]));

if (!$userInfoResp) {
    header('Location: ' . $_oauthReturn . '?error=yahoo_userinfo_failed');
    exit;
}

$userInfo = json_decode($userInfoResp, true);
$oauthId = $userInfo['sub'] ?? '';
$email = $userInfo['email'] ?? '';
$name = trim(($userInfo['given_name'] ?? '') . ' ' . ($userInfo['family_name'] ?? ''));
if (!$name) $name = $userInfo['name'] ?? $email;
$avatar = $userInfo['picture'] ?? '';

if (!$oauthId) {
    header('Location: ' . $_oauthReturn . '?error=yahoo_no_user_id');
    exit;
}

$result = resolveOAuthUser($db, 'yahoo', $oauthId, $email, $name, $avatar);

if ($result['action'] === 'pending_link') {
    $return = $_SESSION['oauth_return'] ?? '/community';
    unset($_SESSION['oauth_return']);
    header('Location: ' . $return . '#link-account');
    exit;
}

$_SESSION['user_key'] = $result['user_key'];
$_SESSION['user_display_name'] = $name;
$_SESSION['user_avatar'] = $avatar;

$return = $_SESSION['oauth_return'] ?? '/community';
unset($_SESSION['oauth_return']);
header('Location: ' . $return);
exit;
