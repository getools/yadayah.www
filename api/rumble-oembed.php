<?php
/**
 * Proxy for Rumble oEmbed API.
 * Returns the embed URL for a given Rumble video page URL.
 * Caches results indefinitely (embed IDs don't change).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$videoUrl = $_GET['url'] ?? '';
if (!$videoUrl || !preg_match('#^https://rumble\.com/v[a-z0-9]+-#', $videoUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Rumble video URL']);
    exit;
}

// Cache by URL hash
$cacheDir = sys_get_temp_dir() . '/rumble_oembed';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
$cacheFile = $cacheDir . '/' . md5($videoUrl) . '.json';

if (file_exists($cacheFile)) {
    echo file_get_contents($cacheFile);
    exit;
}

$oembedUrl = 'https://rumble.com/api/Media/oembed.json?url=' . urlencode($videoUrl);
$response = @file_get_contents($oembedUrl);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to fetch oEmbed data']);
    exit;
}

$data = json_decode($response, true);

// Extract embed URL from the iframe HTML
$embedUrl = '';
if (!empty($data['html']) && preg_match('#src="(https://rumble\.com/embed/[^"]+)"#', $data['html'], $m)) {
    $embedUrl = $m[1];
}

$result = json_encode(['embed_url' => $embedUrl], JSON_UNESCAPED_SLASHES);
file_put_contents($cacheFile, $result);

echo $result;
