<?php
// yy_resource.resource_downloads helpers — a resource's download files, e.g. a
// font offered as .ttc, .ttf and/or .woff2. Stored as [{"ext":"ttc","path":"/fonts/X.ttc"}, ...]
// in display order. Used by admin-resources.php (edit) and resources.php (public).

const RESOURCE_DOWNLOAD_TYPES = [
    'ttc'   => 'TTC',
    'ttf'   => 'TTF',
    'woff2' => 'WOFF2',
    'otf'   => 'OTF',
    'woff'  => 'WOFF',
];

// Validate admin input (JSON string or array) into the stored shape.
function resourceDownloadsNormalize($input): array {
    if (is_string($input)) $input = $input === '' ? [] : json_decode($input, true);
    if (!is_array($input)) errorResponse('resource_downloads must be a JSON list');
    $out = [];
    $seen = [];
    foreach ($input as $d) {
        $ext  = strtolower(trim((string)($d['ext'] ?? '')));
        $path = '/' . ltrim(trim((string)($d['path'] ?? '')), '/');
        if (!isset(RESOURCE_DOWNLOAD_TYPES[$ext])) errorResponse("Download type .$ext is not supported");
        if (isset($seen[$ext])) errorResponse("Download type .$ext is listed twice");
        if (strpos($path, '..') !== false || !preg_match('#^/[A-Za-z0-9._/ ()+-]+$#', $path)) {
            errorResponse("Invalid download path: $path");
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== $ext) {
            errorResponse("Download path $path is not a .$ext file");
        }
        $seen[$ext] = true;
        $out[] = ['ext' => $ext, 'path' => $path];
    }
    return $out;
}

// Add label, size, mtime and a public url to each entry. /fonts/* files go
// through /api/font.php (cache-busted redirect; /fonts is cached for a year).
// $publicOnly drops entries whose file is missing.
function resourceDownloadsEnrich($stored, bool $publicOnly): array {
    $list = is_string($stored) ? json_decode($stored, true) : $stored;
    if (!is_array($list)) return [];
    $root = realpath(__DIR__ . '/..');
    $out = [];
    foreach ($list as $d) {
        $ext  = $d['ext'] ?? '';
        $path = $d['path'] ?? '';
        $file = $root . $path;
        $exists = $path !== '' && strpos($path, '..') === false && is_file($file);
        if ($publicOnly && !$exists) continue;
        $name = basename($path);
        $url = preg_match('#^/fonts/([A-Za-z0-9._-]+)$#', $path, $m)
            ? '/api/font.php?f=' . rawurlencode($m[1])
            : $path . ($exists ? '?v=' . filemtime($file) : '');
        $out[] = [
            'ext'    => $ext,
            'label'  => RESOURCE_DOWNLOAD_TYPES[$ext] ?? strtoupper($ext),
            'path'   => $path,
            'name'   => $name,
            'url'    => $url,
            'exists' => $exists,
            'size'   => $exists ? filesize($file) : null,
            'mtime'  => $exists ? date('c', filemtime($file)) : null,
        ];
    }
    return $out;
}
