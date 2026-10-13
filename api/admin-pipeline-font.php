<?php
// PDF-pipeline fonts (Admin → Site → Fonts → files popover).
//
// The website's fonts (public/fonts/*) and the fonts the PDF pipeline renders
// with are separate copies: the pipeline uses /usr/local/share/fonts/yada on
// the host (embed_fonts.py + the ONLYOFFICE container). This endpoint queues an
// upload for the host-side installer (parsers/pipeline_font_install.py, run by
// pipeline-font.path), which backs up the old file, installs the new .ttf or
// .ttc, writes alias copies, refreshes ONLYOFFICE and rewrites status.json.
//
//   GET                → status.json (installed files + recent jobs) + queued job ids
//   GET  ?job=ID       → that job's state: queued | installed | error
//   POST file, base    → queue an install; the type (.ttf/.ttc/.otf) is the file's own
require_once __DIR__ . '/config.php';
$user = requireAuth();

$JOB_DIR  = realpath(__DIR__ . '/..') . '/jobs/pipeline-fonts';
$INCOMING = "$JOB_DIR/incoming";
$FILES    = "$JOB_DIR/files";
$STATUS   = "$JOB_DIR/status.json";
$EXTS     = ['ttf', 'ttc', 'otf'];

function pfStatus(string $path): array {
    $d = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    return is_array($d) ? $d : ['fonts' => [], 'jobs' => []];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = pfStatus($STATUS);
    $queued = array_map(fn($p) => basename($p, '.json'), glob("$INCOMING/*.json") ?: []);
    if (isset($_GET['job'])) {
        $id = (string)$_GET['job'];
        if (in_array($id, $queued, true)) jsonResponse(['id' => $id, 'state' => 'queued']);
        foreach ($status['jobs'] ?? [] as $j) {
            if (($j['id'] ?? '') === $id) jsonResponse($j);
        }
        jsonResponse(['id' => $id, 'state' => 'unknown'], 404);
    }
    $status['queued'] = $queued;
    jsonResponse($status);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$status = pfStatus($STATUS);
$base = (string)($_POST['base'] ?? '');
if (!isset($status['fonts'][$base])) {
    errorResponse('Not a PDF-pipeline font: ' . $base . ' (known: ' . implode(', ', array_keys($status['fonts'] ?? [])) . ')');
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['file']['error'] ?? -1;
    errorResponse($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
        ? 'File is larger than the server upload limit (' . ini_get('upload_max_filesize') . ')'
        : 'No file uploaded');
}
$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $EXTS, true)) errorResponse('Pipeline fonts must be .ttf, .ttc or .otf (got .' . $ext . ')');
if (isset($_POST['ext']) && $_POST['ext'] !== $ext) {
    errorResponse("You chose .{$_POST['ext']} but the selected file is .$ext");
}
$magic = (string)file_get_contents($file['tmp_name'], false, null, 0, 4);
$okMagic = $ext === 'ttc' ? $magic === 'ttcf' : in_array($magic, ["\x00\x01\x00\x00", 'OTTO', 'true'], true);
if (!$okMagic) errorResponse(".$ext file is not a valid " . ($ext === 'ttc' ? 'font collection' : 'font') . ' (wrong file header)');

foreach ([$INCOMING, $FILES] as $d) {
    if (!is_dir($d) && !@mkdir($d, 02775, true)) errorResponse('Cannot create ' . basename($d) . ' folder', 500);
}
$id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
$stored = "$id.$ext";
// Font first, job second: the installer only sees a job once its file is complete.
if (!move_uploaded_file($file['tmp_name'], "$FILES/$stored")) errorResponse('Failed to save file', 500);
@chmod("$FILES/$stored", 0664);
$job = [
    'base'      => $base,
    'ext'       => $ext,
    'file'      => $stored,
    'orig_name' => $file['name'],
    'size'      => filesize("$FILES/$stored"),
    'user'      => (int)($user['user_key'] ?? 0),
    'queued'    => date('c'),
];
$tmp = "$INCOMING/.$id.json.tmp";
if (file_put_contents($tmp, json_encode($job)) === false || !rename($tmp, "$INCOMING/$id.json")) {
    @unlink($tmp);
    @unlink("$FILES/$stored");
    errorResponse('Failed to queue the install', 500);
}

logMonitorEvent('admin-pipeline-font', 'info', "Queued pipeline font $base.$ext",
    json_encode(['job' => $id, 'orig_name' => $file['name'], 'size' => $job['size']]), true);

jsonResponse(['id' => $id, 'state' => 'queued', 'base' => $base, 'ext' => $ext], 202);
