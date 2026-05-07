# Yada Yah Book PDF Generator

Desktop app that runs on the author's Windows machine. Picks up
locally-edited `.docx` files, generates PDFs via **Microsoft Word's own
renderer** (so PDF page N = docx footer page N, exactly), and uploads
both files to the server. The server pipeline picks up the upload and
runs the FlipHTML5 + offline-bundle steps with the known-good PDF —
the docx→PDF conversion step is skipped entirely.

## Why this exists

`.docx` rendered by *anything other than Word* drifts in pagination —
ONLYOFFICE, LibreOffice, Google Docs, PDF24, etc. all measure fonts and
break lines slightly differently. The only PDF that page-matches a
Word-authored docx is one Word produced. This tool moves PDF generation
to the author's machine where Word is already installed.

## What it does

On launch:

1. Reads the saved source-folder path from local settings
   (Windows registry: `HKCU\Software\yadayah\uploader`).
2. Calls `GET /api/admin-books-status.php` with your bearer token to
   learn each book's *server-side* docx mtime.
3. Walks the source folder and lists every `.docx`.
4. Compares mtimes — anything newer locally is auto-flagged **Queued**.

Click **Process All Pending** to:
- Open each queued docx headlessly in Word (`win32com`)
- Export to PDF via `Documents.ExportAsFixedFormat` (Word's own export)
- POST docx + pdf to `/api/admin-books-upload-pair.php`
- Server stores the files, drops a pipeline job, returns "queued"
- The host pipeline worker sees the PDF is current and skips the
  docx→PDF conversion entirely

A live progress bar runs through Word-export and upload phases. The log
pane at the bottom shows everything for audit.

## Setup (one-time)

```powershell
# 1. Install Python 3.11+ from python.org (check "Add to PATH").
# 2. Install dependencies.
py -m pip install -r requirements.txt

# 3. Get an author token from the admin (random ~40-char string stored
#    in yy_setting at scope='config', code='author-upload-token').
#    Run this once on the server to provision one:
#
#      docker exec yada-postgres-prod psql -U postgres -d yada -c "
#        INSERT INTO yy_setting
#          (setting_scope_code, setting_group_code, setting_code,
#           setting_value_code, setting_value, setting_label, setting_sort)
#        VALUES
#          ('config', 'author-upload', 'author-upload-token',
#           'text', encode(gen_random_bytes(24), 'hex'),
#           'Author desktop uploader bearer token', 0)
#        RETURNING setting_value;"
#
#    Copy the printed setting_value — that's the token.
```

## Run

```powershell
py yada_uploader.py
```

First launch will prompt for:
1. **Server URL** — `https://yadayah.com`
2. **Author token** — paste the value from above

Then it asks for the source directory (the folder where you save your
`.docx` files). Both choices are remembered — next launch just shows the
file table populated and ready.

## Build the single-file installer .exe

```powershell
.\build.bat
# → dist\YadaYahBookPDFGenerator.exe
```

The `.exe` produced is **both the installer and the app**. There is no
separate setup vs. runtime artifact:

- First time it's run (e.g. from the user's `Downloads` folder), it
  shows a small one-screen wizard with two checkboxes (Start Menu /
  Desktop shortcuts), copies itself to
  `%LOCALAPPDATA%\Programs\YadaYahBookPDFGenerator\`, creates the
  shortcuts, and relaunches itself from the install path.
- Subsequent launches detect they're running from the install path and
  go straight to the app.
- Per-user only: no admin / UAC prompt, no PATH munging, no registry
  keys outside `HKCU\Software\yadayah`.

`build.bat` also generates `icon.ico` from `icon.png` (using Pillow) so
Windows shows the Manowrah logo on the executable in Explorer and on
both shortcuts.

### Publish for download from Admin → Books

The Books admin page has a **↓ Yada Yah Book PDF Generator** link in
the toolbar that points at `/downloads/YadaYahBookPDFGenerator.exe`.
Upload the built .exe so the link works:

```powershell
scp dist\YadaYahBookPDFGenerator.exe ^
    root@187.77.13.242:/opt/yada-www/public/downloads/
```

End-user flow from there: click link in admin → save .exe → double-click
→ wizard → installed and running.

## Files

- `yada_uploader.py` — the app
- `icon.png` — the Manowrah brand mark (bundled into the .exe at build time)
- `requirements.txt` — pip dependencies
- `build.bat` — pyinstaller convenience wrapper
- Server-side endpoints (already deployed):
  - `/api/admin-books-status.php` — GET book status + mtimes
  - `/api/admin-books-upload-pair.php` — POST docx + pdf pair
