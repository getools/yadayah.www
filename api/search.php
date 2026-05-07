<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    errorResponse('Search query is required', 400);
}

// Strip half-rings, modifiers, apostrophes, and single quotes from search input
$stripChars = "\u{02BF}\u{02BE}\u{02BC}\u{02BB}\u{02B9}\u{02BA}\u{2018}\u{2019}\u{201C}\u{201D}\u{2013}\u{2014}'";
$q = str_replace(str_split($stripChars), '', $q);
$q = preg_replace('/\s{2,}/', ' ', trim($q));

$mode   = $_GET['mode'] ?? 'all';
$series = isset($_GET['series']) && $_GET['series'] !== '' ? (int)$_GET['series'] : null;
$volume = isset($_GET['volume']) && $_GET['volume'] !== '' ? (int)$_GET['volume'] : null;
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;

$pdo = getDb();

// --- Alias expansion: look up alternate forms for each word in the query ---
$queryWords = preg_split('/\s+/', $q);
$aliasTargets = [];
foreach ($queryWords as $w) {
    $aliasStmt = $pdo->prepare("SELECT alias_target FROM yy_search_alias WHERE lower(alias_term) = lower(?)");
    $aliasStmt->execute([$w]);
    $targets = $aliasStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($targets as $t) {
        $aliasTargets[] = $t;
    }
}

// Build filter conditions (shared between all tiers)
$filterConditions = ["p.paragraph_active_flag = true", "v.volume_active_flag = true"];
$filterParams = [];
if ($series !== null) {
    $filterConditions[] = "p.series_key = ?";
    $filterParams[] = $series;
}
if ($volume !== null) {
    $filterConditions[] = "p.volume_key = ?";
    $filterParams[] = $volume;
}

// --- TIER 1: Full-text search (handles stemming) ---
$tsqParam = $q;
switch ($mode) {
    case 'phrase':
        $tsqSql = "phraseto_tsquery('english', ?)";
        break;
    case 'any':
        $tsqParam = implode(' | ', array_map(function($w) {
            return preg_replace('/[^a-zA-Z0-9]/', '', $w);
        }, $queryWords));
        $tsqSql = "to_tsquery('english', ?)";
        break;
    default:
        $tsqSql = "plainto_tsquery('english', ?)";
        break;
}

// Build FTS condition including alias targets as ILIKE OR clauses
$ftsMatchConditions = ["p.paragraph_tsv @@ $tsqSql"];
$ftsMatchParams = [$tsqParam];

// Add ILIKE for each alias target — search against normalized text (half-rings stripped)
foreach ($aliasTargets as $at) {
    $ftsMatchConditions[] = "normalize_search_text(p.paragraph_text_plain) ILIKE ?";
    $ftsMatchParams[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $at) . '%';
}

$allConditions = array_merge(['(' . implode(' OR ', $ftsMatchConditions) . ')'], $filterConditions);
$ftsWhere = 'WHERE ' . implode(' AND ', $allConditions);
$allParams = array_merge($ftsMatchParams, $filterParams);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $ftsWhere");
$countStmt->execute($allParams);
$total = (int)$countStmt->fetchColumn();

$fuzzy = false;

if ($total > 0) {
    $stmt = $pdo->prepare("
        SELECT v.volume_label AS volume_label,
               v.volume_code AS volume_code,
               v.volume_flip_code AS flip_code,
               v.volume_pdf AS volume_pdf,
               s.series_label AS series_label,
               ch.chapter_name AS chapter_name,
               ch.chapter_number AS chapter_number,
               p.paragraph_page AS page,
               ts_rank(p.paragraph_tsv, $tsqSql) AS rank,
               ts_headline('english', COALESCE(p.paragraph_text_plain, ''), $tsqSql,
                   'StartSel=<mark>, StopSel=</mark>, MaxWords=40, MinWords=20, MaxFragments=2, FragmentDelimiter= ... '
               ) AS snippet,
               p.paragraph_text_html AS html
        FROM yy_paragraph p
        JOIN yy_volume v ON v.volume_key = p.volume_key
        JOIN yy_series s ON s.series_key = p.series_key
        LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
        $ftsWhere
        ORDER BY rank DESC, v.volume_sort, p.paragraph_page, p.paragraph_number
        LIMIT ? OFFSET ?
    ");

    // Params: ts_rank(?), ts_headline(?), WHERE(?s), LIMIT, OFFSET
    $stmtParams = array_merge([$tsqParam, $tsqParam], $allParams, [$limit, $offset]);
    $stmt->execute($stmtParams);
    $results = $stmt->fetchAll();

    // For alias-matched results (no FTS highlight), highlight alias terms
    foreach ($results as &$row) {
        if ($row['snippet'] && strpos($row['snippet'], '<mark>') === false) {
            $escaped = htmlspecialchars($row['snippet'], ENT_QUOTES, 'UTF-8');
            // Highlight each alias target word
            foreach ($aliasTargets as $at) {
                foreach (preg_split('/\s+/', $at) as $aw) {
                    $escaped = preg_replace('/(' . preg_quote($aw, '/') . ')/i', '<mark>$1</mark>', $escaped);
                }
            }
            // Also highlight the original query words
            foreach ($queryWords as $qw) {
                $escaped = preg_replace('/(' . preg_quote($qw, '/') . ')/i', '<mark>$1</mark>', $escaped);
            }
            $row['snippet'] = $escaped;
        }
    }
    unset($row);
} else {
    // --- TIER 2: ILIKE substring search ---
    $fuzzy = true;
    $likePattern = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $fuzzyConditions = array_merge(["normalize_search_text(p.paragraph_text_plain) ILIKE ?"], $filterConditions);
    $fuzzyWhere = 'WHERE ' . implode(' AND ', $fuzzyConditions);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $fuzzyWhere");
    $countStmt->execute(array_merge([$likePattern], $filterParams));
    $total = (int)$countStmt->fetchColumn();

    if ($total > 0) {
        $stmt = $pdo->prepare("
            SELECT v.volume_label AS volume_label,
                   v.volume_code AS volume_code,
                   v.volume_flip_code AS flip_code,
                   v.volume_pdf AS volume_pdf,
                   s.series_label AS series_label,
                   ch.chapter_name AS chapter_name,
                   ch.chapter_number AS chapter_number,
                   p.paragraph_page AS page,
                   similarity(normalize_search_text(p.paragraph_text_plain), ?) AS rank,
                   CASE WHEN length(p.paragraph_text_plain) > 300
                        THEN substring(p.paragraph_text_plain FROM greatest(1, position(lower(?) in lower(normalize_search_text(p.paragraph_text_plain))) - 100) FOR 300)
                        ELSE p.paragraph_text_plain
                   END AS snippet,
                   p.paragraph_text_html AS html
            FROM yy_paragraph p
            JOIN yy_volume v ON v.volume_key = p.volume_key
            JOIN yy_series s ON s.series_key = p.series_key
            LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
            $fuzzyWhere
            ORDER BY rank DESC, v.volume_sort, p.paragraph_page, p.paragraph_number
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge([$q, $q], [$likePattern], $filterParams, [$limit, $offset]));
        $results = $stmt->fetchAll();

        foreach ($results as &$row) {
            if ($row['snippet']) {
                $row['snippet'] = preg_replace(
                    '/(' . preg_quote($q, '/') . ')/i',
                    '<mark>$1</mark>',
                    htmlspecialchars($row['snippet'], ENT_QUOTES, 'UTF-8')
                );
            }
        }
        unset($row);
    } else {
        // --- TIER 3: Fuzzy word_similarity (handles typos/misspellings) ---
        $pdo->exec("SET pg_trgm.word_similarity_threshold = 0.4");
        $simConditions = [];
        $simParams = [];
        foreach ($queryWords as $w) {
            $simConditions[] = "? %> normalize_search_text(p.paragraph_text_plain)";
            $simParams[] = $w;
        }
        $simWhere = 'WHERE (' . implode(' OR ', $simConditions) . ')';
        if (count($filterConditions) > 0) {
            $simWhere .= ' AND ' . implode(' AND ', $filterConditions);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $simWhere");
        $countStmt->execute(array_merge($simParams, $filterParams));
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT v.volume_label AS volume_label,
                   v.volume_code AS volume_code,
                   v.volume_flip_code AS flip_code,
                   v.volume_pdf AS volume_pdf,
                   s.series_label AS series_label,
                   ch.chapter_name AS chapter_name,
                   ch.chapter_number AS chapter_number,
                   p.paragraph_page AS page,
                   greatest(" . implode(', ', array_fill(0, count($queryWords), 'word_similarity(?, normalize_search_text(p.paragraph_text_plain))')) . ") AS rank,
                   CASE WHEN length(p.paragraph_text_plain) > 300
                        THEN substring(p.paragraph_text_plain FROM 1 FOR 300)
                        ELSE p.paragraph_text_plain
                   END AS snippet,
                   p.paragraph_text_html AS html
            FROM yy_paragraph p
            JOIN yy_volume v ON v.volume_key = p.volume_key
            JOIN yy_series s ON s.series_key = p.series_key
            LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
            $simWhere
            ORDER BY rank DESC
            LIMIT ? OFFSET ?
        ");
        $rankParams = [];
        foreach ($queryWords as $w) $rankParams[] = $w;
        $stmt->execute(array_merge($rankParams, $simParams, $filterParams, [$limit, $offset]));
        $results = $stmt->fetchAll();

        foreach ($results as &$row) {
            if ($row['snippet']) {
                $escaped = htmlspecialchars($row['snippet'], ENT_QUOTES, 'UTF-8');
                foreach ($queryWords as $w) {
                    $escaped = preg_replace('/(' . preg_quote($w, '/') . ')/i', '<mark>$1</mark>', $escaped);
                }
                $row['snippet'] = $escaped;
            }
        }
        unset($row);
    }
}

// Build per-result links.
//
// Page numbers in yy_paragraph come from the source docx footer. The new
// self-hosted flipbook viewer reads pages straight from the Word-COM-
// generated PDF, so PDF page = docx footer page = the page stored here
// (provided the volume's PDF was produced by the desktop "YY PDF
// Generator App"). The viewer accepts `#chapter=slug&page=N` in the URL
// hash and seeks to that page on load; if `page=N` exceeds the actual
// page count it silently clamps, so older volumes whose PDF hasn't been
// regenerated yet fail gracefully toward the chapter or book home.

// Slugify a chapter's display title ("1 Babel ~ Confusion") to the
// stable URL slug the flipbook viewer recognizes ("1-babel-confusion").
// Mirrors the JS slugify in extract_toc_v3.py / the new flipbook code.
function chapterSlug(string $title): string {
    $s = mb_strtolower($title, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);
    $s = preg_replace('/[\s_-]+/u', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? $s : 'section';
}

foreach ($results as &$row) {
    // Strip apostrophe-ish glyphs from the canonical book code to get
    // the public-facing slug. The named-path FlipHTML5 bundles on disk
    // (e.g., /opt/yada-www/public/YY-s02v01-Yada-Yahowah-Baresyth-Beginning/)
    // strip apostrophes; volume_code preserves them to match the docx
    // filename, so we need the strip step here.
    $bookSlug = $row['volume_code']
        ? preg_replace("/[\u{0027}\u{2018}\u{2019}\u{02BC}]/u", '', $row['volume_code'])
        : null;

    $bookUrl = $bookSlug ? '/' . $bookSlug . '/' : null;
    $row['book_url'] = $bookUrl;

    // Build the deep-link hash from whatever location info we have for
    // this paragraph: chapter slug and/or page. Both keys are honored by
    // the new flipbook viewer (`page` takes precedence over `chapter`
    // when both are present, which is what we want — page is the more
    // specific destination).
    $hashParts = [];
    if ($row['chapter_number'] && $row['chapter_name']) {
        $title = $row['chapter_number'] . ' ' . $row['chapter_name'];
        $hashParts[] = 'chapter=' . chapterSlug($title);
    }
    if (!empty($row['page'])) {
        $hashParts[] = 'page=' . (int)$row['page'];
    }
    // Pass the search query along so the flipbook viewer pre-populates its
    // own search box on load and highlights matches in the text-layer.
    // flipbook.js reads params.get('q') from location.hash.
    if ($q !== '') {
        $hashParts[] = 'q=' . rawurlencode($q);
    }
    $hash = $hashParts ? '#' . implode('&', $hashParts) : '';

    // chapter_url is the URL site-search.js wraps the location text in
    // ("Ch 3 · Foo · Page 47"). Includes both chapter and page when
    // available so the deep-link is as precise as possible. Falls back
    // to null if neither is known; the renderer then uses bookHref.
    $row['chapter_url'] = ($bookUrl && $hashParts) ? ($bookUrl . $hash) : null;

    // flip_url retained for backwards compatibility (book.html, search
    // prototype). Always populated; carries the same hash as chapter_url
    // when location info is present.
    $row['flip_url'] = $bookUrl ? ($bookUrl . $hash) : null;

    unset($row['rank']);
}
unset($row);

jsonResponse([
    'total'   => $total,
    'page'    => $page,
    'limit'   => $limit,
    'pages'   => (int)ceil($total / $limit),
    'fuzzy'   => $fuzzy,
    'results' => $results,
]);
