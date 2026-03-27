/* ── community-notifications.js ── Bell, dropdown, notification list ── */
(function() {
'use strict';

window.CommunityNotifications = {};

var _pollInterval = null;
var _unreadCount = 0;

// ── Render bell icon with badge ──
CommunityNotifications.renderBell = function() {
    var bell = document.getElementById('notification-bell');
    if (!bell) return;
    if (_unreadCount > 0) {
        bell.classList.add('has-unread');
        bell.innerHTML = '&#128276;<span class="notif-badge">' + (_unreadCount > 99 ? '99+' : _unreadCount) + '</span>';
    } else {
        bell.classList.remove('has-unread');
        bell.innerHTML = '&#128276;';
    }
};

// ── Toggle dropdown ──
CommunityNotifications.toggleDropdown = function(event) {
    event.stopPropagation();
    var dd = document.getElementById('notification-dropdown');
    if (!dd) return;
    var isOpen = dd.style.display !== 'none';
    dd.style.display = isOpen ? 'none' : '';
    if (!isOpen) {
        CommunityNotifications.loadDropdown();
    }
};

// ── Load recent notifications for dropdown ──
CommunityNotifications.loadDropdown = function() {
    var dd = document.getElementById('notification-dropdown');
    if (!dd) return;
    dd.innerHTML = '<div class="notif-loading">Loading...</div>';

    Community.api('/api/community-notifications.php?limit=10').then(function(data) {
        var items = data.notifications || [];
        var html = '<div class="notif-header">'
            + '<span>Notifications</span>'
            + (_unreadCount > 0 ? '<a onclick="CommunityNotifications.markAllRead()">Mark all read</a>' : '')
            + '</div>';

        if (!items.length) {
            html += '<div class="notif-empty">No notifications yet</div>';
        } else {
            items.forEach(function(n) {
                var unread = !n.notification_read;
                html += '<div class="notif-item' + (unread ? ' unread' : '') + '" onclick="CommunityNotifications.clickNotification(' + n.notification_key + ',\'' + Community.esc(n.notification_link || '') + '\')">'
                    + '<div class="notif-text">' + Community.esc(n.notification_text) + '</div>'
                    + '<div class="notif-time">' + Community.timeAgo(n.notification_dtime) + '</div>'
                    + '</div>';
            });
        }

        html += '<div class="notif-footer"><a href="#notifications" onclick="document.getElementById(\'notification-dropdown\').style.display=\'none\'">View all</a></div>';
        dd.innerHTML = html;
    });
};

// ── Click notification ──
CommunityNotifications.clickNotification = function(key, link) {
    Community.api('/api/community-notifications.php', {
        method: 'POST',
        body: { action: 'mark_read', notification_key: key }
    }).then(function() {
        CommunityNotifications.fetchUnread();
    });
    document.getElementById('notification-dropdown').style.display = 'none';
    if (link) window.location.hash = link;
};

// ── Mark all read ──
CommunityNotifications.markAllRead = function() {
    Community.api('/api/community-notifications.php', {
        method: 'POST',
        body: { action: 'mark_all_read' }
    }).then(function() {
        _unreadCount = 0;
        CommunityNotifications.renderBell();
        CommunityNotifications.loadDropdown();
    });
};

// ── Fetch unread count ──
CommunityNotifications.fetchUnread = function() {
    if (!Community.currentUser) return;
    Community.api('/api/community-session.php').then(function(data) {
        _unreadCount = data.unread_notifications || 0;
        CommunityNotifications.renderBell();
    });
};

// ── Poll for new notifications every 30s ──
CommunityNotifications.startPolling = function() {
    CommunityNotifications.fetchUnread();
    if (_pollInterval) clearInterval(_pollInterval);
    _pollInterval = setInterval(function() {
        CommunityNotifications.fetchUnread();
    }, 30000);
};

CommunityNotifications.stopPolling = function() {
    if (_pollInterval) {
        clearInterval(_pollInterval);
        _pollInterval = null;
    }
};

// ── Show all notifications view ──
CommunityNotifications.showAll = function() {
    Community.showView('view-notifications');
    var el = document.getElementById('view-notifications');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state">Sign in to see your notifications.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    Community.api('/api/community-notifications.php').then(function(data) {
        var items = data.notifications || [];
        var html = '<div class="section-header">'
            + '<h2 class="section-title">&#128276; Notifications</h2>'
            + (_unreadCount > 0 ? '<button class="btn btn-outline btn-sm" onclick="CommunityNotifications.markAllRead();CommunityNotifications.showAll();">Mark all read</button>' : '')
            + '</div>';

        if (!items.length) {
            html += '<div class="empty-state">No notifications</div>';
        } else {
            html += '<div class="notification-list">';
            items.forEach(function(n) {
                var unread = !n.notification_read;
                html += '<div class="notification-list-item' + (unread ? ' unread' : '') + '">'
                    + '<div class="notif-content" onclick="CommunityNotifications.clickNotification(' + n.notification_key + ',\'' + Community.esc(n.notification_link || '') + '\')">'
                    + '<div class="notif-text">' + Community.esc(n.notification_text) + '</div>'
                    + '<div class="notif-time">' + Community.timeAgo(n.notification_dtime) + '</div>'
                    + '</div></div>';
            });
            html += '</div>';
        }
        el.innerHTML = html;
    });
};

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    var dd = document.getElementById('notification-dropdown');
    var bell = document.getElementById('notification-bell');
    if (dd && dd.style.display !== 'none' && !dd.contains(e.target) && e.target !== bell) {
        dd.style.display = 'none';
    }
});

})();
