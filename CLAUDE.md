# Yada Yah Project

## Architecture
- **Frontend**: Static HTML pages in `public/`, vanilla JS, no framework
- **Backend**: PHP API in `api/`, PostgreSQL database
- **Production**: `root@187.77.13.242`, web root `/opt/yada-www/`, Docker container `yada-postgres-prod`
- **Deploy**: `scp` files to prod (no rsync on Git Bash). API files to `/opt/yada-www/api/`, public files to `/opt/yada-www/public/`
- **DB access**: `ssh root@187.77.13.242 "docker exec -i yada-postgres-prod psql -U postgres -d yada -c \"SQL\""`
- **Cache busting**: ALWAYS bump `?v=N` on JS/CSS files when deploying changes

## Books pipeline
- Source of truth: the Word-rendered PDF at `/opt/yada-www/public/pdf/<stem>.pdf`. Pagination, search hits, flipbook display, and DB rows all derive from it. Never derive page numbers from docx XML.
- Worker: `/opt/yada-www/book-pipeline-worker.sh` — cron every 2 min, flock'd. **File is `+i` (immutable)** — `chattr -i` to edit, `chattr +i` after.
- Parser: `/opt/yada-www/parsers/parse_volume_from_bundle.py --volume-key N`. Uses PyMuPDF on the PDF, writes `yy_paragraph` + `yy_translation`. Companion modules: `bundle_paragraphs.py`, `bundle_translations.py`. Older `parse_volume.py` (docx-based) is dead — do not invoke.
- Flipbook bundle: `/opt/yada-www/rebuild_prototype.sh <stem>` regenerates `pages/`, `thumbs/`, `text/`, `search.json`, `toc.json` from the PDF and bumps `bundleVersion` in `<stem>/index.html`.

## Flipbook
- Per-book shell: `<stem>/index.html` — sets `window.FLIPBOOK_CONFIG = {total, title, bookCode, pdfPath, bundleVersion}` and loads the viewer.
- Central viewer: `/js/flipbook-viewer.js` (cache-bust via `?v=N`). CSS: `/css/flipbook-viewer.css`.
- URL hash format: `#chapter=<slug>&page=<N>&q=<query>`. Page is physical PDF page (1-indexed). Hashchange triggers seek + search-highlight.
- Bundle-asset cache-busting: viewer appends `?v=<bundleVersion>` to JPG / text-layer / search.json / toc.json fetches. `rebuild_prototype.sh` bumps it on every rebuild.

## Search
- `/api/search.php` returns `book_url`, `chapter_url`, `flip_url` — deep-link hash (chapter+page+q) baked in. Page = physical PDF page.
- Global bar JS: `/js/site-search.js`, loaded by `/js/site-nav.js`.

## Desktop uploader (YY PDF Generator App)
- Download: `/api/admin-uploader-download.php` (auth-gated; `.exe` in `/opt/yada-www/private/uploader/`, outside web root).
- Token: `/api/admin-author-token.php` auto-provisions and returns the bearer token used by the app.
- Upload endpoints: `/api/admin-books-status.php` (GET status), `/api/admin-books-upload-pair.php` (POST docx+pdf).
- Build: GitHub Actions `windows-latest` SCPs the `.exe` via a forced-command SSH key (`/usr/local/bin/uploader-deploy.sh`). Triggered by changes under `desktop/` in the repo.

## Git flow
- `/opt/yada-www/api/git-push.sh` rsyncs a *subset* of `/opt/yada-www` → `/opt/yada-git` and pushes. Subset = `api/*.{php,sh}`, `public/css/*`, `public/js/*`, `public/*.html`, specific root configs. Anything outside (e.g. `desktop/`, `.github/`, `/opt/yada-www/parsers/`) must be `scp`'d to `/opt/yada-git` directly before pushing.
- Pushing workflow files (`.github/workflows/*.yml`) requires `workflow` scope on the token. The server's SSH deploy key lacks it; push those from a local machine.

## Operational gotchas
- PostgreSQL on host port **5433** (Docker maps to internal 5432).
- PHP container is `yada-www-web-1`; web root inside container is `/var/www/html/` (bind-mount of `/opt/yada-www/`). Lint: `docker exec yada-www-web-1 php -l /var/www/html/<path>`.
- Cloudflare caches un-versioned static assets aggressively; `?v=N` is the only reliable invalidator (Last-Modified/ETag revalidation is *not* reliable across the CDN).

## Error Monitoring
- Errors logged to `yy_monitor_event` table (source, severity, message, detail, file, resolved_flag)
- Client-side: `public/js/error-reporter.js` captures JS errors, fetch failures, resource errors → `api/client-error.php`
- Server-side: `api/cron-monitor.php` parses Apache error log, checks sync/DB health
- Revision triggers have EXCEPTION handlers — failures warn instead of crash

## Key Tables
- `yy_volume` — `volume_pdf`, `volume_docx`, `volume_parse_status`, `volume_parse_flag`, `volume_paragraph_count`
- `yy_paragraph` — `paragraph_page` is physical PDF page (1-indexed); `paragraph_active_flag=false` for junk rows; `paragraph_text_html` for display, `paragraph_text_plain` for FTS
- `yy_translation` — links to `yy_chapter` via `chapter_key`; resolves to `yah_chapter`/`yah_verse` via `yah_scroll_key` (looked up in `yy_cite_book_map`)
- `yy_chapter` — per-volume; `chapter_key` ↔ `chapter_number` map
- `yy_setting` — config storage (e.g. `setting_code='author-upload-token'`)
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
