<?php
// Cache-busting entry point for site fonts: /api/font.php?f=YadaTowrah-Times.woff2
// 302s to /fonts/<f>?v=<mtime>. /fonts/* is cached for a year (Caddyfile @fonts,
// Cloudflare), so pages reference fonts through here; a font replaced in
// Admin → Site → Fonts gets a new ?v= and reaches everyone once this short-lived
// redirect expires. No DB/session needed — keep it cheap.
$f = $_GET['f'] ?? '';
if (!preg_match('/^[A-Za-z0-9._-]+\.(woff2|woff|ttf|otf)$/i', $f) || strpos($f, '..') !== false) {
    http_response_code(400);
    exit;
}
$path = __DIR__ . '/../fonts/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');
header('Location: /fonts/' . rawurlencode($f) . '?v=' . filemtime($path), true, 302);
