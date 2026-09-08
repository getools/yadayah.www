<?php
/**
 * Public card data for the /books grid (Pages-New page 10, section 55).
 * No auth — everything here is already public on /books.
 *
 *   GET → { series:  [ { series_key, series_label, series_sort } ],
 *           volumes: [ { series_key, volume_key, sort, alt, read_url,
 *                        img, asin, pdf_url, docx_url, audio_url, cls } ] }
 *
 * Two flags decide what the Books page shows, and both are editable in admin:
 *   yy_series.series_books_display_flag — whether the series gets a section
 *   yy_volume.volume_active_flag        — whether the book gets a card
 * Series are returned in display order and volumes are already filtered to
 * those series, so the page renders what it is given without second-guessing.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';

/** Legacy per-book thumbnails: /images/covers/<PREFIX><token>-245x300.jpg */
const BOOKS_COVER_DIR    = '/images/covers/';
const BOOKS_COVER_PREFIX = 'YY.book_.3d.6x9-vertical-softcover-spine.00490x00600.';

/**
 * The one book that is not a YY volume: it lives on its own domain and its
 * cover is a different shape (300x264, hence the 'itc' class). Everything
 * else about it — PDF, Word, ASIN, active flag — is ordinary volume data.
 */
const BOOKS_OVERRIDES = [
    'In-the-Company-of-Good-and-Evil' => [
        'read_url' => 'https://InTheCompanyOfGoodAndEvil.com',
        'img'      => '/images/covers/itc-300x264.png',
        'cls'      => 'itc',
    ],
];

/** '/var/www/html' — where the public tree is mounted, for is_file() checks. */
function booksDocRoot(): string {
    return rtrim($_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__) . '/public', '/');
}

/**
 * Card thumbnail. Prefers the legacy 245x300 JPEG the page has always used,
 * so existing cards render byte-identically; falls back to the volume's
 * uploaded cover art (front-3D, then front-2D), so a NEW book only needs a
 * cover slot filled in and no legacy file at all.
 *
 * Some legacy files are saved HTTP redirects rather than images, so the
 * fallback is gated on getimagesize(), not merely on the file existing.
 */
function booksCoverUrl(?string $token, ?string $front3d, ?string $front2d): ?string {
    if ($token !== null) {
        $web = BOOKS_COVER_DIR . BOOKS_COVER_PREFIX . $token . '-245x300.jpg';
        $abs = booksDocRoot() . $web;
        if (is_file($abs) && @getimagesize($abs)) return $web;
    }
    foreach ([$front3d, $front2d] as $slot) {
        if (($slot ?? '') !== '') return imageSizeUrl($slot, 'sm');
    }
    return null;
}

/** 'Qowl-A Voice' → 'Qowl - A Voice' (the spacing the cards have always used). */
function booksAltText(string $label): string {
    return preg_replace('/(?<=\S)-(?=\S)/', ' - ', $label, 1);
}

$pdo = getDb();
$series = $pdo->query("
    SELECT series_key, series_label, series_sort
      FROM yy_series
     WHERE series_books_display_flag = TRUE
     ORDER BY series_sort, series_key
")->fetchAll();

/**
 * Volumes with narration the flipbook will actually serve. Mirrors the
 * live-audio gate in api/tts-audio.php exactly — path set, a clean build
 * promoted (live_dtime), and not hidden by the active flag — so the card's
 * audio-book icon can never point at a book whose player would come up
 * dead. See the "live_dtime is the publish gate" note in that file.
 */
$audioVolumes = array_flip(array_map('intval', $pdo->query("
    SELECT DISTINCT a.volume_key
      FROM yy_tts_audio a
     WHERE a.tts_audio_path IS NOT NULL
       AND a.tts_audio_live_dtime IS NOT NULL
       AND a.tts_audio_active_flag = TRUE
")->fetchAll(PDO::FETCH_COLUMN)));

$rows = $pdo->query("
    SELECT v.volume_key, v.series_key, v.volume_number, v.volume_sort,
           v.volume_code, v.volume_label, v.volume_amazon_asin,
           v.volume_img_front_3d, v.volume_img_front_2d
      FROM yy_volume v
      JOIN yy_series s ON s.series_key = v.series_key
     WHERE v.volume_active_flag = TRUE
       AND s.series_books_display_flag = TRUE
     ORDER BY s.series_sort, v.volume_sort, v.volume_number
")->fetchAll();

$volumes = [];
foreach ($rows as $r) {
    $code = $r['volume_code'];
    if (($code ?? '') === '') continue;                     // unnamed row → no URLs to build

    $token = preg_match('/-(s\d{2}v\d{2})-/', $code, $m) ? $m[1] : null;
    $ov    = BOOKS_OVERRIDES[$code] ?? [];

    $img = $ov['img'] ?? booksCoverUrl($token, $r['volume_img_front_3d'], $r['volume_img_front_2d']);
    if ($img === null) continue;                            // no cover → no card, rather than a broken image

    $volumes[] = [
        'series_key' => (int)$r['series_key'],
        'volume_key' => (int)$r['volume_key'],
        'sort'       => (int)$r['volume_sort'],
        'alt'        => booksAltText((string)$r['volume_label']),
        'read_url'   => $ov['read_url'] ?? '/' . $code . '/',
        'img'        => $img,
        'asin'       => trim((string)($r['volume_amazon_asin'] ?? '')),
        'pdf_url'    => 'https://yadayah.com/pdf/' . $code . '.pdf',
        'docx_url'   => '/u/books-word/' . $code . '.docx',
        // Audio book: the flipbook opened with #auto=1, which turns to the
        // first narrated page and starts playing (/js/flipbook-tts.js).
        // Always the LOCAL flipbook even where read_url is overridden to an
        // off-site edition — the narration only exists here. Null when the
        // volume has no live audio, so the card just omits the icon.
        'audio_url'  => isset($audioVolumes[(int)$r['volume_key']])
                            ? '/' . $code . '/#auto=1'
                            : null,
        'cls'        => $ov['cls'] ?? '',
    ];
}

// Drop a series that ended up with no cards, so the page never renders a
// heading with nothing under it.
$counts = array_count_values(array_column($volumes, 'series_key'));
$series = array_values(array_filter($series, function ($s) use ($counts) {
    return !empty($counts[(int)$s['series_key']]);
}));

jsonResponse(['series' => $series, 'volumes' => $volumes]);
