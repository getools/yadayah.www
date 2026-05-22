<?php
/**
 * TEST — rebuild the materialized yy_section_item rows for Items section(s).
 *
 * yy_section_item caches every feed item that matches a section's filter
 * criteria (the FULL matching pool, paginated past resolveItemsSection's 200
 * cap — not just the section's display slice), in resolved order, tagged with
 * the section-scoped yy_category each item falls under (NULL when the item has
 * no category in that section).
 *
 *   POST ?section_key=K  → rebuild one section        → { section_key, items }
 *   POST ?all=1          → rebuild every Items section → { sections:[...], total }
 *
 * Also a library: define SECTION_ITEMS_LIB before requiring to get
 * rebuildSectionItems()/rebuildAllSectionItems() without running the endpoint
 * (admin-pages.php uses this to recompute a section's rows on save).
 * Auth required for the endpoint.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../feed-helpers.php';
define('PAGE_RENDER_LIB', true);              // load page-render.php as a library
require_once __DIR__ . '/page-render.php';    // resolveItemsSection()

/**
 * The section-scoped yy_category key for an item, or null.
 * Bridges the item's page-scoped assignment (yy_feed_item_category ->
 * yy_feed_page_category) to the section's own yy_category set by slug
 * (slug is unique within a page). If the item maps to several, the
 * lowest-sorted category wins.
 */
function sectionItemCategory(PDO $db, int $sectionKey, int $feedItemKey, array $pageKeys): ?int {
    if (!$pageKeys) return null;
    $place = implode(',', array_fill(0, count($pageKeys), '?'));
    $st = $db->prepare("
        SELECT yc.category_key
          FROM yy_feed_item_category fic
          JOIN yy_feed_page_category pc ON pc.category_key = fic.category_key AND pc.page_key IN ($place)
          JOIN yy_category yc ON yc.section_key = ? AND yc.category_slug = pc.category_slug
                             AND yc.category_active_flag = TRUE
         WHERE fic.feed_item_key = ?
         ORDER BY yc.category_sort, yc.category_key
         LIMIT 1
    ");
    $st->execute(array_merge($pageKeys, [$sectionKey, $feedItemKey]));
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
}

/** Rebuild yy_section_item for one section. Returns the number of items stored. */
function rebuildSectionItems(PDO $db, int $sectionKey): int {
    $st = $db->prepare("SELECT section_type, section_config FROM yy_section WHERE section_key = ?");
    $st->execute([$sectionKey]);
    $row = $st->fetch();
    // Not an items section (or gone) — make sure no stale rows linger.
    if (!$row || $row['section_type'] !== 'items') {
        $db->prepare("DELETE FROM yy_section_item WHERE section_key = ?")->execute([$sectionKey]);
        return 0;
    }
    $cfg = $row['section_config'];
    $cfg = is_string($cfg) ? (json_decode($cfg, true) ?: []) : ($cfg ?: []);

    // Full matching pool, in order — page past the per-call 200 cap.
    $items  = [];
    $offset = 0;
    do {
        $batchCfg = $cfg;
        $batchCfg['max_count'] = 200;
        $batchCfg['_offset']   = $offset;
        $batch = resolveItemsSection($db, $batchCfg);
        foreach ($batch as $b) $items[] = $b;
        $offset += 200;
    } while (count($batch) === 200 && $offset < 100000);

    // Page scope for the category bridge.
    $pageKeys = [];
    foreach (($cfg['pages'] ?? []) as $p) if (!empty($p['page_key'])) $pageKeys[] = (int)$p['page_key'];
    if (!empty($cfg['page_key'])) $pageKeys[] = (int)$cfg['page_key'];
    $pageKeys = array_values(array_unique($pageKeys));

    $db->beginTransaction();
    $db->prepare("DELETE FROM yy_section_item WHERE section_key = ?")->execute([$sectionKey]);
    $ins = $db->prepare("INSERT INTO yy_section_item (section_key, feed_item_key, category_key, section_item_sort)
                         VALUES (?, ?, ?, ?)
                         ON CONFLICT (section_key, feed_item_key) DO UPDATE
                           SET category_key       = EXCLUDED.category_key,
                               section_item_sort  = EXCLUDED.section_item_sort,
                               section_item_dtime = NOW()");
    $sort = 0;
    $seen = [];
    foreach ($items as $it) {
        $fik = (int)$it['feed_item_key'];
        if (isset($seen[$fik])) continue;   // dedupe (pinned + filter overlap)
        $seen[$fik] = true;
        $catKey = sectionItemCategory($db, $sectionKey, $fik, $pageKeys);
        $ins->execute([$sectionKey, $fik, $catKey, $sort++]);
    }
    $db->commit();
    return $sort;
}

/** Rebuild every active Items section. Returns [{section_key, items}, ...]. */
function rebuildAllSectionItems(PDO $db): array {
    $secs = $db->query("SELECT section_key FROM yy_section WHERE section_type = 'items' ORDER BY section_key")
               ->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($secs as $sk) {
        $out[] = ['section_key' => (int)$sk, 'items' => rebuildSectionItems($db, (int)$sk)];
    }
    return $out;
}

// ── Endpoint body (skipped when included as a library) ──
if (!defined('SECTION_ITEMS_LIB')) {
    requireAuth();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') errorResponse('POST required', 405);
    $db = getDb();
    if (!empty($_GET['all'])) {
        $res   = rebuildAllSectionItems($db);
        $total = 0;
        foreach ($res as $r) $total += $r['items'];
        jsonResponse(['sections' => $res, 'total' => $total]);
    }
    $sk = (int)($_GET['section_key'] ?? 0);
    if (!$sk) errorResponse('section_key or all=1 required');
    jsonResponse(['section_key' => $sk, 'items' => rebuildSectionItems($db, $sk)]);
}
