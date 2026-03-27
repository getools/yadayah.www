/* ── community-notifications.js ── Bell, dropdown, SSE real-time updates ── */
(function() {
'use strict';

window.CommunityNotifications = {};

var _eventSource = null;
var _unreadCount = 0;
var _unreadDm = 0;

// ── Render bell icon with badge ──
CommunityNotifications.renderBell = function() {
    var bell = document.getElementById('notification-bell');
    if (!bell) return;
    if (_unreadDm > 0) {
        bell.classList.add('has-unread');
        bell.innerHTML = '&#128276;<span class="notif-badge">' + (_unreadDm > 99 ? '99+' : _unreadDm) + '</span>';
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

// ── Fetch unread count (fallback, used after mark_read) ──
CommunityNotifications.fetchUnread = function() {
    if (!Community.currentUser) return;
    Community.api('/api/community-session.php').then(function(data) {
        if (data.user) {
            _unreadCount = data.user.unread_notifications || 0;
            _unreadDm = data.user.unread_dm || 0;
        }
        CommunityNotifications.renderBell();
    });
};

// ── Start real-time updates: SSE + polling fallback ──
CommunityNotifications.startPolling = function() {
    CommunityNotifications.fetchUnread();

    // Close any existing connections
    CommunityNotifications.stopPolling();

    // Always poll every 10s as baseline (covers SSE failures, read-receipts, etc.)
    CommunityNotifications._fallbackInterval = setInterval(function() {
        CommunityNotifications.fetchUnread();
    }, 10000);

    // Layer SSE on top for instant push of new messages
    if (window.EventSource) {
        _eventSource = new EventSource('/api/community-sse.php');

        _eventSource.addEventListener('counts', function(e) {
            var data = JSON.parse(e.data);
            _unreadCount = data.unread_notifications || 0;
            _unreadDm = data.unread_dm || 0;
            CommunityNotifications.renderBell();
            if (typeof CommunityDM !== 'undefined' && CommunityDM.updateUnreadBadge) {
                CommunityDM.updateUnreadBadge(_unreadDm);
            }
        });

        _eventSource.addEventListener('dm', function(e) {
            var msg = JSON.parse(e.data);
            if (typeof CommunityDM !== 'undefined' && CommunityDM.onNewMessage) {
                CommunityDM.onNewMessage(msg);
            }
        });

        _eventSource.addEventListener('reconnect', function() {
            if (_eventSource) { _eventSource.close(); _eventSource = null; }
            setTimeout(function() {
                if (Community.currentUser && !_eventSource) {
                    _eventSource = new EventSource('/api/community-sse.php');
                }
            }, 1000);
        });
    }
};

CommunityNotifications.stopPolling = function() {
    if (_eventSource) {
        _eventSource.close();
        _eventSource = null;
    }
    if (CommunityNotifications._fallbackInterval) {
        clearInterval(CommunityNotifications._fallbackInterval);
        CommunityNotifications._fallbackInterval = null;
    }
};

// ── Expose unread DM count ──
CommunityNotifications.getUnreadDm = function() { return _unreadDm; };

// ── Instantly clear DM count (called when user views messages) ──
CommunityNotifications.clearDmCount = function() {
    _unreadDm = 0;
    CommunityNotifications.renderBell();
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
