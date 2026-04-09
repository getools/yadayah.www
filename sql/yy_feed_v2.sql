-- Rename existing tables
ALTER TABLE yy_page_feed RENAME TO yy_page_feed_old;
ALTER TABLE yy_feed RENAME TO yy_feed_old;

-- yy_feed: one row per site + account combination
CREATE TABLE yy_feed (
    feed_key        serial PRIMARY KEY,
    feed_name       varchar(100) NOT NULL,
    feed_site_code  varchar(20)  NOT NULL,       -- youtube, facebook, rumble, x, tiktok, etc.
    feed_account_id varchar(200),                 -- channel ID, page ID, profile ID, handle
    feed_api_key    text,                         -- API key or token
    feed_active_flag boolean     NOT NULL DEFAULT true,
    feed_dtime      timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- yy_feed_page: links a feed to a page with type/filter/display config
CREATE TABLE yy_feed_page (
    feed_page_key           serial PRIMARY KEY,
    feed_key                int         NOT NULL REFERENCES yy_feed(feed_key),
    page_key                int         NOT NULL REFERENCES yy_page(page_key),
    feed_page_name          varchar(100),          -- display name (e.g. "Shorts", "Invite Videos")
    feed_page_type_code     varchar(20),           -- channel, shorts, playlist, video, post
    feed_page_list          varchar(200),          -- playlist ID or sub-resource identifier
    feed_page_filter_include varchar(200),         -- e.g. "#Invite,#DoYouYada"
    feed_page_filter_exclude varchar(200),         -- e.g. "Private,Deleted video"
    feed_page_duration_code varchar(20),           -- long, short
    feed_page_per_page      int         NOT NULL DEFAULT 0,
    feed_page_paging_code   varchar(20) NOT NULL DEFAULT 'none', -- none, auto, load_more, page_numbers
    feed_page_api_endpoint  varchar(200),          -- API path
    feed_page_sort          smallint    NOT NULL DEFAULT 0,
    feed_page_active_flag   boolean     NOT NULL DEFAULT true,
    feed_page_dtime         timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Migrate existing data

-- Sources (deduplicated by site + account)
INSERT INTO yy_feed (feed_key, feed_name, feed_site_code, feed_account_id, feed_api_key) VALUES
    (1, 'YahowahsHerald',    'youtube',  'UCKxk-PvJk6rLfBxykgSGXbA', (SELECT feed_api_key FROM yy_feed_old WHERE feed_key = 1)),
    (2, 'Yada Yahowah Page', 'facebook', '102425844783696',           (SELECT feed_api_key FROM yy_feed_old WHERE feed_key = 5)),
    (3, 'YadaYahowah7',      'rumble',   'YadaYahowah7',              NULL);
SELECT setval('yy_feed_feed_key_seq', 3);

-- Feed-page links (one per old feed + page combo)
INSERT INTO yy_feed_page (feed_key, page_key, feed_page_name, feed_page_type_code, feed_page_list, feed_page_filter_include, feed_page_filter_exclude, feed_page_duration_code, feed_page_per_page, feed_page_paging_code, feed_page_api_endpoint, feed_page_sort) VALUES
    (1, 4,  'Channel Videos',     'channel',  NULL, NULL, NULL, 'long',  15, 'load_more',    '/api/youtube-feed.php?type=channel',  1),
    (1, 4,  'Shorts',             'shorts',   NULL, NULL, NULL, 'short',  4, 'none',         '/api/youtube-feed.php?type=shorts',   2),
    (1, 4,  'Featured - Messiah', 'playlist', 'PL2remztpK0uDrhUMrHaUF4gqMXFz9hzBf', NULL, NULL, NULL, 1, 'none', '/api/youtube-feed.php?type=playlist', 3),
    (1, 5,  'Music Videos',       'playlist', 'PLW5gXgQ3YcPCy7jFNQ_4Q759SVdS0e035', NULL, 'Private,Deleted video', NULL, 1, 'none', '/api/youtube-feed.php?type=playlist', 4),
    (2, 2,  'Invite Videos',      'video',    NULL, '#Invite', NULL, NULL, 24, 'auto', '/api/invite-videos.php', 5),
    (2, 3,  'DoYouYada',          'post',     NULL, '#DoYouYada,#DoUYada', 'Private', NULL, 25, 'auto', '/api/sync-blog.php', 6),
    (2, 6,  'Blog Posts',         'post',     NULL, NULL, NULL, NULL, 25, 'auto', '/api/sync-blog.php', 7),
    (3, 1,  'Rumble Vlog',        'channel',  NULL, NULL, NULL, NULL, 24, 'page_numbers', '/api/rumble-videos.php', 9),
    (1, 20, 'Basics Videos',      'channel',  NULL, '#Basics', 'Private,Deleted video', NULL, 0, 'none', NULL, 10);
