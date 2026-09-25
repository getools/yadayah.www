# systemd units (source of truth)

Live copies are in `/etc/systemd/system/`. These are the version-controlled
originals — edit here, then:

    cp systemd/book-pipeline.* /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now book-pipeline.path

## book-pipeline.path + book-pipeline.service

Push trigger for the book pipeline. `admin-books.php` (and
`admin-books-upload-pair.php`) drop a job `.json` into
`public/jobs/book-pipeline/` the instant a DOCX is uploaded. The `.path`
unit watches that directory with inotify and starts the worker immediately,
so PDF regeneration / parsing / flipbook rebuild begin within ~1s of upload
instead of waiting for the `*/10` cron tick.

The `*/10` cron entry is retained as a backstop and should NOT be removed.

## book-pdf.path + book-pdf-trigger.service

Second push trigger, for the **PDF** side. `book-pipeline.path` only fires on a
job file, i.e. on the DOCX upload routes. A PDF that changes by any other route
(author pair-upload, a manual `scp`/`rsync` into `public/pdf/`, an out-of-band
re-export) produced no job file, so its flipbook rebuild waited on the `*/10`
cron backstop. `book-pdf.path` watches `public/pdf/` with inotify and starts the
worker; the worker's **Phase 6 flipbook sweep** already regenerates any book
whose PDF is newer than its `pages/` dir, so nothing else had to change.

The trigger unit sleeps 45s before starting `book-pipeline.service`. That both
debounces a burst (a path unit drops events while its trigger unit is active, so
N PDFs written in the window collapse into one run) and keeps `pdftoppm` off a
file that is still being copied — a failed render burns one of Phase 6's three
allowed attempts per slug.

⚠ `TriggerLimitIntervalSec=0` on the path unit and `StartLimitIntervalSec=0` on
the trigger unit are deliberate. With the defaults, copying many PDFs at once
trips systemd's rate limits, and a rate-limited **path** unit goes to `failed`
and silently stops watching until somebody restarts it.
