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

    // List all files with database status and upload time
    $db = getDb();
    $parsed = [];
    $stmt = $db->query("SELECT DISTINCT transcript_source FROM yy_transcript");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $parsed[$row['transcript_source']] = true;
    }

    // Get upload timestamps
    $uploads = [];
    $stmt = $db->query("SELECT filename, uploaded_dtime FROM yy_transcript_upload");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $uploads[$row['filename']] = $row['uploaded_dtime'];
    }

    $files = [];
    foreach (glob("$TRANSCRIPTS_DIR/*.vtt") as $path) {
        $name = basename($path);
        $files[] = [
            'name' => $name,
            'size' => filesize($path),
            'modified' => date('Y-m-d H:i:s', filemtime($path)),
            'uploaded' => $uploads[$name] ?? null,
            'in_database' => isset($parsed[$name]),
        ];
    }
    usort($files, function($a, $b) { return strcmp($b['name'], $a['name']); });
    jsonResponse(['files' => $files, 'count' => count($files)]);
}

// Dedup action: remove files with identical content
if (isset($_GET['action']) && $_GET['action'] === 'dedup') {
    $hashes = []; // md5 => first filename
    $removed = 0;
    $db = getDb();

    foreach (glob("$TRANSCRIPTS_DIR/*.vtt") as $path) {
        $name = basename($path);
        $hash = md5_file($path);
        if (isset($hashes[$hash])) {
            // Duplicate — remove this one
            unlink($path);
            $db->prepare("DELETE FROM yy_transcript WHERE transcript_source = ?")->execute([$name]);
            $db->prepare("DELETE FROM yy_transcript_upload WHERE filename = ?")->execute([$name]);
            $removed++;
        } else {
            $hashes[$hash] = $name;
        }
    }
    jsonResponse(['removed' => $removed, 'kept' => count($hashes)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process action: parse specific files into database
    if (isset($_GET['action']) && $_GET['action'] === 'process') {
        $input = json_decode(file_get_contents('php://input'), true);
        $filenames = $input['files'] ?? [];
        if (empty($filenames)) errorResponse('No files specified');

        $db = getDb();
        $CHUNK_WORDS = 500;
        $results = [];

        foreach ($filenames as $filename) {
            $filename = basename($filename);
            $filepath = "$TRANSCRIPTS_DIR/$filename";
            if (!file_exists($filepath)) { $results[] = ['file' => $filename, 'error' => 'Not found']; continue; }

            // Check if already in database
            $reprocess = $input['reprocess'] ?? false;
            $check = $db->prepare("SELECT COUNT(*) FROM yy_transcript WHERE transcript_source = ?");
            $check->execute([$filename]);
            if ($check->fetchColumn() > 0) {
                if (!$reprocess) { $results[] = ['file' => $filename, 'skipped' => true, 'reason' => 'Already in database']; continue; }
                // Remove old records before reprocessing
                $del = $db->prepare("DELETE FROM yy_transcript WHERE transcript_source = ?");
                $del->execute([$filename]);
            }

            // Extract yyyyMM prefix and title
            $yearmonth = null;
            $title = $filename;
            if (preg_match('/^(\d{6})-(.+)\.vtt$/i', $filename, $m)) {
                $yearmonth = $m[1];
                $title = str_replace('_', "'", $m[2]);
            } elseif (preg_match('/^(.+)\.vtt$/i', $filename, $m)) {
                $title = str_replace('_', "'", $m[1]);
            }

            // Parse VTT cues
            $content = file_get_contents($filepath);
            $lines = explode("\n", str_replace("\r", "", $content));
            $cues = [];
            $currentTime = null; $currentEndTime = null; $currentText = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^(\d{2}:\d{2}:\d{2}\.\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2}\.\d{3})/', $line, $tm)) {
                    if ($currentTime !== null && count($currentText) > 0) {
                        $cues[] = ['start' => $currentTime, 'end' => $currentEndTime, 'text' => implode(' ', $currentText)];
                    }
                    $currentTime = $tm[1]; $currentEndTime = $tm[2]; $currentText = [];
                } elseif ($line === '' || $line === 'WEBVTT' || preg_match('/^\d+$/', $line)) {
                    continue;
                } elseif ($currentTime !== null) {
                    $currentText[] = strip_tags($line);
                }
            }
            if ($currentTime !== null && count($currentText) > 0) {
                $cues[] = ['start' => $currentTime, 'end' => $currentEndTime, 'text' => implode(' ', $currentText)];
            }
            if (empty($cues)) { $results[] = ['file' => $filename, 'error' => 'No cues found']; continue; }

            // Chunk cues into ~500-word segments
            $chunks = []; $chunkText = []; $chunkWordCount = 0;
            $chunkStart = $cues[0]['start']; $chunkEnd = $cues[0]['end'];
            foreach ($cues as $cue) {
                $chunkText[] = $cue['text'];
                $chunkWordCount += str_word_count($cue['text']);
                $chunkEnd = $cue['end'];
                if ($chunkWordCount >= $CHUNK_WORDS) {
                    $chunks[] = ['start' => $chunkStart, 'end' => $chunkEnd, 'text' => implode(' ', $chunkText)];
                    $chunkText = []; $chunkWordCount = 0; $chunkStart = $chunkEnd;
                }
            }
            if (count($chunkText) > 0) {
                $chunks[] = ['start' => $chunkStart, 'end' => $chunkEnd, 'text' => implode(' ', $chunkText)];
            }

            // Insert chunks
            $stmt = $db->prepare("INSERT INTO yy_transcript (transcript_source, transcript_title, transcript_yearmonth, transcript_chunk_num, transcript_start_time, transcript_end_time, transcript_text, transcript_tsv) VALUES (?, ?, ?, ?, ?, ?, ?, to_tsvector('english', ?))");
            foreach ($chunks as $i => $chunk) {
                $stmt->execute([$filename, $title, $yearmonth, $i + 1, $chunk['start'], $chunk['end'], $chunk['text'], $chunk['text']]);
            }
            $results[] = ['file' => $filename, 'processed' => true, 'cues' => count($cues), 'chunks' => count($chunks)];
        }
        jsonResponse(['results' => $results]);
    }

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
    $overwrite = isset($_GET['overwrite']) && $_GET['overwrite'] === '1';
    if (file_exists($dest) && !$overwrite) {
        jsonResponse(['name' => $name, 'skipped' => true, 'reason' => 'Already exists']);
    }
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        errorResponse('Failed to save file');
    }
    // Record upload timestamp
    $db = getDb();
    $db->prepare("INSERT INTO yy_transcript_upload (filename, file_size, uploaded_dtime) VALUES (?, ?, NOW()) ON CONFLICT (filename) DO UPDATE SET uploaded_dtime = NOW(), file_size = ?")
       ->execute([$name, filesize($dest), filesize($dest)]);
    jsonResponse(['name' => $name, 'saved' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = basename($input['name'] ?? '');
    if (!$name) errorResponse('No filename provided');
    $path = "$TRANSCRIPTS_DIR/$name";
    if (!file_exists($path)) errorResponse('File not found', 404);
    if (!unlink($path)) errorResponse('Failed to delete file');
    // Also remove from database
    $db = getDb();
    $stmt = $db->prepare("DELETE FROM yy_transcript WHERE transcript_source = ?");
    $stmt->execute([$name]);
    $db->prepare("DELETE FROM yy_transcript_upload WHERE filename = ?")->execute([$name]);
    jsonResponse(['deleted' => $name, 'db_removed' => $stmt->rowCount()]);
}

errorResponse('Method not allowed', 405);
