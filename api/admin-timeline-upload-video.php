<?php
require_once __DIR__ . '/config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

if (empty($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No video uploaded', 400);
}

$UPLOAD_DIR = __DIR__ . '/../u/timeline';
$file = $_FILES['video_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['mp4', 'webm', 'ogg', 'mov'];
if (!in_array($ext, $allowed)) {
    errorResponse('Video must be: ' . implode(', ', $allowed));
}

if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);
$safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
$dest = "$UPLOAD_DIR/$safeName";
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    errorResponse('Failed to save video');
}

$videoPath = "u/timeline/$safeName";
$origSize = filesize($dest);
$converted = false;
$cropped = false;
$cropFilter = '';

// Auto-detect and crop black bars if requested
if (!empty($_POST['crop'])) {
    set_time_limit(300);
    // Detect crop dimensions by sampling frames at 10%, 30%, 50% of duration
    $detectCmd = "ffmpeg -i " . escapeshellarg($dest) . " -vf cropdetect=24:16:0 -f null - 2>&1";
    exec($detectCmd, $detectOutput, $detectCode);
    if ($detectCode === 0) {
        // Parse last cropdetect line for final crop values
        $cropVal = null;
        foreach (array_reverse($detectOutput) as $line) {
            if (preg_match('/crop=(\d+:\d+:\d+:\d+)/', $line, $m)) {
                $cropVal = $m[1];
                break;
            }
        }
        if ($cropVal) {
            // Only crop if it actually changes dimensions
            $parts = explode(':', $cropVal);
            $probeCmd = "ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 " . escapeshellarg($dest) . " 2>&1";
            exec($probeCmd, $probeOutput);
            $dims = isset($probeOutput[0]) ? trim($probeOutput[0]) : '';
            if ($dims) {
                list($origW, $origH) = explode(',', $dims);
                if ((int)$parts[0] < (int)$origW || (int)$parts[1] < (int)$origH) {
                    $cropFilter = "crop=$cropVal,";
                    $cropped = true;
                }
            }
        }
    }
}

// Convert to webm using fast VP8 if it reduces file size (or just crop if already webm)
if ($ext !== 'webm' || $cropped) {
    set_time_limit(300);
    $webmName = pathinfo($safeName, PATHINFO_FILENAME) . '.webm';
    $webmPath = "$UPLOAD_DIR/$webmName";
    $vf = $cropFilter ? "-vf " . escapeshellarg(rtrim($cropFilter, ',')) . " " : "";
    $convertCmd = "ffmpeg -i " . escapeshellarg($dest)
        . " " . $vf . "-c:v libvpx -quality good -cpu-used 5 -crf 10 -b:v 1M -c:a libvorbis "
        . escapeshellarg($webmPath) . " -y 2>&1";
    exec($convertCmd, $convertOutput, $convertCode);
    if ($convertCode === 0 && file_exists($webmPath)) {
        $webmSize = filesize($webmPath);
        if ($webmSize < $origSize || $cropped) {
            unlink($dest);
            $safeName = $webmName;
            $dest = "$UPLOAD_DIR/$webmName";
            $videoPath = "u/timeline/$webmName";
            $converted = true;
        } else {
            unlink($webmPath);
        }
    }
}

// Extract first frame as thumbnail
$thumbPath = null;
$thumbName = pathinfo($safeName, PATHINFO_FILENAME) . '_thumb.jpg';
$thumbDest = "$UPLOAD_DIR/$thumbName";
$thumbCmd = "ffmpeg -i " . escapeshellarg($dest) . " -vframes 1 -q:v 2 " . escapeshellarg($thumbDest) . " -y 2>&1";
exec($thumbCmd, $thumbOutput, $thumbCode);
if ($thumbCode === 0 && file_exists($thumbDest)) {
    $thumbPath = "u/timeline/$thumbName";
}

$finalSize = filesize($dest);

jsonResponse([
    'saved' => true,
    'video_path' => $videoPath,
    'thumb_path' => $thumbPath,
    'original_size' => $origSize,
    'final_size' => $finalSize,
    'converted' => $converted,
    'cropped' => $cropped,
]);
