<?php
/**
 * Admin API for book cover artwork.
 *
 * Six artwork slots per volume — front / spine / back, each in 2D and 3D —
 * plus a derived icon. The icon is the thumbnail every other surface consumes
 * (search results today); the full-size slots are for book pages and marketing.
 *
 * Any of the six slots can be the icon's source. `volume_img_icon_slot` records
 * which one; NULL means the historical default, the 2D front. The icon is
 * rebuilt automatically whenever its source slot is uploaded/replaced, and
 * cleared when its source slot is deleted — the other five slots don't touch it.
 *
 * Uploads keep the untouched file in originals/ and serve a scaled display
 * copy, same shape as the logo/resource uploaders. Filenames are unique per
 * upload so a replacement never collides with a cached URL.
 *
 * POST multipart  volume_key, slot, image_file      — upload / replace a slot
 * POST json       {action:'delete',     volume_key, slot}
 * POST json       {action:'regen_icon', volume_key, slot?}   slot = new source
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';
$authUser = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$COVER_SLOTS = ['front_2d', 'spine_2d', 'back_2d', 'front_3d', 'spine_3d', 'back_3d'];
// Icon/sm/md/lg all come from IMG_SIZE_SET in image-helpers.php now. The base
// display copy stays 1200×1600 so every path already stored in yy_volume
// keeps resolving to the same file it did before.
$FULL_MAX_W  = 1200;   // display copy; the untouched upload stays in originals/
$FULL_MAX_H  = 1600;

// uploadDir() resolves against the real docroot and guarantees the directory
// exists and is www-data-writable — never hand-roll these paths (see config.php).
$UPLOAD_DIR = uploadDir('covers');
$ORIG_DIR   = uploadDir('covers/originals');
$WEB_DIR    = uploadUrl('covers');

$db = getDb();

// Multipart posts leave php://input empty, so read both.
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? $_POST['action'] ?? $_GET['action'] ?? 'upload';
$key    = (int)($_POST['volume_key'] ?? $body['volume_key'] ?? $_GET['key'] ?? 0);
if (!$key) errorResponse('Volume key required');

$cur = $db->prepare("SELECT * FROM yy_volume WHERE volume_key = ?");
$cur->execute([$key]);
$vol = $cur->fetch();
if (!$vol) errorResponse('Volume not found', 404);

// Same checkout rule as a DOCX upload: another admin's lock blocks the write,
// your own lock does not. See admin-books.php toggle_lock.
$myKey = (int)($authUser['user_key'] ?? 0);
if (!empty($vol['volume_locked_flag']) && (int)$vol['volume_locked_by_key'] !== $myKey) {
    errorResponse('This book is checked out by ' . ($vol['volume_locked_by_name'] ?: 'another admin') . '.', 423);
}

/** Web path (/u/covers/x.jpg) → absolute path, or null if it isn't ours. */
function coverAbs(?string $webPath, string $uploadDir): ?string {
    if (!$webPath) return null;
    $base = basename(parse_url($webPath, PHP_URL_PATH) ?: $webPath);
    if ($base === '' || $base === '.' || $base === '..') return null;
    return rtrim($uploadDir, '/') . '/' . $base;
}

/** Remove a slot's display copy, its whole size set, and its original. */
function coverUnlink(?string $webPath, string $uploadDir, string $origDir): void {
    $abs = coverAbs($webPath, $uploadDir);
    if ($abs && is_file($abs)) @unlink($abs);
    if ($abs) unlinkImageSizes($uploadDir, basename($abs));
    $orig = coverAbs($webPath, $origDir);
    if ($orig && is_file($orig)) @unlink($orig);
}

/**
 * (Re)build the size set from a slot's stored original and return the icon's
 * web path, or null when there is nothing to derive from.
 *
 * The icon is just the 'icon' member of the standard set — covers do NOT get
 * their own thumbnail rule any more, so a rebuild here and an upload produce
 * byte-identical files under the same name.
 */
function coverBuildIcon(?string $slotPath, string $uploadDir, string $origDir, string $webDir): ?string {
    if (!$slotPath) return null;
    $src = coverAbs($slotPath, $origDir);
    if (!$src || !is_file($src)) $src = coverAbs($slotPath, $uploadDir);   // original pruned? fall back
    if (!$src || !is_file($src)) return null;

    // Variant names key off the slot's own filename, not the source's, so a
    // fallback to the display copy still yields '<stem>-icon.<ext>'.
    $made = makeImageSizes($src, $uploadDir, basename(coverAbs($slotPath, $uploadDir)));
    if (empty($made['icon'])) return null;
    return rtrim($webDir, '/') . '/' . $made['icon'];
}

/** Which slot the icon is derived from. NULL in the DB = the 2D front. */
function coverIconSlot(array $vol, array $slots): string {
    $s = (string)($vol['volume_img_icon_slot'] ?? '');
    return in_array($s, $slots, true) ? $s : 'front_2d';
}

/**
 * Retire the old icon file — but only when it is genuinely orphaned.
 *
 * Since the icon is the '-icon' member of its source slot's size set, the file
 * it points at usually still belongs to a slot that is very much alive (that is
 * exactly the case when the admin re-points the icon at a different slot).
 * Deleting it there would blow a hole in that slot's srcset, so leave any file
 * that is still a variant of a stored slot alone and only unlink strays —
 * standalone icons from before covers shared the common size set.
 */
function coverRetireIcon(?string $iconPath, array $vol, array $slots, string $uploadDir, string $origDir): void {
    if (!$iconPath) return;
    foreach ($slots as $s) {
        $p = $vol['volume_img_' . $s] ?? null;
        if (!$p) continue;
        if (preg_replace('/-icon(\.[A-Za-z0-9]+)$/', '$1', basename($iconPath)) === basename($p)) return;
    }
    coverUnlink($iconPath, $uploadDir, $origDir);
}

// ── Delete a slot ────────────────────────────────────────────────────────
if ($action === 'delete') {
    $slot = (string)($body['slot'] ?? $_POST['slot'] ?? '');
    if (!in_array($slot, $COVER_SLOTS, true)) errorResponse('Unknown slot');
    $col = 'volume_img_' . $slot;                       // whitelisted above

    // Unlinking the slot takes its whole size set with it — including the
    // '-icon' variant, when this is the slot the icon is derived from. So read
    // the icon-source decision first, then delete.
    $iconSlot = coverIconSlot($vol, $COVER_SLOTS);
    coverUnlink($vol[$col] ?? null, $UPLOAD_DIR, $ORIG_DIR);

    // Dropping the slot the icon is derived from drops the icon it fed. The
    // source pointer resets so the next upload falls back to the 2D front.
    if ($slot === $iconSlot) {
        coverRetireIcon($vol['volume_img_icon'] ?? null, $vol, $COVER_SLOTS, $UPLOAD_DIR, $ORIG_DIR);
        $db->prepare("UPDATE yy_volume SET $col = NULL, volume_img_icon = NULL, volume_img_icon_slot = NULL WHERE volume_key = ?")->execute([$key]);
        jsonResponse(['deleted' => true, 'slot' => $slot, 'path' => null, 'icon' => null, 'icon_slot' => null]);
    }

    $db->prepare("UPDATE yy_volume SET $col = NULL WHERE volume_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true, 'slot' => $slot, 'path' => null]);
}

// ── Rebuild the icon, optionally re-pointing it at a different slot ──────
if ($action === 'regen_icon') {
    // An explicit slot re-points the icon; omitting it rebuilds from whatever
    // slot the icon already uses.
    $want = (string)($body['slot'] ?? $_POST['slot'] ?? '');
    if ($want !== '' && !in_array($want, $COVER_SLOTS, true)) errorResponse('Unknown slot');
    $iconSlot = $want !== '' ? $want : coverIconSlot($vol, $COVER_SLOTS);

    $icon = coverBuildIcon($vol['volume_img_' . $iconSlot] ?? null, $UPLOAD_DIR, $ORIG_DIR, $WEB_DIR);
    if (!$icon) errorResponse('That slot has no image to derive an icon from');
    if (($vol['volume_img_icon'] ?? null) && $vol['volume_img_icon'] !== $icon) {
        coverRetireIcon($vol['volume_img_icon'], $vol, $COVER_SLOTS, $UPLOAD_DIR, $ORIG_DIR);
    }
    $db->prepare("UPDATE yy_volume SET volume_img_icon = ?, volume_img_icon_slot = ? WHERE volume_key = ?")
       ->execute([$icon, $iconSlot, $key]);
    jsonResponse(['icon' => $icon, 'icon_slot' => $iconSlot]);
}

// ── Clear the icon without touching any artwork slot ─────────────────────
if ($action === 'delete_icon') {
    coverRetireIcon($vol['volume_img_icon'] ?? null, $vol, $COVER_SLOTS, $UPLOAD_DIR, $ORIG_DIR);
    $db->prepare("UPDATE yy_volume SET volume_img_icon = NULL, volume_img_icon_slot = NULL WHERE volume_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true, 'icon' => null, 'icon_slot' => null]);
}

// ── Upload / replace a slot ──────────────────────────────────────────────
$slot = (string)($_POST['slot'] ?? '');
if (!in_array($slot, $COVER_SLOTS, true)) errorResponse('Unknown slot');
$col = 'volume_img_' . $slot;                           // whitelisted above

$file = $_FILES['image_file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        errorResponse('Image is larger than the server upload limit (' . ini_get('upload_max_filesize') . ')');
    }
    errorResponse('No file uploaded');
}
if (!is_uploaded_file($file['tmp_name'])) errorResponse('Invalid upload');

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
if ($ext === 'jpeg') $ext = 'jpg';
if (!in_array($ext, $allowed, true)) errorResponse('Image must be a JPG, PNG, GIF or WEBP');
$dims = @getimagesize($file['tmp_name']);
if (!$dims) errorResponse('That file is not a readable image');

// GD needs 4 bytes/pixel however well the file compresses, so reject the
// handful of images too big to decode at all rather than letting the request
// die on a memory fatal. imgMemoryHeadroom() raises the limit for the rest.
$mp = ($dims[0] * $dims[1]) / 1e6;
if (ceil($mp * 1e6 * 4 * 2.2 / 1048576) + 32 > IMG_MEMORY_CAP) {
    errorResponse(sprintf('Image is too large to process (%dx%d, %.1f megapixels). Please save it at a smaller size.', $dims[0], $dims[1], $mp));
}

$name = 'vol' . $key . '-' . $slot . '-' . uniqid('', false) . '.' . $ext;
$origAbs = $ORIG_DIR . '/' . $name;
if (!move_uploaded_file($file['tmp_name'], $origAbs)) errorResponse('Could not store the upload', 500);

// Scaled display copy; if GD can't handle it, serve the original bytes.
$destAbs = $UPLOAD_DIR . '/' . $name;
if (!scaleImage($origAbs, $destAbs, $FULL_MAX_W, $FULL_MAX_H)) {
    copy($origAbs, $destAbs);
}
$webPath = $WEB_DIR . '/' . $name;

// Every slot — not just the 2D front — gets the full derivative set, built
// from the untouched original so no variant is a rescale of a rescale. The
// paths are conventional (<stem>-icon|sm|md|lg.<ext>), so consumers derive
// them with imageSizeUrl() instead of us storing six more columns per slot.
$sizes = makeImageSizes($origAbs, $UPLOAD_DIR, $name);
$oldPath = $vol[$col] ?? null;
$resp = [
    'saved' => true,
    'slot'  => $slot,
    'path'  => $webPath,
    'sizes' => array_map(fn($f) => $WEB_DIR . '/' . $f, $sizes),
];

// Only the icon's own source slot refreshes it — replacing, say, the 3D spine
// must not yank an icon the admin deliberately pointed at the 2D back. The
// exception is a book with no icon at all: whatever is uploaded first becomes
// the source, so a book whose artwork isn't a 2D front still gets a thumbnail.
$adoptsIcon = empty($vol['volume_img_icon']) && empty($vol['volume_img_icon_slot']);
if ($slot === coverIconSlot($vol, $COVER_SLOTS) || $adoptsIcon) {
    // The icon is always derived, never uploaded — it is simply the 'icon'
    // member of the set we just built.
    $icon = !empty($sizes['icon']) ? $WEB_DIR . '/' . $sizes['icon'] : null;
    if (($vol['volume_img_icon'] ?? null) && $vol['volume_img_icon'] !== $icon) {
        coverRetireIcon($vol['volume_img_icon'], $vol, $COVER_SLOTS, $UPLOAD_DIR, $ORIG_DIR);
    }
    $db->prepare("UPDATE yy_volume SET $col = ?, volume_img_icon = ?, volume_img_icon_slot = ? WHERE volume_key = ?")
       ->execute([$webPath, $icon, $slot, $key]);
    $resp['icon'] = $icon;
    $resp['icon_slot'] = $slot;
} else {
    $db->prepare("UPDATE yy_volume SET $col = ? WHERE volume_key = ?")->execute([$webPath, $key]);
}

// Replaced file goes last, so a failed UPDATE never leaves the row pointing
// at bytes we already deleted.
if ($oldPath && $oldPath !== $webPath) coverUnlink($oldPath, $UPLOAD_DIR, $ORIG_DIR);

jsonResponse($resp);
