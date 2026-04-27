<?php
/**
 * Feed item → page association helpers.
 * Maintains yy_feed_item_page join table based on feed_page filter rules.
 *
 * Call these functions when:
 *   - A feed item is inserted or updated (tags, title, orientation change)
 *   - A feed_page's include/exclude/orientation filters are changed
 *   - A feed_page is created or deleted
 */
require_once __DIR__ . '/feed-helpers.php';

/**
 * Evaluate which pages a single feed item belongs to and update the join table.
 */
function updateItemPages(PDO $db, int $feedItemKey): void {
    // Get the item's data
    $stmt = $db->prepare("
        SELECT feed_item_key, feed_key, feed_item_tags,
               COALESCE(feed_item_title_override, feed_item_title_import) AS title,
               feed_item_orientation, feed_item_active_flag
        FROM yy_feed_item WHERE feed_item_key = ?
    ");
    $stmt->execute([$feedItemKey]);
    $item = $stmt->fetch();
    if (!$item) return;

    // Get all feed_page entries for this item's feed
    $fpStmt = $db->prepare("
        SELECT fp.page_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
        FROM yy_feed_page fp
        WHERE fp.feed_key = ? AND fp.feed_page_active_flag = TRUE
    ");
    $fpStmt->execute([$item['feed_key']]);
    $feedPages = $fpStmt->fetchAll();

    $matchedPages = [];
    foreach ($feedPages as $fp) {
        if (itemMatchesPage($item, $fp)) {
            $matchedPages[] = (int)$fp['page_key'];
        }
    }

    // Replace existing associations
    $db->prepare("DELETE FROM yy_feed_item_page WHERE feed_item_key = ?")->execute([$feedItemKey]);
    if ($matchedPages) {
        $insertStmt = $db->prepare("INSERT INTO yy_feed_item_page (feed_item_key, page_key) VALUES (?, ?) ON CONFLICT DO NOTHING");
        foreach ($matchedPages as $pk) {
            $insertStmt->execute([$feedItemKey, $pk]);
        }
    }
}

/**
 * Evaluate all feed items for a specific page and update the join table.
 * Called when a page's filters change.
 */
function updatePageItems(PDO $db, int $pageKey): void {
    // Get the feed_page config(s) for this page
    $fpStmt = $db->prepare("
        SELECT fp.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
        FROM yy_feed_page fp
        WHERE fp.page_key = ? AND fp.feed_page_active_flag = TRUE
    ");
    $fpStmt->execute([$pageKey]);
    $feedPages = $fpStmt->fetchAll();

    // Clear existing associations for this page
    $db->prepare("DELETE FROM yy_feed_item_page WHERE page_key = ?")->execute([$pageKey]);

    foreach ($feedPages as $fp) {
        // Get all items from this feed
        $itemStmt = $db->prepare("
            SELECT feed_item_key, feed_key, feed_item_tags,
                   COALESCE(feed_item_title_override, feed_item_title_import) AS title,
                   feed_item_orientation
            FROM yy_feed_item
            WHERE feed_key = ? AND feed_item_active_flag = TRUE
        ");
        $itemStmt->execute([$fp['feed_key']]);

        $insertStmt = $db->prepare("INSERT INTO yy_feed_item_page (feed_item_key, page_key) VALUES (?, ?) ON CONFLICT DO NOTHING");
        while ($item = $itemStmt->fetch()) {
            if (itemMatchesPage($item, $fp)) {
                $insertStmt->execute([$item['feed_item_key'], $pageKey]);
            }
        }
    }
}

/**
 * Rebuild the entire join table from scratch. Use sparingly.
 */
function rebuildAllItemPages(PDO $db): array {
    $db->exec("TRUNCATE yy_feed_item_page");

    // Get all active feed_page configs
    $fpStmt = $db->query("
        SELECT fp.page_key, fp.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
        FROM yy_feed_page fp
        WHERE fp.feed_page_active_flag = TRUE
        ORDER BY fp.page_key
    ");
    $feedPages = $fpStmt->fetchAll();

    $insertStmt = $db->prepare("INSERT INTO yy_feed_item_page (feed_item_key, page_key) VALUES (?, ?) ON CONFLICT DO NOTHING");
    $total = 0;
    $pageCount = 0;

    foreach ($feedPages as $fp) {
        $itemStmt = $db->prepare("
            SELECT feed_item_key, feed_key, feed_item_tags,
                   COALESCE(feed_item_title_override, feed_item_title_import) AS title,
                   feed_item_orientation
            FROM yy_feed_item
            WHERE feed_key = ? AND feed_item_active_flag = TRUE
        ");
        $itemStmt->execute([$fp['feed_key']]);
        $count = 0;

        while ($item = $itemStmt->fetch()) {
            if (itemMatchesPage($item, $fp)) {
                $insertStmt->execute([$item['feed_item_key'], (int)$fp['page_key']]);
                $count++;
            }
        }
        $total += $count;
        $pageCount++;
    }

    return ['pages' => $pageCount, 'associations' => $total];
}

/**
 * Batch update page associations for multiple feed items (used by sync scripts).
 */
function updateItemPagesForFeed(PDO $db, int $feedKey): void {
    // Get all feed_page configs for this feed
    $fpStmt = $db->prepare("
        SELECT fp.page_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
        FROM yy_feed_page fp
        WHERE fp.feed_key = ? AND fp.feed_page_active_flag = TRUE
    ");
    $fpStmt->execute([$feedKey]);
    $feedPages = $fpStmt->fetchAll();
    if (!$feedPages) return;

    // Get all items for this feed
    $itemStmt = $db->prepare("
        SELECT feed_item_key, feed_key, feed_item_tags,
               COALESCE(feed_item_title_override, feed_item_title_import) AS title,
               feed_item_orientation
        FROM yy_feed_item WHERE feed_key = ?
    ");
    $itemStmt->execute([$feedKey]);
    $items = $itemStmt->fetchAll();

    // Delete existing associations for these items
    if ($items) {
        $keys = array_column($items, 'feed_item_key');
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $db->prepare("DELETE FROM yy_feed_item_page WHERE feed_item_key IN ($placeholders)")->execute($keys);
    }

    $insertStmt = $db->prepare("INSERT INTO yy_feed_item_page (feed_item_key, page_key) VALUES (?, ?) ON CONFLICT DO NOTHING");
    foreach ($items as $item) {
        foreach ($feedPages as $fp) {
            if (itemMatchesPage($item, $fp)) {
                $insertStmt->execute([$item['feed_item_key'], (int)$fp['page_key']]);
            }
        }
    }
}

/**
 * Check if a feed item matches a page's filters.
 */
function itemMatchesPage(array $item, array $feedPage): bool {
    $tags = $item['feed_item_tags'] ?? $item['tags'] ?? '';
    $title = $item['title'] ?? '';

    // Check orientation filter
    $orientFilter = $feedPage['feed_page_filter_orientation'] ?? null;
    if ($orientFilter && ($item['feed_item_orientation'] ?? $item['orientation'] ?? null) !== $orientFilter) {
        return false;
    }

    // Check include filters — item must match at least one
    $includeStr = $feedPage['feed_page_filter_include'] ?? '';
    if ($includeStr) {
        $terms = array_filter(array_map('trim', preg_split('/[,|]/', $includeStr)));
        if ($terms) {
            $matched = false;
            foreach ($terms as $term) {
                $pat = filterLikePattern($term);
                // Convert SQL ILIKE pattern to PHP regex
                $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pat, '/')) . '$/i';
                // Fix double-escaped wildcards
                $regex = str_replace(['\\.*', '\\.'], ['.*', '.'], $regex);
                if (preg_match($regex, $tags) || preg_match($regex, $title)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) return false;
        }
    }

    // Check exclude filters — item must not match any
    $excludeStr = $feedPage['feed_page_filter_exclude'] ?? '';
    if ($excludeStr) {
        $terms = array_filter(array_map('trim', preg_split('/[,|]/', $excludeStr)));
        foreach ($terms as $term) {
            $pat = filterLikePattern($term);
            $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pat, '/')) . '$/i';
            $regex = str_replace(['\\.*', '\\.'], ['.*', '.'], $regex);
            if (preg_match($regex, $tags) || preg_match($regex, $title)) {
                return false;
            }
        }
    }

    return true;
}
