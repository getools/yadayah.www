<?php
/**
 * Community profile API.
 * GET: return current user's profile
 * POST: update profile fields (name, handle, bio, email, password, avatar upload)
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->prepare("SELECT user_key, user_display_name, user_handle, user_email, user_avatar, user_bio, user_oauth_provider FROM yy_user WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $user = $stmt->fetch();
    if (!$user) errorResponse('User not found', 404);
    // Don't expose whether they have a password, just whether they CAN change it
    $user['has_password'] = !empty($user['user_oauth_provider']) && $user['user_oauth_provider'] !== 'email' ? false : true;
    unset($user['user_oauth_provider']);
    jsonResponse($user);
}

if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // Handle avatar upload (multipart)
    if (strpos($contentType, 'multipart/form-data') !== false) {
        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            errorResponse('No file uploaded');
        }
        $file = $_FILES['avatar'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) errorResponse('Invalid image type');
        if ($file['size'] > 2 * 1024 * 1024) errorResponse('Image must be under 2MB');

        $dir = __DIR__ . '/../u/avatars';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'av_' . $userKey . '_' . time() . '.' . $ext;
        $dest = $dir . '/' . $filename;

        // Resize to 200x200
        $src = null;
        if ($ext === 'png') $src = imagecreatefrompng($file['tmp_name']);
        elseif ($ext === 'gif') $src = imagecreatefromgif($file['tmp_name']);
        elseif ($ext === 'webp') $src = imagecreatefromwebp($file['tmp_name']);
        else $src = imagecreatefromjpeg($file['tmp_name']);

        if ($src) {
            $w = imagesx($src);
            $h = imagesy($src);
            $size = min($w, $h);
            $thumb = imagecreatetruecolor(200, 200);
            imagecopyresampled($thumb, $src, 0, 0, ($w - $size) / 2, ($h - $size) / 2, 200, 200, $size, $size);
            imagejpeg($thumb, $dest, 85);
            imagedestroy($src);
            imagedestroy($thumb);
            $ext = 'jpg'; // always save as jpg after resize
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
            if (basename($dest) !== $filename) {
                $newDest = $dir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
                if ($newDest !== $dest && file_exists($dest)) rename($dest, $newDest);
                $dest = $newDest;
            }
        } else {
            move_uploaded_file($file['tmp_name'], $dest);
        }

        $avatarUrl = '/u/avatars/' . basename($dest);
        $db->prepare("UPDATE yy_user SET user_avatar = ? WHERE user_key = ?")->execute([$avatarUrl, $userKey]);
        $_SESSION['user_avatar'] = $avatarUrl;
        jsonResponse(['saved' => true, 'avatar' => $avatarUrl]);
    }

    // Handle JSON profile update
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? 'update';

    if ($action === 'update') {
        $fields = [];
        $params = [];

        if (isset($data['display_name'])) {
            $name = trim($data['display_name']);
            if (!$name) errorResponse('Display name cannot be empty');
            $fields[] = 'user_display_name = ?';
            $params[] = $name;
            $_SESSION['user_display_name'] = $name;
        }
        if (isset($data['handle'])) {
            $handle = trim($data['handle']);
            if ($handle && !preg_match('/^[a-zA-Z0-9_]{1,30}$/', $handle)) errorResponse('Handle must be letters/numbers/underscore only, max 30 characters');
            if ($handle) {
                $chk = $db->prepare("SELECT user_key FROM yy_user WHERE user_handle = ? AND user_key != ?");
                $chk->execute([$handle, $userKey]);
                if ($chk->fetchColumn()) errorResponse('This handle is already taken');
            }
            $fields[] = 'user_handle = ?';
            $params[] = $handle ?: null;
        }
        if (isset($data['bio'])) {
            $fields[] = 'user_bio = ?';
            $params[] = trim($data['bio']);
        }
        if (isset($data['email'])) {
            $email = trim($data['email']);
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) errorResponse('Invalid email');
            if ($email) {
                $chk = $db->prepare("SELECT user_key FROM yy_user WHERE user_email = ? AND user_key != ?");
                $chk->execute([$email, $userKey]);
                if ($chk->fetchColumn()) errorResponse('This email is already in use');
            }
            $fields[] = 'user_email = ?';
            $params[] = $email;
        }

        if (empty($fields)) errorResponse('Nothing to update');
        $params[] = $userKey;
        $db->prepare("UPDATE yy_user SET " . implode(', ', $fields) . " WHERE user_key = ?")->execute($params);
        jsonResponse(['saved' => true]);
    }

    if ($action === 'change_password') {
        $current = $data['current_password'] ?? '';
        $newPass = $data['new_password'] ?? '';
        if (!strlen($newPass)) errorResponse('New password cannot be empty');

        // Check if user has existing password
        $stmt = $db->prepare("SELECT user_pass, user_oauth_provider FROM yy_user WHERE user_key = ?");
        $stmt->execute([$userKey]);
        $user = $stmt->fetch();

        if ($user['user_pass']) {
            if (!$current) errorResponse('Current password is required');
            if (!password_verify($current, $user['user_pass'])) errorResponse('Current password is incorrect');
        }

        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE yy_user SET user_pass = ? WHERE user_key = ?")->execute([$hash, $userKey]);
        jsonResponse(['saved' => true]);
    }

    errorResponse('Unknown action');
}

errorResponse('Method not allowed', 405);
