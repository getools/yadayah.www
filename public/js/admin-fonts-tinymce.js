/**
 * admin-fonts-tinymce.js — shared TinyMCE font integration.
 *
 * Pulls the central font registry from /api/site-fonts.php and replaces
 * each editor's stock `fontfamily` button with a custom dropdown that:
 *
 *   - Groups items by section_group (NULL group first, then 1, 2, ...).
 *   - Inserts a separator between groups.
 *   - For fonts with a `glyph` (sample text), renders the display name in
 *     the UI font with an SVG icon showing the glyph in the actual font.
 *     For fonts without a `glyph`, the icon shows the display name in the
 *     font (so the entry doubles as a preview).
 *
 * Usage in a TinyMCE init config:
 *
 *   tinymce.init({
 *       toolbar: '... fontfamily_yy fontsize ...',   // ← replaces 'fontfamily'
 *       setup: function(editor){
 *           YYAdminFonts.setup(editor);              // returns a Promise
 *       },
 *       ...
 *   });
 *
 * The helper is idempotent: fetching the registry once and caching for
 * subsequent editors on the same page.
 */
(function() {
    // Prefer the synchronous global set by <script src="/api/site-fonts.js.php">.
    // TinyMCE's setup callback isn't awaited, so the toolbar gets built before
    // an async fetch can resolve — without the pre-loaded global, fontfamily_yy
    // would be silently dropped from the toolbar.
    var fontsPromise = null;
    function fetchFonts() {
        if (window.YY_FONTS && window.YY_FONTS.length) {
            return Promise.resolve(window.YY_FONTS);
        }
        if (fontsPromise) return fontsPromise;
        fontsPromise = fetch('/api/site-fonts.php', { credentials: 'omit' })
            .then(function(r) { return r.json(); })
            .then(function(d) { return Array.isArray(d.fonts) ? d.fonts : []; })
            .catch(function() { return []; });
        return fontsPromise;
    }
    // Synchronous accessor — returns null if data isn't yet loaded.
    function fontsSync() {
        return (window.YY_FONTS && window.YY_FONTS.length) ? window.YY_FONTS : null;
    }

    // CSS-quote a font family for inline style/attribute use.
    function esc(s) {
        return String(s).replace(/[<>&"]/g, function(c) {
            return { '<':'&lt;', '>':'&gt;', '&':'&amp;', '"':'&quot;' }[c];
        });
    }

    // The CSS family for SVG <text font-family="…"> is the FIRST font in
    // the stack (the custom one). Strip wrapping quotes for SVG attr use.
    function primaryFamily(stack) {
        var first = (stack.split(',')[0] || '').trim().replace(/^['"]|['"]$/g, '');
        return first;
    }

    // SVG icon for a font: shows either the glyph (sample text) in the
    // font, or the display name in the font when no glyph is configured.
    function buildIconSvg(font) {
        var family = esc(primaryFamily(font.stack));
        var text   = esc(font.glyph || font.display);
        // 24px high keeps it aligned with TinyMCE's menu item height.
        // letter-length is variable so we widen the viewBox generously.
        return '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="20" viewBox="0 0 80 20">'
             +   '<text x="0" y="15" font-family="' + family + '" font-size="14" fill="currentColor">'
             +     text
             +   '</text>'
             + '</svg>';
    }

    // Build a TinyMCE @font-face CSS string for fonts that map to a known
    // file at /fonts/<family>.{woff2|ttf}. Static for now — the file naming
    // is conventional. If a font isn't in this map it's assumed system or
    // already declared in app.css.
    var FONTFACE_FILES = {
        'YadaTowrah-Times': { woff2: '/fonts/YadaTowrah-Times.woff2', ttf: '/fonts/YadaTowrah-Times.ttf' },
        'Semitic Early'   : { ttf:   '/fonts/SemiticEarly.ttf' },
        'Moabite Stone'   : { ttf:   '/fonts/MoabiteStone.ttf' },
        'Jupiter-Yada'    : { woff2: '/fonts/JupiterYada-Regular.woff2', ttf: '/fonts/JupiterYada-Regular.ttf' }
    };
    function buildFontFaceCss(fonts) {
        var seen = {};
        var out = [];
        fonts.forEach(function(f) {
            var fam = primaryFamily(f.stack);
            if (!FONTFACE_FILES[fam] || seen[fam]) return;
            seen[fam] = true;
            var files = FONTFACE_FILES[fam];
            var sources = [];
            if (files.woff2) sources.push('url("' + files.woff2 + '") format("woff2")');
            if (files.ttf)   sources.push('url("' + files.ttf   + '") format("truetype")');
            out.push('@font-face { font-family: "' + fam + '"; src: ' + sources.join(',') + '; font-display: swap; }');
        });
        return out.join(' ');
    }

    // Group fonts by section_group (null first, then 1, 2, ...).
    function groupFonts(fonts) {
        var groups = {};
        fonts.forEach(function(f) {
            var g = (f.group === null || f.group === undefined) ? -1 : f.group;
            (groups[g] = groups[g] || []).push(f);
        });
        var keys = Object.keys(groups).map(Number).sort(function(a, b) { return a - b; });
        return keys.map(function(k) { return groups[k]; });
    }

    window.YYAdminFonts = {
        // Returns a Promise that resolves to { fontFaceCss, fonts }.
        load: function() {
            return fetchFonts().then(function(fonts) {
                return {
                    fontFaceCss: buildFontFaceCss(fonts),
                    fonts: fonts
                };
            });
        },
        // Wire the custom font button into a TinyMCE editor. Call from setup.
        // Registration MUST happen synchronously inside the setup callback —
        // TinyMCE builds the toolbar from the registry right after setup
        // returns and silently drops unrecognized toolbar names. Pages should
        // include <script src="/api/site-fonts.js.php"> before the editor
        // initializes so window.YY_FONTS is ready. If fonts aren't yet loaded,
        // we fall back to async (button shows up post-init via fetch).
        setup: function(editor) {
            var fonts = fontsSync();
            if (fonts) {
                registerFontButton(editor, fonts);
                return Promise.resolve();
            }
            return fetchFonts().then(function(loaded) { registerFontButton(editor, loaded); });
        }
    };

    function registerFontButton(editor, fonts) {
        // Register one icon per font (idempotent — addIcon will overwrite).
        fonts.forEach(function(f) {
            try {
                editor.ui.registry.addIcon('yy-font-' + f.key, buildIconSvg(f));
            } catch (e) { /* ignore */ }
        });
        editor.ui.registry.addMenuButton('fontfamily_yy', {
            text: 'Font',
            tooltip: 'Font family',
            fetch: function(callback) {
                var items = [];
                var groups = groupFonts(fonts);
                groups.forEach(function(g, idx) {
                    if (idx > 0) items.push({ type: 'separator' });
                    g.forEach(function(f) {
                        items.push({
                            type: 'menuitem',
                            text: f.display,
                            icon: 'yy-font-' + f.key,
                            onAction: (function(stack) {
                                return function() { editor.execCommand('FontName', false, stack); };
                            })(f.stack)
                        });
                    });
                });
                callback(items);
            }
        });
    };
})();
