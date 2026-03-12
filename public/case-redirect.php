<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$query = parse_url($uri, PHP_URL_QUERY);

$lower = strtolower($path);

// If already lowercase, serve 404
if ($lower === $path) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Special case: Craig_Winn keeps its casing
if (strtolower($path) === '/craig_winn') {
    $lower = '/Craig_Winn';
}

// Check if the lowercase .html file exists
$docRoot = $_SERVER['DOCUMENT_ROOT'];
if (file_exists($docRoot . $lower) || file_exists($docRoot . $lower . '.html')) {
    $redirect = $lower;
    if ($query) {
        $redirect .= '?' . $query;
    }
    header('Location: ' . $redirect, true, 301);
    exit;
}

// No matching file found
http_response_code(404);
echo 'Not found';
