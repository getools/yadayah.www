<?php
require_once __DIR__ . '/config.php';
requireAuth();

$TRANSCRIPTS_DIR = __DIR__ . '/../transcripts';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return single file content
    if (isset($_GET['file'])) {
        $name = basename($_GET['file']);
        $path = "$TRANSCRIPTS_DIR/$name";
        if (!file_exists($path)) errorResponse('File not found', 404);
        $content = file_get_contents($path);
        // Strip VTT timestamps and cue numbers, return plain text
        $lines = explode("\n", str_replace("\r", "", $content));
        $text = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'WEBVTT' || $line === '' || preg_match('/^\d+$/', $line) || preg_match('/^\d{2}:\d{2}:\d{2}/', $line)) continue;
            $text[] = strip_tags($line);
        }
        jsonResponse(['name' => $name, 'text' => implode(' ', $text)]);
    }

    // List all files
    $files = [];
    foreach (glob("$TRANSCRIPTS_DIR/*.vtt") as $path) {
        $name = basename($path);
        $files[] = [
            'name' => $name,
            'size' => filesize($path),
            'modified' => date('Y-m-d H:i:s', filemtime($path)),
        ];
    }
    usort($files, function($a, $b) { return strcmp($b['name'], $a['name']); });
    jsonResponse(['files' => $files, 'count' => count($files)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['file'])) {
        errorResponse('No file uploaded');
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        errorResponse('Upload error: ' . $file['error']);
    }

    $name = basename($file['name']);
    // Only allow .vtt files
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'vtt') {
        errorResponse('Only .vtt files are allowed');
    }

    // Prepend yyyyMM- if not already present
    if (!preg_match('/^\d{6}-/', $name)) {
        $prefix = date('Ym');
        $name = $prefix . '-' . $name;
    }

    $dest = "$TRANSCRIPTS_DIR/$name";
    if (file_exists($dest)) {
        errorResponse('File already exists: ' . $name);
    }
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        errorResponse('Failed to save file');
    }
    jsonResponse(['name' => $name, 'saved' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = basename($input['name'] ?? '');
    if (!$name) errorResponse('No filename provided');
    $path = "$TRANSCRIPTS_DIR/$name";
    if (!file_exists($path)) errorResponse('File not found', 404);
    if (!unlink($path)) errorResponse('Failed to delete file');
    jsonResponse(['deleted' => $name]);
}

errorResponse('Method not allowed', 405);
