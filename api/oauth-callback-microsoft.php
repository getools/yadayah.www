<?php
/**
 * Microsoft OAuth callback handler.
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

// Find or create user
$stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_oauth_provider = 'microsoft' AND user_oauth_id = ?");
$stmt->execute([$oauthId]);
$existing = $stmt->fetchColumn();

if ($existing) {
    $userKey = $existing;
    $db->prepare("UPDATE yy_user SET user_display_name = ?, user_email = ? WHERE user_key = ?")
       ->execute([$name, $email, $userKey]);
} else {
    if ($email) {
        $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_email = ?");
        $stmt->execute([$email]);
        $emailMatch = $stmt->fetchColumn();
        if ($emailMatch) {
            $userKey = $emailMatch;
            $db->prepare("UPDATE yy_user SET user_oauth_provider = 'microsoft', user_oauth_id = ?, user_display_name = ? WHERE user_key = ?")
               ->execute([$oauthId, $name, $userKey]);
        }
    }
    if (!isset($userKey)) {
        $stmt = $db->prepare("INSERT INTO yy_user (user_code, user_email, user_display_name, user_oauth_provider, user_oauth_id, user_active_flag, user_verified) VALUES (?, ?, ?, 'microsoft', ?, TRUE, TRUE) RETURNING user_key");
        $stmt->execute(['microsoft:' . $oauthId, $email, $name, $oauthId]);
        $userKey = $stmt->fetchColumn();
        $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, 1)")->execute([$userKey]);
    }
}

// Merge mode
if (!empty($_SESSION['merge_via_oauth']) && !empty($_SESSION['user_key'])) {
    unset($_SESSION['merge_via_oauth']);
    if ((int)$userKey === (int)$_SESSION['user_key']) { header('Location: /community#merge-error&reason=same'); exit; }
    $u = $db->prepare("SELECT user_display_name, user_email FROM yy_user WHERE user_key = ?"); $u->execute([$userKey]); $info = $u->fetch();
    $_SESSION['merge_target'] = ['user_key' => (int)$userKey, 'display_name' => $info['user_display_name'] ?? $name, 'email' => $info['user_email'] ?? $email, 'token' => bin2hex(random_bytes(16)), 'expires' => time() + 600];
    header('Location: /community#merge-confirm'); exit;
}

$_SESSION['user_key'] = $userKey;
$_SESSION['user_display_name'] = $name;
$_SESSION['user_avatar'] = '';
$_SESSION['oauth_provider'] = 'microsoft';

$return = $_SESSION['oauth_return'] ?? '/community';
unset($_SESSION['oauth_return']);
header('Location: ' . $return);
exit;
