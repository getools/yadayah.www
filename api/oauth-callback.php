<?php
/**
 * OAuth callback handler. Exchanges code for tokens, creates/finds user, sets session.
 * Redirects to /community on success.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$provider = $_GET['provider'] ?? '';
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';
$_oauthReturn = $_SESSION['oauth_return'] ?? '/community';

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
    $tokenResp = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
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
        ],
    ]));

    if (!$tokenResp) {
        header('Location: ' . $_oauthReturn . '?error=token_exchange_failed');
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

    // Find or create user
    $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_oauth_provider = 'google' AND user_oauth_id = ?");
    $stmt->execute([$oauthId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $userKey = $existing;
        // Update profile info
        $db->prepare("UPDATE yy_user SET user_display_name = ?, user_avatar = ?, user_email = ? WHERE user_key = ?")
           ->execute([$name, $avatar, $email, $userKey]);
    } else {
        // Check if email already exists (link accounts)
        if ($email) {
            $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_email = ?");
            $stmt->execute([$email]);
            $emailMatch = $stmt->fetchColumn();
            if ($emailMatch) {
                $userKey = $emailMatch;
                $db->prepare("UPDATE yy_user SET user_oauth_provider = 'google', user_oauth_id = ?, user_display_name = ?, user_avatar = ? WHERE user_key = ?")
                   ->execute([$oauthId, $name, $avatar, $userKey]);
            }
        }

        if (!isset($userKey)) {
            // Create new user
            $stmt = $db->prepare("INSERT INTO yy_user (user_code, user_email, user_display_name, user_avatar, user_oauth_provider, user_oauth_id, user_active_flag) VALUES (?, ?, ?, ?, 'google', ?, TRUE) RETURNING user_key");
            $stmt->execute(['google:' . $oauthId, $email, $name, $avatar, $oauthId]);
            $userKey = $stmt->fetchColumn();

            // Assign public role
            $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, 1)")->execute([$userKey]);
        }
    }

    // Merge mode: store target instead of logging in
    if (!empty($_SESSION['merge_via_oauth']) && !empty($_SESSION['user_key'])) {
        unset($_SESSION['merge_via_oauth']);
        if ((int)$userKey === (int)$_SESSION['user_key']) {
            header('Location: /community#merge-error&reason=same');
            exit;
        }
        $u = $db->prepare("SELECT user_display_name, user_email FROM yy_user WHERE user_key = ?");
        $u->execute([$userKey]);
        $info = $u->fetch();
        $_SESSION['merge_target'] = [
            'user_key' => (int)$userKey,
            'display_name' => $info['user_display_name'] ?? $name,
            'email' => $info['user_email'] ?? $email,
            'token' => bin2hex(random_bytes(16)),
            'expires' => time() + 600,
        ];
        header('Location: /community#merge-confirm');
        exit;
    }

    // Set session
    $_SESSION['user_key'] = $userKey;
    $_SESSION['user_display_name'] = $name;
    $_SESSION['user_avatar'] = $avatar;
    $_SESSION['oauth_provider'] = 'google';

    $return = $_SESSION['oauth_return'] ?? '/community';
    unset($_SESSION['oauth_return']);
    header('Location: ' . $return);
    exit;
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

    // Find or create user
    $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_oauth_provider = 'facebook' AND user_oauth_id = ?");
    $stmt->execute([$oauthId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $userKey = $existing;
        $db->prepare("UPDATE yy_user SET user_display_name = ?, user_avatar = ?, user_email = ? WHERE user_key = ?")
           ->execute([$name, $avatar, $email, $userKey]);
    } else {
        if ($email) {
            $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_email = ?");
            $stmt->execute([$email]);
            $emailMatch = $stmt->fetchColumn();
            if ($emailMatch) {
                $userKey = $emailMatch;
                $db->prepare("UPDATE yy_user SET user_oauth_provider = 'facebook', user_oauth_id = ?, user_display_name = ?, user_avatar = ? WHERE user_key = ?")
                   ->execute([$oauthId, $name, $avatar, $userKey]);
            }
        }
        if (!isset($userKey)) {
            $stmt = $db->prepare("INSERT INTO yy_user (user_code, user_email, user_display_name, user_avatar, user_oauth_provider, user_oauth_id, user_active_flag) VALUES (?, ?, ?, ?, 'facebook', ?, TRUE) RETURNING user_key");
            $stmt->execute(['facebook:' . $oauthId, $email, $name, $avatar, $oauthId]);
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
    $_SESSION['user_avatar'] = $avatar;
    $_SESSION['oauth_provider'] = 'facebook';

    $return = $_SESSION['oauth_return'] ?? '/community';
    unset($_SESSION['oauth_return']);
    header('Location: ' . $return);
    exit;
}

header('Location: ' . $_oauthReturn . '?error=unknown_provider');
