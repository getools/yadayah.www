<?php
/**
 * TEST endpoint — public renderer for a multi-section page.
 *
 *   GET ?code=foo
 *   GET ?key=N
 *
 * Returns:
 *   { page: {...}, sections: [
 *       {type, title, config, items?:[...]}, ...   // items present only for type=items
 *   ]}
 *
 * No auth required (public read), but only active pages and sections are returned.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../feed-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);

$db = getDb();

// ── Load-More paginated request ────────────────────────────────────────
// When the public renderer's Load More button is clicked, it calls this
// endpoint with ?section_key=N&offset=M&limit=K to fetch the *next* batch
// of items for one section. Response is just {items:[...]}; the caller
// appends them to the existing grid. No auth required (same as the
// non-paginated path — only active sections are returned).
$sectionKey = isset($_GET['section_key']) ? (int)$_GET['section_key'] : 0;
$paginatedLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
if ($sectionKey > 0 && $paginatedLimit > 0) {
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    if ($paginatedLimit > 200) $paginatedLimit = 200;
    $st = $db->prepare("SELECT page_section_test_config FROM yy_page_section_test WHERE page_section_test_key = ? AND page_section_test_type = 'items' AND page_section_test_active_flag = TRUE");
    $st->execute([$sectionKey]);
    $cfgRow = $st->fetchColumn();
    if ($cfgRow === false) errorResponse('Section not found', 404);
    $cfg = is_string($cfgRow) ? (json_decode($cfgRow, true) ?: []) : ($cfgRow ?: []);
    $cfg['max_count'] = $paginatedLimit;
    $cfg['_offset']   = $offset;
    jsonResponse(['items' => resolveItemsSection($db, $cfg)]);
}

if (!empty($_GET['key'])) {
    $stmt = $db->prepare("SELECT * FROM yy_page_test WHERE page_test_key = ? AND page_test_active_flag = TRUE");
    $stmt->execute([(int)$_GET['key']]);
} elseif (!empty($_GET['code'])) {
    $stmt = $db->prepare("SELECT * FROM yy_page_test WHERE page_test_code = ? AND page_test_active_flag = TRUE");
    $stmt->execute([trim($_GET['code'])]);
} else {
    errorResponse('Missing code or key');
}
$page = $stmt->fetch();
if (!$page) errorResponse('Page not found', 404);

$sec = $db->prepare("SELECT * FROM yy_page_section_test WHERE page_test_key = ? AND page_section_test_active_flag = TRUE ORDER BY page_section_test_sort, page_section_test_key");
$sec->execute([$page['page_test_key']]);

$out = [];
foreach ($sec->fetchAll() as $s) {
    $cfg = $s['page_section_test_config'];
    $cfg = is_string($cfg) ? (json_decode($cfg, true) ?: []) : ($cfg ?: []);
    $section = [
        'key'        => (int)$s['page_section_test_key'],
        'parent_key' => $s['page_section_test_parent_key'] !== null ? (int)$s['page_section_test_parent_key'] : null,
        'sort'       => (int)$s['page_section_test_sort'],
        'type'       => $s['page_section_test_type'],
        'title'      => $s['page_section_test_title'],
        'config'     => $cfg,
    ];
    if ($section['type'] === 'items') {
        $section['items'] = resolveItemsSection($db, $cfg);
    }
    $out[] = $section;
}

jsonResponse(['page' => $page, 'sections' => $out]);


/**
 * Translate an Items-section config object into a list of feed_item rows.
 *
 * Config shape (all fields optional unless noted):
 *   feed_keys:        [int]            — restrict to these feeds
 *   feed_item_keys:   [int]            — explicit pinned items (bypasses other filters when present)
 *   age_min_h: int total hours — items must be at least this old
 *   age_max_h: int total hours — items must be at most this old
 *   include_hashtags: 'tag1,tag2'      — passed through buildFeedPageFilters
 *   exclude_hashtags: 'tag1,tag2'
 *   duration_min_sec: int
 *   duration_max_sec: int
 *   content_type:     'video'|'image'|'audio'|...
 *   page_key:         int              — items linked to this page via yy_feed_item_page
 *   category_key:     int              — items in this yy_feed_page_category
 *   title_include:    'foo,bar'        — wildcard convention
 *   title_exclude:    'baz'
 *   sort:             'posted'|'title'|'orientation'|'page'|'category'|'random'
 *   sort_dir:         'asc'|'desc'    — ignored when sort='random'
 *   max_count:        int (default 24, capped at 200)
 */
function resolveItemsSection(PDO $db, array $cfg): array {
    $where  = "i.feed_item_active_flag = TRUE";
    $params = [];
    $joins  = "";

    if (!empty($cfg['feed_item_keys']) && is_array($cfg['feed_item_keys'])) {
        $ids = array_values(array_filter(array_map('intval', $cfg['feed_item_keys'])));
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND i.feed_item_key IN ($place)";
            array_push($params, ...$ids);
        }
    }
    // All non-pinned filters (feeds, age, duration, content_type,
    // orientation, pages/categories, hashtags, title include/exclude) are
    // built by the shared helper in feed-helpers.php — the SAME helper the
    // admin "Selected Titles" typeahead uses, so the pinned-item search
    // results always match what this section would render.
    appendItemsSectionFilters($cfg, $where, $params);

    // Build multi-field ORDER BY. Accept either:
    //   cfg.sorts: [{field, dir}, ...]   (preferred)
    //   cfg.sort + cfg.sort_dir          (legacy single-field)
    $sorts = [];
    if (!empty($cfg['sorts']) && is_array($cfg['sorts'])) {
        foreach ($cfg['sorts'] as $entry) {
            if (!empty($entry['field'])) {
                $sorts[] = ['field' => $entry['field'], 'dir' => $entry['dir'] ?? 'desc'];
            }
        }
    } elseif (!empty($cfg['sort'])) {
        $sorts[] = ['field' => $cfg['sort'], 'dir' => $cfg['sort_dir'] ?? 'desc'];
    }
    if (!$sorts) $sorts[] = ['field' => 'posted', 'dir' => 'desc'];

    $orderParts = [];
    foreach ($sorts as $srt) {
        $dir = strtolower($srt['dir']) === 'asc' ? 'ASC' : 'DESC';
        switch ($srt['field']) {
            case 'title':
                $orderParts[] = "COALESCE(i.feed_item_title_override, i.feed_item_title_import) $dir";
                break;
            case 'orientation':
                $orderParts[] = "i.feed_item_orientation $dir NULLS LAST";
                break;
            case 'page':
                $orderParts[] = "(SELECT MIN(page_key) FROM yy_feed_item_page WHERE feed_item_key = i.feed_item_key) $dir NULLS LAST";
                break;
            case 'category':
                $orderParts[] = "(SELECT MIN(category_key) FROM yy_feed_item_category WHERE feed_item_key = i.feed_item_key) $dir NULLS LAST";
                break;
            case 'duration':
                $orderParts[] = "i.feed_item_duration_seconds $dir NULLS LAST";
                break;
            case 'random':
                $orderParts[] = "RANDOM()";
                break;
            case 'posted':
            default:
                $orderParts[] = "COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) $dir";
                break;
        }
    }
    $order = implode(', ', $orderParts);

    $maxCount = (int)($cfg['max_count'] ?? 24);
    if ($maxCount < 1) $maxCount = 24;
    if ($maxCount > 200) $maxCount = 200;
    // _offset is set by the Load More paginated path above to fetch the
    // next batch. Not exposed as a UI field — it's a transient runtime hint.
    $offsetVal = max(0, (int)($cfg['_offset'] ?? 0));

    $sql = "SELECT i.feed_item_key, i.feed_key, i.feed_item_external_id, i.feed_item_embed_id,
                   COALESCE(i.feed_item_title_override, i.feed_item_title_import) AS feed_item_title,
                   i.feed_item_url, i.feed_item_thumbnail, i.feed_item_duration, i.feed_item_duration_seconds,
                   i.feed_item_orientation, i.feed_item_type, i.feed_item_tags,
                   COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) AS feed_item_posted_dtime
            FROM yy_feed_item i
            $joins
            WHERE $where
            ORDER BY $order
            LIMIT " . (int)$maxCount . " OFFSET " . (int)$offsetVal;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Normalize relative thumbnail paths (e.g. "u/blog/…") to absolute so
    // they render from the web root, not relative to /test/page.php.
    foreach ($rows as &$r) {
        if (isset($r['feed_item_thumbnail'])) $r['feed_item_thumbnail'] = normalizeMediaUrl($r['feed_item_thumbnail']);
    }
    unset($r);
    return $rows;
}
