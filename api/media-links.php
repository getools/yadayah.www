<?php
/**
 * Public API for Media links (social media accounts).
 *
 * GET — returns all active media links with icon SVG
 */
require_once __DIR__ . '/config.php';

$db = getDb();

$stmt = $db->query("
    SELECT m.media_key, m.media_code, m.media_link, m.media_sort,
           i.icon_name, i.icon_svg
    FROM yy_media m
    JOIN yy_icon i ON i.icon_key = m.icon_key
    WHERE m.media_active_flag = TRUE AND i.icon_active_flag = TRUE
    ORDER BY m.media_sort
");

jsonResponse($stmt->fetchAll());
