/* ── video-lightbox.js ── Shared lightbox with comments ──
 *
 * Generic lightbox frame: title bar, content area, comments toggle, nav.
 * Pages supply a renderContent(item, contentEl) callback to fill the
 * content area with a video iframe, image, or anything else.
 */
(function() {
'use strict';

window.VideoLightbox = {};

var _items = [];
var _index = -1;
var _pageCode = '';
var _videoSource = 'youtube';
var _getId = null;
var _getTitle = null;
var _renderContent = null;
var _getThumbnail = null;
var _getSourceUrl = null;
var _expandText = 'Comments';
var _contentText = 'Show Content';

// Load configurable toggle texts
(function() {
    fetch('/api/site-config.php?t=' + Date.now()).then(function(r) { return r.json(); }).then(function(cfg) {
        if (cfg['comments-expand-text']) _expandText = cfg['comments-expand-text'];
        if (cfg['comments-content-text']) _contentText = cfg['comments-content-text'];
    }).catch(function() {});
})();

// ── Inject HTML + CSS once ──
var style = document.createElement('style');
style.textContent = ''
    + '.vl-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:#222; z-index:10000; justify-content:center; align-items:center; }'
    + '.vl-overlay.active { display:flex; }'
    + '.vl-container { width:90%; max-width:900px; position:relative; display:flex; flex-direction:column; height:90vh; box-sizing:border-box; }'
    + '.vl-close { position:absolute; top:-36px; right:0; color:#fff; font-size:1.8rem; cursor:pointer; background:none; border:none; padding:4px 10px; box-shadow:none; z-index:2; }'
    + '.vl-close:hover { color:#E5C86C; }'
    + '.vl-nav { position:absolute; top:50%; transform:translateY(-50%); background:rgba(0,0,0,0.6); color:#fff; border:none; width:40px; height:60px; font-size:2rem; cursor:pointer; z-index:2; border-radius:4px; display:flex; align-items:center; justify-content:center; opacity:0.6; transition:opacity 0.15s; box-shadow:none; }'
    + '.vl-nav:hover { opacity:1; background:rgba(0,0,0,0.8); }'
    + '.vl-prev { left:-56px; }'
    + '.vl-next { right:-56px; }'
    + '.vl-title { background:#222; color:#E5C86C; padding:8px 14px; font-size:0.85rem; font-weight:600; border-radius:8px 8px 0 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex-shrink:0; }'
    + '.vl-content { flex:0 1 auto; transition:height 0.3s ease; overflow:hidden; display:flex; justify-content:center; align-items:center; background:#000; position:relative; min-height:60px; }'
    + '.vl-content iframe { width:100%; aspect-ratio:16/9; border:none; display:block; }'
    + '.vl-content img { max-width:100%; max-height:59vh; object-fit:contain; display:block; }'
    + '.vl-container.expanded .vl-content { height:0; flex:0 0 0; }'
    + '.vl-container:not(.expanded) .vl-content { border-radius:8px 8px 0 0; }'
    + '.vl-container.expanded .vl-title { border-radius:8px 8px 0 0; }'
    + '.vl-toggle { -webkit-appearance:none; appearance:none; width:100%; background:rgba(255,255,255,0.15); border:none; border-top:1px solid rgba(255,255,255,0.2); padding:10px 0; cursor:pointer; font-size:0.85rem; font-weight:600; color:rgba(255,255,255,0.85); text-align:center; transition:background 0.15s; flex-shrink:0; letter-spacing:0.5px; }'
    + '.vl-toggle:hover { background:rgba(255,255,255,0.2); color:#fff; }'
    + '.vl-comments { background:#fff; border-radius:0 0 8px 8px; flex:1 1 0; overflow-y:auto; padding:12px 16px; min-height:120px; }'
    + '.vl-container.expanded .vl-comments { max-height:none; }'
    + '.vl-source-icon { position:absolute;z-index:3;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:opacity 0.15s;opacity:0.75; }'
    + '.vl-source-icon:hover { opacity:1; }'
    + '@media (max-width:1024px) { .vl-prev { left:8px; } .vl-next { right:8px; } }'
    + '@media (max-width:600px) { .vl-container { width:96%; } .vl-nav { width:32px; height:44px; font-size:1.4rem; } .vl-prev { left:4px; } .vl-next { right:4px; } }'
    ;
document.head.appendChild(style);

var overlay = document.createElement('div');
overlay.className = 'vl-overlay';
overlay.id = 'vl-overlay';
overlay.onclick = function(e) { if (e.target === overlay) VideoLightbox.close(); };
overlay.innerHTML = ''
    + '<div class="vl-container" id="vl-container">'
    + '<button class="vl-close" onclick="VideoLightbox.close()">&times;</button>'
    + '<button class="vl-nav vl-prev" onclick="VideoLightbox.nav(-1)">&#8249;</button>'
    + '<button class="vl-nav vl-next" onclick="VideoLightbox.nav(1)">&#8250;</button>'
    + '<div class="vl-title" id="vl-title"></div>'
    + '<div class="vl-content" id="vl-content"></div>'
    + '<button class="vl-toggle" id="vl-toggle" onclick="VideoLightbox.toggleExpand()"></button>'
    + '<div class="vl-comments" id="vl-comments"></div>'
    + '</div>';
document.body.appendChild(overlay);

document.addEventListener('keydown', function(e) {
    if (!overlay.classList.contains('active')) return;
    if (e.key === 'Escape') VideoLightbox.close();
    // Skip arrow nav when typing in a text field, textarea, or rich text editor (including TinyMCE iframe)
    var el = document.activeElement || {};
    var tag = el.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'IFRAME' || el.getAttribute && el.getAttribute('contenteditable')) return;
    if (e.key === 'ArrowLeft') VideoLightbox.nav(-1);
    if (e.key === 'ArrowRight') VideoLightbox.nav(1);
});

/**
 * Configure the lightbox for a page.
 * @param {Object} opts
 * @param {string}   opts.pageCode      - e.g. 'basics', 'vlog', 'music', 'doyouyada'
 * @param {string}   opts.videoSource   - e.g. 'youtube', 'rumble', 'facebook'
 * @param {Function} opts.getId         - (item) => string  (comment key)
 * @param {Function} opts.getTitle      - (item) => string
 * @param {Function} opts.renderContent - (item, containerEl) => void
 *   Fill containerEl with an iframe, img, or any HTML.
 *   For async (e.g. oEmbed), do the fetch inside and update containerEl when ready.
 *
 * Legacy support: getVideoId/getEmbedUrl still work — a default
 * renderContent is built from them if renderContent is not provided.
 */
VideoLightbox.configure = function(opts) {
    _pageCode = opts.pageCode || '';
    _videoSource = opts.videoSource || 'youtube';
    _getId = opts.getId || opts.getVideoId || function(v) { return v.id || v.video_id || ''; };
    _getTitle = opts.getTitle || function(v) { return v.title || v.name || ''; };
    _getThumbnail = opts.getThumbnail || null;
    _getSourceUrl = opts.getSourceUrl || null;

    if (opts.renderContent) {
        _renderContent = opts.renderContent;
    } else {
        // Legacy: build renderContent from getEmbedUrl
        var _getEmbedUrl = opts.getEmbedUrl || function(v) {
            return 'https://www.youtube.com/embed/' + (_getId(v));
        };
        _renderContent = function(item, el) {
            var iframe = document.createElement('iframe');
            iframe.allowFullscreen = true;
            iframe.allow = 'autoplay; encrypted-media';
            var result = _getEmbedUrl(item);
            if (result && typeof result.then === 'function') {
                el.innerHTML = '';
                el.appendChild(iframe);
                result.then(function(url) { if (url) iframe.src = url; });
            } else {
                iframe.src = result || '';
                el.innerHTML = '';
                el.appendChild(iframe);
            }
        };
    }
};

/**
 * Set the item list (videos, images, posts, etc).
 */
VideoLightbox.setVideos = function(items) {
    _items = items || [];
};

VideoLightbox.open = function(idx) {
    if (idx < 0 || idx >= _items.length) return;
    _index = idx;
    _audioMode = false;
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    _render();
};

var _audioMode = false;

VideoLightbox.openAudio = function(idx) {
    if (idx < 0 || idx >= _items.length) return;
    _index = idx;
    _audioMode = true;
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    _render();
};

VideoLightbox.close = function() {
    overlay.classList.remove('active');
    document.getElementById('vl-content').innerHTML = '';
    document.getElementById('vl-container').classList.remove('expanded');
    document.getElementById('vl-toggle').innerHTML = '&#9650; ' + _expandText;
    document.getElementById('vl-comments').innerHTML = '';
    document.body.style.overflow = '';
    if (typeof VideoComments !== 'undefined') VideoComments.close();
    _index = -1;
};

VideoLightbox.nav = function(dir) {
    var next = _index + dir;
    if (next < 0 || next >= _items.length) return;
    _index = next;
    _audioMode = false;
    document.getElementById('vl-container').classList.remove('expanded');
    document.getElementById('vl-toggle').innerHTML = '&#9650; ' + _expandText;
    _render();
};

VideoLightbox.toggleExpand = function() {
    var c = document.getElementById('vl-container');
    var btn = document.getElementById('vl-toggle');

    if (c.classList.contains('expanded')) {
        c.classList.remove('expanded');
        btn.innerHTML = '&#9650; ' + _expandText;
    } else {
        c.classList.add('expanded');
        btn.innerHTML = '&#9660; ' + _contentText;
    }
};

function _render() {
    var item = _items[_index];
    if (!item) return;

    var id = _getId(item);
    var title = _getTitle(item);
    var contentEl = document.getElementById('vl-content');

    document.getElementById('vl-title').textContent = title;

    // Show/hide nav arrows
    document.querySelector('.vl-prev').style.display = _index > 0 ? '' : 'none';
    document.querySelector('.vl-next').style.display = _index < _items.length - 1 ? '' : 'none';

    // Set toggle button text
    document.getElementById('vl-toggle').innerHTML = '&#9650; ' + _expandText;

    // Render content via page-supplied callback
    contentEl.innerHTML = '';
    if (_audioMode && item.audio) {
        var audioUrl = item.audio.indexOf('/') === 0 ? item.audio : '/' + item.audio;
        var audioWrap = document.createElement('div');
        audioWrap.style.cssText = 'width:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:30px 20px;gap:16px;background:#111;min-height:180px;';
        if (item.thumbnail) {
            var poster = document.createElement('img');
            poster.src = item.thumbnail;
            poster.alt = title;
            poster.style.cssText = 'max-width:280px;max-height:160px;border-radius:8px;object-fit:cover;opacity:0.7;';
            audioWrap.appendChild(poster);
        }
        var headphones = document.createElement('div');
        headphones.textContent = '\u{1F3A7}';
        headphones.style.cssText = 'font-size:2.5rem;';
        audioWrap.appendChild(headphones);
        var player = document.createElement('audio');
        player.controls = true;
        player.autoplay = true;
        player.src = audioUrl;
        player.style.cssText = 'width:90%;max-width:500px;';
        audioWrap.appendChild(player);
        var dlLink = document.createElement('a');
        dlLink.href = audioUrl;
        dlLink.download = '';
        dlLink.textContent = 'Download MP3';
        dlLink.style.cssText = 'color:#E5C86C;font-size:0.85rem;text-decoration:none;font-weight:600;';
        audioWrap.appendChild(dlLink);
        contentEl.appendChild(audioWrap);
    } else if (_renderContent) {
        _renderContent(item, contentEl);
    }

    // Add source icon linking to original post/video
    var oldIcon = document.getElementById('vl-source-link');
    if (oldIcon) oldIcon.remove();
    var sourceUrl = _getSourceUrl ? _getSourceUrl(item) : (item.feed_url || item.url || '');
    if (sourceUrl) {
        var svgMap = {
            facebook: '<svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M12 2C6.477 2 2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.129 22 16.99 22 12c0-5.523-4.477-10-10-10z"/></svg>',
            youtube: '<svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.54 3.5 12 3.5 12 3.5s-7.54 0-9.38.55A3.02 3.02 0 0 0 .5 6.19 31.6 31.6 0 0 0 0 12a31.6 31.6 0 0 0 .5 5.81 3.02 3.02 0 0 0 2.12 2.14c1.84.55 9.38.55 9.38.55s7.54 0 9.38-.55a3.02 3.02 0 0 0 2.12-2.14A31.6 31.6 0 0 0 24 12a31.6 31.6 0 0 0-.5-5.81zM9.55 15.57V8.43L15.82 12l-6.27 3.57z"/></svg>',
            rumble: '<svg viewBox="0 0 24 24" width="20" height="20" fill="#fff"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 10.55l-6 4A.65.65 0 0 1 9.62 16V8a.65.65 0 0 1 1.02-.55l6 4a.65.65 0 0 1 0 1.1z"/></svg>'
        };
        var bgMap = { facebook: 'rgba(24,119,242,0.85)', youtube: 'rgba(255,0,0,0.85)', rumble: 'rgba(133,180,44,0.85)' };
        var svg = svgMap[_videoSource] || svgMap.youtube;
        var bg = bgMap[_videoSource] || 'rgba(100,100,100,0.85)';
        var srcLink = document.createElement('a');
        srcLink.id = 'vl-source-link';
        srcLink.href = sourceUrl;
        srcLink.target = '_blank';
        srcLink.rel = 'noopener';
        srcLink.title = 'View on ' + _videoSource.charAt(0).toUpperCase() + _videoSource.slice(1);
        srcLink.className = 'vl-source-icon';
        srcLink.style.background = bg;
        srcLink.innerHTML = svg;
        // Append to content area — use padding to keep inside bounds
        contentEl.appendChild(srcLink);
        // Position inside the content area, accounting for overflow:hidden
        srcLink.style.position = 'absolute';
        srcLink.style.bottom = '10px';
        srcLink.style.right = '10px';
    }

    // Load comments inline
    if (typeof VideoComments !== 'undefined') {
        var thumb = _getThumbnail ? _getThumbnail(item) : '';
        VideoComments.openInline(id, _pageCode, _videoSource, 'vl-comments', title, thumb);
    }
}

})();
