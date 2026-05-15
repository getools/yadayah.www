<?php
/**
 * Look up a paragraph's text by (volume, paragraph_number). Public read-only.
 * Used by the flipbook viewer to render the URL-driven paragraph highlight
 * (i.e. when a translation or search-result link includes #h=<N>).
 *
 *   GET ?v=<volume_code>&h=<paragraph_number>
 *     → { text: "...", page: 266 }
 *
 *   GET ?v=<volume_key>&h=<paragraph_number>          (integer v is treated as key)
 *
 * Returns 404 if the paragraph doesn't exist or is flagged inactive.
 * The volume can be looked up by code (e.g. "YY-s04v01-...") or numeric key.
 */
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') errorResponse('Method not allowed', 405);

$vRaw = trim((string)($_GET['v'] ?? ''));
$h    = (int)($_GET['h'] ?? 0);
if ($vRaw === '' || $h <= 0) errorResponse('v and h required');

$db = getDb();

// Resolve volume_key — accept either the integer key or the slug/code.
if (ctype_digit($vRaw)) {
    $volumeKey = (int)$vRaw;
} else {
    $stmt = $db->prepare("SELECT volume_key FROM yy_volume WHERE volume_code = ? OR REPLACE(REPLACE(volume_code, ' ', '-'), '''', '') = ? LIMIT 1");
    $stmt->execute([$vRaw, $vRaw]);
    $volumeKey = (int)($stmt->fetchColumn() ?: 0);
}
if ($volumeKey <= 0) errorResponse('Volume not found', 404);

$stmt = $db->prepare("
    SELECT paragraph_text_plain AS text, paragraph_page AS page
      FROM yy_paragraph
     WHERE volume_key = ? AND paragraph_number = ?
       AND paragraph_active_flag IS NOT FALSE
     LIMIT 1
");
$stmt->execute([$volumeKey, $h]);
$row = $stmt->fetch();
if (!$row) errorResponse('Paragraph not found', 404);

header('Cache-Control: public, max-age=3600');
jsonResponse(['text' => $row['text'], 'page' => (int)$row['page']]);
