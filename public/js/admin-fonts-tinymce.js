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

    // SVG icon for a font — renders the FULL visual since we suppress the
    // menu item's separate text label. Avoids the duplicated "Name (UI
    // font) | Name (own font)" we'd otherwise show on non-glyph fonts.
    //
    //   - With glyph: display name in UI font, sample glyph alongside in
    //     the target font (e.g. "Yada Towrah  HWHY").
    //   - Without glyph: display name rendered in the target font only
    //     (matches TinyMCE's stock font preview behavior).
    //
    // The "data-yy-font" attribute lets us target these SVGs (and their
    // surrounding menu items) with the injected dropdown CSS below.
    function buildIconSvg(font) {
        var family  = esc(primaryFamily(font.stack));
        var display = esc(font.display);
        var H = 26;
        var charNarrow = 8;   // ~px/char at UI font, size 15
        var charWide   = 12;  // ~px/char at sample size 20
        if (font.glyph) {
            var glyph  = esc(font.glyph);
            var nameW  = display.length * charNarrow + 6;
            var gap    = 18;
            var glyphW = glyph.length   * charWide   + 6;
            var W      = nameW + gap + glyphW;
            return '<svg xmlns="http://www.w3.org/2000/svg" data-yy-font="1" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">'
                 +   '<text x="0" y="' + (H - 7) + '" font-size="15" fill="currentColor">' + display + '</text>'
                 +   '<text x="' + (nameW + gap) + '" y="' + (H - 5) + '" font-family="' + family + '" font-size="20" fill="currentColor">' + glyph + '</text>'
                 + '</svg>';
        }
        var size = 20;
        var w = display.length * charWide + 6;
        return '<svg xmlns="http://www.w3.org/2000/svg" data-yy-font="1" width="' + w + '" height="' + H + '" viewBox="0 0 ' + w + ' ' + H + '">'
             +   '<text x="0" y="' + (H - 5) + '" font-family="' + family + '" font-size="' + size + '" fill="currentColor">' + display + '</text>'
             + '</svg>';
    }

    // One-time CSS injection: widen the icon column so our oversized SVGs
    // aren't clipped, and hide the empty label slot for our font items.
    function injectDropdownCss() {
        if (document.getElementById('yy-font-dropdown-css')) return;
        var css =
            '.tox-collection__item-icon:has(svg[data-yy-font]) { width: auto !important; flex: 0 0 auto !important; padding-right: 8px; }' +
            '.tox-collection__item-icon svg[data-yy-font] { width: auto !important; height: 26px !important; }' +
            '.tox-collection__item:has(svg[data-yy-font]) .tox-collection__item-label { display: none !important; }';
        var el = document.createElement('style');
        el.id = 'yy-font-dropdown-css';
        el.textContent = css;
        document.head.appendChild(el);
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
        // Widen icon column + hide empty label slot for our font items.
        injectDropdownCss();
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
                            // text intentionally a single space — TinyMCE
                            // still renders the row, but our injected CSS
                            // hides the label so the icon is the only
                            // visible element. Accessible name comes from
                            // `aria-label` (TinyMCE falls back to icon name
                            // when text is blank) — set tooltip explicitly.
                            text: ' ',
                            tooltip: f.display,
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
