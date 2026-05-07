<?php
// Streams the desktop "Yada Yah Book PDF Generator" .exe to authenticated
// admins. The artifact lives outside the public web root in
// /opt/yada-www/private/uploader/ — it's never reachable directly. GitHub
// Actions builds it on a Windows runner and scp's it into that dir using
// a restricted-command deploy key.

require_once __DIR__ . '/config.php';
requireAuth();

$path = '/opt/yada-www/private/uploader/YadaYahBookPDFGenerator.exe';
if (!is_file($path) || filesize($path) < 100000) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Installer not yet built. Check back in a couple of minutes — the GitHub Actions build runs after each desktop/ change.";
    exit;
}

$mtime = filemtime($path);
$etag  = '"' . md5($path . $mtime . filesize($path)) . '"';

if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag)
 || (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)) {
    http_response_code(304);
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="YadaYahBookPDFGenerator.exe"');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');

while (ob_get_level()) ob_end_clean();
readfile($path);
