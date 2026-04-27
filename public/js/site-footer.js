/* site-footer.js — renders the entire site footer from a single source */
(function() {
'use strict';

// ── Livestream indicator + embedded player popover + admin control ──
(function() {
    var lsStyle = document.createElement('style');
    lsStyle.textContent = ''
        + '#livestream-indicator { position:fixed;top:12px;left:12px;z-index:99999;background:linear-gradient(135deg,#c0392b,#e74c3c);color:#fff;border:none;border-radius:8px;padding:0;font-size:0.8rem;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(192,57,43,0.4);animation:livestream-pulse 2s infinite;overflow:hidden; }'
        + '#livestream-indicator:hover { transform:scale(1.05);box-shadow:0 4px 16px rgba(192,57,43,0.5); }'
        + '#livestream-indicator .live-label { display:flex;align-items:center;gap:6px;padding:6px 14px; }'
        + '#livestream-indicator .live-dot { width:8px;height:8px;background:#fff;border-radius:50%;animation:livestream-blink 1s infinite;flex-shrink:0; }'
        + '#livestream-indicator .live-thumb { display:block;width:auto;height:56px;object-fit:cover; }'
        + '@keyframes livestream-blink { 0%,100%{opacity:1} 50%{opacity:0.3} }'
        + '@keyframes livestream-pulse { 0%,100%{box-shadow:0 2px 10px rgba(192,57,43,0.4)} 50%{box-shadow:0 2px 20px rgba(192,57,43,0.6)} }'
        + '#livestream-popover { display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:100000;justify-content:center;align-items:center; }'
        + '#livestream-popover-inner { background:#000;border-radius:12px;overflow:hidden;width:90vw;max-width:1200px;height:80vh;max-height:700px;display:flex;position:relative;box-shadow:0 8px 40px rgba(0,0,0,0.5); }'
        + '#livestream-popover-inner .ls-player { flex:1;min-width:0; }'
        + '#livestream-popover-inner .ls-player iframe { width:100%;height:100%;border:none; }'
        + '#livestream-popover-inner .ls-chat { width:340px;border-left:1px solid #333; }'
        + '#livestream-popover-inner .ls-chat iframe { width:100%;height:100%;border:none; }'
        + '#livestream-popover-close { position:absolute;top:8px;right:8px;z-index:10;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:32px;height:32px;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center; }'
        + '#livestream-popover-close:hover { background:rgba(255,255,255,0.2); }'
        + '@media (max-width:768px) { #livestream-popover-inner { flex-direction:column;width:95vw;height:90vh; } #livestream-popover-inner .ls-chat { width:100%;height:40%;border-left:none;border-top:1px solid #333; } }'
        + '#stream-admin-btn { position:fixed;top:12px;left:12px;z-index:99998;width:36px;height:36px;border-radius:50%;border:none;background:rgba(192,57,43,0.8);color:#fff;font-size:0.7rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.3); }'
        + '#stream-admin-modal { display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100000;justify-content:center;align-items:center; }';
    document.head.appendChild(lsStyle);

    var _streamFeedKey = null;
    var _streamVideoId = null;

    function extractVideoId(url) {
        if (!url) return null;
        var m = url.match(/[?&]v=([^&]+)/) || url.match(/youtu\.be\/([^?&]+)/) || url.match(/embed\/([^?&]+)/);
        return m ? m[1] : null;
    }

    function checkLivestream() {
        fetch('/api/livestream-status.php').then(function(r) { return r.json(); }).then(function(d) {
            var existing = document.getElementById('livestream-indicator');
            _streamFeedKey = d.feed_key || null;

            if (d.live) {
                _streamVideoId = extractVideoId(d.feed_source_url);
                if (!existing) {
                    var btn = document.createElement('button');
                    btn.id = 'livestream-indicator';
                    btn.title = 'Watch live stream';
                    btn.onclick = function() { openLivestreamPopover(); };
                    var label = '<div class="live-label"><span class="live-dot"></span> LIVE</div>';
                    var thumb = _streamVideoId ? '<img class="live-thumb" src="https://img.youtube.com/vi/' + _streamVideoId + '/mqdefault.jpg" alt="Live">' : '';
                    btn.innerHTML = label + thumb;
                    document.body.appendChild(btn);
                }
                var ghost = document.getElementById('stream-admin-btn');
                if (ghost) ghost.remove();
            } else {
                if (existing) existing.remove();
                closeLivestreamPopover();
                showAdminStreamButton(d);
            }
        }).catch(function() {});
    }

    function openLivestreamPopover() {
        var pop = document.getElementById('livestream-popover');
        if (!pop) {
            pop = document.createElement('div');
            pop.id = 'livestream-popover';
            pop.onclick = function(ev) { if (ev.target === pop) closeLivestreamPopover(); };
            pop.innerHTML = '<div id="livestream-popover-inner">'
                + '<button id="livestream-popover-close" onclick="window._closeLivePopover()">&times;</button>'
                + '<div class="ls-player"><iframe id="ls-player-frame" allow="autoplay;encrypted-media" allowfullscreen></iframe></div>'
                + '<div class="ls-chat"><iframe id="ls-chat-frame"></iframe></div>'
                + '</div>';
            document.body.appendChild(pop);
        }
        if (_streamVideoId) {
            document.getElementById('ls-player-frame').src = 'https://www.youtube.com/embed/' + _streamVideoId + '?autoplay=1';
            document.getElementById('ls-chat-frame').src = 'https://www.youtube.com/live_chat?v=' + _streamVideoId + '&embed_domain=' + location.hostname;
        }
        pop.style.display = 'flex';
    }

    function closeLivestreamPopover() {
        var pop = document.getElementById('livestream-popover');
        if (pop) {
            pop.style.display = 'none';
            var player = document.getElementById('ls-player-frame');
            var chat = document.getElementById('ls-chat-frame');
            if (player) player.src = '';
            if (chat) chat.src = '';
        }
    }
    window._closeLivePopover = closeLivestreamPopover;

    function showAdminStreamButton(d) {
        if (document.getElementById('stream-admin-btn')) return;
        fetch('/api/community-session.php', {credentials:'include'}).then(function(r) { return r.json(); }).then(function(sess) {
            if (!sess.user || !sess.user.roles) return;
            var isAdmin = sess.user.roles.indexOf('admin') >= 0 || sess.user.roles.indexOf('moderator') >= 0;
            if (!isAdmin) return;

            var btn = document.createElement('button');
            btn.id = 'stream-admin-btn';
            btn.style.opacity = '0.4';
            btn.innerHTML = '&#9679;<br>LIVE';
            btn.title = 'Stream control (admin)';
            btn.onmouseover = function() { btn.style.opacity = '0.8'; };
            btn.onmouseout = function() { btn.style.opacity = '0.4'; };
            btn.onclick = function() { openStreamAdminModal(); };
            document.body.appendChild(btn);
        }).catch(function() {});
    }

    function openStreamAdminModal() {
        var modal = document.getElementById('stream-admin-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'stream-admin-modal';
            modal.onclick = function(ev) { if (ev.target === modal) modal.style.display = 'none'; };
            modal.innerHTML = '<div style="background:#fff;border-radius:10px;padding:24px;max-width:400px;width:90%;text-align:center;">'
                + '<h3 style="margin:0 0 8px;font-size:1rem;color:#31345A;">Stream Control</h3>'
                + '<p id="stream-admin-info" style="font-size:0.85rem;color:#666;margin:0 0 20px;"></p>'
                + '<div style="display:flex;flex-direction:column;gap:10px;">'
                + '<button onclick="window._streamAdminAction(\'start\')" style="padding:10px;background:#c0392b;color:#fff;border:none;border-radius:6px;font-size:0.9rem;font-weight:600;cursor:pointer;">&#9679; Start Stream (set to now)</button>'
                + '<button onclick="window._streamAdminAction(\'end\')" style="padding:10px;background:#888;color:#fff;border:none;border-radius:6px;font-size:0.9rem;cursor:pointer;">End Stream (clear time)</button>'
                + '<button onclick="document.getElementById(\'stream-admin-modal\').style.display=\'none\'" style="padding:10px;background:#f0f0f0;color:#333;border:none;border-radius:6px;font-size:0.9rem;cursor:pointer;">Cancel</button>'
                + '</div></div>';
            document.body.appendChild(modal);
        }
        document.getElementById('stream-admin-info').textContent = _streamFeedKey ? 'Feed #' + _streamFeedKey : 'No stream-enabled feed found';
        modal.style.display = 'flex';
    }

    window._streamAdminAction = function(action) {
        if (!_streamFeedKey) {
            // Find a stream-enabled feed
            fetch('/api/livestream-status.php?all=1').then(function(r) { return r.json(); }).then(function(d) {
                if (d.feed_key) { _streamFeedKey = d.feed_key; doStreamAction(action); }
                else { alert('No feed with stream_flag enabled. Enable it in Admin > Feeds first.'); }
            });
            return;
        }
        doStreamAction(action);
    };

    function doStreamAction(action) {
        var dtime = action === 'start' ? new Date().toISOString() : null;
        fetch('/api/admin-feeds.php', {
            method: 'POST', credentials: 'include',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({feed_key: _streamFeedKey, feed_stream_dtime: dtime})
        }).then(function(r) { return r.json(); }).then(function() {
            document.getElementById('stream-admin-modal').style.display = 'none';
            // Clear cache and recheck
            fetch('/api/livestream-status.php?bust=' + Date.now()).then(function() {
                var ghost = document.getElementById('stream-admin-btn');
                if (ghost) ghost.remove();
                var banner = document.getElementById('livestream-banner');
                if (banner) { banner.remove(); document.body.classList.remove('has-livestream'); }
                checkLivestream();
            });
        }).catch(function() { alert('Failed — are you logged in as admin?'); });
    }

    checkLivestream(); // Single check on page load — updates come via WebSub push
})();

// Load error reporter
if (!document.getElementById('error-reporter-js')) {
    var s = document.createElement('script');
    s.id = 'error-reporter-js';
    s.src = '/js/error-reporter.js?v=5';
    document.head.appendChild(s);
}

// Google Analytics 4
if (!document.getElementById('ga-script')) {
    var ga = document.createElement('script');
    ga.id = 'ga-script';
    ga.async = true;
    ga.src = 'https://www.googletagmanager.com/gtag/js?id=G-VL844ZWNR9';
    document.head.appendChild(ga);
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-VL844ZWNR9');
}

// Inject footer CSS if not already present
if (!document.getElementById('site-footer-css')) {
    var style = document.createElement('style');
    style.id = 'site-footer-css';
    style.textContent = ''
        + 'html, body { overflow-x: hidden; max-width: 100%; }'
        + 'footer { background: url("/images/yy-bg-bluewave.jpg") center/cover no-repeat; position: relative; width: 100%; overflow: hidden; padding: 30px 20px 20px; color: #E5C86C; box-sizing: border-box; margin: auto -20px 0; padding-left: 20px; padding-right: 20px; width: calc(100% + 40px); }'
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

// Background video — apply from sessionStorage since bg-video.js may have run before footer was created
(function() {
    var vid = footer.querySelector('.footer-video');
    if (!vid) return;
    var webm = sessionStorage.getItem('yy_bg_video');
    var mp4 = sessionStorage.getItem('yy_bg_mp4');
    var thumb = sessionStorage.getItem('yy_bg_thumb');
    if (thumb) vid.setAttribute('poster', thumb);
    if (webm) {
        var s1 = document.createElement('source');
        s1.setAttribute('src', webm);
        s1.setAttribute('type', 'video/webm');
        vid.appendChild(s1);
        if (mp4) {
            var s2 = document.createElement('source');
            s2.setAttribute('src', mp4);
            s2.setAttribute('type', 'video/mp4');
            vid.appendChild(s2);
        }
        vid.load();
    } else if (typeof window._bgVideoSrc !== 'undefined' && window._bgVideoSrc) {
        vid.src = window._bgVideoSrc;
    }
})();

// ── Scroll nav buttons ──
var scrollBtnStyle = document.createElement('style');
scrollBtnStyle.textContent = ''
    + '.scroll-btn { display:none; position:fixed; right:8px; width:40px; height:40px; border-radius:50%; background:rgba(49,52,90,0.85); color:#E5C86C; border:none; font-size:1.1rem; cursor:pointer; z-index:9999; box-shadow:0 2px 8px rgba(0,0,0,0.3); transition:opacity 0.2s,background 0.2s; opacity:0.7; align-items:center; justify-content:center; }'
    + '.scroll-btn:hover { opacity:1; background:rgba(49,52,90,1); }'
    + '.scroll-btn.visible { display:flex; }';
document.head.appendChild(scrollBtnStyle);

// Back to top — fixed bottom-right
var btt = document.createElement('button');
btt.id = 'back-to-top';
btt.className = 'scroll-btn';
btt.style.bottom = '8px';
btt.style.right = '8px';
btt.innerHTML = '&#9650;';
btt.title = 'Back to top';
btt.setAttribute('aria-label', 'Back to top');
btt.onclick = function() { window.scrollTo({ top: 0, behavior: 'smooth' }); };
document.body.appendChild(btt);

// Scroll to bottom — fixed top-right
var btb = document.createElement('button');
btb.id = 'scroll-to-bottom';
btb.className = 'scroll-btn';
btb.style.top = '8px';
btb.style.right = '8px';
btb.innerHTML = '&#9660;';
btb.title = 'Go to bottom';
btb.setAttribute('aria-label', 'Go to bottom');
btb.onclick = function() {
    btb.classList.remove('visible');
    var ft = document.querySelector('footer');
    if (ft) { var y = ft.getBoundingClientRect().top + window.scrollY - window.innerHeight; window.scrollTo({ top: y, behavior: 'smooth' }); }
    else { window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }); }
};
document.body.appendChild(btb);

function updateScrollButtons() {
    var docHeight = document.documentElement.scrollHeight;
    var winHeight = window.innerHeight;
    var scrollY = window.scrollY;
    var ft = document.querySelector('footer');
    var footerHeight = ft ? ft.offsetHeight : 0;
    var contentBottom = docHeight - footerHeight;
    var atBottom = scrollY + winHeight >= contentBottom - 50;
    var hasOverflow = contentBottom > winHeight + 150;
    var showUp = scrollY > 150;
    var showDown = hasOverflow && !atBottom;
    btt.classList.toggle('visible', showUp);
    btb.classList.toggle('visible', showDown);
    // Force display in case inline styles override the class
    btt.style.display = showUp ? 'flex' : 'none';
    btb.style.display = showDown ? 'flex' : 'none';
}
window.addEventListener('scroll', updateScrollButtons, { passive: true });
window.addEventListener('resize', updateScrollButtons, { passive: true });
// Run after page settles (images, dynamic content)
updateScrollButtons();
setTimeout(updateScrollButtons, 500);
setTimeout(updateScrollButtons, 2000);
setTimeout(updateScrollButtons, 5000);
// Re-check when DOM changes (dynamic content loading)
if (typeof MutationObserver !== 'undefined') {
    var _scrollBtnTimer = null;
    new MutationObserver(function() {
        clearTimeout(_scrollBtnTimer);
        _scrollBtnTimer = setTimeout(updateScrollButtons, 200);
    }).observe(document.body, { childList: true, subtree: true });
}

// ── Live Feed indicator ──
(function() {
    var _liveStyleInjected = false;
    var _isLive = false;
    var _liveStarted = 0; // timestamp when live was first detected
    var MAX_LIVE_POLL = 3 * 60 * 60 * 1000; // 3 hours

    var _liveData = null;

    function injectLiveStyle() {
        if (_liveStyleInjected) return;
        _liveStyleInjected = true;
        var style = document.createElement('style');
        style.textContent = ''
            + '#live-indicator { position:fixed; top:8px; left:8px; z-index:9999; cursor:pointer; }'
            + '#live-indicator .live-badge { display:flex; align-items:center; gap:6px; background:rgba(200,0,0,0.9); color:#fff; padding:6px 14px 6px 10px; border-radius:8px 8px 8px 0; font-size:0.8rem; font-weight:700; box-shadow:0 2px 10px rgba(200,0,0,0.4); transition:background 0.15s; position:absolute; top:0; left:0; z-index:2; }'
            + '#live-indicator:hover .live-badge { background:rgba(220,0,0,1); }'
            + '#live-indicator:hover { background:rgba(220,0,0,1); }'
            + ''
            + '#live-indicator .live-dot { width:10px; height:10px; background:#fff; border-radius:50%; animation:live-pulse 1.5s ease-in-out infinite; }'
            + '@keyframes live-pulse { 0%,100% { opacity:1; } 50% { opacity:0.3; } }'
            + '.live-preview { width:200px; border-radius:8px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,0.5); background:#000; position:relative; }'
            + '.live-preview img { width:100%; display:block; }'
            + '.live-preview .live-preview-title { padding:6px 8px; font-size:0.7rem; font-weight:400; color:#ccc; line-height:1.3; white-space:normal; }'
            + '#live-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.85); z-index:10001; justify-content:center; align-items:center; }'
            + '#live-overlay.active { display:flex; }'
            + '#live-container { display:flex; width:95vw; max-width:1400px; height:85vh; border-radius:10px; overflow:hidden; box-shadow:0 4px 30px rgba(0,0,0,0.6); position:relative; }'
            + '#live-container .live-video { flex:1; min-width:0; background:#000; }'
            + '#live-container .live-video iframe { width:100%; height:100%; border:none; }'
            + '#live-container .live-chat { width:360px; flex-shrink:0; background:#fff; }'
            + '#live-container .live-chat iframe { width:100%; height:100%; border:none; }'
            + '#live-close { position:absolute; top:-36px; right:0; color:#fff; font-size:1.8rem; cursor:pointer; background:none; border:none; padding:4px 10px; z-index:2; }'
            + '#live-close:hover { color:#E5C86C; }'
            + '#live-title-bar { position:absolute; top:-36px; left:0; color:#E5C86C; font-size:0.85rem; font-weight:600; padding:4px 10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:calc(100% - 60px); }'
            + '@media (max-width:800px) { #live-container { flex-direction:column; width:98vw; height:92vh; } #live-container .live-chat { width:100%; height:280px; } }';
        document.head.appendChild(style);
    }

    function openLivePopover() {
        if (!_liveData) return;
        var existing = document.getElementById('live-overlay');
        if (existing) existing.remove();

        var videoId = _liveData.video_id || '';
        var embedUrl = _liveData.embed_url || ('https://www.youtube.com/embed/' + videoId + '?autoplay=1');
        var chatUrl = 'https://www.youtube.com/live_chat?v=' + videoId + '&embed_domain=' + location.hostname;
        var title = _liveData.title || 'Live Now';

        var overlay = document.createElement('div');
        overlay.id = 'live-overlay';
        overlay.innerHTML = '<div id="live-container">'
            + '<div id="live-title-bar">' + title.replace(/</g, '&lt;') + '</div>'
            + '<button id="live-close" onclick="document.getElementById(\'live-overlay\').remove()" title="Close">&times;</button>'
            + '<div class="live-video"><iframe src="' + embedUrl + '" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>'
            + '<div class="live-chat"><iframe src="' + chatUrl + '"></iframe></div>'
            + '</div>';
        overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
        overlay.classList.add('active');
    }

    function checkLive() {
        fetch('/api/live-check.php').then(function(r) { return r.json(); }).then(function(d) {
            var existing = document.getElementById('live-indicator');
            if (existing) existing.remove();

            if (d.live) {
                if (!_isLive) _liveStarted = Date.now();
                _isLive = true;
                _liveData = d;
                injectLiveStyle();
                var btn = document.createElement('div');
                btn.id = 'live-indicator';
                var isUpcoming = d.upcoming;
                var badgeLabel = isUpcoming ? 'UPCOMING' : 'LIVE';
                var titleText = d.title || (isUpcoming ? 'Upcoming' : 'Live Now');
                btn.title = titleText;
                var previewHtml = '';
                var thumbUrl = d.thumbnail || (d.video_id ? 'https://i.ytimg.com/vi/' + d.video_id + '/mqdefault.jpg' : '');
                if (thumbUrl) {
                    var scheduleInfo = '';
                    if (isUpcoming && d.scheduled_start) {
                        var startDate = new Date(d.scheduled_start);
                        scheduleInfo = '<div style="font-size:0.7rem;color:#E5C86C;margin-top:2px;">' + startDate.toLocaleString(undefined, {weekday:'short', month:'short', day:'numeric', hour:'numeric', minute:'2-digit'}) + '</div>';
                    }
                    previewHtml = '<div class="live-preview">'
                        + '<img src="' + thumbUrl + '" alt="">'
                        + '<div class="live-preview-title">' + titleText.replace(/</g, '&lt;') + '</div>'
                        + scheduleInfo
                        + '</div>';
                }
                var badgeColor = isUpcoming ? 'background:#E5C86C;color:#333;' : '';
                btn.innerHTML = '<div class="live-badge" style="' + badgeColor + '"><span class="live-dot" style="' + (isUpcoming ? 'background:#333;' : '') + '"></span> ' + badgeLabel + '</div>' + previewHtml;
                btn.addEventListener('click', openLivePopover);
                document.body.appendChild(btn);
            } else {
                _isLive = false;
                _liveStarted = 0;
                _liveData = null;
            }

            scheduleNext();
        }).catch(function() { scheduleNext(); });
    }

    function scheduleNext() {
        var delay;
        if (_isLive) {
            // During live: poll every 2 minutes to detect stream end, up to 3 hours
            if (Date.now() - _liveStarted > MAX_LIVE_POLL) {
                // Exceeded 3 hours — stop polling, clear live indicator
                _isLive = false;
                _liveStarted = 0;
                var el = document.getElementById('live-indicator');
                if (el) el.remove();
                return; // Stop polling
            }
            delay = 120000; // 2 minutes
        } else {
            // Not live: check once on page load (already done), then stop
            // Push handles new live events — no need to keep polling
            return;
        }
        setTimeout(checkLive, delay);
    }

    // Initial check on page load
    checkLive();
})();

})();
