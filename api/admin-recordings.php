<?php
/**
 * Recordings queue — lists active YouTube feed_items that don't yet have a
 * transcript, so admins can batch-process them via in-browser audio capture.
 *
 * GET ?page=N&per_page=K — paginated list of items needing a transcript.
 *
 * "Needs transcript" = the item itself has no rows in yy_feed_item_transcript
 * AND none of its yy_feed_item_link siblings (confirmed or pending) do either.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(99999, (int)($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;

// An item is needs-transcript when no row in yy_feed_item_transcript exists
// for it OR any of its link-cluster siblings (only "denied" links — flag=FALSE
// — are excluded from the cluster).
$needsTranscriptWhere = "
    NOT EXISTS (
        SELECT 1 FROM yy_feed_item_transcript t
         WHERE t.feed_item_key = fi.feed_item_key
            OR t.feed_item_key IN (
                SELECT feed_item_key_b FROM yy_feed_item_link
                  WHERE feed_item_key_a = fi.feed_item_key
                    AND feed_item_link_confirmed_flag IS DISTINCT FROM FALSE
                UNION ALL
                SELECT feed_item_key_a FROM yy_feed_item_link
                  WHERE feed_item_key_b = fi.feed_item_key
                    AND feed_item_link_confirmed_flag IS DISTINCT FROM FALSE
            )
    )
";

$where = "fi.feed_item_active_flag = TRUE
       AND fi.feed_item_type = 'video'
       AND lower(f.feed_site_code) = 'youtube'
       AND fi.feed_item_external_id IS NOT NULL
       AND fi.feed_item_external_id != ''
       AND $needsTranscriptWhere";

$cntStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi JOIN yy_feed f ON f.feed_key = fi.feed_key WHERE $where");
$cntStmt->execute();
$total = (int)$cntStmt->fetchColumn();

$stmt = $db->prepare("
    SELECT fi.feed_item_key,
           fi.feed_item_external_id,
           fi.feed_item_url,
           COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title,
           fi.feed_item_thumbnail,
           fi.feed_item_duration,
           fi.feed_item_duration_seconds,
           fi.feed_item_publish_import_dtime AS published_dtime,
           f.feed_site_code,
           f.feed_name,
           (SELECT job_status FROM yy_feed_item_transcript_job j
              WHERE j.feed_item_key = fi.feed_item_key
              ORDER BY j.job_dtime DESC LIMIT 1) AS last_job_status,
           (SELECT job_dtime FROM yy_feed_item_transcript_job j
              WHERE j.feed_item_key = fi.feed_item_key
              ORDER BY j.job_dtime DESC LIMIT 1) AS last_job_dtime
      FROM yy_feed_item fi
      JOIN yy_feed f ON f.feed_key = fi.feed_key
     WHERE $where
     ORDER BY fi.feed_item_publish_import_dtime DESC NULLS LAST, fi.feed_item_key DESC
     LIMIT ? OFFSET ?
");
$stmt->execute([$perPage, $offset]);
$items = $stmt->fetchAll();

jsonResponse([
    'items' => $items,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => max(1, (int)ceil($total / max(1, $perPage)))
]);
