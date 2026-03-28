<?php
/**
 * Microsoft OAuth callback handler.
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
    header('Location: ' . $_oauthReturn . '?error=' . urlencode($_GET['error_description'] ?? $error));
    exit;
}

if (!$state || $state !== ($_SESSION['oauth_state'] ?? '')) {
    unset($_SESSION['oauth_return']);
    header('Location: ' . $_oauthReturn . '?error=invalid_state');
    exit;
}
unset($_SESSION['oauth_state']);

$db = getDb();

$stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code LIKE 'oauth-microsoft-%'");
$stmt->execute();
$settings = [];
foreach ($stmt->fetchAll() as $r) $settings[$r['setting_code']] = $r['setting_value'];

$clientId = $settings['oauth-microsoft-client-id'] ?? '';
$clientSecret = $settings['oauth-microsoft-client-secret'] ?? '';
$redirectUri = 'https://yadayah.com/api/oauth-callback-microsoft.php';

// Exchange code for tokens
$tokenResp = @file_get_contents('https://login.microsoftonline.com/consumers/oauth2/v2.0/token', false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => 'openid email profile User.Read',
        ]),
    ],
]));

if (!$tokenResp) {
    header('Location: ' . $_oauthReturn . '?error=ms_token_exchange_failed');
    exit;
}

$tokens = json_decode($tokenResp, true);
$accessToken = $tokens['access_token'] ?? '';

if (!$accessToken) {
    header('Location: ' . $_oauthReturn . '?error=ms_no_access_token');
    exit;
}

// Get user info from Microsoft Graph
$userInfoResp = @file_get_contents('https://graph.microsoft.com/v1.0/me', false, stream_context_create([
    'http' => [
        'header' => 'Authorization: Bearer ' . $accessToken,
    ],
]));

if (!$userInfoResp) {
    header('Location: ' . $_oauthReturn . '?error=ms_userinfo_failed');
    exit;
}

$userInfo = json_decode($userInfoResp, true);
$oauthId = $userInfo['id'] ?? '';
$email = $userInfo['mail'] ?? $userInfo['userPrincipalName'] ?? '';
$name = $userInfo['displayName'] ?? '';

if (!$oauthId) {
    header('Location: ' . $_oauthReturn . '?error=ms_no_user_id');
    exit;
}

$result = resolveOAuthUser($db, 'microsoft', $oauthId, $email, $name, '');

if ($result['action'] === 'pending_link') {
    $return = $_SESSION['oauth_return'] ?? '/community';
    unset($_SESSION['oauth_return']);
    header('Location: ' . $return . '#link-account');
    exit;
}

$_SESSION['user_key'] = $result['user_key'];
$_SESSION['user_display_name'] = $name;
$_SESSION['user_avatar'] = '';

$return = $_SESSION['oauth_return'] ?? '/community';
unset($_SESSION['oauth_return']);
header('Location: ' . $return);
exit;
