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
        $updates = ['user_display_name = ?'];
        $params = [$name];
        if ($avatar) {
            $updates[] = 'user_avatar = ?';
            $params[] = $avatar;
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
        $stmt = $db->prepare("SELECT user_key, user_display_name FROM yy_user WHERE LOWER(user_email) = LOWER(?) AND user_active_flag = TRUE");
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
                'existing_name' => $match['user_display_name'],
                'token' => bin2hex(random_bytes(16)),
                'expires' => time() + 600, // 10 minutes
            ];
            return ['action' => 'pending_link'];
        }
    }

    // Step 4: Brand new user — create account
    $userCode = $provider . ':' . $oauthId;
    $stmt = $db->prepare("
        INSERT INTO yy_user (user_code, user_email, user_display_name, user_avatar, user_active_flag, user_verified)
        VALUES (?, ?, ?, ?, TRUE, TRUE) RETURNING user_key
    ");
    $stmt->execute([$userCode, $email ?: null, $name, $avatar ?: null]);
    $userKey = (int)$stmt->fetchColumn();

    // Insert auth method
    $db->prepare("INSERT INTO yy_user_auth (user_key, auth_provider, auth_provider_id, auth_email) VALUES (?, ?, ?, ?)")
        ->execute([$userKey, $provider, $oauthId, $email ?: null]);

    // Assign public role
    $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, (SELECT role_key FROM yy_role WHERE role_code = 'public'))")
        ->execute([$userKey]);

    return ['action' => 'new_user', 'user_key' => $userKey];
}
