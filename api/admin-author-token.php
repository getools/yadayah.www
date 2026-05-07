<?php
// Returns the bearer token used by the desktop "Yada Yah Book PDF Generator"
// app to authenticate against /api/admin-books-status.php and
// /api/admin-books-upload-pair.php. Auto-provisions one on first access so
// no SQL setup is required.
//
// Auth: requireAuth() — only logged-in admins (who already have access to
// Admin → Books) ever see this token. The token is the credential the
// desktop app then carries; admins paste it once on first launch.

require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();

$stmt = $db->prepare("
    SELECT setting_value
      FROM yy_setting
     WHERE setting_scope_code = 'config'
       AND setting_group_code = 'author-upload'
       AND setting_code       = 'author-upload-token'
     LIMIT 1
");
$stmt->execute();
$token = $stmt->fetchColumn();

if (!$token) {
    $token = bin2hex(random_bytes(24));
    $ins = $db->prepare("
        INSERT INTO yy_setting
          (setting_scope_code, setting_group_code, setting_code,
           setting_value_code, setting_value, setting_label, setting_sort)
        VALUES
          ('config', 'author-upload', 'author-upload-token',
           'text', ?, 'Author desktop uploader bearer token', 0)
    ");
    $ins->execute([$token]);
}

jsonResponse([
    'token'      => $token,
    'server_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'yadayah.com'),
]);
