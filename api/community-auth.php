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

    // Check if email already exists
    $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) errorResponse('An account with this email already exists. Try logging in instead.');

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO yy_user (user_code, user_email, user_display_name, user_pass, user_oauth_provider, user_active_flag, user_verified) VALUES (?, ?, ?, ?, 'email', TRUE, FALSE) RETURNING user_key");
    $stmt->execute(['email:' . $email, $email, $name, $hash]);
    $userKey = $stmt->fetchColumn();

    // Assign public role
    $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, 1)")->execute([$userKey]);

    // Send verification email
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 86400); // 24 hours
    $db->prepare("INSERT INTO yy_email_verify (user_key, verify_token, verify_expires) VALUES (?, ?, ?)")
       ->execute([$userKey, $token, $expires]);

    $verifyUrl = 'https://yadayah.com/community#verify=' . $token;
    require_once __DIR__ . '/send-mail.php';
    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<h2 style="color:#31345A;">Welcome to Yada Yahowah!</h2>'
        . '<p>Thanks for creating an account, ' . htmlspecialchars($name) . '. Please verify your email address to start posting in the Community.</p>'
        . '<p><a href="' . $verifyUrl . '" style="display:inline-block;padding:12px 24px;background:#31345A;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Verify Email</a></p>'
        . '<p style="font-size:0.85em;color:#999;">This link expires in 24 hours.</p>'
        . '</div>';
    sendMail($db, $email, 'Verify Your Email - Yada Yahowah', $htmlBody);

    $_SESSION['user_key'] = $userKey;
    $_SESSION['user_display_name'] = $name;
    $_SESSION['user_avatar'] = '';
    $_SESSION['oauth_provider'] = 'email';

    jsonResponse(['success' => true, 'user_key' => $userKey, 'needs_verify' => true]);
}

if ($action === 'login') {
    $email = trim($data['email'] ?? '');
    $pass = $data['password'] ?? '';

    if (!$email || !$pass) errorResponse('Email and password are required');

    $stmt = $db->prepare("SELECT user_key, user_pass, user_display_name, user_avatar, user_active_flag FROM yy_user WHERE user_email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) errorResponse('No account found with this email');
    if (!$user['user_pass']) errorResponse('This account uses social login. Try Google or Facebook.');
    if (!password_verify($pass, $user['user_pass'])) errorResponse('Incorrect password');
    if ($user['user_active_flag'] === false || $user['user_active_flag'] === 'f') errorResponse('Account is disabled');

    $_SESSION['user_key'] = $user['user_key'];
    $_SESSION['user_display_name'] = $user['user_display_name'];
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

    jsonResponse(['success' => true, 'message' => 'Email verified! You can now post in the Community.']);
}

if ($action === 'resend_verify') {
    $userKey = $_SESSION['user_key'] ?? null;
    if (!$userKey) errorResponse('Login required', 401);

    $stmt = $db->prepare("SELECT user_email, user_display_name, user_verified FROM yy_user WHERE user_key = ?");
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

    $verifyUrl = 'https://yadayah.com/community#verify=' . $token;
    require_once __DIR__ . '/send-mail.php';
    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<h2 style="color:#31345A;">Verify Your Email</h2>'
        . '<p>Please click below to verify your email address for the Yada Yahowah Community.</p>'
        . '<p><a href="' . $verifyUrl . '" style="display:inline-block;padding:12px 24px;background:#31345A;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Verify Email</a></p>'
        . '<p style="font-size:0.85em;color:#999;">This link expires in 24 hours.</p>'
        . '</div>';
    $sent = sendMail($db, $user['user_email'], 'Verify Your Email - Yada Yahowah', $htmlBody);

    jsonResponse(['success' => true, 'message' => $sent ? 'Verification email sent!' : 'Failed to send email. Please try again.']);
}

if ($action === 'forgot') {
    $email = trim($data['email'] ?? '');
    if (!$email) errorResponse('Email is required');

    $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_email = ?");
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

    $resetUrl = 'https://yadayah.com/community#reset=' . $token;

    // Send email
    require_once __DIR__ . '/send-mail.php';
    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<h2 style="color:#31345A;">Password Reset</h2>'
        . '<p>You requested a password reset for your Yada Yahowah Community account.</p>'
        . '<p><a href="' . $resetUrl . '" style="display:inline-block;padding:12px 24px;background:#31345A;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Reset Password</a></p>'
        . '<p style="font-size:0.85em;color:#999;">This link expires in 1 hour. If you didn\'t request this, you can ignore this email.</p>'
        . '</div>';
    $sent = sendMail($db, $email, 'Password Reset - Yada Yahowah', $htmlBody);

    if ($sent) {
        jsonResponse(['success' => true, 'message' => 'A password reset link has been sent to your email.']);
    } else {
        // Fallback: return the link directly
        jsonResponse(['success' => true, 'message' => 'Email sending failed. Use this link to reset your password:', 'reset_url' => $resetUrl]);
    }
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
    $db->prepare("UPDATE yy_user SET user_pass = ? WHERE user_key = ?")->execute([$hash, $reset['user_key']]);
    $db->prepare("UPDATE yy_password_reset SET reset_used = TRUE WHERE reset_token = ?")->execute([$token]);

    jsonResponse(['success' => true, 'message' => 'Password has been reset. You can now sign in.']);
}

errorResponse('Unknown action');
