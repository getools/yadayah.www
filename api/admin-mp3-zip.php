<?php
/**
 * Stream a zip of MP3 audio files for the given feed_item keys.
 *
 *   GET ?keys=11,22,33
 *
 * Skips any key that has no feed_item_audio_file on disk; the zip
 * silently includes only the recoverable subset. Inside-zip filenames
 * are the basenames of the audio files so the user gets a flat folder.
 *
 * Auth: requireAuth() — admin only, same as the sibling MP3 endpoints.
 */
require_once __DIR__ . '/config.php';

requireAuth();

$keysRaw = $_GET['keys'] ?? '';
$keys = array_values(array_filter(array_map('intval', explode(',', $keysRaw)), function ($k) {
    return $k > 0;
}));
if (!$keys) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing or invalid 'keys' parameter.";
    exit;
}

$db = getDb();
$ph = implode(',', array_fill(0, count($keys), '?'));
$stmt = $db->prepare("
    SELECT feed_item_key,
           COALESCE(feed_item_title_override, feed_item_title_import) AS title,
           feed_item_audio_file
      FROM yy_feed_item
     WHERE feed_item_key IN ($ph)
");
$stmt->execute($keys);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Find each row's MP3 on disk. /var/www/html is the bind-mounted public/.
$publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
$files = [];
foreach ($rows as $r) {
    $rel = trim((string)($r['feed_item_audio_file'] ?? ''));
    if ($rel === '') continue;
    if ($rel[0] === '/') $rel = substr($rel, 1);
    $abs = $publicRoot . '/' . $rel;
    if (!is_file($abs)) continue;
    $files[] = ['abs' => $abs, 'name' => basename($rel)];
}
if (!$files) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No MP3 files found on disk for the requested keys.";
    exit;
}

// Build the zip in /tmp, then stream it and delete. Avoids the memory
// hit of holding the whole archive in PHP's buffer.
$tmpZip = tempnam(sys_get_temp_dir(), 'mp3zip_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpZip);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Failed to create zip file.";
    exit;
}
foreach ($files as $f) {
    // If two items share an audio basename (rare), suffix with the key.
    $name = $f['name'];
    if ($zip->locateName($name) !== false) {
        $dot = strrpos($name, '.');
        $name = $dot === false
            ? $name . '-dup'
            : substr($name, 0, $dot) . '-dup' . substr($name, $dot);
    }
    $zip->addFile($f['abs'], $name);
}
$zip->close();

$zipName = 'mp3s-' . date('Y-m-d-His') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-store');
readfile($tmpZip);
@unlink($tmpZip);
