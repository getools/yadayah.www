/* admin-nav.js — single source for the admin navigation toolbar */
(function() {
'use strict';

var tabs = [
    ['admin-site.html', 'Site'],
    ['admin-translation.html', 'Translation'],
    ['admin-series.html', 'Series'],
    ['admin-word.html', 'Word'],
    ['admin-lookups.html', 'Lookups'],
    ['admin-ask.html', 'Ask'],
    ['admin-transcripts.html', 'Transcripts'],
    ['admin-timeline.html', 'Timeline'],
    ['admin-memorial.html', 'Memorial'],
    ['admin-media.html', 'Media'],
    ['admin-music.html', 'Music'],
    ['admin-chat.html', 'Chat'],
    ['admin-invite.html', 'Invite'],
    ['admin-doyouyada.html', 'DoYou?'],
    ['admin-basics.html', 'Basics'],
    ['admin-backgrounds.html', 'Backgrounds'],
    ['admin-feeds.html', 'Feeds'],
    ['admin-redirects.html', 'Redirects'],
    ['admin-links.html', 'Links'],
    ['admin-resources.html', 'Resources'],
    ['admin-users.html', 'Users'],
    ['admin-misc.html', 'Misc']
];

var nav = document.querySelector('.app-header .nav-tabs');
if (!nav) return;

var currentPage = window.location.pathname.split('/').pop() || '';

nav.innerHTML = tabs.map(function(t) {
    var active = (currentPage === t[0]) ? ' active' : '';
    return '<a href="' + t[0] + '" class="nav-tab' + active + '">' + t[1] + '</a>';
}).join('\n');

})();
