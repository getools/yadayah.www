<?php
/**
 * Shared helpers for yy_feed_page filter building.
 *
 * Wildcard convention for include/exclude filter terms:
 *   term*   → starts with (ILIKE 'term%')
 *   *term   → ends with   (ILIKE '%term')
 *   *term*  → contains    (ILIKE '%term%')
 *   term    → exact match for hashtags (whole-word, comma-bounded);
 *             contains for title text [default]
 *
 * Hashtag whole-word matching: feed_item_tags is a comma-separated list like
 * "#vlog,#Music,#Shabbat". Without this, `#Music` as a filter would falsely
 * match `#MusicVideo` via substring. We require any non-wildcard tag term to
 * sit between commas / string boundaries.
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

/**
 * Build a SQL fragment + bind params for matching a single include/exclude
 * term against the comma-separated `feed_item_tags` column. Without a
 * wildcard the match is whole-token (bounded by commas / string ends) so
 * `#Music` doesn't bleed into `#MusicVideo`. With a `*` anywhere, falls
 * back to the existing ILIKE wildcard behavior.
 *
 * Returns [sqlClause, [paramValues...]].
 */
function tagFilterClause(string $col, string $term, bool $negate): array {
    if (str_contains($term, '*')) {
        $pat = filterLikePattern($term);
        if ($negate) return ["($col NOT ILIKE ? OR $col IS NULL)", [$pat]];
        return ["$col ILIKE ?", [$pat]];
    }
    // POSIX regex with comma boundaries; allow whitespace around the comma
    // so legacy tag strings stored as "#a, #b" still match. preg_quote
    // covers Postgres regex metachars too (overlapping set with PHP).
    $rx = '(^|,)\s*' . preg_quote($term, '/') . '\s*(,|$)';
    if ($negate) return ["($col !~* ? OR $col IS NULL)", [$rx]];
    return ["$col ~* ?", [$rx]];
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
            // Tags use whole-word matching (or wildcards if the term has *).
            // Title still uses substring match — titles aren't comma-tokenised.
            [$tagSql, $tagParams] = tagFilterClause('feed_item_tags', $term, false);
            $titlePat = filterLikePattern($term);
            $clauses[] = "($tagSql OR COALESCE(feed_item_title_override, feed_item_title_import) ILIKE ?)";
            foreach ($tagParams as $p) $params[] = $p;
            $params[] = $titlePat;
        }
        $where .= " AND (" . implode(' OR ', $clauses) . ")";
    }

    $exclude = array_filter(array_map('trim', preg_split('/[,|]/', $excludeStr ?? '')));
    foreach ($exclude as $term) {
        [$tagSql, $tagParams] = tagFilterClause('feed_item_tags', $term, true);
        $titlePat = filterLikePattern($term);
        $where .= " AND $tagSql AND COALESCE(feed_item_title_override, feed_item_title_import) NOT ILIKE ?";
        foreach ($tagParams as $p) $params[] = $p;
        $params[] = $titlePat;
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

/**
 * Append the shared Items-section filter conditions to a query's WHERE.
 * Used by BOTH the public page renderer (page-render.php → resolveItems-
 * Section) and the admin "Selected Titles" typeahead (feed-items-search.php),
 * so the titles a user can pin are exactly the items the section would show.
 *
 * Item rows MUST be aliased `i` in the calling query. Does NOT handle the
 * pinned `feed_item_keys` set (render returns those verbatim; search
 * excludes already-pinned items) — that's the caller's concern.
 *
 * Recognized $cfg keys: feed_keys[], age_min_h, age_max_h, duration_min_sec,
 * duration_max_sec, content_type, orientation, pages[]/page_key+category_key,
 * include_hashtags, exclude_hashtags, title_include, title_exclude.
 */
function appendItemsSectionFilters(array $cfg, string &$where, array &$params): void {
    if (!empty($cfg['feed_keys']) && is_array($cfg['feed_keys'])) {
        $ids = array_values(array_filter(array_map('intval', $cfg['feed_keys'])));
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND i.feed_key IN ($place)";
            array_push($params, ...$ids);
        }
    }
    $ageMinH = (int)($cfg['age_min_h'] ?? 0);
    if ($ageMinH > 0) {
        $where .= " AND COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) <= NOW() - (? || ' hours')::interval";
        $params[] = (string)$ageMinH;
    }
    $ageMaxH = (int)($cfg['age_max_h'] ?? 0);
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
    if (!empty($cfg['orientation']) && in_array($cfg['orientation'], ['vertical', 'horizontal'], true)) {
        $where .= " AND i.feed_item_orientation = ?";
        $params[] = $cfg['orientation'];
    }
    // Page/category filters: cfg.pages [{page_key, category_key}], OR legacy
    // single page_key + category_key.
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
        $orParts = [];
        foreach ($pageEntries as $e) {
            if ($e['category_key']) {
                $orParts[] = "EXISTS (SELECT 1 FROM yy_feed_item_page fip JOIN yy_feed_item_category fic ON fic.feed_item_key = fip.feed_item_key WHERE fip.feed_item_key = i.feed_item_key AND fip.page_key = ? AND fic.category_key = ?)";
                $params[] = $e['page_key'];
                $params[] = $e['category_key'];
            } else {
                $orParts[] = "EXISTS (SELECT 1 FROM yy_feed_item_page fip WHERE fip.feed_item_key = i.feed_item_key AND fip.page_key = ?)";
                $params[] = $e['page_key'];
            }
        }
        $where .= " AND (" . implode(' OR ', $orParts) . ")";
    }
    // Hashtag filters (feed_item_tags only).
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $cfg['include_hashtags'] ?? ''))) as $term) {
        [$tagSql, $tagParams] = tagFilterClause('i.feed_item_tags', $term, false);
        $where .= " AND $tagSql";
        foreach ($tagParams as $p) $params[] = $p;
    }
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $cfg['exclude_hashtags'] ?? ''))) as $term) {
        [$tagSql, $tagParams] = tagFilterClause('i.feed_item_tags', $term, true);
        $where .= " AND $tagSql";
        foreach ($tagParams as $p) $params[] = $p;
    }
    // Title include/exclude (wildcard convention via filterLikePattern).
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $cfg['title_include'] ?? ''))) as $term) {
        $where .= " AND COALESCE(i.feed_item_title_override, i.feed_item_title_import) ILIKE ?";
        $params[] = filterLikePattern($term);
    }
    foreach (array_filter(array_map('trim', preg_split('/[,|]/', $cfg['title_exclude'] ?? ''))) as $term) {
        $where .= " AND COALESCE(i.feed_item_title_override, i.feed_item_title_import) NOT ILIKE ?";
        $params[] = filterLikePattern($term);
    }
}
