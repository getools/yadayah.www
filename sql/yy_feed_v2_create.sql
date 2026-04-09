CREATE TABLE yy_feed (
    feed_key        serial PRIMARY KEY,
    feed_name       varchar(100) NOT NULL,
    feed_site_code  varchar(20)  NOT NULL,
    feed_account_id varchar(200),
    feed_api_key    text,
    feed_active_flag boolean     NOT NULL DEFAULT true,
    feed_dtime      timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE yy_feed_page (
    feed_page_key           serial PRIMARY KEY,
    feed_key                int         NOT NULL REFERENCES yy_feed(feed_key),
    page_key                int         NOT NULL REFERENCES yy_page(page_key),
    feed_page_name          varchar(100),
    feed_page_type_code     varchar(20),
    feed_page_list          varchar(200),
    feed_page_filter_include varchar(200),
    feed_page_filter_exclude varchar(200),
    feed_page_duration_code varchar(20),
    feed_page_per_page      int         NOT NULL DEFAULT 0,
    feed_page_paging_code   varchar(20) NOT NULL DEFAULT 'none',
    feed_page_api_endpoint  varchar(200),
    feed_page_sort          smallint    NOT NULL DEFAULT 0,
    feed_page_active_flag   boolean     NOT NULL DEFAULT true,
    feed_page_dtime         timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO yy_feed (feed_key, feed_name, feed_site_code, feed_account_id, feed_api_key) VALUES
    (1, 'YahowahsHerald',    'youtube',  'UCKxk-PvJk6rLfBxykgSGXbA', (SELECT feed_api_key FROM yy_feed_old WHERE feed_key = 1)),
    (2, 'Yada Yahowah Page', 'facebook', '102425844783696',           (SELECT feed_api_key FROM yy_feed_old WHERE feed_key = 5)),
    (3, 'YadaYahowah7',      'rumble',   'YadaYahowah7',              NULL);
SELECT setval('yy_feed_feed_key_seq', 3);

INSERT INTO yy_feed_page (feed_key, page_key, feed_page_name, feed_page_type_code, feed_page_list, feed_page_filter_include, feed_page_filter_exclude, feed_page_duration_code, feed_page_per_page, feed_page_paging_code, feed_page_api_endpoint, feed_page_sort) VALUES
    (1, (SELECT page_key FROM yy_page WHERE page_code='home'),      'Channel Videos',     'channel',  NULL, NULL, NULL, 'long',  15, 'load_more',    '/api/youtube-feed.php?type=channel',  1),
    (1, (SELECT page_key FROM yy_page WHERE page_code='home'),      'Shorts',             'shorts',   NULL, NULL, NULL, 'short',  4, 'none',         '/api/youtube-feed.php?type=shorts',   2),
    (1, (SELECT page_key FROM yy_page WHERE page_code='home'),      'Featured - Messiah', 'playlist', 'PL2remztpK0uDrhUMrHaUF4gqMXFz9hzBf', NULL, NULL, NULL, 1, 'none', '/api/youtube-feed.php?type=playlist', 3),
    (1, (SELECT page_key FROM yy_page WHERE page_code='music'),     'Music Videos',       'playlist', 'PLW5gXgQ3YcPCy7jFNQ_4Q759SVdS0e035', NULL, 'Private,Deleted video', NULL, 1, 'none', '/api/youtube-feed.php?type=playlist', 4),
    (2, (SELECT page_key FROM yy_page WHERE page_code='invite'),    'Invite Videos',      'video',    NULL, '#Invite', NULL, NULL, 24, 'auto', '/api/invite-videos.php', 5),
    (2, (SELECT page_key FROM yy_page WHERE page_code='doyouyada'), 'DoYouYada',          'post',     NULL, '#DoYouYada,#DoUYada', 'Private', NULL, 25, 'auto', '/api/sync-blog.php', 6),
    (2, (SELECT page_key FROM yy_page WHERE page_code='blog'),      'Blog Posts',         'post',     NULL, NULL, NULL, NULL, 25, 'auto', '/api/sync-blog.php', 7),
    (3, (SELECT page_key FROM yy_page WHERE page_code='vlog'),      'Rumble Vlog',        'channel',  NULL, NULL, NULL, NULL, 24, 'page_numbers', '/api/rumble-videos.php', 9),
    (1, (SELECT page_key FROM yy_page WHERE page_code='basics'),    'Basics Videos',      'channel',  NULL, '#Basics', 'Private,Deleted video', NULL, 0, 'none', NULL, 10);
