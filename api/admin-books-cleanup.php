<?php
/**
 * Admin Books → Cleanup tab.
 *
 * GET ?action=search&q=…[&bold=1][&italic=1][&case=1][&volumes=N,N,…]
 *   Every place the Find text occurs in the parsed book text (yy_paragraph),
 *   as excerpts with the surrounding words. bold / italic narrow the hits to
 *   text that carries that formatting in the source: every character of the
 *   match must sit inside a <b>/<strong> (or <i>/<em>) run. Spaces and the
 *   half-rings ʾ ʿ are exempt from that check, because the parser often emits
 *   them outside the formatted run (a ring is drawn in Yada Towrah, a separate
 *   run in the DOCX).
 *
 * Unset bold/italic means "any formatting", not "must be plain".
 *
 * Candidate rows come from paragraph_text_plain (trigram-indexed); the match
 * and formatting test then run against paragraph_text_html, which is what
 * carries the <b>/<i> runs.
 */
require_once __DIR__ . '/config.php';
requireAuth();

const CLEANUP_MAX_CANDIDATES = 4000;  // paragraphs scanned per search
const CLEANUP_MAX_RESULTS    = 1000;  // excerpts returned per search
const CLEANUP_CONTEXT_CHARS  = 90;    // characters either side of a match

$action = $_GET['action'] ?? '';
if ($action !== 'search') errorResponse('Unknown action');

$q = (string)($_GET['q'] ?? '');
if (trim($q) === '') errorResponse('Enter something to find');
if (mb_strlen($q) > 500) errorResponse('Find text is too long');
$wantBold   = !empty($_GET['bold']);
$wantItalic = !empty($_GET['italic']);
$matchCase  = !empty($_GET['case']);
// Books to search (omitted = all). `volume` is the older single-book form.
$volumeKeys = array_values(array_unique(array_filter(array_map('intval',
    explode(',', (string)($_GET['volumes'] ?? $_GET['volume'] ?? ''))))));

$db = getDb();

$like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
$sql = "SELECT p.paragraph_key, p.paragraph_number, p.paragraph_page, p.paragraph_text_html,
               v.volume_key, v.volume_code, v.volume_label, v.volume_number,
               s.series_key, s.series_label, c.chapter_number, c.chapter_name
          FROM yy_paragraph p
          JOIN yy_volume v ON v.volume_key = p.volume_key
          LEFT JOIN yy_series s ON s.series_key = v.series_key
          LEFT JOIN yy_chapter c ON c.chapter_key = p.chapter_key
         WHERE p.paragraph_active_flag
           AND p.paragraph_text_plain " . ($matchCase ? 'LIKE' : 'ILIKE') . " :like"
     . ($volumeKeys ? ' AND p.volume_key IN (' . implode(',', $volumeKeys) . ')' : '') . "
         ORDER BY s.series_sort, s.series_key, v.volume_sort, v.volume_number, v.volume_key, p.paragraph_number
         LIMIT " . (CLEANUP_MAX_CANDIDATES + 1);
$stmt = $db->prepare($sql);
$stmt->bindValue(':like', $like);
$stmt->execute();
$rows = $stmt->fetchAll();

$scanTruncated = count($rows) > CLEANUP_MAX_CANDIDATES;
if ($scanTruncated) array_pop($rows);

$pattern = '/' . preg_quote($q, '/') . '/u' . ($matchCase ? '' : 'i');
$results = [];
$total = 0;            // matches that passed the formatting test
$formatRejected = 0;   // text matched but formatting did not
$slugs = [];

foreach ($rows as $r) {
    $parsed = cleanupParseHtml((string)$r['paragraph_text_html']);
    $text = $parsed['text'];
    if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;

    foreach ($m[0] as [$hit, $start]) {
        $end = $start + strlen($hit);
        if (($wantBold || $wantItalic)
            && !cleanupHasFormat($parsed['runs'], $text, $start, $end, $wantBold, $wantItalic)) {
            $formatRejected++;
            continue;
        }
        $total++;
        if (count($results) >= CLEANUP_MAX_RESULTS) continue;

        $vk = (int)$r['volume_key'];
        if (!array_key_exists($vk, $slugs)) $slugs[$vk] = cleanupBookSlug($r['volume_code']);
        $results[] = [
            'paragraph_key' => (int)$r['paragraph_key'],
            'number'        => $r['paragraph_number'] === null ? null : (int)$r['paragraph_number'],
            'page'          => $r['paragraph_page'] === null ? null : (int)$r['paragraph_page'],
            'volume_key'    => $vk,
            'volume_code'   => $r['volume_code'],
            'volume_label'  => $r['volume_label'],
            'volume_number' => $r['volume_number'] === null ? null : (int)$r['volume_number'],
            'series_key'    => $r['series_key'] === null ? null : (int)$r['series_key'],
            'series_label'  => $r['series_label'],
            'book_slug'     => $slugs[$vk],
            'chapter'       => $r['chapter_number'] === null ? null : (int)$r['chapter_number'],
            'chapter_name'  => $r['chapter_name'],
            'match'         => $hit,
            'excerpt'       => cleanupExcerpt($parsed['runs'], $text, $start, $end),
            'paragraph'     => cleanupExcerpt($parsed['runs'], $text, $start, $end, null),
        ];
    }
}

jsonResponse([
    'results'         => $results,
    'total'           => $total,
    'shown'           => count($results),
    'format_rejected' => $formatRejected,
    'scanned'         => count($rows),
    'scan_truncated'  => $scanTruncated,
]);

/**
 * Flatten paragraph HTML into its text plus formatting runs:
 * runs = [[byteStart, byteEnd, bold, italic], …] over `text`.
 */
function cleanupParseHtml(string $html): array {
    $text = '';
    $runs = [];
    $b = 0; $i = 0;
    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $part) {
        if ($part[0] === '<' && substr($part, -1) === '>') {
            if (!preg_match('#^<\s*(/?)\s*([a-zA-Z0-9]+)#', $part, $t)) continue;
            $close = $t[1] === '/';
            $tag = strtolower($t[2]);
            if ($tag === 'b' || $tag === 'strong') $b = max(0, $b + ($close ? -1 : 1));
            elseif ($tag === 'i' || $tag === 'em') $i = max(0, $i + ($close ? -1 : 1));
            elseif ($tag === 'br' && !$close) $part = ' ';
            else continue;
            if ($part !== ' ') continue;
        } else {
            $part = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $start = strlen($text);
        $text .= $part;
        $runs[] = [$start, strlen($text), $b > 0, $i > 0];
    }
    return ['text' => $text, 'runs' => $runs];
}

/** Does every formatting-relevant character in [start,end) carry the wanted format? */
function cleanupHasFormat(array $runs, string $text, int $start, int $end, bool $bold, bool $italic): bool {
    foreach ($runs as [$rs, $re, $rb, $ri]) {
        if ($re <= $start || $rs >= $end) continue;
        if ((!$bold || $rb) && (!$italic || $ri)) continue;
        $piece = substr($text, max($rs, $start), min($re, $end) - max($rs, $start));
        // Whitespace and half-rings don't count: the parser often leaves them
        // outside the formatted run.
        if (preg_replace('/[\s\x{02BE}\x{02BF}\x{02BC}]+/u', '', $piece) !== '') return false;
    }
    return true;
}

/** Back up / forward to a UTF-8 character boundary. */
function cleanupCharBoundary(string $s, int $pos, int $dir): int {
    $len = strlen($s);
    while ($pos > 0 && $pos < $len && (ord($s[$pos]) & 0xC0) === 0x80) $pos += $dir;
    return $pos;
}

/**
 * The match with $context characters either side (null = the whole
 * paragraph), as HTML that keeps the source bold/italic and wraps the match
 * in <mark>.
 */
function cleanupExcerpt(array $runs, string $text, int $start, int $end, ?int $context = CLEANUP_CONTEXT_CHARS): string {
    if ($context === null) return cleanupRunsHtml($runs, $text, 0, strlen($text), $start, $end);
    $before = substr($text, 0, $start);
    $after  = substr($text, $end);
    $ctxB = mb_substr($before, -$context);
    $ctxA = mb_substr($after, 0, $context);
    $ws = $start - strlen($ctxB);
    $we = $end + strlen($ctxA);
    // Start/end on a word boundary rather than mid-word.
    if ($ws > 0 && preg_match('/^\S*\s/u', $ctxB, $mm) && strlen($mm[0]) < strlen($ctxB)) $ws += strlen($mm[0]);
    if ($we < strlen($text) && preg_match('/\s\S*$/u', $ctxA, $mm) && strlen($mm[0]) < strlen($ctxA)) $we -= strlen($mm[0]);
    $ws = cleanupCharBoundary($text, $ws, 1);
    $we = cleanupCharBoundary($text, $we, -1);

    return ($ws > 0 ? '… ' : '') . cleanupRunsHtml($runs, $text, $ws, $we, $start, $end)
        . ($we < strlen($text) ? ' …' : '');
}

/** Runs clipped to [ws,we) as <b>/<i> HTML, with [start,end) in <mark>. */
function cleanupRunsHtml(array $runs, string $text, int $ws, int $we, int $start, int $end): string {
    $out = '';
    $cuts = [$start, $end];
    foreach ($runs as [$rs, $re, $rb, $ri]) {
        $a = max($rs, $ws); $z = min($re, $we);
        if ($a >= $z) continue;
        // Split the run at the match edges so <mark> nests cleanly.
        $points = [$a];
        foreach ($cuts as $c) if ($c > $a && $c < $z) $points[] = $c;
        $points[] = $z;
        for ($k = 0; $k < count($points) - 1; $k++) {
            $p0 = $points[$k]; $p1 = $points[$k + 1];
            $seg = htmlspecialchars(substr($text, $p0, $p1 - $p0), ENT_QUOTES, 'UTF-8');
            if ($ri) $seg = '<i>' . $seg . '</i>';
            if ($rb) $seg = '<b>' . $seg . '</b>';
            if ($p0 === $start) $out .= '<mark>';
            $out .= $seg;
            if ($p1 === $end) $out .= '</mark>';
        }
    }
    return $out;
}

/** Public flipbook slug (same rule as admin-glossary-lexicon.php occBookSlug). */
function cleanupBookSlug(?string $volumeCode): ?string {
    if (!$volumeCode) return null;
    $slug = preg_replace("/[\u{0027}\u{2018}\u{2019}\u{02BC}]/u", '', $volumeCode);
    if ($slug === '') return null;
    $root = is_dir('/var/www/html') ? '/var/www/html' : dirname(__DIR__) . '/public';
    return is_dir($root . '/' . $slug . '/text') ? $slug : null;
}
