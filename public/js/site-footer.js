/* site-footer.js — renders the entire site footer from a single source */
(function() {
'use strict';

// Inject footer CSS if not already present
if (!document.getElementById('site-footer-css')) {
    var style = document.createElement('style');
    style.id = 'site-footer-css';
    style.textContent = ''
        + 'footer { background: url("/images/yy-bg-bluewave.jpg") center/cover no-repeat; position: relative; overflow: hidden; padding: 30px 20px 20px; color: #E5C86C; }'
        + 'footer .footer-video { position: absolute; top: 50%; left: 50%; min-width: 100%; min-height: 100%; transform: translate(-50%, -50%); object-fit: cover; z-index: 0; }'
        + 'footer .footer-inner { position: relative; z-index: 1; max-width: 1000px; margin: 0 auto; display: flex; gap: 40px; justify-content: center; flex-wrap: wrap; align-items: flex-start; }'
        + 'footer .footer-col { min-width: auto; }'
        + 'footer .footer-col h4 { font-family: "Julius Sans One", sans-serif; font-size: 1rem; color: #E5C86C; border-bottom: 1px solid rgba(229,200,108,0.3); padding-bottom: 6px; margin: 0 0 10px; }'
        + 'footer .footer-col a { display: block; font-size: 0.9rem; color: rgba(255,255,255,0.8); text-decoration: none; padding: 3px 0; }'
        + 'footer .footer-col a:hover { color: #fff; }'
        + 'footer .footer-links { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 20px; }'
        + 'footer .footer-links-2col { grid-template-columns: 1fr 1fr; }'
        + 'footer .footer-links-1col { grid-template-columns: 1fr; }'
        + 'footer .footer-bottom { position: relative; z-index: 1; text-align: center; margin-top: 20px; font-size: 0.8rem; color: rgba(255,255,255,0.5); }';
    document.head.appendChild(style);
}

// Find the footer element
var footer = document.querySelector('footer');
if (!footer) {
    footer = document.createElement('footer');
    document.body.appendChild(footer);
}

// Build footer HTML
footer.innerHTML = '<video class="footer-video" autoplay muted loop playsinline></video>'
    + '<div class="footer-inner">'
    + '<div class="footer-col">'
    + '<h4 data-config="footer-text-books">Books</h4>'
    + '<div class="footer-links footer-links-2col">'
    + '<a href="books#An_Intro_to_God">An Intro to God</a>'
    + '<a href="books#Yada_Yahowah">Yada Yahowah</a>'
    + '<a href="books#Observations">Observations</a>'
    + '<a href="books#Coming_Home">Coming Home</a>'
    + '<a href="books#Babel">Babel</a>'
    + '<a href="books#Twistianity">Twistianity</a>'
    + '<a href="books#God_Damn_Religion">God Damn Religion</a>'
    + '<a href="books#In_the_Company">In the Company</a>'
    + '</div></div>'
    + '<div class="footer-col">'
    + '<h4 data-config="footer-text-links">Links</h4>'
    + '<div style="display:flex;gap:40px;align-items:flex-start;">'
    + '<div class="footer-links footer-links-1col" data-footer-col="1"></div>'
    + '<div class="footer-links footer-links-1col" data-footer-col="2"></div>'
    + '<div class="footer-links footer-links-1col" data-footer-col="3"></div>'
    + '</div></div></div>'
    + '<div class="footer-bottom">Copyright 2001 - 2026</div>';

// Load dynamic footer links
fetch('/api/page-footer.php')
    .then(function(r) { return r.json(); })
    .then(function(cols) {
        footer.querySelectorAll('[data-footer-col]').forEach(function(el) {
            var col = el.getAttribute('data-footer-col');
            var links = cols[col] || [];
            if (links.length) {
                el.innerHTML = links.map(function(l) {
                    return '<a href="' + l.url + '">' + l.title + '</a>';
                }).join('');
            }
        });
    });

// Apply data-config overrides from site-config (if loaded later by another script)
fetch('/api/site-config.php')
    .then(function(r) { return r.json(); })
    .then(function(cfg) {
        footer.querySelectorAll('[data-config]').forEach(function(el) {
            var k = el.getAttribute('data-config');
            if (cfg[k] != null) el.textContent = cfg[k];
        });
    });

// Background video (reuse bg-video.js pattern if available)
if (typeof window._bgVideoSrc !== 'undefined' && window._bgVideoSrc) {
    var vid = footer.querySelector('.footer-video');
    if (vid) vid.src = window._bgVideoSrc;
}

})();
