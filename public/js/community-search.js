/* ── community-search.js ── Search bar and results ── */
(function() {
'use strict';

window.CommunitySearch = {};

var _timeout = null;

// ── Init search bar (event delegation — works even if input added/removed dynamically) ──
CommunitySearch.init = function() {
    document.addEventListener('input', function(e) {
        if (!e.target || e.target.id !== 'community-search') return;
        clearTimeout(_timeout);
        var q = e.target.value.trim();
        if (q.length < 2) {
            CommunitySearch.hideResults();
            return;
        }
        _timeout = setTimeout(function() { CommunitySearch.search(q); }, 300);
    });

    document.addEventListener('keydown', function(e) {
        if (!e.target || e.target.id !== 'community-search') return;
        if (e.key === 'Escape') {
            e.target.value = '';
            CommunitySearch.hideResults();
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            var q = e.target.value.trim();
            if (q.length >= 2) CommunitySearch.search(q);
        }
    });

    // Close results on outside click
    document.addEventListener('click', function(e) {
        var container = document.getElementById('search-results');
        var input = document.getElementById('community-search');
        if (container && !container.contains(e.target) && e.target !== input) {
            CommunitySearch.hideResults();
        }
    });
};

// ── Perform search ──
CommunitySearch.search = function(query) {
    // #hashtag = filter by category name
    if (query.charAt(0) === '#' && query.length > 1) {
        var catName = query.substring(1).toLowerCase();
        var cats = Community.categories || [];
        for (var i = 0; i < cats.length; i++) {
            if (cats[i].category_name.toLowerCase() === catName || cats[i].category_slug.toLowerCase() === catName) {
                Community.filterCategory(cats[i].category_slug);
                document.getElementById('community-search').value = '';
                CommunitySearch.hideResults();
                return;
            }
        }
        // No exact match — show as no results
        var container = document.getElementById('search-results');
        if (container) {
            container.innerHTML = '<div class="search-empty">No category "' + Community.esc(query) + '"</div>';
            container.style.display = '';
        }
        return;
    }

    var url = '/api/community-search.php?q=' + encodeURIComponent(query);
    if (Community.currentCategory) {
        url += '&category=' + encodeURIComponent(Community.currentCategory);
    }

    Community.api(url).then(function(data) {
        // API returns separate topics and replies arrays — merge them
        var results = [];
        (data.topics || []).forEach(function(r) { r.match_type = 'topic'; results.push(r); });
        (data.replies || []).forEach(function(r) { r.match_type = 'reply'; results.push(r); });
        // Fallback for any legacy shape
        if (!results.length && data.results) results = data.results;

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
                + '<div class="search-result-title">' + Community.esc(r.topic_title || r.title || '') + '</div>'
                + '<div class="search-result-meta">'
                + Community.esc(r.user_name_display || '') + ' &middot; ' + Community.timeAgo(r.topic_dtime || r.reply_dtime || r.dtime)
                + (r.match_type === 'reply' ? ' &middot; <em>match in reply</em>' : '')
                + '</div>'
                + (r.snippet ? '<div class="search-result-snippet">' + r.snippet + '</div>' : '')
                + '</div>';
        });

        container.innerHTML = html;
        container.style.display = '';
    });
};

// ── Trigger search from button click or external code ──
CommunitySearch.runFromInput = function() {
    var input = document.getElementById('community-search');
    if (!input) return;
    var q = input.value.trim();
    if (q.length < 2) return;
    CommunitySearch.search(q);
};

// ── Hide results ──
CommunitySearch.hideResults = function() {
    var container = document.getElementById('search-results');
    if (container) container.style.display = 'none';
};

// Init: handle both cases — DOM already ready or still loading
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { CommunitySearch.init(); });
} else {
    CommunitySearch.init();
}

})();
