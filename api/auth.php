<?php
require_once __DIR__ . '/config.php';

function getUserPages(int $userKey): array {
    try {
        $db = getDb();
        $stmt = $db->prepare(
            "SELECT s.setting_code, us.user_setting_value
             FROM yy_user_setting us
             JOIN yy_setting s ON s.setting_key = us.setting_key
             WHERE us.user_key = :uk
               AND s.setting_scope_code = 'admin'
               AND s.setting_group_code = 'pages'
             ORDER BY s.setting_sort"
        );
        $stmt->execute(['uk' => $userKey]);
        $pages = [];
        foreach ($stmt->fetchAll() as $row) {
            $pages[$row['setting_code']] = $row['user_setting_value'] === '1';
        }
        return $pages;
    } catch (Exception $e) {
        return [];
    }
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!empty($_SESSION['user_key'])) {
            // If user_code not in session (OAuth/community login), look up from DB
            if (empty($_SESSION['user_code'])) {
                $db = getDb();
                $stmt = $db->prepare('SELECT user_code, user_name_display FROM yy_user WHERE user_key = ?');
                $stmt->execute([$_SESSION['user_key']]);
                $u = $stmt->fetch();
                if ($u) {
                    $_SESSION['user_code'] = $u['user_code'];
                    $_SESSION['user_name'] = $u['user_name_display'] ?: $u['user_code'];
                }
            }
            $pages = getUserPages($_SESSION['user_key']);
            jsonResponse([
                'authenticated' => true,
                'user_key' => $_SESSION['user_key'],
                'user_code' => $_SESSION['user_code'] ?? '',
                'user_name' => $_SESSION['user_name'] ?? $_SESSION['user_name_display'] ?? $_SESSION['user_code'] ?? '',
                'pages' => $pages,
            ]);
        } else {
            jsonResponse(['authenticated' => false]);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['login']) || empty($data['password'])) {
            errorResponse('Login and password are required');
        }

        $db = getDb();
        // Try yy_user_auth first (new system), fall back to yy_user.user_pass (legacy)
        $stmt = $db->prepare("
            SELECT u.user_key, u.user_code, ua.auth_pass, u.user_name_display
            FROM yy_user u
            JOIN yy_user_auth ua ON ua.user_key = u.user_key
            WHERE LOWER(u.user_code) = LOWER(?) AND ua.auth_provider = 'email' AND ua.auth_active_flag = TRUE
        ");
        $stmt->execute([$data['login']]);
        $user = $stmt->fetch();

        // Fallback: try legacy user_pass on yy_user
        if (!$user) {
            $stmt = $db->prepare('SELECT user_key, user_code, user_pass AS auth_pass, user_name_display FROM yy_user WHERE LOWER(user_code) = LOWER(?)');
            $stmt->execute([$data['login']]);
            $user = $stmt->fetch();
        }

        if (!$user || !$user['auth_pass'] || !password_verify($data['password'], $user['auth_pass'])) {
            errorResponse('Invalid login or password', 401);
        }

        $_SESSION['user_key'] = $user['user_key'];
        $_SESSION['user_code'] = $user['user_code'];
        $_SESSION['user_name'] = $user['user_name_display'] ?: $user['user_code'];

        $pages = getUserPages($user['user_key']);
        jsonResponse([
            'authenticated' => true,
            'user_key' => $user['user_key'],
            'user_code' => $user['user_code'],
            'user_name' => $_SESSION['user_name'],
            'pages' => $pages,
        ]);
        break;

    case 'DELETE':
        session_destroy();
        jsonResponse(['authenticated' => false]);
        break;

    default:
        errorResponse('Method not allowed', 405);
}
