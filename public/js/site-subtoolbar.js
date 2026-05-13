/* site-subtoolbar.js
 *
 * Auto-fills any element marked with [data-auto-subtoolbar] with the
 * current page's sub-toolbar (siblings under the same yy_page.page_parent_key,
 * sorted by page_parent_sort). The HTML emitted matches the existing
 * .page-tabs markup so each page's CSS theming (gold/transparent on Media,
 * etc.) just keeps working.
 *
 * Usage in any public page:
 *   <div class="page-tabs" data-auto-subtoolbar></div>
 *   <script src="/js/site-subtoolbar.js?v=1"></script>
 */
(function () {
    'use strict';
    var holder = document.querySelector('[data-auto-subtoolbar]');
    if (!holder) return;

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    // Path normalization: ignore trailing slashes for the active comparison.
    var here = (location.pathname || '/').replace(/\/+$/, '') || '/';

    fetch('/api/page-subtoolbar.php?path=' + encodeURIComponent(here), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.items || !d.items.length) {
                // No sub-toolbar for this page — collapse the slot so it doesn't
                // claim vertical space.
                holder.style.display = 'none';
                return;
            }
            holder.innerHTML = d.items.map(function (it) {
                var url = (it.page_url || '').replace(/\/+$/, '') || '/';
                var active = (url.toLowerCase() === here.toLowerCase()) ? ' class="active"' : '';
                return '<a href="' + esc(it.page_url || '#') + '"' + active + '>' + esc(it.page_title || it.page_code || '') + '</a>';
            }).join('');
        })
        .catch(function () { holder.style.display = 'none'; });
})();
