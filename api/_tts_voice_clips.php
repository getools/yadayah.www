<?php
/**
 * Prod-side copy of each custom voice's training (reference) clips.
 *
 * The clips the engine actually trains from live on the GPU box, which is
 * often offline and has no route to download them. So every clip uploaded
 * through admin-tts-customize.php is ALSO kept here, one per style, and
 * admin-tts-voice-edit.php?action=clip_audio streams it (admin auth) so the
 * Edit Custom Voice popover can play each style's clip.
 *
 * Layout: u/tts-voice-clips/<code>/<style>.<ext> + <style>.json {orig_name,
 * saved}. The directory carries a deny-all .htaccess — never served directly.
 */

function ttsClipRoot(): string {
    return dirname(__DIR__) . '/u/tts-voice-clips';   // /var/www/html/u/tts-voice-clips
}

function ttsClipSafe(string $s): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]+$/', $s);
}

function ttsClipEnsureRoot(): bool {
    $root = ttsClipRoot();
    if (!is_dir($root) && !@mkdir($root, 02775, true)) return false;
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");
    return true;
}

/** Find the stored clip for code+style: ['path','orig_name','ext','bytes'] or null. */
function ttsClipFind(string $code, string $style): ?array {
    if (!ttsClipSafe($code) || !ttsClipSafe($style)) return null;
    $dir = ttsClipRoot() . '/' . $code;
    foreach (['mp3', 'wav', 'm4a', 'flac', 'ogg'] as $ext) {
        $p = "$dir/$style.$ext";
        if (is_file($p)) {
            $meta = json_decode((string)@file_get_contents("$dir/$style.json"), true) ?: [];
            return ['path' => $p, 'ext' => $ext, 'bytes' => filesize($p),
                    'orig_name' => (string)($meta['orig_name'] ?? "$style.$ext")];
        }
    }
    return null;
}

/** Store (replace) the clip for code+style. Returns true on success. */
function ttsClipSave(string $code, string $style, string $srcPath, string $origName): bool {
    if (!ttsClipSafe($code) || !ttsClipSafe($style) || !is_file($srcPath)) return false;
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp3', 'wav', 'm4a', 'flac', 'ogg'], true)) return false;
    if (!ttsClipEnsureRoot()) return false;
    $dir = ttsClipRoot() . '/' . $code;
    if (!is_dir($dir) && !@mkdir($dir, 02775, true)) return false;
    // Copy first, then swap, so a failed copy never loses the old clip.
    $tmp = "$dir/.tmp-$style.$ext";
    if (!@copy($srcPath, $tmp)) { @unlink($tmp); return false; }
    ttsClipDelete($code, $style);
    if (!@rename($tmp, "$dir/$style.$ext")) { @unlink($tmp); return false; }
    @file_put_contents("$dir/$style.json", json_encode(['orig_name' => $origName, 'saved' => date('c')]));
    return true;
}

/** Delete one style's clip, or every clip of the voice when $style is ''. */
function ttsClipDelete(string $code, string $style = ''): void {
    if (!ttsClipSafe($code)) return;
    $dir = ttsClipRoot() . '/' . $code;
    if (!is_dir($dir)) return;
    if ($style === '') {
        foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
        @rmdir($dir);
        return;
    }
    if (!ttsClipSafe($style)) return;
    foreach (glob("$dir/$style.*") ?: [] as $f) @unlink($f);
}

/** Move a voice's clips when its code is renamed. */
function ttsClipRename(string $code, string $newCode): void {
    if (!ttsClipSafe($code) || !ttsClipSafe($newCode)) return;
    $src = ttsClipRoot() . '/' . $code;
    $dst = ttsClipRoot() . '/' . $newCode;
    if (is_dir($src) && !file_exists($dst)) @rename($src, $dst);
}
