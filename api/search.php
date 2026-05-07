<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';
$_searchStart = microtime(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    errorResponse('Search query is required', 400);
}

// Strip half-rings, modifiers, apostrophes, and single quotes from search input
$stripCharsArray = [
    "\u{02BF}", "\u{02BE}", "\u{02BC}", "\u{02BB}", "\u{02B9}", "\u{02BA}",
    "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "'"
];
$q = str_replace($stripCharsArray, '', $q);
$q = preg_replace('/\s{2,}/', ' ', trim($q));

$mode   = $_GET['mode'] ?? 'all';
$series = isset($_GET['series']) && $_GET['series'] !== '' ? (int)$_GET['series'] : null;
$volume = isset($_GET['volume']) && $_GET['volume'] !== '' ? (int)$_GET['volume'] : null;
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;

$pdo = getDb();

// One-time scan of the new self-hosted flipbook directory. Only volumes with
// a built flipbook (a directory at /flipbook-prototype/<volume_code>/ containing
// an index.html) get a link in search results — the old FlipHTML5 and direct-PDF
// fallbacks have been dropped per the unified Flipbook integration.
// Webroot inside the container is /var/www/html (not /var/www/html/public),
// so the flipbook dir is one level up from /api, not two.
$_flipbookRoot = dirname(__DIR__) . '/flipbook-prototype';
$_availableFlipbooks = [];
if (is_dir($_flipbookRoot)) {
    foreach (scandir($_flipbookRoot) as $d) {
        if ($d === '.' || $d === '..') continue;
        if (is_file($_flipbookRoot . '/' . $d . '/index.html')) {
            $_availableFlipbooks[$d] = true;
        }
    }
}
function flipbookUrl(?string $volumeCode, $page): ?string {
    global $_availableFlipbooks;
    if (!$volumeCode || empty($_availableFlipbooks[$volumeCode])) return null;
    // Hash deep link uses physical flipbook page. Front matter (cover/copyright/ToC)
    // contributes ~6 pages, so paragraph_page (the docx footer page) maps to
    // physical page paragraph_page + 6 — same offset as the prior FlipHTML5 viewer.
    $physical = ((int)$page) + 6;
    return '/flipbook-prototype/' . rawurlencode($volumeCode) . '/#page=' . $physical;
}

// --- Alias expansion: look up alternate forms for each word in the query ---
$qWords = preg_split('/\s+/', $q);
$aliasTargets = [];
foreach ($qWords as $w) {
    $aliasStmt = $pdo->prepare("SELECT alias_target FROM yy_search_alias WHERE lower(alias_term) = lower(?)");
    $aliasStmt->execute([$w]);
    $targets = $aliasStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($targets as $t) {
        $aliasTargets[] = $t;
    }
}

// Build filter conditions (shared between all tiers).
// volume_search_flag = false hides a volume from search results entirely,
// matching the Series/Volume dropdown filtering done in search-filters.php.
$filterConditions = ["p.paragraph_active_flag = true", "v.volume_active_flag = true", "v.volume_search_flag = true"];
$filterParams = [];
if ($series !== null) {
    $filterConditions[] = "p.series_key = ?";
    $filterParams[] = $series;
}
if ($volume !== null) {
    $filterConditions[] = "p.volume_key = ?";
    $filterParams[] = $volume;
}

// --- For phrase mode, use direct ILIKE on plain text (exact substring match) ---
if ($mode === 'phrase') {
    $fuzzy = false;
    $likePattern = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $phraseConditions = array_merge(["p.paragraph_text_plain ILIKE ?"], $filterConditions);
    $phraseWhere = 'WHERE ' . implode(' AND ', $phraseConditions);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $phraseWhere");
    $countStmt->execute(array_merge([$likePattern], $filterParams));
    $total = (int)$countStmt->fetchColumn();

    $results = [];
    if ($total > 0) {
        $stmt = $pdo->prepare("
            SELECT v.volume_label, v.volume_code AS volume_code,
                   s.series_label, ch.chapter_name, ch.chapter_number,
                   p.paragraph_page AS page,
                   1.0 AS rank,
                   CASE WHEN length(p.paragraph_text_plain) > 300
                        THEN substring(p.paragraph_text_plain FROM greatest(1, position(lower(?) in lower(p.paragraph_text_plain)) - 100) FOR 300)
                        ELSE p.paragraph_text_plain
                   END AS snippet,
                   p.paragraph_text_html AS html
            FROM yy_paragraph p
            JOIN yy_volume v ON v.volume_key = p.volume_key
            JOIN yy_series s ON s.series_key = p.series_key
            LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
            $phraseWhere
            ORDER BY v.volume_sort, p.paragraph_page, p.paragraph_number
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge([$q], [$likePattern], $filterParams, [$limit, $offset]));
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
    }

    // Build flipbook_url for each hit. Returns null when the volume's flipbook
    // hasn't been built yet — frontends render those rows without a link.
    foreach ($results as &$row) {
        $row['flipbook_url'] = flipbookUrl($row['volume_code'] ?? null, $row['page'] ?? 0);
        unset($row['rank']);
    }
    unset($row);

    jsonResponse([
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => max(1, (int)ceil($total / $limit)),
        'fuzzy' => false,
        'elapsed_ms' => round((microtime(true) - $_searchStart) * 1000),
        'results' => $results,
    ]);
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
        }, $qWords));
        $tsqSql = "to_tsquery('english', ?)";
        break;
    default:
        $tsqSql = "plainto_tsquery('english', ?)";
        break;
}

// Build FTS condition including alias targets as ILIKE OR clauses
$ftsMatchConditions = ["p.paragraph_tsv @@ $tsqSql"];
$ftsMatchParams = [$tsqParam];

// Add ILIKE for each alias target
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
$results = [];

if ($total > 0) {
    $stmt = $pdo->prepare("
        SELECT v.volume_label AS volume_label,
               v.volume_code AS volume_code,
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
    $stmtParams = array_merge([$tsqParam, $tsqParam], $allParams, [$limit, $offset]);
    $stmt->execute($stmtParams);
    $results = $stmt->fetchAll();
}

// If no FTS results, try fuzzy word substitution before falling back to ILIKE
$didYouMean = null;
if (empty($results) && count($qWords) >= 1) {
    // For each query word, find the closest match in the vocabulary
    $substitutedWords = [];
    $hasSubstitution = false;
    foreach ($qWords as $w) {
        $clean = preg_replace('/[^a-zA-Z]/', '', $w);
        if (strlen($clean) < 4) {
            $substitutedWords[] = $w;
            continue;
        }
        $fuzzyStmt = $pdo->prepare("
            SELECT word, similarity(word, ?) AS sim, levenshtein(word, ?) AS lev
            FROM yy_search_word
            WHERE word % ?
              AND levenshtein(word, ?) <= 3
              AND lower(word) != lower(?)
            ORDER BY similarity(word, ?) DESC, frequency DESC
            LIMIT 1
        ");
        $fuzzyStmt->execute([$clean, $clean, $clean, $clean, $clean, $clean]);
        $match = $fuzzyStmt->fetch();
        if ($match && (float)$match['sim'] >= 0.4) {
            $substitutedWords[] = $match['word'];
            $hasSubstitution = true;
        } else {
            $substitutedWords[] = $w;
        }
    }

    if ($hasSubstitution) {
        $didYouMean = implode(' ', $substitutedWords);
        // Retry FTS search with the corrected query
        $fuzzyTsqParam = $didYouMean;
        $retryStmt = $pdo->prepare("
            SELECT v.volume_label AS volume_label,
                   v.volume_code AS volume_code,
                   s.series_label AS series_label,
                   ch.chapter_name AS chapter_name,
                   ch.chapter_number AS chapter_number,
                   p.paragraph_page AS page,
                   ts_rank(p.paragraph_tsv, plainto_tsquery('english', ?)) AS rank,
                   ts_headline('english', COALESCE(p.paragraph_text_plain, ''), plainto_tsquery('english', ?),
                       'StartSel=<mark>, StopSel=</mark>, MaxWords=40, MinWords=20, MaxFragments=2, FragmentDelimiter= ... '
                   ) AS snippet,
                   p.paragraph_text_html AS html
            FROM yy_paragraph p
            JOIN yy_volume v ON v.volume_key = p.volume_key
            JOIN yy_series s ON s.series_key = p.series_key
            LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
            WHERE p.paragraph_tsv @@ plainto_tsquery('english', ?)
              AND p.paragraph_active_flag = true AND v.volume_active_flag = true
              " . ($series !== null ? "AND p.series_key = ?" : "") . "
              " . ($volume !== null ? "AND p.volume_key = ?" : "") . "
            ORDER BY rank DESC, v.volume_sort, p.paragraph_page, p.paragraph_number
            LIMIT ? OFFSET ?
        ");
        $retryParams = [$fuzzyTsqParam, $fuzzyTsqParam, $fuzzyTsqParam];
        if ($series !== null) $retryParams[] = $series;
        if ($volume !== null) $retryParams[] = $volume;
        $retryParams[] = $limit;
        $retryParams[] = $offset;
        $retryStmt->execute($retryParams);
        $results = $retryStmt->fetchAll();

        if (!empty($results)) {
            $countParams = [$fuzzyTsqParam];
            if ($series !== null) $countParams[] = $series;
            if ($volume !== null) $countParams[] = $volume;
            $countSql = "SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key
                         WHERE p.paragraph_tsv @@ plainto_tsquery('english', ?)
                           AND p.paragraph_active_flag = true AND v.volume_active_flag = true"
                         . ($series !== null ? " AND p.series_key = ?" : "")
                         . ($volume !== null ? " AND p.volume_key = ?" : "");
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($countParams);
            $total = (int)$countStmt->fetchColumn();
        }
    }
}

// If still no results, fall back to fuzzy ILIKE search
if (empty($results)) {
    $fuzzy = true;
    $likePattern = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $fuzzyConditions = array_merge(["p.paragraph_text_plain ILIKE ?"], $filterConditions);
    $fuzzyWhere = 'WHERE ' . implode(' AND ', $fuzzyConditions);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM yy_paragraph p JOIN yy_volume v ON v.volume_key = p.volume_key $fuzzyWhere");
    $countStmt->execute(array_merge([$likePattern], $filterParams));
    $total = (int)$countStmt->fetchColumn();

    if ($total > 0) {
        $stmt = $pdo->prepare("
            SELECT v.volume_label AS volume_label,
                   v.volume_code AS volume_code,
                   s.series_label AS series_label,
                   ch.chapter_name AS chapter_name,
                   ch.chapter_number AS chapter_number,
                   p.paragraph_page AS page,
                   0.0 AS rank,
                   CASE WHEN length(p.paragraph_text_plain) > 300
                        THEN substring(p.paragraph_text_plain FROM greatest(1, position(lower(?) in lower(p.paragraph_text_plain)) - 100) FOR 300)
                        ELSE p.paragraph_text_plain
                   END AS snippet,
                   p.paragraph_text_html AS html
            FROM yy_paragraph p
            JOIN yy_volume v ON v.volume_key = p.volume_key
            JOIN yy_series s ON s.series_key = p.series_key
            LEFT JOIN yy_chapter ch ON ch.chapter_key = p.chapter_key
            $fuzzyWhere
            ORDER BY v.volume_sort, p.paragraph_page, p.paragraph_number
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge([$q], [$likePattern], $filterParams, [$limit, $offset]));
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
    }
}

// Build flipbook_url for each hit (see phrase-mode block above for rationale).
foreach ($results as &$row) {
    $row['flipbook_url'] = flipbookUrl($row['volume_code'] ?? null, $row['page'] ?? 0);
    unset($row['rank']);
}
unset($row);

jsonResponse([
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'pages' => max(1, (int)ceil($total / $limit)),
    'fuzzy' => $fuzzy,
    'did_you_mean' => $didYouMean ?? null,
    'elapsed_ms' => round((microtime(true) - $_searchStart) * 1000),
    'results' => $results,
]);
