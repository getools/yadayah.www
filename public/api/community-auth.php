<?php
/**
 * Email/password authentication for community.
 * POST with action=login, action=register, or action=logout.
 */
require_once __DIR__ . '/config.php';
session_start();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') errorResponse('POST required', 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$db = getDb();

if ($action === 'register') {
    $email = trim($data['email'] ?? '');
    $name = trim($data['name'] ?? '');
    $pass = $data['password'] ?? '';

    if (!$email || !$name || !$pass) errorResponse('Name, email, and password are required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) errorResponse('Invalid email address');

    // Check if email already exists (case-insensitive)
    $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE LOWER(user_email) = LOWER(?)");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) errorResponse('An account with this email already exists. Try logging in instead.');

    // Check yy_user_auth too
    $stmt = $db->prepare("SELECT 1 FROM yy_user_auth WHERE auth_provider = 'email' AND LOWER(auth_email) = LOWER(?) AND auth_active_flag = TRUE");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) errorResponse('An account with this email already exists. Try logging in instead.');

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO yy_user (user_code, user_email, user_name_display, user_active_flag, user_verified) VALUES (?, ?, ?, TRUE, FALSE) RETURNING user_key");
    $stmt->execute(['email:' . $email, $email, $name]);
    $userKey = $stmt->fetchColumn();

    // Insert auth method
    $db->prepare("INSERT INTO yy_user_auth (user_key, auth_provider, auth_email, auth_pass) VALUES (?, 'email', ?, ?)")
        ->execute([$userKey, $email, $hash]);

    // Assign public role
    $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, (SELECT role_key FROM yy_role WHERE role_code = 'public'))")->execute([$userKey]);

    // Send verification email
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 86400); // 24 hours
    $db->prepare("INSERT INTO yy_email_verify (user_key, verify_token, verify_expires) VALUES (?, ?, ?)")
       ->execute([$userKey, $token, $expires]);

    $verifyUrl = 'https://yadayah.com/chat#verify=' . $token;
    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<h2 style="color:#31345A;">Welcome to Yada Yahowah!</h2>'
        . '<p>Thanks for creating an account, ' . htmlspecialchars($name) . '. Please verify your email address to start posting in Chat.</p>'
        . '<p><a href="' . $verifyUrl . '" style="display:inline-block;padding:12px 24px;background:#31345A;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Verify Email</a></p>'
        . '<p style="font-size:0.85em;color:#999;">This link expires in 24 hours.</p>'
        . '</div>';
    $db->prepare("INSERT INTO yy_email_queue (to_email, subject, body_html) VALUES (?, ?, ?)")
       ->execute([$email, 'Verify Your Email - Yada Yahowah', $htmlBody]);
    @exec('php ' . escapeshellarg(__DIR__ . '/process-email-queue.php') . ' > /dev/null 2>&1 &');

    $_SESSION['user_key'] = $userKey;
    $_SESSION['user_name_display'] = $name;
    $_SESSION['user_avatar'] = '';
    $_SESSION['oauth_provider'] = 'email';

    jsonResponse(['success' => true, 'user_key' => $userKey, 'needs_verify' => true]);
}

if ($action === 'login') {
    $email = trim($data['email'] ?? '');
    $pass = $data['password'] ?? '';

    if (!$email || !$pass) errorResponse('Email and password are required');

    // Look up via yy_user_auth for email/password login
    // Match on auth_email OR user_email (admin users may have different auth_email)
    $stmt = $db->prepare("
        SELECT u.user_key, ua.auth_pass, u.user_name_display, u.user_avatar, u.user_active_flag
        FROM yy_user u
        JOIN yy_user_auth ua ON ua.user_key = u.user_key
        WHERE ua.auth_provider = 'email' AND ua.auth_active_flag = TRUE
          AND (LOWER(ua.auth_email) = LOWER(?) OR LOWER(u.user_email) = LOWER(?))
    ");
    $stmt->execute([$email, $email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Check if user exists but only has OAuth
        $stmt2 = $db->prepare("SELECT 1 FROM yy_user WHERE LOWER(user_email) = LOWER(?) AND user_active_flag = TRUE");
        $stmt2->execute([$email]);
        if ($stmt2->fetchColumn()) {
            errorResponse('This account uses social login. Try signing in with Google, Microsoft, Yahoo, or X.');
        }
        errorResponse('No account found with this email');
    }
    if (!$user['auth_pass'] || !password_verify($pass, $user['auth_pass'])) errorResponse('Incorrect password');
    if ($user['user_active_flag'] === false || $user['user_active_flag'] === 'f') errorResponse('Account is disabled');

    $_SESSION['user_key'] = $user['user_key'];
    $_SESSION['user_name_display'] = $user['user_name_display'];
    $_SESSION['user_avatar'] = $user['user_avatar'] ?? '';
    $_SESSION['oauth_provider'] = 'email';

    jsonResponse(['success' => true]);
}

if ($action === 'verify') {
    $token = trim($data['token'] ?? '');
    if (!$token) errorResponse('Verification token is required');

    $stmt = $db->prepare("SELECT v.user_key, v.verify_expires FROM yy_email_verify v WHERE v.verify_token = ? AND v.verify_used = FALSE");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) errorResponse('Invalid or expired verification link');
    if (new DateTime($row['verify_expires']) < new DateTime()) {
        $db->prepare("UPDATE yy_email_verify SET verify_used = TRUE WHERE verify_token = ?")->execute([$token]);
        errorResponse('This verification link has expired. Please request a new one from your profile.');
    }

    $db->prepare("UPDATE yy_user SET user_verified = TRUE WHERE user_key = ?")->execute([$row['user_key']]);
    $db->prepare("UPDATE yy_email_verify SET verify_used = TRUE WHERE verify_token = ?")->execute([$token]);

    jsonResponse(['success' => true, 'message' => 'Email verified! You can now post in Chat.']);
}

if ($action === 'resend_verify') {
    $userKey = $_SESSION['user_key'] ?? null;
    if (!$userKey) errorResponse('Login required', 401);

    $stmt = $db->prepare("SELECT user_email, user_name_display, user_verified FROM yy_user WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $user = $stmt->fetch();
    if (!$user) errorResponse('User not found');
    if ($user['user_verified'] === true || $user['user_verified'] === 't') errorResponse('Email is already verified');

    // Invalidate old tokens
    $db->prepare("UPDATE yy_email_verify SET verify_used = TRUE WHERE user_key = ? AND verify_used = FALSE")->execute([$userKey]);

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 86400);
    $db->prepare("INSERT INTO yy_email_verify (user_key, verify_token, verify_expires) VALUES (?, ?, ?)")
       ->execute([$userKey, $token, $expires]);

    $verifyUrl = 'https://yadayah.com/chat#verify=' . $token;
    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<h2 style="color:#31345A;">Verify Your Email</h2>'
        . '<p>Please click below to verify your email address for the Yada Yahowah Chat.</p>'
        . '<p><a href="' . $verifyUrl . '" style="display:inline-block;padding:12px 24px;background:#31345A;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Verify Email</a></p>'
        . '<p style="font-size:0.85em;color:#999;">This link expires in 24 hours.</p>'
        . '</div>';
    $db->prepare("INSERT INTO yy_email_queue (to_email, subject, body_html) VALUES (?, ?, ?)")
       ->execute([$user['user_email'], 'Verify Your Email - Yada Yahowah', $htmlBody]);
    @exec('php ' . escapeshellarg(__DIR__ . '/process-email-queue.php') . ' > /dev/null 2>&1 &');

    jsonResponse(['success' => true, 'message' => 'Verification email sent!']);
}

if ($action === 'forgot') {
    $email = trim($data['email'] ?? '');
    if (!$email) errorResponse('Email is required');

    $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE LOWER(user_email) = LOWER(?) AND user_active_flag = TRUE");
    $stmt->execute([$email]);
    $userKey = $stmt->fetchColumn();

    if (!$userKey) errorResponse('No account found with this email');

    // Invalidate old tokens
    $db->prepare("UPDATE yy_password_reset SET reset_used = TRUE WHERE user_key = ? AND reset_used = FALSE")->execute([$userKey]);

    // Generate token (valid 1 hour)
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $db->prepare("INSERT INTO yy_password_reset (user_key, reset_token, reset_expires) VALUES (?, ?, ?)")
       ->execute([$userKey, $token, $expires]);

    $resetUrl = 'https://yadayah.com/chat#reset=' . $token;

    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<h2 style="color:#31345A;">Password Reset</h2>'
        . '<p>You requested a password reset for your Yada Yahowah Chat account.</p>'
        . '<p><a href="' . $resetUrl . '" style="display:inline-block;padding:12px 24px;background:#31345A;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Reset Password</a></p>'
        . '<p style="font-size:0.85em;color:#999;">This link expires in 1 hour. If you didn\'t request this, you can ignore this email.</p>'
        . '</div>';
    $db->prepare("INSERT INTO yy_email_queue (to_email, subject, body_html) VALUES (?, ?, ?)")
       ->execute([$email, 'Password Reset - Yada Yahowah', $htmlBody]);
    @exec('php ' . escapeshellarg(__DIR__ . '/process-email-queue.php') . ' > /dev/null 2>&1 &');

    jsonResponse(['success' => true, 'message' => 'A password reset link has been sent to your email.']);
}

if ($action === 'reset') {
    $token = trim($data['token'] ?? '');
    $newPass = $data['password'] ?? '';

    if (!$token) errorResponse('Reset token is required');
    if (!strlen($newPass)) errorResponse('Password cannot be empty');

    $stmt = $db->prepare("SELECT r.user_key, r.reset_expires FROM yy_password_reset r WHERE r.reset_token = ? AND r.reset_used = FALSE");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) errorResponse('Invalid or expired reset link');
    if (new DateTime($reset['reset_expires']) < new DateTime()) {
        $db->prepare("UPDATE yy_password_reset SET reset_used = TRUE WHERE reset_token = ?")->execute([$token]);
        errorResponse('This reset link has expired. Please request a new one.');
    }

    $hash = password_hash($newPass, PASSWORD_BCRYPT);
    $resetUserKey = $reset['user_key'];

    // Update or create email auth method
    $stmt = $db->prepare("SELECT user_auth_key FROM yy_user_auth WHERE user_key = ? AND auth_provider = 'email' AND auth_active_flag = TRUE");
    $stmt->execute([$resetUserKey]);
    if ($stmt->fetchColumn()) {
        $db->prepare("UPDATE yy_user_auth SET auth_pass = ? WHERE user_key = ? AND auth_provider = 'email' AND auth_active_flag = TRUE")
            ->execute([$hash, $resetUserKey]);
    } else {
        // Create email auth method (allows OAuth-only users to set a password)
        $emailStmt = $db->prepare("SELECT user_email FROM yy_user WHERE user_key = ?");
        $emailStmt->execute([$resetUserKey]);
        $userEmail = $emailStmt->fetchColumn();
        $db->prepare("INSERT INTO yy_user_auth (user_key, auth_provider, auth_email, auth_pass) VALUES (?, 'email', ?, ?)")
            ->execute([$resetUserKey, $userEmail, $hash]);
    }
    // Keep yy_user.user_pass in sync for backward compatibility
    $db->prepare("UPDATE yy_user SET user_pass = ? WHERE user_key = ?")->execute([$hash, $resetUserKey]);
    $db->prepare("UPDATE yy_password_reset SET reset_used = TRUE WHERE reset_token = ?")->execute([$token]);

    jsonResponse(['success' => true, 'message' => 'Password has been reset. You can now sign in.']);
}

errorResponse('Unknown action');
