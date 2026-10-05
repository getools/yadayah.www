<?php
// Upload a file to an arbitrary web path (e.g. /fonts/YadaTowrah-Times.ttf)
// from the Resources popover. Replacing an existing file needs overwrite=1;
// the old copy is kept under u/resources/_replaced/.
require_once __DIR__ . '/config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$WEB_ROOT = realpath(__DIR__ . '/..');
// Static, non-executable types only — never php/html/js/css/htaccess.
$ALLOWED = ['ttf', 'otf', 'woff', 'woff2', 'pdf', 'epub', 'mobi', 'doc', 'docx', 'xls', 'xlsx',
            'ppt', 'pptx', 'odt', 'rtf', 'txt', 'csv', 'zip', 'jpg', 'jpeg', 'png', 'gif', 'webp',
            'mp3', 'm4a', 'wav', 'ogg', 'mp4', 'webm', 'mov'];
// Top-level folders that hold code or app data, not downloadable files.
$BLOCKED_TOP = ['api', 'js', 'css', 'vendor', 'node_modules', 'jobs', 'cgi-bin'];

$path = trim($_POST['path'] ?? '');
$path = '/' . ltrim(str_replace('\\', '/', $path), '/');
$path = preg_replace('#/+#', '/', $path);
$segs = array_values(array_filter(explode('/', $path), 'strlen'));
if (!$segs) errorResponse('Target path is required, e.g. /fonts/YadaTowrah-Times.ttf');
foreach ($segs as $s) {
    if ($s[0] === '.' || !preg_match('/^[A-Za-z0-9._ ()+-]+$/', $s)) {
        errorResponse("Invalid path segment: $s");
    }
}
if (in_array(strtolower($segs[0]), $BLOCKED_TOP, true)) {
    errorResponse("Uploads into /{$segs[0]}/ are not allowed");
}
$name = end($segs);
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, $ALLOWED, true)) {
    errorResponse('File type not allowed. Allowed: ' . implode(', ', $ALLOWED));
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['file']['error'] ?? -1;
    errorResponse($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
        ? 'File is larger than the server upload limit (' . ini_get('upload_max_filesize') . ')'
        : 'No file uploaded');
}
$file = $_FILES['file'];
$srcExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($srcExt !== $ext) {
    errorResponse("The selected file is .$srcExt but the target path is .$ext");
}

$relDir = implode('/', array_slice($segs, 0, -1));
$dir = $WEB_ROOT . ($relDir !== '' ? "/$relDir" : '');
$dest = "$dir/$name";
$urlPath = '/' . implode('/', $segs);

if (!is_dir($dir)) {
    // Only create missing folders inside an existing writable one.
    if (!@mkdir($dir, 02775, true)) errorResponse("Cannot create folder /$relDir (not writable by the web server)", 500);
}
$realDir = realpath($dir);
if ($realDir === false || strpos($realDir . '/', $WEB_ROOT . '/') !== 0) errorResponse('Invalid target folder');
if (!is_writable($realDir)) errorResponse("Folder /$relDir is not writable by the web server", 500);

$exists = is_file($dest);
if ($exists && empty($_POST['overwrite'])) {
    jsonResponse([
        'exists' => true,
        'path'   => $urlPath,
        'size'   => filesize($dest),
        'mtime'  => date('c', filemtime($dest)),
    ], 409);
}

// Write to a temp name in the same folder, then rename: readers never see a half file.
$tmp = "$realDir/.upload-" . bin2hex(random_bytes(6)) . ".$ext";
if (!move_uploaded_file($file['tmp_name'], $tmp)) errorResponse('Failed to save file', 500);
@chmod($tmp, 0664);

$backup = null;
if ($exists) {
    $bakDir = $WEB_ROOT . '/u/resources/_replaced';
    if (!is_dir($bakDir)) @mkdir($bakDir, 02775, true);
    $bakName = str_replace('/', '__', ltrim($urlPath, '/')) . '.' . date('Ymd-His');
    if (@copy($dest, "$bakDir/$bakName")) $backup = "/u/resources/_replaced/$bakName";
}
if (!@rename($tmp, $dest)) {
    @unlink($tmp);
    errorResponse('Failed to move file into place (is the existing file writable?)', 500);
}
clearstatcache(true, $dest);

// A replaced .ttf/.otf does nothing visible if a same-name .woff2/.woff exists:
// css/app.css lists the woff2 first, so browsers never fetch the ttf.
$warnings = [];
if (in_array($ext, ['ttf', 'otf'], true)) {
    $base = pathinfo($name, PATHINFO_FILENAME);
    foreach (['woff2', 'woff'] as $sib) {
        if (is_file("$realDir/$base.$sib") && filemtime("$realDir/$base.$sib") < filemtime($dest)) {
            $warnings[] = "/" . ($relDir !== '' ? "$relDir/" : '') . "$base.$sib is older than this file and browsers load it first. Upload a matching .$sib too (Admin → Site → Fonts), or the new font will not show.";
        }
    }
}

logMonitorEvent('admin-resource-file', 'info', ($exists ? 'Replaced ' : 'Uploaded ') . $urlPath,
    json_encode(['size' => filesize($dest), 'backup' => $backup, 'orig_name' => $file['name']]), true);

jsonResponse([
    'path'     => $urlPath,
    'size'     => filesize($dest),
    'replaced' => $exists,
    'backup'   => $backup,
    'warnings' => $warnings,
]);
