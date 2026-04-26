<?php
/**
 * Shared OAuth helper: resolves an OAuth identity to a user account.
 * Uses yy_user_auth table for multi-provider support.
 *
 * Returns: ['action' => 'login'|'linked'|'pending_link'|'new_user', 'user_key' => int|null]
 */

function resolveOAuthUser(PDO $db, string $provider, string $oauthId, string $email, string $name, string $avatar): array {
    // Step 1: Check if this exact provider+oauthId already exists
    $stmt = $db->prepare("
        SELECT ua.user_key FROM yy_user_auth ua
        WHERE ua.auth_provider = ? AND ua.auth_provider_id = ? AND ua.auth_active_flag = TRUE
    ");
    $stmt->execute([$provider, $oauthId]);
    $existingUserKey = $stmt->fetchColumn();

    if ($existingUserKey) {
        // Known OAuth identity — update profile, log them in
        $updates = ['user_name_display = ?', 'user_display_name = ?'];
        $params = [$name, $name];

        // Only update avatar if the user doesn't have a manually uploaded one
        if ($avatar) {
            $avStmt = $db->prepare("SELECT user_avatar FROM yy_user WHERE user_key = ?");
            $avStmt->execute([$existingUserKey]);
            $currentAvatar = $avStmt->fetchColumn() ?: '';
            // Local uploads start with /u/ — don't overwrite those with OAuth avatars
            if (!$currentAvatar || strpos($currentAvatar, '/u/') !== 0) {
                $updates[] = 'user_avatar = ?';
                $params[] = $avatar;
            }
        }

        if ($email) {
            $updates[] = 'user_email = ?';
            $params[] = $email;
        }
        $params[] = $existingUserKey;
        $db->prepare("UPDATE yy_user SET " . implode(', ', $updates) . " WHERE user_key = ?")
            ->execute($params);

        // Update email on the auth row too
        if ($email) {
            $db->prepare("UPDATE yy_user_auth SET auth_email = ? WHERE auth_provider = ? AND auth_provider_id = ?")
                ->execute([$email, $provider, $oauthId]);
        }

        return ['action' => 'login', 'user_key' => (int)$existingUserKey];
    }

    // Step 2: User is already logged in and wants to LINK this provider
    if (!empty($_SESSION['linking_provider']) && !empty($_SESSION['user_key'])) {
        unset($_SESSION['linking_provider']);
        $db->prepare("INSERT INTO yy_user_auth (user_key, auth_provider, auth_provider_id, auth_email) VALUES (?, ?, ?, ?)")
            ->execute([$_SESSION['user_key'], $provider, $oauthId, $email ?: null]);

        return ['action' => 'linked', 'user_key' => (int)$_SESSION['user_key']];
    }

    // Step 3: Check if email matches an existing user (potential link)
    if ($email) {
        $stmt = $db->prepare("SELECT user_key, user_name_display FROM yy_user WHERE LOWER(user_email) = LOWER(?) AND user_active_flag = TRUE");
        $stmt->execute([$email]);
        $match = $stmt->fetch();
        if ($match) {
            // Store pending link in session — frontend will prompt user
            $_SESSION['pending_link'] = [
                'provider' => $provider,
                'oauth_id' => $oauthId,
                'email' => $email,
                'name' => $name,
                'avatar' => $avatar,
                'existing_user_key' => (int)$match['user_key'],
                'existing_name' => $match['user_name_display'],
                'token' => bin2hex(random_bytes(16)),
                'expires' => time() + 600, // 10 minutes
            ];
            return ['action' => 'pending_link'];
        }
    }

    // Step 4: Brand new user — create account
    $userCode = $provider . ':' . $oauthId;
    // Fallback display name: use part before @ in email
    if (!$name && $email) $name = explode('@', $email)[0];
    if (!$name) $name = 'Unknown';
    $stmt = $db->prepare("
        INSERT INTO yy_user (user_code, user_email, user_display_name, user_name_display, user_avatar, user_active_flag, user_verified)
        VALUES (?, ?, ?, ?, ?, TRUE, TRUE) RETURNING user_key
    ");
    $stmt->execute([$userCode, $email ?: null, $name, $name, $avatar ?: null]);
    $userKey = (int)$stmt->fetchColumn();

    // Insert auth method
    $db->prepare("INSERT INTO yy_user_auth (user_key, auth_provider, auth_provider_id, auth_email) VALUES (?, ?, ?, ?)")
        ->execute([$userKey, $provider, $oauthId, $email ?: null]);

    // Assign public role
    $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, (SELECT role_key FROM yy_role WHERE role_code = 'public'))")
        ->execute([$userKey]);

    return ['action' => 'new_user', 'user_key' => $userKey];
}

/**
 * Complete OAuth flow: redirect normally, or close popup and notify opener.
 */
function oauthComplete(string $url): void {
    if (!empty($_SESSION['oauth_popup'])) {
        unset($_SESSION['oauth_popup']);
        // Detect HTTPS correctly behind reverse proxy (Cloudflare/Caddy)
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
        $origin = ($isHttps ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body><script>'
           . 'try{if(window.opener&&!window.opener.closed){window.opener.postMessage({type:"oauth_complete"},"' . htmlspecialchars($origin) . '");}}catch(e){}'
           . 'window.close();'
           . 'setTimeout(function(){document.body.innerHTML="<p style=\"font-family:sans-serif;text-align:center;padding:40px\">Login complete. You can close this window.</p>";},100);'
           . '</script></body></html>';
        exit;
    }
    header('Location: ' . $url);
    exit;
}
