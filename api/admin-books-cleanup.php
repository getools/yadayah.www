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
 *
 * POST ?action=replace — see cleanupReplace() below.
 */
require_once __DIR__ . '/config.php';
$authUser = requireAuth();

const CLEANUP_MAX_CANDIDATES = 4000;  // paragraphs scanned per search
const CLEANUP_MAX_RESULTS    = 1000;  // excerpts returned per search
const CLEANUP_CONTEXT_CHARS  = 90;    // characters either side of a match
// Fonts whose letters are glyph art (YT, Paleo…): never wrap replacement letters in them.
const CLEANUP_GLYPH_FONTS = ['Yada Towrah', 'PictoHeb', 'Isaiah Scroll', 'Moabite Stone', 'Semitic Early', 'Hebrew Script'];

$action = $_GET['action'] ?? '';
if ($action === 'replace') cleanupReplace($authUser);   // responds and exits
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
            'start'         => $start,
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

/* ── Replace ─────────────────────────────────────────────────────────────
 *
 * POST ?action=replace  JSON body:
 *   {mode: 'next'|'all', volume_key, q, bold, italic, case,
 *    replace, rbold, ritalic, target: {paragraph_key, start, match}}
 *
 * One volume per request (Cloudflare cuts a request off at ~100s, and one
 * book's DOCX round trip is a few seconds), so the page walks Replace All
 * book by book.
 *
 * Every edit lands in BOTH places, or in neither:
 *   1. the Word DOCX (u/books-word/<volume_docx>), via _docx_replace.py, which
 *      lines each parsed match up with its spot in the DOCX by the text
 *      around it. The previous DOCX is copied to u/books-word-backup/ first.
 *   2. yy_paragraph — html, plain and raw — so search and the reader show the
 *      change at once. yy_paragraph_rev keeps the old text.
 * A match the DOCX side cannot place is reported and left alone in both.
 *
 * The volume is then queued exactly like a DOCX upload, so the pipeline
 * re-renders the PDF and flipbook and re-parses the paragraphs from it.
 */
function cleanupReplace(array $authUser): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required', 405);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = $in['mode'] ?? '';
    if ($mode !== 'next' && $mode !== 'all') errorResponse('mode must be next or all');
    $q = (string)($in['q'] ?? '');
    if (trim($q) === '') errorResponse('Enter something to find');
    $replace = (string)($in['replace'] ?? '');
    if (mb_strlen($replace) > 500) errorResponse('Replace text is too long');
    $wantBold = !empty($in['bold']); $wantItalic = !empty($in['italic']);
    $matchCase = !empty($in['case']);
    $rBold = !empty($in['rbold']); $rItalic = !empty($in['ritalic']);
    $vk = (int)($in['volume_key'] ?? 0);
    if (!$vk) errorResponse('volume_key required');

    $db = getDb();
    $st = $db->prepare('SELECT volume_key, volume_code, volume_docx, volume_pdf, volume_label,
                               volume_locked_flag, volume_locked_by_key, volume_locked_by_name
                          FROM yy_volume WHERE volume_key = ?');
    $st->execute([$vk]);
    $vol = $st->fetch();
    if (!$vol) errorResponse('Volume not found', 404);
    $label = $vol['volume_code'] ?: $vol['volume_label'];
    if ($vol['volume_locked_flag'] && (int)$vol['volume_locked_by_key'] !== (int)($authUser['user_key'] ?? 0)) {
        errorResponse($label . ' is checked out by ' . ($vol['volume_locked_by_name'] ?: 'another admin')
            . ' — ask them to release the lock first.', 423);
    }
    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : dirname(__DIR__) . '/public';
    $docxName = $vol['volume_docx'] ?: ($vol['volume_code'] ? $vol['volume_code'] . '.docx' : '');
    $docxPath = $publicRoot . '/u/books-word/' . $docxName;
    if (!$docxName || !is_file($docxPath)) errorResponse($label . ': no DOCX on the server to edit');

    // The whole volume's parsed text, in reading order: the stream gives each
    // match its surrounding text for lining it up with the DOCX.
    $st = $db->prepare('SELECT paragraph_key, paragraph_text_html FROM yy_paragraph
                         WHERE volume_key = ? AND paragraph_active_flag ORDER BY paragraph_number');
    $st->execute([$vk]);
    $pattern = '/' . preg_quote($q, '/') . '/u' . ($matchCase ? '' : 'i');
    $stream = '';
    $dbList = [];      // every text match, for alignment
    $meta = [];        // id → [paragraph_key, start, hit]
    $htmlByKey = [];
    $target = $in['target'] ?? null;
    $targetFound = false;
    while ($r = $st->fetch()) {
        $pk = (int)$r['paragraph_key'];
        $parsed = cleanupParseHtml((string)$r['paragraph_text_html']);
        $text = $parsed['text'];
        $base = strlen($stream);
        $stream .= $text . ' ';
        if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;
        $htmlByKey[$pk] = (string)$r['paragraph_text_html'];
        foreach ($m[0] as [$hit, $start]) {
            $end = $start + strlen($hit);
            if ($mode === 'next') {
                $apply = $target && (int)$target['paragraph_key'] === $pk
                    && (int)$target['start'] === $start && (string)$target['match'] === $hit;
                if ($apply) $targetFound = true;
            } else {
                $apply = !($wantBold || $wantItalic)
                    || cleanupHasFormat($parsed['runs'], $text, $start, $end, $wantBold, $wantItalic);
            }
            $id = $pk . ':' . $start;
            $meta[$id] = [$pk, $start, $hit];
            $dbList[] = ['id' => $id, 'apply' => $apply, 'pos' => $base + $start, 'end' => $base + $end];
        }
    }
    if ($mode === 'next' && !$targetFound) {
        errorResponse('That match is no longer in the text — search again.', 409);
    }
    $toApply = count(array_filter($dbList, function ($d) { return $d['apply']; }));
    if (!$toApply) jsonResponse(['volume_key' => $vk, 'replaced' => 0, 'unmapped' => 0, 'failed' => [], 'items' => []]);
    foreach ($dbList as &$d) {
        $d['before'] = cleanupUtf8Clean(substr($stream, max(0, $d['pos'] - 600), $d['pos'] - max(0, $d['pos'] - 600)));
        $d['after']  = cleanupUtf8Clean(substr($stream, $d['end'], 600));
        unset($d['pos'], $d['end']);
    }
    unset($d);
    $stream = '';

    // One replace per book at a time (Replace Next clicked quickly, two admins).
    $lock = fopen($publicRoot . '/u/books-word/.cleanup-' . $vk . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) errorResponse('Could not lock ' . $label);

    $tmpOut = dirname($docxPath) . '/.' . basename($docxPath) . '.cleanup-' . getmypid() . '.tmp';
    $res = cleanupRunDocxScript([
        'docx_in' => $docxPath, 'docx_out' => $tmpOut,
        'find' => $q, 'case' => $matchCase,
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
        // 1. Work out every paragraph's new text before touching anything.
        $byPara = [];
        foreach ($applied as $id) {
            if (isset($meta[$id])) $byPara[$meta[$id][0]][] = $meta[$id];
        }
        $repl = cleanupReplacementSegments($replace, $rBold, $rItalic);
        $rows = [];
        try {
            foreach ($byPara as $pk => $list) {
                usort($list, function ($a, $b) { return $b[1] - $a[1]; });   // last match first
                $html = $htmlByKey[$pk];
                foreach ($list as [, $start, $hit]) {
                    $html = cleanupHtmlReplace($html, $start, $start + strlen($hit), $repl);
                }
                $parsed = cleanupParseHtml($html);
                $rows[$pk] = [$html, trim(preg_replace('/\s+/u', ' ', $parsed['text'])), cleanupRawJson($html)];
                if ($mode === 'next') {
                    [, $start] = $list[0];
                    $e = $start + strlen($replace);
                    $items[] = [
                        'paragraph_key' => $pk, 'start' => $start, 'delta' => strlen($replace) - strlen($list[0][2]),
                        'excerpt'   => cleanupExcerpt($parsed['runs'], $parsed['text'], $start, $e),
                        'paragraph' => cleanupExcerpt($parsed['runs'], $parsed['text'], $start, $e, null),
                    ];
                }
            }
        } catch (\Throwable $ex) {
            @unlink($tmpOut);
            errorResponse($label . ': could not edit the paragraph text (' . $ex->getMessage() . ') — nothing was changed', 500);
        }

        // 2. Keep the previous DOCX (last 10 per book), then swap the new one in.
        $bakDir = $publicRoot . '/u/books-word-backup';
        if (!is_dir($bakDir)) @mkdir($bakDir, 0775, true);
        $stem = pathinfo($docxName, PATHINFO_FILENAME);
        $bakPath = $bakDir . '/' . $stem . '.' . date('Ymd-His') . '.docx';
        if (!@copy($docxPath, $bakPath)) {
            @unlink($tmpOut);
            errorResponse($label . ': could not back up the DOCX — nothing was changed', 500);
        }
        $old = glob($bakDir . '/' . $stem . '.*.docx') ?: [];
        rsort($old);
        foreach (array_slice($old, 10) as $f) @unlink($f);
        @chmod($tmpOut, 0644);
        if (!@rename($tmpOut, $docxPath)) {
            @unlink($tmpOut);
            errorResponse($label . ': could not write the DOCX — nothing was changed', 500);
        }

        // 3. The parsed paragraphs + queue the book exactly as a DOCX upload
        //    does. If any of it fails, put the old DOCX back so the two agree.
        try {
            $db->beginTransaction();
            $up = $db->prepare('UPDATE yy_paragraph SET paragraph_text_html = ?, paragraph_text_plain = ?,
                                       paragraph_text_raw = ?, paragraph_revision_user_key = ?
                                 WHERE paragraph_key = ?');
            foreach ($rows as $pk => [$html, $plain, $raw]) {
                $up->execute([$html, $plain, $raw, (int)($authUser['user_key'] ?? 0), $pk]);
            }
            $db->prepare("UPDATE yy_volume
                             SET volume_pipeline_status = 'queued',
                                 volume_pipeline_message = 'Cleanup replace edited the DOCX — awaiting PDF + flipbook rebuild',
                                 volume_pipeline_retry_count = 0,
                                 volume_parse_status = 'queued',
                                 volume_parse_message = 'Awaiting host worker (paragraph + translation extraction)',
                                 volume_revision_dtime = NOW()
                           WHERE volume_key = ?")->execute([$vk]);
            $db->commit();
        } catch (\Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            $restored = @copy($bakPath, $docxPath);
            if ($restored) @unlink($bakPath);
            logMonitorEvent('cleanup_replace', 'error', $label . ': DB update failed — ' . $ex->getMessage(),
                $ex->getFile() . ':' . $ex->getLine() . ($restored ? "\nDOCX restored" : "\nDOCX NOT restored — backup at $bakPath"));
            errorResponse($label . ': saving the paragraph text failed (' . $ex->getMessage() . ')'
                . ($restored ? ' — the DOCX was put back, nothing was changed' : ' — ⚠ the DOCX could not be put back; backup: ' . basename($bakPath)), 500);
        }
        $jobsDir = $publicRoot . '/jobs/book-pipeline';
        if (!is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);
        @file_put_contents($jobsDir . '/' . sprintf('%010d', $vk) . '_' . time() . '.json', json_encode([
            'volume_key' => $vk,
            'docx_name'  => $docxName,
            'pdf_name'   => $vol['volume_pdf'] ?: pathinfo($docxName, PATHINFO_FILENAME) . '.pdf',
            'flip_code'  => null,
            'queued_at'  => date('c'),
            'source'     => 'cleanup-replace',
        ], JSON_PRETTY_PRINT));
    } else {
        @unlink($tmpOut);
    }
    flock($lock, LOCK_UN);
    fclose($lock);

    $failed = [];
    foreach (($res['failed'] ?? []) as $id => $why) $failed[] = $why;
    jsonResponse([
        'volume_key'   => $vk,
        'replaced'     => count($applied),
        'unmapped'     => count($res['unmapped'] ?? []),
        'failed'       => $failed,
        'docx_matches' => $res['docx_matches'] ?? null,
        'items'        => $items,
    ]);
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
