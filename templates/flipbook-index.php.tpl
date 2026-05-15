<?php
// Per-book flipbook wrapper. All HTML, scripts, and cache-bust versions
// live in /opt/yada-www/public/_shared/flipbook-frame.php. Each book is
// just the four fields below; bug fixes happen once in the shared shell
// and every flipbook picks them up on the next request.
$FB = [
    'total'    => {{TOTAL}},
    'title'    => '{{TITLE_PHP}}',
    'bookCode' => '{{BOOK_CODE}}',
];
require __DIR__ . '/../_shared/flipbook-frame.php';
