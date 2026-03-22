<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && !empty($_POST['_method'])) $method = strtoupper($_POST['_method']);
$input = null;
if (in_array($method, ['POST','PUT'])) {
    $input = json_decode(file_get_contents('php://input'), true);
}

switch ($method) {
    case 'GET':
        // List users with their page settings
        $users = $db->query("
            SELECT user_key, user_code, user_name_full, user_email, user_dtime
            FROM yy_user ORDER BY user_key
        ")->fetchAll();

        $settings = $db->query("
            SELECT setting_key, setting_code FROM yy_setting
            WHERE setting_scope_code = 'admin' AND setting_group_code = 'pages'
            ORDER BY setting_sort
        ")->fetchAll();

        $userSettings = $db->query("
            SELECT us.user_key, us.setting_key, us.user_setting_value
            FROM yy_user_setting us
            JOIN yy_setting s ON s.setting_key = us.setting_key
            WHERE s.setting_scope_code = 'admin' AND s.setting_group_code = 'pages'
        ")->fetchAll();

        // Index user settings by user_key => setting_key => value
        $settingsMap = [];
        foreach ($userSettings as $us) {
            $settingsMap[$us['user_key']][$us['setting_key']] = $us['user_setting_value'];
        }

        // Attach settings to each user
        foreach ($users as &$u) {
            $u['page_settings'] = [];
            foreach ($settings as $s) {
                $val = $settingsMap[$u['user_key']][$s['setting_key']] ?? '1';
                $u['page_settings'][] = [
                    'setting_key' => (int)$s['setting_key'],
                    'setting_code' => $s['setting_code'],
                    'enabled' => $val === '1',
                ];
            }
        }
        unset($u);

        jsonResponse(['users' => $users, 'settings' => $settings]);
        break;

    case 'PUT':
        if (!$input || empty($input['user_key'])) errorResponse('user_key required');
        $key = (int)$input['user_key'];

        // Update user fields
        $stmt = $db->prepare("
            UPDATE yy_user SET
                user_code = :code,
                user_name_full = :name_full,
                user_email = :email
            WHERE user_key = :key
        ");
        $stmt->execute([
            'code' => $input['user_code'] ?? '',
            'name_full' => $input['user_name_full'] ?? '',
            'email' => $input['user_email'] ?? '',
            'key' => $key,
        ]);

        // Update password if provided
        if (!empty($input['new_password'])) {
            $hash = password_hash($input['new_password'], PASSWORD_DEFAULT);
            $db->prepare("UPDATE yy_user SET user_pass = :pass WHERE user_key = :key")
               ->execute(['pass' => $hash, 'key' => $key]);
        }

        // Update page settings
        if (isset($input['page_settings']) && is_array($input['page_settings'])) {
            $upsert = $db->prepare("
                INSERT INTO yy_user_setting (user_key, setting_key, user_setting_value)
                VALUES (:uk, :sk, :val)
                ON CONFLICT (user_key, setting_key)
                DO UPDATE SET user_setting_value = EXCLUDED.user_setting_value
            ");
            foreach ($input['page_settings'] as $ps) {
                $upsert->execute([
                    'uk' => $key,
                    'sk' => (int)$ps['setting_key'],
                    'val' => $ps['enabled'] ? '1' : '0',
                ]);
            }
        }

        jsonResponse(['saved' => true]);
        break;

    case 'POST':
        if (empty($input['user_code'])) errorResponse('user_code required');

        $stmt = $db->prepare("
            INSERT INTO yy_user (user_code, user_name_full, user_email, user_pass)
            VALUES (:code, :name_full, :email, :pass)
            RETURNING user_key
        ");
        $hash = !empty($input['new_password']) ? password_hash($input['new_password'], PASSWORD_DEFAULT) : null;
        $stmt->execute([
            'code' => $input['user_code'],
            'name_full' => $input['user_name_full'] ?? '',
            'email' => $input['user_email'] ?? '',
            'pass' => $hash,
        ]);
        $newKey = $stmt->fetchColumn();

        // Create default page settings for new user
        $settings = $db->query("SELECT setting_key FROM yy_setting WHERE setting_scope_code = 'admin' AND setting_group_code = 'pages'")->fetchAll();
        $ins = $db->prepare("INSERT INTO yy_user_setting (user_key, setting_key, user_setting_value) VALUES (:uk, :sk, '1') ON CONFLICT DO NOTHING");
        foreach ($settings as $s) {
            $ins->execute(['uk' => $newKey, 'sk' => $s['setting_key']]);
        }

        jsonResponse(['saved' => true, 'user_key' => (int)$newKey]);
        break;

    default:
        errorResponse('Method not allowed', 405);
}
