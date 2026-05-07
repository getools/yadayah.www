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
 * Pages this item is explicitly categorized on (via yy_feed_item_category).
 * These are page assignments that came from #vlog|slug|ep / #basics|slug|ep
 * style hashtag declarations during sync — an explicit "this video belongs
 * on page X" signal that overrides any include/exclude filter rules on the
 * destination page.
 */
function explicitPagesForItem(PDO $db, int $feedItemKey): array {
    $stmt = $db->prepare("
        SELECT DISTINCT fpc.page_key
        FROM yy_feed_item_category fic
        JOIN yy_feed_page_category fpc ON fpc.category_key = fic.category_key
        WHERE fic.feed_item_key = ?
    ");
    $stmt->execute([$feedItemKey]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

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
    // Union with pages we're explicitly categorized on — overrides filters.
    foreach (explicitPagesForItem($db, $feedItemKey) as $pk) {
        if (!in_array($pk, $matchedPages, true)) $matchedPages[] = $pk;
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

    // Also add any items explicitly categorized on this page — overrides filters.
    $expStmt = $db->prepare("
        INSERT INTO yy_feed_item_page (feed_item_key, page_key)
        SELECT DISTINCT fic.feed_item_key, fpc.page_key
        FROM yy_feed_item_category fic
        JOIN yy_feed_page_category fpc ON fpc.category_key = fic.category_key
        JOIN yy_feed_item fi ON fi.feed_item_key = fic.feed_item_key
        WHERE fpc.page_key = ? AND fi.feed_item_active_flag = TRUE
        ON CONFLICT DO NOTHING
    ");
    $expStmt->execute([$pageKey]);
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

    // Pre-fetch explicit page assignments (from yy_feed_item_category) so we
    // can union them with filter-matched pages. An item with #vlog|slug|ep or
    // #basics|slug|ep in its description gets a category row at sync time —
    // that's a strong "this video belongs here" signal that overrides any
    // include/exclude filter rule on the destination page.
    $explicit = [];
    if ($items) {
        $itemKeys = array_map('intval', array_column($items, 'feed_item_key'));
        $ph = implode(',', array_fill(0, count($itemKeys), '?'));
        $expStmt = $db->prepare("
            SELECT fic.feed_item_key, fpc.page_key
            FROM yy_feed_item_category fic
            JOIN yy_feed_page_category fpc ON fpc.category_key = fic.category_key
            WHERE fic.feed_item_key IN ($ph)
        ");
        $expStmt->execute($itemKeys);
        foreach ($expStmt->fetchAll() as $r) {
            $explicit[(int)$r['feed_item_key']][] = (int)$r['page_key'];
        }
    }

    $insertStmt = $db->prepare("INSERT INTO yy_feed_item_page (feed_item_key, page_key) VALUES (?, ?) ON CONFLICT DO NOTHING");
    foreach ($items as $item) {
        $itemKey = (int)$item['feed_item_key'];
        $assigned = [];
        foreach ($feedPages as $fp) {
            if (itemMatchesPage($item, $fp)) {
                $pk = (int)$fp['page_key'];
                if (!in_array($pk, $assigned, true)) $assigned[] = $pk;
            }
        }
        foreach ($explicit[$itemKey] ?? [] as $pk) {
            if (!in_array($pk, $assigned, true)) $assigned[] = $pk;
        }
        foreach ($assigned as $pk) {
            $insertStmt->execute([$itemKey, $pk]);
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
                if (tagMatchesTerm($tags, $term) || titleMatchesTerm($title, $term)) {
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
            if (tagMatchesTerm($tags, $term) || titleMatchesTerm($title, $term)) {
                return false;
            }
        }
    }

    return true;
}

/**
 * PHP-side analogue of SQL `tagFilterClause`: whole-word match against the
 * comma-separated `feed_item_tags` string unless the term contains `*`.
 */
function tagMatchesTerm(string $tags, string $term): bool {
    if ($tags === '') return false;
    if (str_contains($term, '*')) {
        $pat = filterLikePattern($term);
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pat, '/')) . '$/i';
        $regex = str_replace(['\\.*', '\\.'], ['.*', '.'], $regex);
        return (bool)preg_match($regex, $tags);
    }
    // Allow whitespace around the comma separator so legacy tag strings
    // stored as "#a, #b" still match.
    $regex = '/(^|,)\s*' . preg_quote($term, '/') . '\s*(,|$)/i';
    return (bool)preg_match($regex, $tags);
}

/**
 * Title still uses substring/wildcard match — titles aren't tokenized.
 */
function titleMatchesTerm(string $title, string $term): bool {
    if ($title === '') return false;
    $pat = filterLikePattern($term);
    $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pat, '/')) . '$/i';
    $regex = str_replace(['\\.*', '\\.'], ['.*', '.'], $regex);
    return (bool)preg_match($regex, $title);
}
