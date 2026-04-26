<?php
/**
 * OAuth callback handler. Exchanges code for tokens, creates/finds user, sets session.
 * Redirects to /community on success.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oauth-helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$provider = $_GET['provider'] ?? '';
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';
$_oauthReturn = $_SESSION['oauth_return'] ?? '/chat';

if ($error) {
    unset($_SESSION['oauth_return']);
    header('Location: ' . $_oauthReturn . '?error=' . urlencode($error));
    exit;
}

// Verify CSRF state
if (!$state || $state !== ($_SESSION['oauth_state'] ?? '')) {
    unset($_SESSION['oauth_return']);
    header('Location: ' . $_oauthReturn . '?error=invalid_state');
    exit;
}
unset($_SESSION['oauth_state']);

$db = getDb();

if ($provider === 'google') {
    // Get OAuth credentials from DB
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code LIKE 'oauth-google-%'");
    $stmt->execute();
    $settings = [];
    foreach ($stmt->fetchAll() as $r) $settings[$r['setting_code']] = $r['setting_value'];

    $clientId = $settings['oauth-google-client-id'] ?? '';
    $clientSecret = $settings['oauth-google-client-secret'] ?? '';
    $redirectUri = 'https://yadayah.com/api/oauth-callback.php?provider=google';

    // Exchange code for tokens
    $tokenResp = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
            'ignore_errors' => true,
        ],
    ]));

    if (!$tokenResp) {
        error_log('Google OAuth token exchange: no response');
        header('Location: ' . $_oauthReturn . '?error=token_exchange_failed');
        exit;
    }

    // Log non-200 responses
    $tokens = json_decode($tokenResp, true);
    if (isset($tokens['error'])) {
        error_log('Google OAuth token exchange error: ' . $tokens['error'] . ' - ' . ($tokens['error_description'] ?? ''));
        header('Location: ' . $_oauthReturn . '?error=token_exchange_failed&detail=' . urlencode($tokens['error']));
        exit;
    }

    $tokens = json_decode($tokenResp, true);
    $idToken = $tokens['id_token'] ?? '';
    $accessToken = $tokens['access_token'] ?? '';

    if (!$accessToken) {
        header('Location: ' . $_oauthReturn . '?error=no_access_token');
        exit;
    }

    // Get user info
    $userInfoResp = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, stream_context_create([
        'http' => [
            'header' => 'Authorization: Bearer ' . $accessToken,
        ],
    ]));

    if (!$userInfoResp) {
        header('Location: ' . $_oauthReturn . '?error=userinfo_failed');
        exit;
    }

    $userInfo = json_decode($userInfoResp, true);
    $oauthId = $userInfo['id'] ?? '';
    $email = $userInfo['email'] ?? '';
    $name = $userInfo['name'] ?? '';
    $avatar = $userInfo['picture'] ?? '';

    if (!$oauthId) {
        header('Location: ' . $_oauthReturn . '?error=no_user_id');
        exit;
    }

    $result = resolveOAuthUser($db, 'google', $oauthId, $email, $name, $avatar);

    if ($result['action'] === 'pending_link') {
        $return = $_SESSION['oauth_return'] ?? '/chat';
        unset($_SESSION['oauth_return']);
        oauthComplete($return . '#link-account');
    }

    $_SESSION['user_key'] = $result['user_key'];
    $_SESSION['user_name_display'] = $name;
    $_SESSION['user_avatar'] = $avatar;

    $return = $_SESSION['oauth_return'] ?? '/chat';
    unset($_SESSION['oauth_return']);
    oauthComplete($return);
}

if ($provider === 'facebook') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'oauth' AND setting_code LIKE 'oauth-facebook-%'");
    $stmt->execute();
    $settings = [];
    foreach ($stmt->fetchAll() as $r) $settings[$r['setting_code']] = $r['setting_value'];

    $appId = $settings['oauth-facebook-app-id'] ?? '';
    $appSecret = $settings['oauth-facebook-app-secret'] ?? '';
    $redirectUri = 'https://yadayah.com/api/oauth-callback.php?provider=facebook';

    // Exchange code for access token
    $tokenUrl = 'https://graph.facebook.com/v22.0/oauth/access_token?' . http_build_query([
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'redirect_uri' => $redirectUri,
        'code' => $code,
    ]);
    $tokenResp = @file_get_contents($tokenUrl);
    if (!$tokenResp) {
        header('Location: ' . $_oauthReturn . '?error=fb_token_exchange_failed');
        exit;
    }
    $tokens = json_decode($tokenResp, true);
    $accessToken = $tokens['access_token'] ?? '';
    if (!$accessToken) {
        header('Location: ' . $_oauthReturn . '?error=fb_no_access_token');
        exit;
    }

    // Get user info
    $userInfoUrl = 'https://graph.facebook.com/v22.0/me?fields=id,name,email,picture.type(large)&access_token=' . urlencode($accessToken);
    $userInfoResp = @file_get_contents($userInfoUrl);
    if (!$userInfoResp) {
        header('Location: ' . $_oauthReturn . '?error=fb_userinfo_failed');
        exit;
    }
    $userInfo = json_decode($userInfoResp, true);
    $oauthId = $userInfo['id'] ?? '';
    $email = $userInfo['email'] ?? '';
    $name = $userInfo['name'] ?? '';
    $avatar = $userInfo['picture']['data']['url'] ?? '';

    if (!$oauthId) {
        header('Location: ' . $_oauthReturn . '?error=fb_no_user_id');
        exit;
    }

    $result = resolveOAuthUser($db, 'facebook', $oauthId, $email, $name, $avatar);

    if ($result['action'] === 'pending_link') {
        $return = $_SESSION['oauth_return'] ?? '/chat';
        unset($_SESSION['oauth_return']);
        oauthComplete($return . '#link-account');
    }

    $_SESSION['user_key'] = $result['user_key'];
    $_SESSION['user_name_display'] = $name;
    $_SESSION['user_avatar'] = $avatar;

    $return = $_SESSION['oauth_return'] ?? '/chat';
    unset($_SESSION['oauth_return']);
    oauthComplete($return);
}

header('Location: ' . $_oauthReturn . '?error=unknown_provider');
