/* ── community-search.js ── Search bar and results ── */
(function() {
'use strict';

window.CommunitySearch = {};

var _timeout = null;

// ── Init search bar ──
CommunitySearch.init = function() {
    var input = document.getElementById('community-search');
    if (!input) return;

    input.addEventListener('input', function() {
        clearTimeout(_timeout);
        var q = this.value.trim();
        if (q.length < 2) {
            CommunitySearch.hideResults();
            return;
        }
        _timeout = setTimeout(function() {
            CommunitySearch.search(q);
        }, 300);
    });

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            CommunitySearch.hideResults();
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            var q = this.value.trim();
            if (q.length >= 2) CommunitySearch.search(q);
        }
    });

    // Close results on outside click
    document.addEventListener('click', function(e) {
        var container = document.getElementById('search-results');
        if (container && !container.contains(e.target) && e.target !== input) {
            CommunitySearch.hideResults();
        }
    });
};

// ── Perform search ──
CommunitySearch.search = function(query) {
    var url = '/api/community-search.php?q=' + encodeURIComponent(query);
    if (Community.currentCategory) {
        url += '&category=' + encodeURIComponent(Community.currentCategory);
    }

    Community.api(url).then(function(data) {
        var results = data.results || [];
        var container = document.getElementById('search-results');
        if (!container) return;

        if (!results.length) {
            container.innerHTML = '<div class="search-empty">No results for "' + Community.esc(query) + '"</div>';
            container.style.display = '';
            return;
        }

        var html = '';
        results.forEach(function(r) {
            html += '<div class="search-result-item" onclick="window.location.hash=\'#topic/' + r.topic_key + '\';CommunitySearch.hideResults();">'
                + '<div class="search-result-title">' + Community.esc(r.topic_title || r.title) + '</div>'
                + '<div class="search-result-meta">'
                + Community.esc(r.user_display_name || '') + ' &middot; ' + Community.timeAgo(r.topic_dtime || r.dtime)
                + (r.match_type === 'reply' ? ' &middot; <em>match in reply</em>' : '')
                + '</div>'
                + (r.snippet ? '<div class="search-result-snippet">' + Community.esc(r.snippet) + '</div>' : '')
                + '</div>';
        });

        container.innerHTML = html;
        container.style.display = '';
    });
};

// ── Hide results ──
CommunitySearch.hideResults = function() {
    var container = document.getElementById('search-results');
    if (container) container.style.display = 'none';
};

// Init when DOM ready
document.addEventListener('DOMContentLoaded', function() {
    CommunitySearch.init();
});

})();
