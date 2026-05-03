<?php
/**
 * Recordings queue endpoint with per-column filtering.
 *
 * GET ?page&per_page&sort&dir
 *     &key=          — substring match on feed_item_key (text)
 *     &title=        — substring match on title
 *     &pub_from=     — published date >= YYYY-MM-DD
 *     &pub_to=       — published date <= YYYY-MM-DD
 *     &feed_key=N    — exact feed match
 *     &dur_min_s=N   — duration_seconds >= N
 *     &dur_max_s=N   — duration_seconds <= N
 *     &status=...    — comma-separated subset of: complete, partial, new
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(99999, (int)($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;

$keyQ        = trim((string)($_GET['key'] ?? ''));
$titleQ      = trim((string)($_GET['title'] ?? ''));
$pubFrom     = trim((string)($_GET['pub_from'] ?? ''));
$pubTo       = trim((string)($_GET['pub_to'] ?? ''));
$feedKey     = (int)($_GET['feed_key'] ?? 0);
$durMin      = isset($_GET['dur_min_s']) && $_GET['dur_min_s'] !== '' ? (int)$_GET['dur_min_s'] : null;
$durMax      = isset($_GET['dur_max_s']) && $_GET['dur_max_s'] !== '' ? (int)$_GET['dur_max_s'] : null;
$statusRaw   = trim((string)($_GET['status'] ?? ''));
$statuses    = $statusRaw === '' ? [] : array_filter(array_map('trim', explode(',', $statusRaw)));
// page_keys filter: comma-separated list of yy_page.page_key. Items shown
// must be associated with at least one of these pages (via yy_feed_item_page).
$pageKeysRaw = trim((string)($_GET['page_keys'] ?? ''));
$pageKeys    = $pageKeysRaw === '' ? [] :
    array_values(array_filter(array_map('intval',
        array_map('trim', explode(',', $pageKeysRaw))), function($n){ return $n > 0; }));

$sortMap = [
    'title'      => 'COALESCE(fi.feed_item_title_override, fi.feed_item_title_import)',
    'published'  => 'COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime)',
    'duration'   => 'fi.feed_item_duration_seconds',
    'feed'       => 'f.feed_name',
    'key'        => 'fi.feed_item_key',
    'processed'  => "CASE
                       WHEN fi.feed_item_audio_file IS NOT NULL THEN 2
                       WHEN fi.feed_item_audio_resume_seconds > 0 THEN 1
                       ELSE 0 END",
];
$sort = $_GET['sort'] ?? 'published';
$sortCol = $sortMap[$sort] ?? $sortMap['published'];
$dir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$where = "fi.feed_item_active_flag = TRUE
       AND fi.feed_item_type = 'video'
       AND lower(f.feed_site_code) = 'youtube'
       AND fi.feed_item_external_id IS NOT NULL
       AND fi.feed_item_external_id != ''";
$params = [];

if ($keyQ !== '')   { $where .= " AND CAST(fi.feed_item_key AS TEXT) LIKE ?"; $params[] = $keyQ . '%'; }
if ($titleQ !== '') { $where .= " AND COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) ILIKE ?"; $params[] = '%' . $titleQ . '%'; }
if ($pubFrom !== '') { $where .= " AND COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) >= ?"; $params[] = $pubFrom; }
if ($pubTo !== '')   { $where .= " AND COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) < (?::date + INTERVAL '1 day')"; $params[] = $pubTo; }
if ($feedKey > 0)    { $where .= " AND fi.feed_key = ?"; $params[] = $feedKey; }
if ($durMin !== null && $durMin >= 0) { $where .= " AND fi.feed_item_duration_seconds >= ?"; $params[] = $durMin; }
if ($durMax !== null && $durMax > 0)  { $where .= " AND fi.feed_item_duration_seconds <= ?"; $params[] = $durMax; }
if (!empty($pageKeys)) {
    $placeholders = implode(',', array_fill(0, count($pageKeys), '?'));
    $where .= " AND EXISTS (SELECT 1 FROM yy_feed_item_page fip
                              WHERE fip.feed_item_key = fi.feed_item_key
                                AND fip.page_key IN ($placeholders))";
    foreach ($pageKeys as $pk) $params[] = $pk;
}

// Status filter: multi-checkbox of Complete / Partial / New
//   complete = feed_item_audio_file IS NOT NULL (or any cluster sibling has audio)
//   partial  = audio_file IS NULL AND resume_seconds > 0
//   new      = audio_file IS NULL AND (resume_seconds IS NULL OR resume_seconds = 0)
$clusterHasAudioSql = "
    EXISTS (
        SELECT 1 FROM yy_feed_item sib
         WHERE sib.feed_item_audio_file IS NOT NULL
           AND sib.feed_item_key IN (
                SELECT feed_item_key_b FROM yy_feed_item_link
                  WHERE feed_item_key_a = fi.feed_item_key
                    AND feed_item_link_confirmed_flag IS DISTINCT FROM FALSE
                UNION ALL
                SELECT feed_item_key_a FROM yy_feed_item_link
                  WHERE feed_item_key_b = fi.feed_item_key
                    AND feed_item_link_confirmed_flag IS DISTINCT FROM FALSE
           )
    )";
if (!empty($statuses)) {
    $statusOrs = [];
    foreach ($statuses as $s) {
        if ($s === 'complete') {
            $statusOrs[] = "(fi.feed_item_audio_file IS NOT NULL OR $clusterHasAudioSql)";
        } elseif ($s === 'partial') {
            $statusOrs[] = "(fi.feed_item_audio_file IS NULL AND COALESCE(fi.feed_item_audio_resume_seconds, 0) > 0)";
        } elseif ($s === 'new') {
            $statusOrs[] = "(fi.feed_item_audio_file IS NULL AND COALESCE(fi.feed_item_audio_resume_seconds, 0) = 0 AND NOT $clusterHasAudioSql)";
        }
    }
    if ($statusOrs) $where .= " AND (" . implode(' OR ', $statusOrs) . ")";
}

$cntStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi JOIN yy_feed f ON f.feed_key = fi.feed_key WHERE $where");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$orderBy = "$sortCol $dir NULLS LAST, fi.feed_item_key DESC";

$stmt = $db->prepare("
    SELECT fi.feed_item_key,
           fi.feed_item_external_id,
           fi.feed_item_url,
           COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title,
           fi.feed_item_thumbnail,
           fi.feed_item_duration,
           fi.feed_item_duration_seconds,
           COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS published_dtime,
           fi.feed_item_audio_file,
           fi.feed_item_audio_resume_seconds AS resume_seconds,
           f.feed_site_code,
           f.feed_key,
           f.feed_name,
           CASE
             WHEN fi.feed_item_audio_file IS NOT NULL THEN 'complete'
             WHEN COALESCE(fi.feed_item_audio_resume_seconds, 0) > 0 THEN 'partial'
             WHEN $clusterHasAudioSql THEN 'complete'
             ELSE 'new'
           END AS processed_status
      FROM yy_feed_item fi
      JOIN yy_feed f ON f.feed_key = fi.feed_key
     WHERE $where
     ORDER BY $orderBy
     LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$items = $stmt->fetchAll();

// Attach the list of pages each item is associated with (page_key, page_code,
// page_title) so the UI can render small badges in the new "Pages" column.
if ($items) {
    $itemKeys = array_map(fn($r) => (int)$r['feed_item_key'], $items);
    $ph = implode(',', array_fill(0, count($itemKeys), '?'));
    $pgStmt = $db->prepare("
        SELECT fip.feed_item_key, p.page_key, p.page_code, p.page_title
          FROM yy_feed_item_page fip
          JOIN yy_page p ON p.page_key = fip.page_key
         WHERE fip.feed_item_key IN ($ph)
         ORDER BY p.page_header_sort, p.page_key");
    $pgStmt->execute($itemKeys);
    $pagesByItem = [];
    foreach ($pgStmt->fetchAll() as $r) {
        $pagesByItem[(int)$r['feed_item_key']][] = [
            'page_key'   => (int)$r['page_key'],
            'page_code'  => $r['page_code'],
            'page_title' => $r['page_title'],
        ];
    }
    foreach ($items as &$it) {
        $it['pages'] = $pagesByItem[(int)$it['feed_item_key']] ?? [];
    }
    unset($it);
}

// Pages that actually have at least one feed_item associated with them —
// used to render the checkbox filter under the Pages column header. Filtering
// the active-pages list by EXISTS in yy_feed_item_page avoids cluttering
// the UI with pages that would never narrow the result set.
$allPagesStmt = $db->query("
    SELECT p.page_key, p.page_code, p.page_title
      FROM yy_page p
     WHERE p.page_active_flag IS DISTINCT FROM FALSE
       AND p.page_code IN ('vlog','invite','doyouyada','basics','shorts','music')
       AND EXISTS (SELECT 1 FROM yy_feed_item_page fip WHERE fip.page_key = p.page_key)
     ORDER BY p.page_header_sort, p.page_key");
$allPages = $allPagesStmt->fetchAll();

$feedsStmt = $db->query("
    SELECT DISTINCT f.feed_key, f.feed_name
      FROM yy_feed f
      JOIN yy_feed_item fi ON fi.feed_key = f.feed_key
     WHERE fi.feed_item_active_flag = TRUE AND fi.feed_item_type = 'video'
     ORDER BY f.feed_name
");

jsonResponse([
    'items' => $items,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => max(1, (int)ceil($total / max(1, $perPage))),
    'sort' => $sort, 'dir' => strtolower($dir),
    'feeds' => $feedsStmt->fetchAll(),
    'pages' => $allPages,
    'filters' => [
        'key' => $keyQ, 'title' => $titleQ,
        'pub_from' => $pubFrom, 'pub_to' => $pubTo,
        'feed_key' => $feedKey,
        'dur_min_s' => $durMin, 'dur_max_s' => $durMax,
        'status' => $statuses,
        'page_keys' => $pageKeys,
    ],
]);
