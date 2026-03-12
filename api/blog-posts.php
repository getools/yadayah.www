<?php
/**
 * Public API for Blog posts (Facebook page posts).
 *
 * GET ?page=N — paginated list (12 per page)
 */
require_once __DIR__ . '/config.php';

$PER_PAGE = 24;

$db = getDb();

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;

$countStmt = $db->query("SELECT COUNT(*) FROM yy_blog WHERE blog_active_flag = TRUE");
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT blog_key, blog_post_id, blog_message,
           CASE WHEN blog_image IS NOT NULL THEN '/' || blog_image ELSE NULL END AS blog_image,
           blog_type_code, blog_create_dtime, blog_video_id
    FROM yy_blog
    WHERE blog_active_flag = TRUE
    ORDER BY blog_create_dtime DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$PER_PAGE, $offset]);

jsonResponse([
    'posts'       => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
