<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!empty($_SESSION['user_key'])) {
            jsonResponse([
                'authenticated' => true,
                'user_key' => $_SESSION['user_key'],
                'user_code' => $_SESSION['user_code'],
                'user_name' => $_SESSION['user_name'] ?? $_SESSION['user_code'],
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
        $stmt = $db->prepare('SELECT user_key, user_code, user_pass, user_name_full FROM yy_user WHERE LOWER(user_code) = LOWER(?)');
        $stmt->execute([$data['login']]);
        $user = $stmt->fetch();

        if (!$user || !$user['user_pass'] || !password_verify($data['password'], $user['user_pass'])) {
            errorResponse('Invalid login or password', 401);
        }

        $_SESSION['user_key'] = $user['user_key'];
        $_SESSION['user_code'] = $user['user_code'];
        $_SESSION['user_name'] = $user['user_name_full'] ?: $user['user_code'];

        jsonResponse([
            'authenticated' => true,
            'user_key' => $user['user_key'],
            'user_code' => $user['user_code'],
            'user_name' => $_SESSION['user_name'],
        ]);
        break;

    case 'DELETE':
        session_destroy();
        jsonResponse(['authenticated' => false]);
        break;

    default:
        errorResponse('Method not allowed', 405);
}
