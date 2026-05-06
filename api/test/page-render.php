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
        'key'    => (int)$s['page_section_test_key'],
        'type'   => $s['page_section_test_type'],
        'title'  => $s['page_section_test_title'],
        'config' => $cfg,
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
 *   age_min_days, age_min_hours: int — items at least this old
 *   age_max_days, age_max_hours: int — items at most this old
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
    if (!empty($cfg['feed_keys']) && is_array($cfg['feed_keys'])) {
        $ids = array_values(array_filter(array_map('intval', $cfg['feed_keys'])));
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND i.feed_key IN ($place)";
            array_push($params, ...$ids);
        }
    }
    // Age Min — item must be at least this old (posted_dtime <= now - age_min)
    $ageMinH = (int)($cfg['age_min_days'] ?? 0) * 24 + (int)($cfg['age_min_hours'] ?? 0);
    if ($ageMinH > 0) {
        $where .= " AND COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) <= NOW() - (? || ' hours')::interval";
        $params[] = (string)$ageMinH;
    }
    // Age Max — item must be at most this old (posted_dtime >= now - age_max)
    $ageMaxH = (int)($cfg['age_max_days'] ?? 0) * 24 + (int)($cfg['age_max_hours'] ?? 0);
    if ($ageMaxH > 0) {
        $where .= " AND COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) >= NOW() - (? || ' hours')::interval";
        $params[] = (string)$ageMaxH;
    }
    if (isset($cfg['duration_min_sec']) && $cfg['duration_min_sec'] !== '' && $cfg['duration_min_sec'] !== null) {
        $where .= " AND i.feed_item_duration_seconds >= ?";
        $params[] = (int)$cfg['duration_min_sec'];
    }
    if (isset($cfg['duration_max_sec']) && $cfg['duration_max_sec'] !== '' && $cfg['duration_max_sec'] !== null) {
        $where .= " AND i.feed_item_duration_seconds <= ?";
        $params[] = (int)$cfg['duration_max_sec'];
    }
    if (!empty($cfg['content_type'])) {
        $where .= " AND i.feed_item_type = ?";
        $params[] = $cfg['content_type'];
    }
    // Page/category filters. Preferred shape:
    //   cfg.pages: [{page_key, category_key}, ...]  — items match if any pair matches
    // Legacy shape (still honored for older saved configs):
    //   cfg.page_key + cfg.category_key
    $pageEntries = [];
    if (!empty($cfg['pages']) && is_array($cfg['pages'])) {
        foreach ($cfg['pages'] as $e) {
            if (!empty($e['page_key'])) {
                $pageEntries[] = ['page_key' => (int)$e['page_key'], 'category_key' => !empty($e['category_key']) ? (int)$e['category_key'] : null];
            }
        }
    } elseif (!empty($cfg['page_key'])) {
        $pageEntries[] = ['page_key' => (int)$cfg['page_key'], 'category_key' => !empty($cfg['category_key']) ? (int)$cfg['category_key'] : null];
    }
    if ($pageEntries) {
        // Build EXISTS subquery with one OR'd pair per entry. Use EXISTS rather than JOIN
        // so multiple entries don't cross-multiply rows.
        $orParts = [];
        foreach ($pageEntries as $e) {
            if ($e['category_key']) {
                $orParts[] = "EXISTS (
                    SELECT 1 FROM yy_feed_item_page fip
                    JOIN yy_feed_item_category fic ON fic.feed_item_key = fip.feed_item_key
                    WHERE fip.feed_item_key = i.feed_item_key AND fip.page_key = ? AND fic.category_key = ?)";
                $params[] = $e['page_key'];
                $params[] = $e['category_key'];
            } else {
                $orParts[] = "EXISTS (SELECT 1 FROM yy_feed_item_page fip WHERE fip.feed_item_key = i.feed_item_key AND fip.page_key = ?)";
                $params[] = $e['page_key'];
            }
        }
        $where .= " AND (" . implode(' OR ', $orParts) . ")";
    }

    // Title and hashtag filters via buildFeedPageFilters — but its $where uses bare column names,
    // we're aliasing as i.* so reproduce its logic inline with the alias.
    $include = !empty($cfg['include_hashtags']) ? $cfg['include_hashtags'] : '';
    $exclude = !empty($cfg['exclude_hashtags']) ? $cfg['exclude_hashtags'] : '';
    // Hashtag filters apply ONLY to feed_item_tags (not titles). Title-based
    // matching uses the separate title_include / title_exclude fields below.
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $include))) as $term) {
        $pat = filterLikePattern($term);
        $where .= " AND i.feed_item_tags ILIKE ?";
        $params[] = $pat;
    }
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $exclude))) as $term) {
        $pat = filterLikePattern($term);
        $where .= " AND (i.feed_item_tags NOT ILIKE ? OR i.feed_item_tags IS NULL)";
        $params[] = $pat;
    }
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $cfg['title_include'] ?? ''))) as $term) {
        $pat = filterLikePattern($term);
        $where .= " AND COALESCE(i.feed_item_title_override, i.feed_item_title_import) ILIKE ?";
        $params[] = $pat;
    }
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $cfg['title_exclude'] ?? ''))) as $term) {
        $pat = filterLikePattern($term);
        $where .= " AND COALESCE(i.feed_item_title_override, i.feed_item_title_import) NOT ILIKE ?";
        $params[] = $pat;
    }

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

    $sql = "SELECT i.feed_item_key, i.feed_key, i.feed_item_external_id, i.feed_item_embed_id,
                   COALESCE(i.feed_item_title_override, i.feed_item_title_import) AS feed_item_title,
                   i.feed_item_url, i.feed_item_thumbnail, i.feed_item_duration, i.feed_item_duration_seconds,
                   i.feed_item_orientation, i.feed_item_type, i.feed_item_tags,
                   COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) AS feed_item_posted_dtime
            FROM yy_feed_item i
            $joins
            WHERE $where
            ORDER BY $order
            LIMIT " . (int)$maxCount;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
