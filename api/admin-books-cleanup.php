<?php
/**
 * Admin Books → Cleanup tab: Search/Replace across the parsed book text, with
 * replacements made in the Word DOCX too and queued until an admin reprocesses.
 *
 * GET  ?action=search&q=…[&bold=1][&italic=1][&case=1][&volumes=N,N,…]
 *        Match counts per book — every match, no cap. bold / italic narrow the
 *        hits to text carrying that formatting: every character of the match
 *        must sit inside a <b>/<strong> (or <i>/<em>) run. Spaces and the
 *        half-rings ʾ ʿ are exempt (the parser emits them outside the run).
 *        Unset bold/italic means "any formatting", not "must be plain".
 * GET  ?action=matches&volume=N&…same filters…[&after=PN][&limit=300]
 *        One book's matches with excerpts, a page at a time, paged by
 *        paragraph number so replacements made meanwhile can't shift the page.
 * POST ?action=replace  — see cleanupReplace().
 * GET  ?action=pending[&volume=N]   — queued (not yet reprocessed) changes.
 * POST ?action=reprocess {volume_keys}  /  ?action=discard {volume_keys}
 * GET  ?action=history[&status=pending,processed,discarded][&volume=N][&q=…][&offset=N]
 *        Every Replace batch ever made, newest first, queued or committed (or
 *        discarded); &batch=ID lists one batch's changes. See cleanupHistory().
 * GET  ?action=word_meta / ?action=words — the glossary word picker; see cleanupWords().
 *
 * Candidate rows come from paragraph_text_plain (trigram-indexed); the match
 * and formatting test then run against paragraph_text_html, which is what
 * carries the <b>/<i> runs.
 */
require_once __DIR__ . '/config.php';
$authUser = requireAuth();

const CLEANUP_PAGE_MATCHES   = 300;   // matches per page when a book is expanded
const CLEANUP_CONTEXT_CHARS  = 90;    // characters either side of a match
// Fonts whose letters are glyph art (YT, Paleo…): never wrap replacement letters in them.
const CLEANUP_GLYPH_FONTS = ['Yada Towrah', 'PictoHeb', 'Isaiah Scroll', 'Moabite Stone', 'Semitic Early', 'Hebrew Script'];
// Glossary word picker (cleanupWords). Top-level consts must come before the
// action routing below, which calls the functions that use them.
const CLEANUP_WORD_BASE_SQL = "coalesce(nullif(trim(w.word_code), ''), nullif(trim(w.word_hebrew), ''))";
const CLEANUP_WORD_SCRIPT_SQL = "CASE
        WHEN coalesce(nullif(trim(w.word_code), ''), nullif(trim(w.word_hebrew), '')) IS NULL THEN 'none'
        WHEN coalesce(nullif(trim(w.word_code), ''), w.word_hebrew) ~ '[\\u0590-\\u05FF]' THEN 'hebrew'
        WHEN coalesce(nullif(trim(w.word_code), ''), w.word_hebrew) ~ '[\\u0700-\\u074F]' THEN 'syriac'
        WHEN coalesce(nullif(trim(w.word_code), ''), w.word_hebrew) ~ '[\\u0370-\\u03FF\\u1F00-\\u1FFF]' THEN 'greek'
        WHEN coalesce(nullif(trim(w.word_code), ''), w.word_hebrew) ~ '[\\u0600-\\u06FF]' THEN 'arabic'
        WHEN coalesce(nullif(trim(w.word_code), ''), w.word_hebrew) ~ '[A-Za-z]' THEN 'latin'
        ELSE 'other' END";
// Find wildcard: ❜ (U+275C, the page's "any apostrophe" key) matches any one
// half-ring or apostrophe-like mark. Keep in step with APOS_CLASS in
// _docx_replace.py so Replace finds exactly what Search found.
// Per book, search reports up to this many distinct (match text, format) kinds.
const CLEANUP_KINDS_MAX = 300;
const CLEANUP_APOS_WILDCARD = "\u{275C}";
const CLEANUP_APOS_CLASS = "[\u{02BF}\u{02BE}'`\u{2018}\u{2019}\u{201A}\u{201B}\u{00B4}\u{02BC}\u{02BB}\u{02B9}\u{2032}]";
const CLEANUP_WORD_SCRIPTS = [
    'hebrew' => 'Hebrew / Aramaic (square script)', 'syriac' => 'Syriac (Aramaic)', 'greek' => 'Greek',
    'arabic' => 'Arabic', 'latin' => 'Latin letters', 'other' => 'Other', 'none' => 'No base spelling',
];

$action = $_GET['action'] ?? '';
if ($action === 'replace')   cleanupReplace($authUser);      // each responds and exits
if ($action === 'pending')   cleanupPending();
if ($action === 'reprocess') cleanupReprocess($authUser);
if ($action === 'discard')   cleanupDiscard($authUser);
if ($action === 'history')   cleanupHistory();
if ($action === 'word_meta') cleanupWordMeta();
if ($action === 'words')     cleanupWords();
if ($action !== 'search' && $action !== 'matches') errorResponse('Unknown action');

$q = (string)($_GET['q'] ?? '');
if (trim($q) === '') errorResponse('Enter something to find');
if (mb_strlen($q) > 500) errorResponse('Find text is too long');
$wantBold   = !empty($_GET['bold']);
$wantItalic = !empty($_GET['italic']);
$matchCase  = !empty($_GET['case']);
$wholeWord  = !empty($_GET['word']);
$pattern = cleanupFindPattern($q, $matchCase, $wholeWord);
$like = cleanupFindLike($q);
$op = $matchCase ? 'LIKE' : 'ILIKE';
$db = getDb();

if ($action === 'matches') {
    $vk = (int)($_GET['volume'] ?? 0);
    if (!$vk) errorResponse('volume required');
    $after = (int)($_GET['after'] ?? -1);
    $limit = max(50, min(2000, (int)($_GET['limit'] ?? CLEANUP_PAGE_MATCHES)));
    $st = $db->prepare("SELECT p.paragraph_key, p.paragraph_number, p.paragraph_page, p.paragraph_text_html,
                               v.volume_code, c.chapter_number, c.chapter_name
                          FROM yy_paragraph p
                          JOIN yy_volume v ON v.volume_key = p.volume_key
                          LEFT JOIN yy_chapter c ON c.chapter_key = p.chapter_key
                         WHERE p.volume_key = ? AND p.paragraph_active_flag AND p.paragraph_number > ?
                           AND p.paragraph_text_plain $op ?
                         ORDER BY p.paragraph_number");
    $st->execute([$vk, $after, $like]);
    $items = [];
    $lastPn = $after;
    $more = false;
    $slug = null; $slugDone = false;
    while ($r = $st->fetch()) {
        if (count($items) >= $limit) { $more = true; break; }   // stop on a paragraph boundary
        $parsed = cleanupParseHtml((string)$r['paragraph_text_html']);
        $text = $parsed['text'];
        $lastPn = (int)$r['paragraph_number'];
        if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;
        if (!$slugDone) { $slug = cleanupBookSlug($r['volume_code']); $slugDone = true; }
        foreach ($m[0] as [$hit, $start]) {
            $end = $start + strlen($hit);
            if (($wantBold || $wantItalic) && !cleanupHasFormat($parsed['runs'], $text, $start, $end, $wantBold, $wantItalic)) continue;
            $items[] = [
                'paragraph_key' => (int)$r['paragraph_key'],
                'number'        => (int)$r['paragraph_number'],
                'page'          => $r['paragraph_page'] === null ? null : (int)$r['paragraph_page'],
                'volume_key'    => $vk,
                'book_slug'     => $slug,
                'chapter'       => $r['chapter_number'] === null ? null : (int)$r['chapter_number'],
                'chapter_name'  => $r['chapter_name'],
                'match'         => $hit,
                'start'         => $start,
                // 'b' / 'i' / 'bi' / '' when the whole match has one format, null when mixed
                // (the page uses it to spot replacements that would change nothing).
                'fmt'           => cleanupMatchFormat($parsed['runs'], $text, $start, $end),
                'excerpt'       => cleanupExcerpt($parsed['runs'], $text, $start, $end),
                'paragraph'     => cleanupExcerpt($parsed['runs'], $text, $start, $end, null),
            ];
        }
    }
    jsonResponse(['items' => $items, 'after' => $lastPn, 'more' => $more]);
}

// action=search: counts per book over every candidate paragraph. A cursor
// keeps memory flat however common the term is.
$volumeKeys = array_values(array_unique(array_filter(array_map('intval',
    explode(',', (string)($_GET['volumes'] ?? $_GET['volume'] ?? ''))))));
$db->beginTransaction();
$db->prepare("DECLARE cl_cur NO SCROLL CURSOR FOR
              SELECT p.volume_key, p.paragraph_text_html
                FROM yy_paragraph p
               WHERE p.paragraph_active_flag AND p.paragraph_text_plain $op " . $db->quote($like)
           . ($volumeKeys ? ' AND p.volume_key IN (' . implode(',', $volumeKeys) . ')' : ''))->execute();
$counts = [];
$kinds = [];       // vk → [match \0 fmt → n], or false past CLEANUP_KINDS_MAX
$total = 0;
$formatRejected = 0;
$scanned = 0;
while (true) {
    $rows = $db->query('FETCH 2000 FROM cl_cur')->fetchAll();
    if (!$rows) break;
    foreach ($rows as $r) {
        $scanned++;
        $parsed = cleanupParseHtml((string)$r['paragraph_text_html']);
        $text = $parsed['text'];
        if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as [$hit, $start]) {
            if (($wantBold || $wantItalic)
                && !cleanupHasFormat($parsed['runs'], $text, $start, $start + strlen($hit), $wantBold, $wantItalic)) {
                $formatRejected++;
                continue;
            }
            $total++;
            $vk = (int)$r['volume_key'];
            $counts[$vk] = ($counts[$vk] ?? 0) + 1;
            // How many of each (exact text, format): lets the page tell which
            // matches a Replace would leave unchanged without loading them.
            if (isset($kinds[$vk]) && $kinds[$vk] === false) continue;
            $fmt = cleanupMatchFormat($parsed['runs'], $text, $start, $start + strlen($hit));
            $kk = $hit . "\x00" . ($fmt ?? '~');
            if (!isset($kinds[$vk][$kk]) && count($kinds[$vk] ?? []) >= CLEANUP_KINDS_MAX) { $kinds[$vk] = false; continue; }
            $kinds[$vk][$kk] = ($kinds[$vk][$kk] ?? 0) + 1;
        }
    }
}
$db->exec('CLOSE cl_cur');
$db->commit();

$volumes = [];
if ($counts) {
    $st = $db->query('SELECT v.volume_key, v.volume_code, v.volume_label, v.volume_number,
                             s.series_key, s.series_label
                        FROM yy_volume v LEFT JOIN yy_series s ON s.series_key = v.series_key
                       WHERE v.volume_key IN (' . implode(',', array_keys($counts)) . ')
                       ORDER BY s.series_sort, s.series_key, v.volume_sort, v.volume_number, v.volume_key');
    foreach ($st->fetchAll() as $v) {
        $volumes[] = [
            'volume_key'    => (int)$v['volume_key'],
            'volume_code'   => $v['volume_code'],
            'volume_label'  => $v['volume_label'],
            'volume_number' => $v['volume_number'] === null ? null : (int)$v['volume_number'],
            'series_key'    => $v['series_key'] === null ? null : (int)$v['series_key'],
            'series_label'  => $v['series_label'],
            'count'         => $counts[(int)$v['volume_key']],
            // [[match, fmt ('b'/'i'/'bi'/'' or null = mixed), n], …]; null when too varied
            'kinds'         => empty($kinds[(int)$v['volume_key']]) ? null : array_map(function ($kk, $n) {
                [$hit, $fmt] = explode("\x00", $kk, 2);
                return [$hit, $fmt === '~' ? null : $fmt, $n];
            }, array_keys($kinds[(int)$v['volume_key']]), $kinds[(int)$v['volume_key']]),
        ];
    }
}
jsonResponse(['total' => $total, 'format_rejected' => $formatRejected, 'scanned' => $scanned, 'volumes' => $volumes]);

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

/**
 * The one format of [start,end) as 'b' / 'i' / 'bi' / '', or null when mixed.
 * Whitespace and half-rings don't count, as in cleanupHasFormat().
 */
function cleanupMatchFormat(array $runs, string $text, int $start, int $end, bool $first = false): ?string {
    $fmt = null;
    foreach ($runs as [$rs, $re, $rb, $ri]) {
        if ($re <= $start || $rs >= $end) continue;
        $piece = substr($text, max($rs, $start), min($re, $end) - max($rs, $start));
        if (preg_replace('/[\s\x{02BE}\x{02BF}\x{02BC}]+/u', '', $piece) === '') continue;
        $f = ($rb ? 'b' : '') . ($ri ? 'i' : '');
        if ($first) return $f;   // mixed: the first run's format
        if ($fmt !== null && $fmt !== $f) return null;
        $fmt = $f;
    }
    return $fmt ?? '';
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

/* ── Replace + the queue ─────────────────────────────────────────────────
 *
 * POST ?action=replace  JSON body:
 *   {mode: 'next'|'all'|'some', volume_key, q, bold, italic, case, word,
 *    replace, rbold, ritalic,
 *    target: {paragraph_key, start, match}        — next: that one match
 *    targets: [{paragraph_key, start, match}, …]  — some: just these
 *    exclude: [{paragraph_key, start, match}, …]  — all: every match but these
 *    apos: [{paragraph_key, start, match, apos}, …]} — the ʿ/ʾ picked per ❜ in
 *          replace, per loaded match; others get cleanupAposDefault()
 * The page sends 'all' (optionally with exclude) or 'some', per its checkboxes.
 *
 * One volume per request (Cloudflare cuts a request off at ~100s), so the
 * page walks Replace All book by book.
 *
 * Every edit lands in BOTH places, or in neither:
 *   1. a STAGED copy of the book's Word DOCX, u/books-word-staged/<docx>,
 *      made from the live DOCX on the first change. _docx_replace.py lines
 *      each parsed match up with its spot in the DOCX by the text around it.
 *      The live DOCX is not touched, so nothing rebuilds yet.
 *   2. yy_paragraph (html, plain, raw), so search and the reader show the
 *      change at once. yy_cleanup_change logs each change with the paragraph
 *      before and after, which is what Discard puts back.
 * A match the DOCX side cannot place is reported and left alone in both.
 *
 * Reprocess (per book, when the admin says so) backs up the live DOCX, moves
 * the staged one into place and queues the book exactly like a DOCX upload:
 * PDF, flipbook, re-parse. Discard drops the staged DOCX and restores the
 * paragraphs.
 *
 * <docx>.json beside the staged file records the live DOCX's md5 when staging
 * began. If the live DOCX changes after that (a new upload), the staged copy
 * is stale: replace and reprocess refuse until it is discarded.
 */
function cleanupPaths(array $vol): array {
    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : dirname(__DIR__) . '/public';
    $docxName = $vol['volume_docx'] ?: ($vol['volume_code'] ? $vol['volume_code'] . '.docx' : '');
    return [
        'root'    => $publicRoot,
        'name'    => $docxName,
        'live'    => $publicRoot . '/u/books-word/' . $docxName,
        'staged'  => $publicRoot . '/u/books-word-staged/' . $docxName,
        'sidecar' => $publicRoot . '/u/books-word-staged/' . $docxName . '.json',
        'lock'    => $publicRoot . '/u/books-word/.cleanup-' . (int)$vol['volume_key'] . '.lock',
    ];
}

function cleanupVolume(PDO $db, int $vk): array {
    $st = $db->prepare('SELECT v.volume_key, v.volume_code, v.volume_docx, v.volume_pdf, v.volume_label,
                               v.volume_locked_flag, v.volume_locked_by_key, v.volume_locked_by_name
                          FROM yy_volume v WHERE v.volume_key = ?');
    $st->execute([$vk]);
    $vol = $st->fetch();
    if (!$vol) errorResponse('Volume not found', 404);
    $vol['label'] = $vol['volume_code'] ?: $vol['volume_label'];
    return $vol;
}

function cleanupCheckLock(array $vol, array $authUser): void {
    if ($vol['volume_locked_flag'] && (int)$vol['volume_locked_by_key'] !== (int)($authUser['user_key'] ?? 0)) {
        errorResponse($vol['label'] . ' is checked out by ' . ($vol['volume_locked_by_name'] ?: 'another admin')
            . ' — ask them to release the lock first.', 423);
    }
}

/** 'none' | 'ok' | 'stale' — is there a staged DOCX, and is it built on the current live one? */
function cleanupStageState(array $paths): string {
    if (!is_file($paths['staged'])) return 'none';
    $meta = json_decode((string)@file_get_contents($paths['sidecar']), true) ?: [];
    return (($meta['base_md5'] ?? '') === md5_file($paths['live'])) ? 'ok' : 'stale';
}

function cleanupReplace(array $authUser): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required', 405);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? '';
    if (!in_array($mode, ['next', 'all', 'some'], true)) errorResponse('mode must be next, all or some');
    $q = (string)($in['q'] ?? '');
    if (trim($q) === '') errorResponse('Enter something to find');
    $replace = (string)($in['replace'] ?? '');
    if (mb_strlen($replace) > 500) errorResponse('Replace text is too long');
    $wantBold = !empty($in['bold']); $wantItalic = !empty($in['italic']);
    $matchCase = !empty($in['case']);
    $wholeWord = !empty($in['word']);
    $rBold = !empty($in['rbold']); $rItalic = !empty($in['ritalic']);
    // Find and Replace set the same B / I: only the text changes, each match
    // keeps its formatting. Otherwise every replacement gets Replace's B / I.
    $keepFmt = $wantBold === $rBold && $wantItalic === $rItalic;
    $rFmt = ($rBold ? 'b' : '') . ($rItalic ? 'i' : '');
    $vk = (int)($in['volume_key'] ?? 0);
    if (!$vk) errorResponse('volume_key required');

    $db = getDb();
    $vol = cleanupVolume($db, $vk);
    $label = $vol['label'];
    cleanupCheckLock($vol, $authUser);
    $paths = cleanupPaths($vol);
    if (!$paths['name'] || !is_file($paths['live'])) errorResponse($label . ': no DOCX on the server to edit');

    // One replace per book at a time (Replace Next clicked quickly, two admins).
    $lock = fopen($paths['lock'], 'c');
    if (!$lock || !flock($lock, LOCK_EX)) errorResponse('Could not lock ' . $label);
    $stage = cleanupStageState($paths);
    if ($stage === 'stale') {
        errorResponse($label . ': a new DOCX was uploaded after the queued changes were made. '
            . 'Discard this book\'s queued changes first.', 409);
    }

    // The whole volume's parsed text, in reading order: the stream gives each
    // match its surrounding text for lining it up with the DOCX.
    $st = $db->prepare('SELECT paragraph_key, paragraph_number, paragraph_text_html, paragraph_text_plain, paragraph_text_raw
                          FROM yy_paragraph WHERE volume_key = ? AND paragraph_active_flag ORDER BY paragraph_number');
    $st->execute([$vk]);
    $pattern = cleanupFindPattern($q, $matchCase, $wholeWord);
    $stream = '';
    $dbList = [];      // every text match, for alignment
    $meta = [];        // id → [paragraph_key, start, hit]
    $paraRow = [];
    $target = $in['target'] ?? null;
    $targetFound = false;
    // mode 'some': only these matches; mode 'all' + exclude: all but these.
    // Each is {paragraph_key, start, match}, as the search listed it.
    $keyed = function ($list) {
        $out = [];
        foreach ((array)$list as $t) {
            if (is_array($t)) $out[(int)($t['paragraph_key'] ?? 0) . ':' . (int)($t['start'] ?? -1)] = (string)($t['match'] ?? '');
        }
        return $out;
    };
    $targets = $keyed($in['targets'] ?? []);
    $exclude = $keyed($in['exclude'] ?? []);
    // ❜ in Replace: the half ring picked on each loaded row, {.., apos: 'ʿʾ'}.
    // Matches without a pick get cleanupAposDefault().
    $aposPick = [];
    foreach ((array)($in['apos'] ?? []) as $t) {
        if (is_array($t)) $aposPick[(int)($t['paragraph_key'] ?? 0) . ':' . (int)($t['start'] ?? -1)]
            = [(string)($t['match'] ?? ''), (string)($t['apos'] ?? '')];
    }
    $replFor = function (string $id, string $hit) use ($replace, $aposPick): string {
        $pick = isset($aposPick[$id]) && $aposPick[$id][0] === $hit ? $aposPick[$id][1] : '';
        return cleanupAposResolve($replace, $hit, $pick);
    };
    $targetsFound = 0;
    $unchanged = 0;    // chosen matches the replacement would leave exactly as they are
    while ($r = $st->fetch()) {
        $pk = (int)$r['paragraph_key'];
        $parsed = cleanupParseHtml((string)$r['paragraph_text_html']);
        $text = $parsed['text'];
        $base = strlen($stream);
        $stream .= $text . ' ';
        if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;
        $paraRow[$pk] = $r;
        foreach ($m[0] as [$hit, $start]) {
            $end = $start + strlen($hit);
            $id = $pk . ':' . $start;
            if ($mode === 'next') {
                $apply = $target && (int)$target['paragraph_key'] === $pk
                    && (int)$target['start'] === $start && (string)$target['match'] === $hit;
                if ($apply) $targetFound = true;
            } elseif ($mode === 'some') {
                $apply = isset($targets[$id]) && $targets[$id] === $hit;
                if ($apply) $targetsFound++;
            } else {
                $apply = (!($wantBold || $wantItalic)
                        || cleanupHasFormat($parsed['runs'], $text, $start, $end, $wantBold, $wantItalic))
                    && !(isset($exclude[$id]) && $exclude[$id] === $hit);
            }
            $rep = $apply ? $replFor($id, $hit) : $replace;
            // The format the replacement gets: Replace's B / I when they differ
            // from Find's, else the match keeps its own (first run's if mixed).
            $fmt = cleanupMatchFormat($parsed['runs'], $text, $start, $end);
            $eff = $keepFmt ? ($fmt ?? cleanupMatchFormat($parsed['runs'], $text, $start, $end, true)) : $rFmt;
            // Same text, same format: nothing would change, so no DB or DOCX edit.
            if ($apply && $rep === $hit && ($keepFmt || $fmt === $rFmt)) {
                $apply = false;
                $unchanged++;
            }
            $effB = strpos($eff, 'b') !== false; $effI = strpos($eff, 'i') !== false;
            $meta[$id] = [$pk, $start, $hit, $rep, $effB, $effI];
            $d = ['id' => $id, 'apply' => $apply, 'pos' => $base + $start, 'end' => $base + $end];
            if ($rep !== $replace) $d['replace'] = $rep;
            if ($effB !== $rBold || $effI !== $rItalic) { $d['bold'] = $effB; $d['italic'] = $effI; }
            $dbList[] = $d;
        }
    }
    if ($mode === 'next' && !$targetFound) {
        errorResponse('That match is no longer in the text — search again.', 409);
    }
    $toApply = count(array_filter($dbList, function ($d) { return $d['apply']; }));
    // Ticked matches the text no longer has (changed since the search).
    $missing = $mode === 'some' ? count($targets) - $targetsFound : 0;
    if (!$toApply) jsonResponse(['volume_key' => $vk, 'replaced' => 0, 'unmapped' => 0, 'missing' => $missing, 'failed' => [], 'items' => [],
                                 'unchanged' => $unchanged]);
    foreach ($dbList as &$d) {
        $d['before'] = cleanupUtf8Clean(substr($stream, max(0, $d['pos'] - 600), $d['pos'] - max(0, $d['pos'] - 600)));
        $d['after']  = cleanupUtf8Clean(substr($stream, $d['end'], 600));
        unset($d['pos'], $d['end']);
    }
    unset($d);
    $stream = '';

    $stageDir = dirname($paths['staged']);
    if (!is_dir($stageDir)) @mkdir($stageDir, 0775, true);
    $tmpOut = $stageDir . '/.' . $paths['name'] . '.cleanup-' . getmypid() . '.tmp';
    $res = cleanupRunDocxScript([
        'docx_in' => $stage === 'ok' ? $paths['staged'] : $paths['live'], 'docx_out' => $tmpOut,
        'find' => $q, 'case' => $matchCase, 'whole' => $wholeWord,
        'replace' => $replace, 'bold' => $rBold, 'italic' => $rItalic,
        'db' => $dbList,
    ]);
    if (empty($res['ok'])) {
        @unlink($tmpOut);
        errorResponse($label . ': DOCX edit failed — ' . ($res['error'] ?? 'no output'), 500);
    }
    $applied = $res['applied'] ?? [];
    $items = [];
    if ($applied) {
        // 1. Every paragraph's new text, before touching anything.
        $byPara = [];
        foreach ($applied as $id) {
            if (isset($meta[$id])) $byPara[$meta[$id][0]][] = $meta[$id];
        }
        $rows = [];       // pk → [html, plain, raw]
        $logs = [];       // change rows
        try {
            foreach ($byPara as $pk => $list) {
                $old = $paraRow[$pk];
                $oldParsed = cleanupParseHtml((string)$old['paragraph_text_html']);
                usort($list, function ($a, $b) { return $b[1] - $a[1]; });   // last match first
                $html = (string)$old['paragraph_text_html'];
                foreach ($list as [, $start, $hit, $rep, $effB, $effI]) {
                    $html = cleanupHtmlReplace($html, $start, $start + strlen($hit),
                        cleanupReplacementSegments($rep, $effB, $effI));
                }
                $parsed = cleanupParseHtml($html);
                $rows[$pk] = [$html, trim(preg_replace('/\s+/u', ' ', $parsed['text'])), cleanupRawJson($html)];
                // Where each replacement sits in the new text: earlier ones
                // shift the later ones by their length change.
                $list = array_reverse($list);
                $shift = 0;
                foreach ($list as [, $start, $hit, $rep]) {
                    $ns = $start + $shift;
                    $ne = $ns + strlen($rep);
                    $shift += strlen($rep) - strlen($hit);
                    $logs[] = [$pk, (int)$old['paragraph_number'], $hit, $rep, $old, $html,
                        cleanupExcerpt($oldParsed['runs'], $oldParsed['text'], $start, $start + strlen($hit)),
                        cleanupExcerpt($parsed['runs'], $parsed['text'], $ns, $ne)];
                    if ($mode === 'next') {
                        $items[] = [
                            'paragraph_key' => $pk, 'start' => $start, 'delta' => strlen($rep) - strlen($hit),
                            'excerpt'   => cleanupExcerpt($parsed['runs'], $parsed['text'], $ns, $ne),
                            'paragraph' => cleanupExcerpt($parsed['runs'], $parsed['text'], $ns, $ne, null),
                        ];
                    }
                }
            }
        } catch (\Throwable $ex) {
            @unlink($tmpOut);
            errorResponse($label . ': could not edit the paragraph text (' . $ex->getMessage() . ') — nothing was changed', 500);
        }

        // 2. The new DOCX becomes the staged copy (the previous staged copy is
        //    kept aside until the DB has committed).
        $prev = null;
        if ($stage === 'ok') {
            $prev = $paths['staged'] . '.prev';
            @rename($paths['staged'], $prev);
        } else {
            @file_put_contents($paths['sidecar'], json_encode([
                'base_md5' => md5_file($paths['live']), 'staged_at' => date('c'), 'volume_key' => $vk,
            ]));
        }
        @chmod($tmpOut, 0644);
        if (!@rename($tmpOut, $paths['staged'])) {
            @unlink($tmpOut);
            if ($prev) @rename($prev, $paths['staged']); else @unlink($paths['sidecar']);
            errorResponse($label . ': could not write the staged DOCX — nothing was changed', 500);
        }

        // 3. Paragraphs + change log in one transaction; on failure undo step 2.
        try {
            $batch = bin2hex(random_bytes(8));
            $opts = json_encode(['mode' => $mode, 'bold' => $wantBold, 'italic' => $wantItalic, 'case' => $matchCase, 'word' => $wholeWord,
                                 'rbold' => $rBold, 'ritalic' => $rItalic, 'replace' => $replace,
                                 // the page's id for one Replace click across books (Replace History groups by it)
                                 'run' => substr(preg_replace('/[^a-z0-9]/', '', (string)($in['run'] ?? '')), 0, 40) ?: null]);
            $db->beginTransaction();
            $up = $db->prepare('UPDATE yy_paragraph SET paragraph_text_html = ?, paragraph_text_plain = ?,
                                       paragraph_text_raw = ?, paragraph_revision_user_key = ?
                                 WHERE paragraph_key = ?');
            foreach ($rows as $pk => [$html, $plain, $raw]) {
                $up->execute([$html, $plain, $raw, (int)($authUser['user_key'] ?? 0), $pk]);
            }
            $ins = $db->prepare('INSERT INTO yy_cleanup_change
                (cleanup_change_batch, volume_key, paragraph_key, paragraph_number, cleanup_change_find,
                 cleanup_change_replace, cleanup_change_options, cleanup_change_match,
                 cleanup_change_before_html, cleanup_change_before_plain, cleanup_change_before_raw,
                 cleanup_change_after_html, cleanup_change_excerpt_before, cleanup_change_excerpt_after,
                 cleanup_change_user_key, cleanup_change_user_name)
                VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($logs as [$pk, $pn, $hit, $rep, $old, $newHtml, $exBefore, $exAfter]) {
                $ins->execute([$batch, $vk, $pk, $pn, $q, $rep, $opts, $hit,
                    $old['paragraph_text_html'], $old['paragraph_text_plain'], $old['paragraph_text_raw'],
                    $newHtml, $exBefore, $exAfter,
                    (int)($authUser['user_key'] ?? 0), (string)($authUser['user_name'] ?? '')]);
            }
            $db->commit();
        } catch (\Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            if ($prev) { @rename($prev, $paths['staged']); }
            else { @unlink($paths['staged']); @unlink($paths['sidecar']); }
            logMonitorEvent('cleanup_replace', 'error', $label . ': DB update failed — ' . $ex->getMessage(),
                $ex->getFile() . ':' . $ex->getLine());
            errorResponse($label . ': saving the paragraph text failed (' . $ex->getMessage() . ') — nothing was changed', 500);
        }
        if ($prev) @unlink($prev);
    } else {
        @unlink($tmpOut);
    }
    flock($lock, LOCK_UN);
    fclose($lock);

    jsonResponse([
        'volume_key'   => $vk,
        'replaced'     => count($applied),
        'unmapped'     => count($res['unmapped'] ?? []),
        'failed'       => array_values($res['failed'] ?? []),
        'docx_matches' => $res['docx_matches'] ?? null,
        'items'        => $items,
        'missing'      => $missing,
        'unchanged'    => $unchanged,
    ]);
}

/**
 * Replace history: one row per batch (one Replace click in one book), newest
 * first. status = pending (queued) | processed (committed) | discarded; every
 * change in a batch shares it, since commit / discard act on a whole book.
 * Filters: status (comma list, default pending,processed), volume, q (in the
 * Find or Replace text). ?batch=ID returns that batch's changes instead.
 */
function cleanupHistory(): void {
    $db = getDb();
    $batch = (string)($_GET['batch'] ?? '');
    if ($batch !== '') {
        $st = $db->prepare("SELECT cleanup_change_key, paragraph_number, cleanup_change_match, cleanup_change_replace,
                                   cleanup_change_excerpt_before, cleanup_change_excerpt_after, cleanup_change_status
                              FROM yy_cleanup_change WHERE cleanup_change_batch = ? ORDER BY cleanup_change_key");
        $st->execute([$batch]);
        jsonResponse(['changes' => $st->fetchAll()]);
    }
    $statuses = array_values(array_intersect(['pending', 'processed', 'discarded'],
        explode(',', (string)($_GET['status'] ?? 'pending,processed'))));
    if (!$statuses) $statuses = ['pending', 'processed'];
    $where = ['c.cleanup_change_status IN (' . implode(',', array_map([$db, 'quote'], $statuses)) . ')'];
    $args = [];
    if ((int)($_GET['volume'] ?? 0)) { $where[] = 'c.volume_key = ?'; $args[] = (int)$_GET['volume']; }
    $user = (string)($_GET['user'] ?? '');
    if ($user !== '') { $where[] = 'c.cleanup_change_user_name = ?'; $args[] = $user; }
    // When: from (inclusive) / to (exclusive) instants; the page sends local
    // midnights. Blank = unbounded.
    foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
        $v = trim((string)($_GET[$k] ?? ''));
        if ($v === '') continue;
        if (strtotime($v) === false) errorResponse("Bad $k date");
        $where[] = "c.cleanup_change_dtime $op ?::timestamptz";
        $args[] = $v;
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(c.cleanup_change_find ILIKE ? OR c.cleanup_change_replace ILIKE ? OR c.cleanup_change_options->>'replace' ILIKE ?)";
        $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        array_push($args, $like, $like, $like);
    }
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = 5000;   // the page groups the batches (by run or by book) itself
    $st = $db->prepare("SELECT c.cleanup_change_batch AS batch, c.volume_key, min(c.cleanup_change_find) AS find,
                               min(c.cleanup_change_options::text) AS options, count(*) AS n,
                               min(c.cleanup_change_status) AS status, min(c.cleanup_change_dtime) AS dtime,
                               max(c.cleanup_change_done_dtime) AS done_dtime, min(c.cleanup_change_user_name) AS user_name,
                               array_to_json(array_agg(DISTINCT c.cleanup_change_replace)) AS replaced,
                               v.volume_code, v.volume_label, v.volume_number, s.series_label,
                               count(*) OVER () AS total
                          FROM yy_cleanup_change c
                          JOIN yy_volume v ON v.volume_key = c.volume_key
                          LEFT JOIN yy_series s ON s.series_key = v.series_key
                         WHERE " . implode(' AND ', $where) . "
                         GROUP BY c.cleanup_change_batch, c.volume_key, v.volume_code, v.volume_label, v.volume_number, s.series_label
                         ORDER BY min(c.cleanup_change_key) DESC
                         LIMIT $limit OFFSET $offset");
    $st->execute($args);
    $out = [];
    $total = 0;
    foreach ($st->fetchAll() as $b) {
        $total = (int)$b['total'];
        $o = json_decode((string)$b['options'], true) ?: [];
        $out[] = [
            'batch' => $b['batch'], 'volume_key' => (int)$b['volume_key'],
            'volume_code' => $b['volume_code'], 'volume_label' => $b['volume_label'],
            'volume_number' => $b['volume_number'] === null ? null : (int)$b['volume_number'],
            'series_label' => $b['series_label'],
            'find' => $b['find'], 'replace' => $o['replace'] ?? null,
            'replaced' => json_decode((string)$b['replaced'], true) ?: [], 'options' => $o,
            'count' => (int)$b['n'], 'status' => $b['status'],
            'dtime' => $b['dtime'], 'done_dtime' => $b['done_dtime'], 'user_name' => $b['user_name'],
        ];
    }
    // Books with any history, for the filter.
    $vols = $db->query("SELECT DISTINCT v.volume_key, v.volume_code, v.volume_label, v.volume_number, v.volume_sort, s.series_sort
                          FROM yy_cleanup_change c JOIN yy_volume v ON v.volume_key = c.volume_key
                          LEFT JOIN yy_series s ON s.series_key = v.series_key
                         ORDER BY s.series_sort, v.volume_sort, v.volume_number")->fetchAll();
    // Everyone who has made changes, and the first / last change, for the
    // By and When filters (over all history, not just this filter).
    $users = $db->query("SELECT DISTINCT cleanup_change_user_name FROM yy_cleanup_change
                          WHERE coalesce(cleanup_change_user_name, '') <> '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $span = $db->query('SELECT min(cleanup_change_dtime) AS first, max(cleanup_change_dtime) AS last FROM yy_cleanup_change')->fetch();
    jsonResponse(['batches' => $out, 'total' => $total, 'offset' => $offset, 'limit' => $limit,
                  'users' => $users, 'first_dtime' => $span['first'], 'last_dtime' => $span['last'],
                  'volumes' => array_map(function ($v) {
                      return ['volume_key' => (int)$v['volume_key'], 'label' => $v['volume_code'] ?: $v['volume_label']];
                  }, $vols)]);
}

/** Queued changes: per-book summary, or one book's change list (?volume=N). */
function cleanupPending(): void {
    $db = getDb();
    $vk = (int)($_GET['volume'] ?? 0);
    if ($vk) {
        $st = $db->prepare("SELECT cleanup_change_key, cleanup_change_batch, paragraph_number, cleanup_change_find, cleanup_change_replace,
                                   cleanup_change_options, cleanup_change_match, cleanup_change_excerpt_before,
                                   cleanup_change_excerpt_after, cleanup_change_user_name, cleanup_change_dtime
                              FROM yy_cleanup_change
                             WHERE volume_key = ? AND cleanup_change_status = 'pending'
                             ORDER BY cleanup_change_key");
        $st->execute([$vk]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) $r['cleanup_change_options'] = json_decode($r['cleanup_change_options'], true);
        jsonResponse(['changes' => $rows]);
    }
    $rows = $db->query("SELECT c.volume_key, count(*) AS n, min(c.cleanup_change_dtime) AS first_dtime,
                               max(c.cleanup_change_dtime) AS last_dtime,
                               v.volume_code, v.volume_label, v.volume_number, v.volume_docx,
                               s.series_key, s.series_label
                          FROM yy_cleanup_change c
                          JOIN yy_volume v ON v.volume_key = c.volume_key
                          LEFT JOIN yy_series s ON s.series_key = v.series_key
                         WHERE c.cleanup_change_status = 'pending'
                         GROUP BY c.volume_key, v.volume_code, v.volume_label, v.volume_number, v.volume_docx,
                                  s.series_key, s.series_label, s.series_sort, v.volume_sort
                         ORDER BY s.series_sort, s.series_key, v.volume_sort, v.volume_number, c.volume_key")->fetchAll();
    // Each book's batches (one per Replace click): what was searched for and
    // what it was replaced with. 'replace' is the Replace field as typed
    // (recorded in the options since 2026-10-03); older batches only have the
    // per-match results in 'replaced'.
    $batches = [];
    foreach ($db->query("SELECT volume_key, cleanup_change_batch AS batch, min(cleanup_change_find) AS find,
                                min(cleanup_change_options::text) AS options, count(*) AS n,
                                min(cleanup_change_dtime) AS dtime, min(cleanup_change_user_name) AS user_name,
                                array_to_json(array_agg(DISTINCT cleanup_change_replace)) AS replaced
                           FROM yy_cleanup_change WHERE cleanup_change_status = 'pending'
                          GROUP BY volume_key, cleanup_change_batch
                          ORDER BY min(cleanup_change_key)")->fetchAll() as $b) {
        $o = json_decode((string)$b['options'], true) ?: [];
        $batches[(int)$b['volume_key']][] = [
            'batch' => $b['batch'], 'find' => $b['find'], 'replace' => $o['replace'] ?? null,
            'replaced' => json_decode((string)$b['replaced'], true) ?: [], 'options' => $o,
            'count' => (int)$b['n'], 'dtime' => $b['dtime'], 'user_name' => $b['user_name'],
        ];
    }
    $out = [];
    foreach ($rows as $r) {
        $paths = cleanupPaths($r + ['volume_key' => $r['volume_key']]);
        $out[] = [
            'volume_key' => (int)$r['volume_key'], 'count' => (int)$r['n'],
            'volume_code' => $r['volume_code'], 'volume_label' => $r['volume_label'],
            'volume_number' => $r['volume_number'] === null ? null : (int)$r['volume_number'],
            'series_key' => $r['series_key'] === null ? null : (int)$r['series_key'], 'series_label' => $r['series_label'],
            'first_dtime' => $r['first_dtime'], 'last_dtime' => $r['last_dtime'],
            'stage' => cleanupStageState($paths),
            'batches' => $batches[(int)$r['volume_key']] ?? [],
        ];
    }
    jsonResponse(['volumes' => $out]);
}

function cleanupVolumeKeysFromBody(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required', 405);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $keys = array_values(array_unique(array_filter(array_map('intval', (array)($in['volume_keys'] ?? [])))));
    if (!$keys) errorResponse('volume_keys required');
    return $keys;
}

/** Put the staged DOCX live and queue the book's rebuild, book by book. */
function cleanupReprocess(array $authUser): void {
    $db = getDb();
    $results = [];
    foreach (cleanupVolumeKeysFromBody() as $vk) {
        $vol = cleanupVolume($db, $vk);
        $paths = cleanupPaths($vol);
        $r = ['volume_key' => $vk, 'label' => $vol['label'], 'ok' => false];
        if ($vol['volume_locked_flag'] && (int)$vol['volume_locked_by_key'] !== (int)($authUser['user_key'] ?? 0)) {
            $results[] = $r + ['error' => 'checked out by ' . ($vol['volume_locked_by_name'] ?: 'another admin')];
            continue;
        }
        $lock = fopen($paths['lock'], 'c');
        flock($lock, LOCK_EX);
        $stage = cleanupStageState($paths);
        if ($stage === 'none') {
            $results[] = $r + ['error' => 'no staged DOCX — nothing to commit'];
        } elseif ($stage === 'stale') {
            $results[] = $r + ['error' => 'a new DOCX was uploaded after these changes — discard them'];
        } else {
            $bakDir = $paths['root'] . '/u/books-word-backup';
            if (!is_dir($bakDir)) @mkdir($bakDir, 0775, true);
            $stem = pathinfo($paths['name'], PATHINFO_FILENAME);
            if (!@copy($paths['live'], $bakDir . '/' . $stem . '.' . date('Ymd-His') . '.docx')) {
                $results[] = $r + ['error' => 'could not back up the live DOCX — nothing changed'];
            } elseif (!@rename($paths['staged'], $paths['live'])) {
                $results[] = $r + ['error' => 'could not move the staged DOCX into place — nothing changed'];
            } else {
                @unlink($paths['sidecar']);
                $old = glob($bakDir . '/' . $stem . '.*.docx') ?: [];
                rsort($old);
                foreach (array_slice($old, 10) as $f) @unlink($f);
                $n = $db->prepare("UPDATE yy_cleanup_change SET cleanup_change_status = 'processed', cleanup_change_done_dtime = now()
                                    WHERE volume_key = ? AND cleanup_change_status = 'pending'");
                $n->execute([$vk]);
                cleanupQueueRebuild($db, $vol, $paths);
                $results[] = ['volume_key' => $vk, 'label' => $vol['label'], 'ok' => true, 'changes' => $n->rowCount()];
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    jsonResponse(['results' => $results]);
}

/** Queue the book exactly as a DOCX upload does (admin-books.php upload_docx). */
function cleanupQueueRebuild(PDO $db, array $vol, array $paths): void {
    $vk = (int)$vol['volume_key'];
    $db->prepare("UPDATE yy_volume
                     SET volume_pipeline_status = 'queued',
                         volume_pipeline_message = 'Cleanup changes committed — awaiting PDF + flipbook rebuild',
                         volume_pipeline_retry_count = 0,
                         volume_parse_status = 'queued',
                         volume_parse_message = 'Awaiting host worker (paragraph + translation extraction)',
                         volume_revision_dtime = NOW()
                   WHERE volume_key = ?")->execute([$vk]);
    $jobsDir = $paths['root'] . '/jobs/book-pipeline';
    if (!is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);
    @file_put_contents($jobsDir . '/' . sprintf('%010d', $vk) . '_' . time() . '.json', json_encode([
        'volume_key' => $vk,
        'docx_name'  => $paths['name'],
        'pdf_name'   => $vol['volume_pdf'] ?: pathinfo($paths['name'], PATHINFO_FILENAME) . '.pdf',
        'flip_code'  => null,
        'queued_at'  => date('c'),
        'source'     => 'cleanup-reprocess',
    ], JSON_PRETTY_PRINT));
}

/**
 * Drop a book's queued changes: delete the staged DOCX and put each touched
 * paragraph back the way it was — newest batch first, and only while the
 * paragraph still holds exactly what that batch wrote (a re-parse since
 * then has already replaced it from the live DOCX, which never had them).
 */
function cleanupDiscard(array $authUser): void {
    $db = getDb();
    $results = [];
    foreach (cleanupVolumeKeysFromBody() as $vk) {
        $vol = cleanupVolume($db, $vk);
        $paths = cleanupPaths($vol);
        $lock = fopen($paths['lock'], 'c');
        flock($lock, LOCK_EX);
        $st = $db->prepare("SELECT DISTINCT ON (cleanup_change_batch, paragraph_key)
                                   cleanup_change_key, cleanup_change_batch, paragraph_key,
                                   cleanup_change_before_html, cleanup_change_before_plain, cleanup_change_before_raw,
                                   cleanup_change_after_html
                              FROM yy_cleanup_change
                             WHERE volume_key = ? AND cleanup_change_status = 'pending'
                             ORDER BY cleanup_change_batch, paragraph_key, cleanup_change_key");
        $st->execute([$vk]);
        $byPara = [];
        foreach ($st->fetchAll() as $row) $byPara[(int)$row['paragraph_key']][] = $row;
        $restored = 0; $skipped = 0;
        $db->beginTransaction();
        $get = $db->prepare('SELECT paragraph_text_html FROM yy_paragraph WHERE paragraph_key = ?');
        $up = $db->prepare('UPDATE yy_paragraph SET paragraph_text_html = ?, paragraph_text_plain = ?,
                                   paragraph_text_raw = ?, paragraph_revision_user_key = ? WHERE paragraph_key = ?');
        foreach ($byPara as $pk => $batches) {
            usort($batches, function ($a, $b) { return (int)$b['cleanup_change_key'] - (int)$a['cleanup_change_key']; });
            $get->execute([$pk]);
            $cur = $get->fetchColumn();
            $target = null;
            foreach ($batches as $b) {
                if ($cur === false || $cur !== $b['cleanup_change_after_html']) break;
                $target = $b;
                $cur = $b['cleanup_change_before_html'];
            }
            if ($target) {
                $up->execute([$target['cleanup_change_before_html'], $target['cleanup_change_before_plain'],
                              $target['cleanup_change_before_raw'], (int)($authUser['user_key'] ?? 0), $pk]);
                $restored++;
            } else {
                $skipped++;
            }
        }
        $n = $db->prepare("UPDATE yy_cleanup_change SET cleanup_change_status = 'discarded', cleanup_change_done_dtime = now()
                            WHERE volume_key = ? AND cleanup_change_status = 'pending'");
        $n->execute([$vk]);
        $db->commit();
        @unlink($paths['staged']);
        @unlink($paths['sidecar']);
        flock($lock, LOCK_UN);
        fclose($lock);
        $results[] = ['volume_key' => $vk, 'label' => $vol['label'], 'ok' => true, 'changes' => $n->rowCount(),
                      'paragraphs_restored' => $restored, 'paragraphs_skipped' => $skipped];
    }
    jsonResponse(['results' => $results]);
}

/** Drop a partial UTF-8 sequence left at either end of a byte slice. */
function cleanupUtf8Clean(string $s): string {
    $s = preg_replace('/^[\x80-\xBF]+/', '', $s);
    return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
}

function cleanupRunDocxScript(array $req): array {
    // -I and cwd '/': without them Python lists the script's and the current
    // directory on every import, and the container's /tmp holds ~1.5M PHP
    // session files — seconds per call, minutes under load.
    $proc = proc_open(['python3', '-I', __DIR__ . '/_docx_replace.py'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/');
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'could not start python3'];
    fwrite($pipes[0], json_encode($req, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $res = json_decode((string)$out, true);
    if (!is_array($res)) return ['ok' => false, 'error' => trim($err) ?: 'no output'];
    return $res;
}


/**
 * Replace with each ❜ turned into a half ring or backtick: $pick's k-th char
 * (ʿ, ʾ or `) when the admin chose one on the match's row, else the default
 * from the k-th apostrophe in the found text — ` stays `, ʿ if it leans left
 * (ʿ ‘ ‛ ʻ), else ʾ.
 * Keep in step with clAposFor() in admin-books.html.
 */
function cleanupAposDefault(string $hit): array {
    preg_match_all('/' . CLEANUP_APOS_CLASS . '/u', $hit, $m);
    return array_map(function ($ch) {
        if ($ch === '`') return '`';
        return in_array($ch, ["\u{02BF}", "\u{2018}", "\u{201B}", "\u{02BB}"], true) ? "\u{02BF}" : "\u{02BE}";
    }, $m[0]);
}
function cleanupAposResolve(string $replace, string $hit, string $pick): string {
    $parts = explode(CLEANUP_APOS_WILDCARD, $replace);
    if (count($parts) === 1) return $replace;
    $def = cleanupAposDefault($hit);
    $pick = preg_split('//u', $pick, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = $parts[0];
    for ($k = 1; $k < count($parts); $k++) {
        $ch = $pick[$k - 1] ?? '';
        if ($ch !== "\u{02BF}" && $ch !== "\u{02BE}" && $ch !== '`') $ch = $def[$k - 1] ?? "\u{02BE}";
        $out .= $ch . $parts[$k];
    }
    return $out;
}

/** The replacement as [text, isRing] pieces plus its B / I. */
function cleanupReplacementSegments(string $replace, bool $b, bool $i): array {
    preg_match_all('/[\x{02BE}\x{02BF}]+|[^\x{02BE}\x{02BF}]+/u', $replace, $m);
    $segs = [];
    foreach ($m[0] as $s) $segs[] = [$s, (bool)preg_match('/^[\x{02BE}\x{02BF}]/u', $s)];
    return ['segs' => $segs, 'b' => $b, 'i' => $i];
}

/**
 * Text tokens of paragraph HTML with their byte ranges in the HTML and in the
 * decoded text, and the tags open around each.
 */
function cleanupHtmlTokens(string $html): array {
    $toks = [];
    $stack = [];
    $text = 0;
    preg_match_all('/<[^>]*>|[^<]+/', $html, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [$part, $off]) {
        if ($part[0] === '<') {
            if (!preg_match('#^<\s*(/?)\s*([a-zA-Z0-9]+)#', $part, $t)) continue;
            $name = strtolower($t[2]);
            if ($name === 'br') {
                $toks[] = ['h0' => $off, 'h1' => $off + strlen($part), 't0' => $text, 't1' => $text + 1,
                           'text' => ' ', 'stack' => $stack, 'br' => true];
                $text += 1;
            } elseif ($t[1] === '/') {
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($stack[$k][0] === $name) { array_splice($stack, $k, 1); break; }
                }
            } elseif (substr($part, -2) !== '/>') {
                $stack[] = [$name, $part];
            }
            continue;
        }
        $dec = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $toks[] = ['h0' => $off, 'h1' => $off + strlen($part), 't0' => $text, 't1' => $text + strlen($dec),
                   'text' => $dec, 'stack' => $stack, 'br' => false];
        $text += strlen($dec);
    }
    return $toks;
}

function cleanupSpanFont(array $stack): ?array {
    for ($k = count($stack) - 1; $k >= 0; $k--) {
        if ($stack[$k][0] !== 'span') continue;
        preg_match('/data-font="([^"]*)"/', $stack[$k][1], $f);
        preg_match('/data-style="([^"]*)"/', $stack[$k][1], $s);
        return ['tag' => $stack[$k][1], 'font' => html_entity_decode($f[1] ?? ''), 'style' => isset($s[1]) ? html_entity_decode($s[1]) : null];
    }
    return null;
}

/**
 * Replace decoded-text bytes [start,end) of paragraph HTML. The first token
 * in range closes its open tags, takes the replacement (its own <b>/<i>, and
 * the letters in the matched text's font span; half-rings bare, as the parser
 * writes them), then reopens the tags for the rest of its text.
 */
function cleanupHtmlReplace(string $html, int $start, int $end, array $repl): string {
    $toks = cleanupHtmlTokens($html);
    $hit = array_values(array_filter($toks, function ($t) use ($start, $end) {
        return $t['t1'] > $start && $t['t0'] < $end;
    }));
    if (!$hit) return $html;
    $span = null;
    foreach ($hit as $t) {
        $sf = cleanupSpanFont($t['stack']);
        if ($sf && !in_array($sf['font'], CLEANUP_GLYPH_FONTS, true)) { $span = $sf['tag']; break; }
    }
    $new = '';
    foreach ($repl['segs'] as [$seg, $ring]) {
        $h = htmlspecialchars($seg, ENT_NOQUOTES, 'UTF-8');
        if (!$ring && $span) $h = $span . $h . '</span>';
        if ($repl['i']) $h = '<i>' . $h . '</i>';
        if ($repl['b']) $h = '<b>' . $h . '</b>';
        $new .= $h;
    }
    $esc = function ($s) { return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8'); };
    $first = $hit[0];
    $last = $hit[count($hit) - 1];
    $close = ''; $open = '';
    foreach (array_reverse($first['stack']) as $tg) $close .= '</' . $tg[0] . '>';
    foreach ($first['stack'] as $tg) $open .= $tg[1];
    $edits = [];
    $before = substr($first['text'], 0, max(0, $start - $first['t0']));
    if ($first === $last) {
        $after = substr($first['text'], $end - $first['t0']);
        $edits[] = [$first['h0'], $first['h1'], $esc($before) . $close . $new . $open . $esc($after)];
    } else {
        $edits[] = [$first['h0'], $first['h1'], $esc($before) . $close . $new . $open];
        for ($k = 1; $k < count($hit) - 1; $k++) $edits[] = [$hit[$k]['h0'], $hit[$k]['h1'], ''];
        $edits[] = [$last['h0'], $last['h1'], $esc(substr($last['text'], $end - $last['t0']))];
    }
    foreach (array_reverse($edits) as [$h0, $h1, $txt]) {
        $html = substr($html, 0, $h0) . $txt . substr($html, $h1);
    }
    // Tidy the empty tags the cut can leave behind.
    do {
        $prev = $html;
        $html = preg_replace('#<(b|i|span)(?:\s[^>]*)?></\1>#', '', $html);
    } while ($html !== $prev);
    return $html;
}

/** paragraph_text_raw: the run breakdown the parser stores, rebuilt from HTML. */
function cleanupRawJson(string $html): string {
    $runs = [];
    foreach (cleanupHtmlTokens($html) as $t) {
        if ($t['text'] === '') continue;
        $names = array_column($t['stack'], 0);
        $sf = cleanupSpanFont($t['stack']);
        $runs[] = ['text' => $t['text'],
                   'bold' => in_array('b', $names, true) || in_array('strong', $names, true),
                   'italic' => in_array('i', $names, true) || in_array('em', $names, true),
                   'font' => $sf ? $sf['font'] : '', 'style' => $sf ? $sf['style'] : null];
    }
    return json_encode($runs, JSON_UNESCAPED_UNICODE);
}

/* ── Glossary word picker ────────────────────────────────────────────────
 *
 * GET ?action=word_meta — filter options with counts: languages, base-letter
 *     scripts, sources.
 * GET ?action=words&language=&script=&sources=a,b&q=&phrases=0|1
 *                  &f_base=&f_yt=&f_strongs=&f_source=&f_count=
 *                  &sort=translit|base|yt|strongs|language|source|count&dir=asc|desc
 *                  &offset=&limit=
 *     yy_word rows (not-a-word excluded). q matches the preferred spelling or
 *     any yy_word_translit spelling. phrases=0 (default) drops entries whose
 *     spelling has a space.
 *
 * "Base letters" is the script of the word's native spelling: Base
 * (word_code), falling back to word_hebrew since Base is still mostly empty.
 * Aramaic written in square script reads as Hebrew letters — the script is
 * the same; Syriac is listed apart.
 */
function cleanupWordMeta(): void {
    $db = getDb();
    $langs = $db->query("SELECT l.word_language_code AS code, l.word_language_label AS label, count(w.word_key) AS n
                           FROM yy_word_language l
                           LEFT JOIN yy_word w ON w.word_language = l.word_language_code AND NOT w.word_excluded_flag
                          GROUP BY 1, 2, l.word_language_sort ORDER BY l.word_language_sort")->fetchAll();
    $none = (int)$db->query("SELECT count(*) FROM yy_word WHERE word_language IS NULL AND NOT word_excluded_flag")->fetchColumn();
    $langs[] = ['code' => 'none', 'label' => 'Not classified', 'n' => $none];
    $scripts = [];
    $rows = $db->query("SELECT " . CLEANUP_WORD_SCRIPT_SQL . " AS s, count(*) AS n FROM yy_word w
                         WHERE NOT w.word_excluded_flag GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (CLEANUP_WORD_SCRIPTS as $code => $label) {
        if (!empty($rows[$code])) $scripts[] = ['code' => $code, 'label' => $label, 'n' => (int)$rows[$code]];
    }
    // Every source, even at zero (yy must stay findable).
    $sources = $db->query("SELECT s.word_source_code AS code, s.word_source_label AS label, count(w.word_key) AS n
                             FROM yy_word_source s
                             LEFT JOIN yy_word w ON trim(w.word_source_code) = s.word_source_code AND NOT w.word_excluded_flag
                            GROUP BY 1, 2, s.word_source_sort ORDER BY s.word_source_sort, s.word_source_label")->fetchAll();
    jsonResponse(['languages' => $langs, 'scripts' => $scripts, 'sources' => $sources]);
}

function cleanupWords(): void {
    $db = getDb();
    $where = ['NOT w.word_excluded_flag'];
    $p = [];
    $lang = (string)($_GET['language'] ?? '');
    if ($lang === 'none') $where[] = 'w.word_language IS NULL';
    elseif ($lang !== '') { $where[] = 'w.word_language = :lang'; $p[':lang'] = substr($lang, 0, 1); }
    $script = (string)($_GET['script'] ?? '');
    if ($script !== '' && isset(CLEANUP_WORD_SCRIPTS[$script])) {
        $where[] = '(' . CLEANUP_WORD_SCRIPT_SQL . ') = :script';
        $p[':script'] = $script;
    }
    $srcs = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($_GET['sources'] ?? ''))))));
    if ($srcs) {
        $in = [];
        foreach ($srcs as $i => $s) { $in[] = ":src$i"; $p[":src$i"] = $s; }
        $where[] = "COALESCE(trim(w.word_source_code), '') IN (" . implode(',', $in) . ')';
    }
    // Half-rings and apostrophes are ignored on both sides, so "anaw" finds
    // ʿanaw and Strong's 'anaw alike — unless the filter itself contains one
    // (typed or from the header keys): then it must match exactly, and ❜
    // matches any one apostrophe-like mark, as in Find.
    $qRaw = trim((string)($_GET['q'] ?? ''));
    $q = trim(preg_replace("/[\x{02BE}\x{02BF}\x{02BC}'\x{2018}\x{2019}]/u", '', $qRaw));
    if ($qRaw !== '' && preg_match("/[\x{02BE}\x{02BF}\x{02BC}'`\x{2018}\x{2019}\x{275C}]/u", $qRaw)) {
        $re = implode(CLEANUP_APOS_CLASS, array_map(function ($part) {
            return preg_replace('/[\\\\.^$|?*+()\[\]{}]/', '\\\\$0', $part);   // POSIX ARE literal
        }, explode(CLEANUP_APOS_WILDCARD, $qRaw)));
        $where[] = '(w.word_translit ~* :qre OR EXISTS (SELECT 1 FROM yy_word_translit t
                      WHERE t.word_key = w.word_key AND t.word_translit_text ~* :qre))';
        $p[':qre'] = $re;
        $q = '';
    }
    if ($q !== '') {
        $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $strip = function ($col) { return "regexp_replace($col, '[\\u02BE\\u02BF\\u02BC''\\u2018\\u2019]', '', 'g')"; };
        $where[] = '(' . $strip('w.word_translit') . ' ILIKE :q OR EXISTS (SELECT 1 FROM yy_word_translit t
                      WHERE t.word_key = w.word_key AND ' . $strip('t.word_translit_text') . ' ILIKE :q))';
        $p[':q'] = $like;
    }
    if (empty($_GET['phrases'])) $where[] = "position(' ' in trim(coalesce(w.word_translit, ''))) = 0";

    // Column filters from the list's filter row ("contains", case-insensitive).
    $esc = function ($s) { return '%' . strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%'; };
    $fBase = trim((string)($_GET['f_base'] ?? ''));
    if ($fBase !== '') { $where[] = CLEANUP_WORD_BASE_SQL . ' ILIKE :f_base'; $p[':f_base'] = $esc($fBase); }
    $fYt = trim((string)($_GET['f_yt'] ?? ''));
    if ($fYt !== '') { $where[] = 'w.word_yt ILIKE :f_yt'; $p[':f_yt'] = $esc($fYt); }
    $fSrc = trim((string)($_GET['f_source'] ?? ''));
    if ($fSrc !== '') {
        $where[] = "(trim(w.word_source_code) ILIKE :f_src OR EXISTS (SELECT 1 FROM yy_word_source fs
                      WHERE fs.word_source_code = trim(w.word_source_code) AND fs.word_source_label ILIKE :f_src))";
        $p[':f_src'] = $esc($fSrc);
    }
    // Strong's: a number (H/G and a suffix letter optional) = that entry, any
    // suffix unless one is given — "430" finds H0430, H0430a, H0430b. Also
    // "none" (established: has none), "?" (not yet determined), "*" (has
    // one); anything else = some code contains it.
    $fStrongs = trim((string)($_GET['f_strongs'] ?? ''));
    if ($fStrongs !== '') {
        $fsLow = strtolower($fStrongs);
        if (in_array($fsLow, ['none', '-', '–', '––', '--'], true)) {
            $where[] = "trim(w.word_strongs) = '' AND NOT EXISTS (SELECT 1 FROM yy_word_strongs ws WHERE ws.word_key = w.word_key)";
        } elseif (in_array($fsLow, ['?', 'unknown'], true)) {
            $where[] = 'w.word_strongs IS NULL AND NOT EXISTS (SELECT 1 FROM yy_word_strongs ws WHERE ws.word_key = w.word_key)';
        } elseif (in_array($fsLow, ['*', 'any'], true)) {
            $where[] = 'EXISTS (SELECT 1 FROM yy_word_strongs ws WHERE ws.word_key = w.word_key)';
        } elseif (preg_match('/^([hg])?\s*0*(\d{1,4})([a-z])?$/i', $fStrongs, $m) && (int)$m[2] > 0) {
            $c = 'ws.word_strongs_number = :fs_num';
            $p[':fs_num'] = (int)$m[2];
            if ($m[1] !== '') { $c .= ' AND ws.word_strongs_language = :fs_lang'; $p[':fs_lang'] = strtoupper($m[1]); }
            if (($m[3] ?? '') !== '') { $c .= ' AND ws.word_strongs_suffix = :fs_suf'; $p[':fs_suf'] = strtolower($m[3]); }
            $where[] = "EXISTS (SELECT 1 FROM yy_word_strongs ws WHERE ws.word_key = w.word_key AND $c)";
        } else {
            $where[] = 'EXISTS (SELECT 1 FROM yy_word_strongs ws WHERE ws.word_key = w.word_key AND ws.word_strongs_code ILIKE :f_strongs)';
            $p[':f_strongs'] = $esc($fStrongs);
        }
    }
    // Count: "5" or ">=5" = at least 5; also >5, <5, <=5, =5, and a range "5-10".
    $fCount = preg_replace('/\s+/', '', (string)($_GET['f_count'] ?? ''));
    if ($fCount !== '') {
        $cnt = 'coalesce(w.word_count_yy, 0)';
        if (preg_match('/^(\d+)-(\d+)$/', $fCount, $m)) {
            $where[] = "$cnt BETWEEN " . (int)$m[1] . ' AND ' . (int)$m[2];
        } elseif (preg_match('/^(>=|<=|>|<|=)?(\d+)$/', $fCount, $m)) {
            $where[] = "$cnt " . ($m[1] !== '' ? $m[1] : '>=') . ' ' . (int)$m[2];
        } else {
            errorResponse('Count filter: use a number (at least), or >n, <n, =n, or a range like 5-10.');
        }
    }

    $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    // Spelling order ignores the half-rings and case, so ʿanaw files under a.
    $translitKey = "lower(regexp_replace(coalesce(w.word_translit, ''), '[\\u02BE\\u02BF\\u02BC]', '', 'g'))";
    $sorts = [
        'translit' => "$translitKey $dir",
        'base'     => CLEANUP_WORD_BASE_SQL . " $dir NULLS LAST, $translitKey",
        'yt'       => "nullif(trim(w.word_yt), '') $dir NULLS LAST, $translitKey",
        'strongs'  => "nullif(trim(w.word_strongs), '') $dir NULLS LAST, $translitKey",
        'language' => "w.word_language $dir NULLS LAST, $translitKey",
        'source'   => "s.word_source_label $dir NULLS LAST, $translitKey",
        'count'    => "coalesce(w.word_count_yy, 0) $dir, $translitKey",
    ];
    $order = $sorts[$_GET['sort'] ?? 'translit'] ?? $sorts['translit'];
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $whereSql = implode(' AND ', $where);

    $st = $db->prepare("SELECT count(*) FROM yy_word w WHERE $whereSql");
    $st->execute($p);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT w.word_key, w.word_translit, " . CLEANUP_WORD_BASE_SQL . " AS base, w.word_yt AS yt,
                               (" . CLEANUP_WORD_SCRIPT_SQL . ") AS script, w.word_language,
                               trim(w.word_source_code) AS source, s.word_source_label AS source_label,
                               coalesce(w.word_count_yy, 0) AS count,
                               trim(w.word_strongs) AS strongs_state,
                               (SELECT string_agg(ws.word_strongs_code, ' · '
                                         ORDER BY (ws.word_strongs_key = w.word_strongs_key) IS TRUE DESC, ws.word_strongs_code)
                                  FROM yy_word_strongs ws WHERE ws.word_key = w.word_key) AS strongs,
                               (SELECT string_agg(DISTINCT t.word_translit_text, ' · ')
                                  FROM yy_word_translit t
                                 WHERE t.word_key = w.word_key AND t.word_translit_text IS DISTINCT FROM w.word_translit) AS alts
                          FROM yy_word w
                          LEFT JOIN yy_word_source s ON s.word_source_code = trim(w.word_source_code)
                         WHERE $whereSql
                         ORDER BY $order, w.word_key
                         LIMIT $limit OFFSET $offset");
    $st->execute($p);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['word_key'] = (int)$r['word_key']; $r['count'] = (int)$r['count']; }
    jsonResponse(['total' => $total, 'offset' => $offset, 'words' => $rows]);
}

/**
 * The Find text as a PCRE pattern: literal, except ❜ = any apostrophe-like mark.
 * $whole: the match may not run on into a neighbouring letter, digit or
 * half-ring (so "anaw" skips ʿanaw, but "God" still finds God’s). Like Word,
 * an edge of the Find text that is not itself a word character gets no test.
 * Keep in step with find_pattern() in _docx_replace.py.
 */
function cleanupFindPattern(string $q, bool $matchCase, bool $whole = false): string {
    $parts = explode(CLEANUP_APOS_WILDCARD, $q);
    $re = implode(CLEANUP_APOS_CLASS, array_map(function ($p) { return preg_quote($p, '/'); }, $parts));
    if ($whole) {
        $wordChar = '[\p{L}\p{N}\p{M}\x{02BE}\x{02BF}]';
        $isWord = function ($ch) { return $ch === CLEANUP_APOS_WILDCARD || preg_match('/^[\p{L}\p{N}\p{M}\x{02BE}\x{02BF}]$/u', $ch); };
        if ($isWord(mb_substr($q, 0, 1))) $re = '(?<!' . $wordChar . ')' . $re;
        if ($isWord(mb_substr($q, -1))) $re .= '(?!' . $wordChar . ')';
    }
    return '/' . $re . '/u' . ($matchCase ? '' : 'i');
}

/** LIKE prefilter for the Find text: ❜ becomes `_` (any one character). */
function cleanupFindLike(string $q): string {
    $parts = explode(CLEANUP_APOS_WILDCARD, $q);
    return '%' . implode('_', array_map(function ($p) {
        return strtr($p, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }, $parts)) . '%';
}
