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
            SELECT v.volume_label, v.volume_flip_code AS flip_code, v.volume_pdf,
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

    // Add flip_url
    foreach ($results as &$row) {
        if ($row['flip_code'] && $row['page']) {
            $row['flip_url'] = '/' . $row['flip_code'] . '/#p=' . ($row['page'] + 6);
        } else {
            $row['flip_url'] = null;
        }
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
    $stmtParams = array_merge([$tsqParam], $allParams, [$tsqParam], [$limit, $offset]);
    $stmt->execute($stmtParams);
    $results = $stmt->fetchAll();
}

// If no FTS results, fall back to fuzzy ILIKE search
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
                   v.volume_flip_code AS flip_code,
                   v.volume_pdf AS volume_pdf,
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

// Add flip_url to all results
foreach ($results as &$row) {
    if (!empty($row['flip_code']) && !empty($row['page'])) {
        $row['flip_url'] = '/' . $row['flip_code'] . '/#p=' . ($row['page'] + 6);
    } else {
        $row['flip_url'] = null;
    }
    unset($row['rank']);
}
unset($row);

jsonResponse([
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'pages' => max(1, (int)ceil($total / $limit)),
    'fuzzy' => $fuzzy,
    'elapsed_ms' => round((microtime(true) - $_searchStart) * 1000),
    'results' => $results,
]);
