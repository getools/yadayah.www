<?php
/**
 * Feed-item MP3 audio + soft-delete. Used by the Feeds tab
 * (admin-feeds.html) for an item's MP3 upload/remove.
 *
 * POST   ?type=audio&key=N  (multipart, field "audio") — attach an MP3
 * DELETE ?type=audio&key=N  — detach the MP3
 * DELETE ?type=video&key=N  — soft-delete the feed item
 *
 * The old Vlog category / video-list endpoints that lived here were retired
 * on 2026-10-11 with yy_feed_page / yy_feed_page_category; categories are now
 * per Items section (yy_category, managed in the page builder).
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? '';
$key = (int)($_GET['key'] ?? 0);

// Upload MP3 for a feed item
if ($type === 'audio' && $method === 'POST' && $key) {
    $file = $_FILES['audio'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('No file uploaded');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'mp3') errorResponse('Only MP3 files are allowed');

    $filename = 'audio_' . $key . '_' . time() . '.mp3';
    $destDir = dirname(__DIR__) . '/public/u/audio';
    if (is_dir('/var/www/html/u/audio')) $destDir = '/var/www/html/u/audio';
    $dest = $destDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) errorResponse('Failed to save file');

    $path = 'u/audio/' . $filename;
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ? WHERE feed_item_key = ?")->execute([$path, $key]);
    jsonResponse(['saved' => true, 'audio_file' => $path]);
}

// Remove MP3 from a feed item
if ($type === 'audio' && $method === 'DELETE' && $key) {
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = NULL WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['removed' => true]);
}

if ($type === 'video' && $method === 'DELETE') {
    if (!$key) errorResponse('Video key required');
    $db->prepare("UPDATE yy_feed_item SET feed_item_active_flag = FALSE, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Invalid request', 400);
