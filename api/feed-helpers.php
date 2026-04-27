<?php
/**
 * Shared helpers for yy_feed_page filter building.
 *
 * Wildcard convention for include/exclude filter terms:
 *   term*   → starts with (ILIKE 'term%')
 *   *term   → ends with   (ILIKE '%term')
 *   *term*  → contains    (ILIKE '%term%')
 *   term    → contains    (ILIKE '%term%')  [default]
 */

/**
 * Clean a feed item title for display: strip hashtags, emojis, leading/trailing ~ and -, trim whitespace.
 */
function cleanFeedTitle(string $title): string {
    // Strip emojis and symbol characters
    $title = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}\x{2300}-\x{23FF}\x{2B50}-\x{2B55}]/u', '', $title);
    // Strip hashtags
    $title = preg_replace('/#\w+\s*/u', '', $title);
    // Strip leading/trailing ~ - and whitespace
    $title = trim(preg_replace('/^[~\- ]+|[~\- ]+$/', '', trim($title)));
    // Collapse multiple spaces
    $title = preg_replace('/  +/', ' ', $title);
    return $title;
}

function filterLikePattern(string $term): string {
    $hasLeading = str_starts_with($term, '*');
    $hasTrailing = str_ends_with($term, '*');
    $core = trim($term, '*');
    if ($hasLeading && $hasTrailing) return '%' . $core . '%';
    if ($hasTrailing) return $core . '%';
    if ($hasLeading) return '%' . $core;
    return '%' . $core . '%';
}

function buildFeedPageFilters(string &$where, array &$params, ?string $includeStr, ?string $excludeStr, ?string $orientation = null): void {
    if ($orientation) {
        $where .= " AND feed_item_orientation = ?";
        $params[] = $orientation;
    }
    $include = array_filter(array_map('trim', preg_split('/[,|]/', $includeStr ?? '')));
    if ($include) {
        $clauses = [];
        foreach ($include as $term) {
            $pat = filterLikePattern($term);
            $clauses[] = "(feed_item_tags ILIKE ? OR COALESCE(feed_item_title_override, feed_item_title_import) ILIKE ?)";
            $params[] = $pat;
            $params[] = $pat;
        }
        $where .= " AND (" . implode(' OR ', $clauses) . ")";
    }

    $exclude = array_filter(array_map('trim', preg_split('/[,|]/', $excludeStr ?? '')));
    foreach ($exclude as $term) {
        $pat = filterLikePattern($term);
        $where .= " AND (feed_item_tags NOT ILIKE ? OR feed_item_tags IS NULL) AND COALESCE(feed_item_title_override, feed_item_title_import) NOT ILIKE ?";
        $params[] = $pat;
        $params[] = $pat;
    }
}

/**
 * Resolve page_key from page_code.
 */
function getPageKey(PDO $db, string $pageCode): ?int {
    static $cache = [];
    if (isset($cache[$pageCode])) return $cache[$pageCode];
    $stmt = $db->prepare("SELECT page_key FROM yy_page WHERE page_code = ?");
    $stmt->execute([$pageCode]);
    $key = $stmt->fetchColumn();
    $cache[$pageCode] = $key ? (int)$key : null;
    return $cache[$pageCode];
}
