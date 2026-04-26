# Yada Yah Project

## Architecture
- **Frontend**: Static HTML pages in `public/`, vanilla JS, no framework
- **Backend**: PHP API in `api/`, PostgreSQL database
- **Production**: `root@187.77.13.242`, web root `/opt/yada-www/`, Docker container `yada-postgres-prod`
- **Deploy**: `scp` files to prod (no rsync on Git Bash). API files to `/opt/yada-www/api/`, public files to `/opt/yada-www/public/`
- **DB access**: `ssh root@187.77.13.242 "docker exec -i yada-postgres-prod psql -U postgres -d yada -c \"SQL\""`
- **Cache busting**: ALWAYS bump `?v=N` on JS/CSS files when deploying changes

## Error Monitoring
- Errors logged to `yy_monitor_event` table (source, severity, message, detail, file, resolved_flag)
- Client-side: `public/js/error-reporter.js` captures JS errors, fetch failures, resource errors → `api/client-error.php`
- Server-side: `api/cron-monitor.php` parses Apache error log, checks sync/DB health
- Revision triggers have EXCEPTION handlers — failures warn instead of crash

## Key Tables
- `yy_feed` / `yy_feed_item` / `yy_feed_page` — external feed system (YouTube, Rumble, Facebook)
- `yy_feed_page_category` — categories for feed pages (Basics page_key=20, Vlog page_key=1)
- `yy_letter` — glossary Hebrew letters
- `yy_community_*` — community/chat system
- `yy_monitor_event` — error/monitoring log
- `*_rev` tables — revision history (triggers on every INSERT/UPDATE/DELETE)

## Conventions
- Domain: yadayah.com (NOT t.yadayah.com)
- Feed filters: `feed-helpers.php` shared helper, wildcard convention (`term*` = starts with, `*term` = ends with, `term` = contains)
- Vlog hashtag: `#vlog|category|episode` in YouTube descriptions, parsed by `sync-youtube.php`
- Skip `#vlog|` detection for Basics videos (`#Basics` in title)

## Auto-Fix Agent Instructions
When checking for unresolved errors in `yy_monitor_event`:

1. Query: `SELECT * FROM yy_monitor_event WHERE event_resolved_flag = FALSE ORDER BY event_dtime DESC`
2. For each error, investigate the root cause by reading the relevant code files
3. Apply the fix locally, test if possible, then deploy via scp
4. Mark resolved: `UPDATE yy_monitor_event SET event_resolved_flag = TRUE, event_resolved_dtime = NOW(), event_action_taken = 'description of fix' WHERE event_key = N`
5. If a fix requires adding a DB column to a `_rev` table, do that too
6. NEVER make destructive changes (DROP TABLE, DELETE data) without explicit approval
7. For errors you cannot confidently fix, set `event_action_taken = 'Needs manual review: reason'` but leave `event_resolved_flag = FALSE`
