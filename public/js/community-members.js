/* ── community-members.js ── Member directory ── */
(function() {
'use strict';

window.CommunityMembers = {};

var _currentSort = 'reputation';
var _currentPage = 1;
var _currentQuery = '';

// ── Load member directory ──
CommunityMembers.load = function(page, sort, query) {
    page = page || _currentPage;
    sort = sort || _currentSort;
    query = query !== undefined ? query : _currentQuery;
    _currentPage = page;
    _currentSort = sort;
    _currentQuery = query;

    Community.showView('view-members');
    var el = document.getElementById('view-members');
    el.innerHTML = '<div class="empty-state">Loading members...</div>';

    var url = '/api/community-members.php?page=' + page + '&sort=' + sort;
    if (query) url += '&q=' + encodeURIComponent(query);

    Community.api(url).then(function(data) {
        var members = data.members || [];
        var html = '<div class="section-header">'
            + '<h2 class="section-title">&#128101; Members</h2>'
            + '</div>';

        // Search and sort bar
        html += '<div class="members-toolbar">'
            + '<div class="members-search-wrap">'
            + '<input id="members-search" class="members-search" placeholder="Search members..." value="' + Community.esc(query) + '">'
            + '</div>'
            + '<div class="members-sort">'
            + '<button class="btn btn-sm' + (sort === 'reputation' ? ' btn-primary' : ' btn-outline') + '" onclick="CommunityMembers.load(1,\'reputation\')">Top</button>'
            + '<button class="btn btn-sm' + (sort === 'newest' ? ' btn-primary' : ' btn-outline') + '" onclick="CommunityMembers.load(1,\'newest\')">Newest</button>'
            + '<button class="btn btn-sm' + (sort === 'name' ? ' btn-primary' : ' btn-outline') + '" onclick="CommunityMembers.load(1,\'name\')">A-Z</button>'
            + '</div></div>';

        if (!members.length) {
            html += '<div class="empty-state">No members found</div>';
        } else {
            html += '<div class="members-grid">';
            members.forEach(function(m) {
                var onlineClass = Community.isOnline(m.user_last_active_dtime) ? ' online' : '';
                html += '<div class="member-card" onclick="Community.showProfileCard(event,' + m.user_key + ')">'
                    + '<div class="member-card-avatar">'
                    + '<span class="avatar-wrap' + onlineClass + '">'
                    + (m.user_avatar
                        ? '<img class="member-avatar" src="' + Community.esc(m.user_avatar) + '">'
                        : '<span class="member-avatar-circle">' + Community.esc(Community.initials(m.user_name_display)) + '</span>')
                    + '</span></div>'
                    + '<div class="member-card-name">' + Community.esc(m.user_name_display) + '</div>'
                    + (m.user_handle ? '<div class="member-card-handle">@' + Community.esc(m.user_handle) + '</div>' : '')
                    + '<div class="member-card-stats">'
                    + '<span title="Reputation">&#11088; ' + (m.user_reputation || 0) + '</span>'
                    + '<span title="Topics">&#128172; ' + (m.topic_count || 0) + '</span>'
                    + '</div>'
                    + '</div>';
            });
            html += '</div>';

            // Pagination
            if (data.pages > 1) {
                html += '<div class="pagination">';
                for (var p = 1; p <= data.pages; p++) {
                    html += '<button class="' + (p === page ? 'active' : '') + '" onclick="CommunityMembers.load(' + p + ')">' + p + '</button>';
                }
                html += '</div>';
            }
        }

        el.innerHTML = html;

        // Bind search
        var searchInput = document.getElementById('members-search');
        if (searchInput) {
            var timeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(timeout);
                var q = this.value.trim();
                timeout = setTimeout(function() {
                    CommunityMembers.load(1, _currentSort, q);
                }, 300);
            });
        }
    });
};

})();
