<?php
/**
 * Public CSS endpoint — emits @font-face rules for every active font that
 * has a known file under /fonts/. Linked from TinyMCE content_css so the
 * editor iframe can render typed text in the chosen font. Also loadable
 * by any page that wants the registry's @font-face rules dynamically.
 *
 * File naming convention: the primary CSS family of the stack is taken
 * literally as the filename stem under /fonts/. Both woff2 and ttf are
 * tried; whichever exists is emitted.
 */
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit;
}

$db = getDb();
$stmt = $db->query("
    SELECT font_css_stack FROM yy_font
     WHERE font_active_flag = TRUE
     ORDER BY font_display_name
");

// Filename conventions for fonts that live in /fonts/. Adding a font here
// is the only place to register the "this CSS family loads from these
// files" mapping. Anything not in this map is assumed to be a system font
// (Arial, Helvetica, etc.) or supplied elsewhere.
$fileMap = [
    'YadaTowrah-Times' => ['woff2' => '/fonts/YadaTowrah-Times.woff2', 'ttf' => '/fonts/YadaTowrah-Times.ttf'],
    'Semitic Early'    => ['ttf'   => '/fonts/SemiticEarly.ttf'],
    'Moabite Stone'    => ['ttf'   => '/fonts/MoabiteStone.ttf'],
    'Jupiter-Yada'     => ['woff2' => '/fonts/JupiterYada-Regular.woff2', 'ttf' => '/fonts/JupiterYada-Regular.ttf'],
];

$emitted = [];
$out = "/* Auto-generated @font-face rules for /api/site-fonts.css.php. */\n\n";
foreach ($stmt->fetchAll() as $row) {
    $stack = $row['font_css_stack'];
    // Primary family = the first comma-separated entry, with surrounding
    // single/double quotes stripped.
    $parts = explode(',', $stack);
    $primary = trim($parts[0]);
    $primary = preg_replace('/^[\'"]|[\'"]$/', '', $primary);
    if (!isset($fileMap[$primary]) || isset($emitted[$primary])) continue;
    $emitted[$primary] = true;
    $files = $fileMap[$primary];
    $sources = [];
    if (!empty($files['woff2'])) $sources[] = "url(\"{$files['woff2']}\") format(\"woff2\")";
    if (!empty($files['ttf']))   $sources[] = "url(\"{$files['ttf']}\") format(\"truetype\")";
    if (!$sources) continue;
    $out .= "@font-face {\n";
    $out .= "    font-family: \"$primary\";\n";
    $out .= "    src: " . implode(",\n         ", $sources) . ";\n";
    $out .= "    font-display: swap;\n";
    $out .= "}\n\n";
}

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo $out;
