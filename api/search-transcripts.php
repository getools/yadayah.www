<?php
// Transcript search for the prototype global search bar.
//
// Query params:
//   q          (required) search text
//   mode       all | phrase | any   (default: all)
//   group      page_key      — filter to feed_items linked to this page
//   category   category_key  — filter to feed_items linked to this category
//   page       1-based page number (default 1)
//   limit      results per page    (default 25, max 100)
//
// Returns a paginated set of transcript hits. Each hit gives the matching
// row plus the immediate-previous and immediate-next transcript rows so
// the client can render before/after context AND start a popover playback
// at the previous row's timestamp. Edge cases: if the matching row is the
// first or last in its transcript, prev / next are null.
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    errorResponse('Search query is required', 400);
}

$mode     = $_GET['mode'] ?? 'all';
$group    = isset($_GET['group'])    && $_GET['group']    !== '' ? (int)$_GET['group']    : null;
$category = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : null;
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(100, max(1, (int)($_GET['limit'] ?? 25)));
$offset   = ($page - 1) * $limit;

$queryWords = preg_split('/\s+/', $q);

// "Exact Phrase" intentionally bypasses FTS to give a literal whole-word(s)
// match — Postgres's phraseto_tsquery still stems each token, so it would
// produce the same row count as plainto for short queries. Word-boundary
// regex on the raw text gives the strict literal match users expect.
if ($mode === 'phrase') {
    $matchSql  = "t.feed_item_transcript_text ~* ?";
    $matchParm = '\m' . preg_quote($q, '/') . '\M';
    // Stub these for the SELECT below (ts_rank/ts_headline references).
    $tsqSql   = "plainto_tsquery('english', ?)";
    $tsqParam = $q;
} else {
    if ($mode === 'any') {
        $tsqSql = "to_tsquery('english', ?)";
        $tsqParam = implode(' | ', array_map(function ($w) {
            return preg_replace('/[^a-zA-Z0-9]/', '', $w);
        }, $queryWords));
    } else {
        $tsqSql = "plainto_tsquery('english', ?)";
        $tsqParam = $q;
    }
    $matchSql  = "t.feed_item_transcript_tsv @@ $tsqSql";
    $matchParm = $tsqParam;
}

$pdo = getDb();

// Inner query: match transcript rows + apply group/category filter at
// the feed_item level. We materialize match candidates with their sort
// keys so the OVER() prev/next lookup below uses the same partitioning.
$where = [$matchSql];
$params = [$matchParm];

if ($group !== null) {
    $where[] = "EXISTS (SELECT 1 FROM yy_feed_item_page fip
                        WHERE fip.feed_item_key = t.feed_item_key
                          AND fip.page_key = ?)";
    $params[] = $group;
}
if ($category !== null) {
    $where[] = "EXISTS (SELECT 1 FROM yy_feed_item_category fic
                        WHERE fic.feed_item_key = t.feed_item_key
                          AND fic.category_key = ?)";
    $params[] = $category;
}
$where[] = "fi.feed_item_active_flag = TRUE";
$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM yy_feed_item_transcript t
    JOIN yy_feed_item fi ON fi.feed_item_key = t.feed_item_key
    $whereSql
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$results = [];
if ($total > 0) {
    // For each hit we need the row immediately before and after it within
    // the same transcript (by sort, then segment). LATERAL subqueries on
    // yy_feed_item_transcript with the (feed_item_key, sort, segment)
    // index do this efficiently.
    $sql = "
        SELECT
            t.feed_item_transcript_key      AS transcript_key,
            t.feed_item_key                 AS feed_item_key,
            t.feed_item_transcript_segment  AS segment,
            t.feed_item_transcript_text     AS hit_text,
            ts_headline('english', t.feed_item_transcript_text, $tsqSql,
                'StartSel=<mark>, StopSel=</mark>, MaxFragments=1, MaxWords=80, MinWords=20, FragmentDelimiter= ... '
            )                               AS hit_snippet,
            ts_rank(t.feed_item_transcript_tsv, $tsqSql) AS rank,
            fi.feed_item_title_import       AS title,
            fi.feed_item_thumbnail          AS thumbnail,
            fi.feed_item_url                AS video_url,
            fi.feed_item_embed_id           AS embed_id,
            fi.feed_item_duration_seconds   AS duration_seconds,
            prev.feed_item_transcript_segment AS prev_segment,
            prev.feed_item_transcript_text    AS prev_text,
            next.feed_item_transcript_segment AS next_segment,
            next.feed_item_transcript_text    AS next_text
        FROM yy_feed_item_transcript t
        JOIN yy_feed_item fi ON fi.feed_item_key = t.feed_item_key
        LEFT JOIN LATERAL (
            SELECT p.feed_item_transcript_segment, p.feed_item_transcript_text
            FROM yy_feed_item_transcript p
            WHERE p.feed_item_key = t.feed_item_key
              AND (p.feed_item_transcript_sort,    p.feed_item_transcript_segment)
                < (t.feed_item_transcript_sort,    t.feed_item_transcript_segment)
            ORDER BY p.feed_item_transcript_sort DESC,
                     p.feed_item_transcript_segment DESC
            LIMIT 1
        ) prev ON TRUE
        LEFT JOIN LATERAL (
            SELECT n.feed_item_transcript_segment, n.feed_item_transcript_text
            FROM yy_feed_item_transcript n
            WHERE n.feed_item_key = t.feed_item_key
              AND (n.feed_item_transcript_sort,    n.feed_item_transcript_segment)
                > (t.feed_item_transcript_sort,    t.feed_item_transcript_segment)
            ORDER BY n.feed_item_transcript_sort ASC,
                     n.feed_item_transcript_segment ASC
            LIMIT 1
        ) next ON TRUE
        $whereSql
        ORDER BY rank DESC, t.feed_item_key, t.feed_item_transcript_sort,
                 t.feed_item_transcript_segment
        LIMIT ? OFFSET ?
    ";

    // Param order: ts_headline tsq, ts_rank tsq, WHERE params, LIMIT, OFFSET
    $stmtParams = array_merge([$tsqParam, $tsqParam], $params, [$limit, $offset]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($stmtParams);
    $rows = $stmt->fetchAll();

    // Convert PG `interval` to a {h,m,s,total_seconds,label} bag the
    // client can use for both display and player seeking.
    $intervalBag = function ($pgInterval) {
        if ($pgInterval === null) return null;
        // Postgres returns intervals as e.g. "01:23:45" or "00:05:12.4".
        // Anything > 1 day comes back as "1 day 02:03:04"; transcripts
        // are videos so this stays in HH:MM:SS territory.
        $s = trim((string)$pgInterval);
        $sec = 0.0;
        if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $s, $m)) {
            $sec = ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (float)$m[3];
        } elseif (preg_match('/^(\d+):(\d+(?:\.\d+)?)$/', $s, $m)) {
            $sec = ((int)$m[1]) * 60 + (float)$m[2];
        } else {
            // fall back: try to count seconds anywhere
            if (preg_match('/(\d+(?:\.\d+)?)\s*sec/i', $s, $m)) {
                $sec = (float)$m[1];
            }
        }
        $h = (int)floor($sec / 3600);
        $m = (int)floor(($sec % 3600) / 60);
        $sw = (int)floor($sec % 60);
        $label = $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $sw)
            : sprintf('%d:%02d', $m, $sw);
        return [
            'total_seconds' => (int)floor($sec),
            'h' => $h, 'm' => $m, 's' => $sw,
            'label' => $label,
        ];
    };

    foreach ($rows as $r) {
        $results[] = [
            'transcript_key'   => (int)$r['transcript_key'],
            'feed_item_key'    => (int)$r['feed_item_key'],
            'title'            => $r['title'],
            'thumbnail'        => $r['thumbnail'],
            'video_url'        => $r['video_url'],
            'embed_id'         => $r['embed_id'],
            'duration_seconds' => $r['duration_seconds'] !== null ? (int)$r['duration_seconds'] : null,
            'segment'          => $intervalBag($r['segment']),
            // popover playback start = previous segment if it exists,
            // otherwise the matching segment itself (handles first-row
            // edge case without throwing). Computed server-side so the
            // client can just do `seek_to: result.start_seconds`.
            'start_seconds'    => $r['prev_segment'] !== null
                ? $intervalBag($r['prev_segment'])['total_seconds']
                : $intervalBag($r['segment'])['total_seconds'],
            // Snippet is the matching row's text with <mark> already
            // applied by ts_headline. before/after rows render plain.
            'snippet_html'     => $r['hit_snippet'] ?: htmlspecialchars($r['hit_text'], ENT_QUOTES, 'UTF-8'),
            'before' => $r['prev_text'] !== null ? [
                'segment'      => $intervalBag($r['prev_segment']),
                'text'         => $r['prev_text'],
            ] : null,
            'after' => $r['next_text'] !== null ? [
                'segment'      => $intervalBag($r['next_segment']),
                'text'         => $r['next_text'],
            ] : null,
        ];
    }
}

jsonResponse([
    'total'   => $total,
    'page'    => $page,
    'limit'   => $limit,
    'pages'   => (int)ceil($total / $limit),
    'results' => $results,
]);
