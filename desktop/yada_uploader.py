"""
Yada Yahowah Document Uploader — desktop app for the author's machine.

Workflow it automates
─────────────────────
  1. Author edits a .docx in Microsoft Word.
  2. App opens that .docx headlessly via Word COM and exports a PDF using
     Word's own renderer (the only renderer whose pagination matches the
     .docx exactly — the whole point of the new pipeline).
  3. App POSTs both files to /api/admin-books-upload-pair.php.
  4. Server stores them, marks the pdf newer than the docx, drops a
     pipeline job. The host worker sees "pdf already current" and skips
     docx→pdf conversion entirely; the FlipHTML5 + offline-bundle steps
     run with a known-good PDF.

Auto-queue logic
────────────────
  On launch the app:
    • reads the saved source dir from QSettings (or asks if first run)
    • POSTs nothing — GETs /api/admin-books-status.php to learn each
      book's *server-side* docx mtime
    • lists every .docx in the source dir, computes its local mtime,
      and flips the row's status to "Newer locally — queued" iff the
      local file is newer than the server copy
  The user can override (queue/skip rows) before clicking "Process All".

Configuration is persisted via QSettings (Windows registry on Win32)
under the key 'yadayah/uploader'. Author opens Settings once to enter
the bearer token printed by the admin who provisioned the account; from
then on it just works.

Dependencies (see requirements.txt):
  PySide6, requests, pywin32

Run:
  python yada_uploader.py
"""

from __future__ import annotations

import os
import sys
import json
import time
import shutil
import subprocess
import traceback
import datetime as dt
from pathlib import Path

from PySide6.QtCore import (
    Qt, QSettings, Signal, QObject, QThread, QSize, QTimer, QUrl,
)
from PySide6.QtGui import QAction, QFont, QColor, QPalette, QDesktopServices, QIcon
from PySide6.QtWidgets import (
    QApplication, QMainWindow, QWidget, QVBoxLayout, QHBoxLayout, QGridLayout,
    QPushButton, QTableWidget, QTableWidgetItem, QLabel, QFileDialog, QLineEdit,
    QProgressBar, QTextEdit, QToolBar, QStatusBar, QHeaderView, QDialog,
    QDialogButtonBox, QMessageBox, QFrame, QSplitter, QCheckBox, QStyle,
    QAbstractItemView,
)

import requests

# ── App-wide identifiers ──────────────────────────────────────────────
APP_ORG     = 'yadayah'
APP_NAME    = 'book-pdf-generator'
APP_TITLE   = 'Yada Yah Book PDF Generator'
DEFAULT_SERVER = 'https://yadayah.com'

# Brand icon — bundled next to the script. The Manowrah (menorah) logo
# is the same image used in the site's <header>. When packaged via
# pyinstaller --onefile, icon.png is extracted into sys._MEIPASS at
# runtime; check there first, then fall back to the script's own dir
# (the dev / pip-install case).
def _resolve_icon():
    for base in [getattr(sys, '_MEIPASS', None), Path(__file__).resolve().parent]:
        if not base:
            continue
        p = Path(base) / 'icon.png'
        if p.is_file():
            return str(p)
    return ''
ICON_PATH = _resolve_icon()


# ── Self-installer ────────────────────────────────────────────────────
# When the .exe is run from anywhere except its install location it
# offers to copy itself to %LOCALAPPDATA%\Programs\YadaYahBookPDFGenerator\
# and create Start Menu + Desktop shortcuts. After install it relaunches
# from the install path and exits the original. Subsequent launches go
# straight to the app.
#
# Per-user install (no admin/UAC). Skipped entirely when running from
# source (sys.frozen is False) — installation only makes sense for the
# bundled .exe artifact.
INSTALL_DIR_NAME = 'YadaYahBookPDFGenerator'
INSTALL_EXE_NAME = 'YadaYahBookPDFGenerator.exe'
SHORTCUT_NAME    = 'Yada Yah Book PDF Generator.lnk'


def _install_dir() -> Path:
    base = os.environ.get('LOCALAPPDATA') or str(Path.home() / 'AppData' / 'Local')
    return Path(base) / 'Programs' / INSTALL_DIR_NAME


def _install_target() -> Path:
    return _install_dir() / INSTALL_EXE_NAME


def _is_frozen_running_from_install() -> bool:
    """True iff this process is the pyinstaller-frozen .exe AND it was
    launched from the canonical install path. False if running from
    source, or from the downloads folder, or from the desktop, etc."""
    if not getattr(sys, 'frozen', False):
        return False
    try:
        return Path(sys.executable).resolve() == _install_target().resolve()
    except Exception:
        return False


def _make_shortcut(link_path: Path, target: Path, description: str = APP_TITLE) -> bool:
    """Create a Windows .lnk via WScript.Shell. Returns True on success."""
    try:
        import win32com.client  # type: ignore
        shell = win32com.client.Dispatch('WScript.Shell')
        sc = shell.CreateShortcut(str(link_path))
        sc.TargetPath = str(target)
        sc.WorkingDirectory = str(target.parent)
        # Embed the same .exe as the icon source so Explorer uses the
        # Manowrah icon pyinstaller wrote into the executable.
        sc.IconLocation = f'{target},0'
        sc.Description = description
        sc.Save()
        return True
    except Exception:
        return False


def _install_self(create_desktop: bool, create_startmenu: bool) -> Path:
    """Copy the running .exe into the per-user Programs folder, make
    shortcuts, return the installed-target path. Idempotent — copying
    over an existing install just refreshes it."""
    target = _install_target()
    target.parent.mkdir(parents=True, exist_ok=True)
    src = Path(sys.executable).resolve()
    if src != target.resolve():
        # If a previous install of the .exe is currently running, the
        # OS holds an exclusive lock on the binary. We're the freshly-
        # downloaded one, so copying over the OLD one normally fails
        # only when the user has the old one open — unlikely on first
        # install. Surface any error to the caller so it can show a
        # message instead of crashing.
        shutil.copy2(src, target)
    if create_startmenu:
        sm = Path(os.environ.get('APPDATA', Path.home() / 'AppData' / 'Roaming')) \
                / 'Microsoft' / 'Windows' / 'Start Menu' / 'Programs' / SHORTCUT_NAME
        sm.parent.mkdir(parents=True, exist_ok=True)
        _make_shortcut(sm, target)
    if create_desktop:
        dt_path = Path(os.environ.get('USERPROFILE', Path.home())) / 'Desktop' / SHORTCUT_NAME
        dt_path.parent.mkdir(parents=True, exist_ok=True)
        _make_shortcut(dt_path, target)
    return target


class InstallDialog(QDialog):
    """One-screen install wizard. Shown only when the .exe is run from
    somewhere other than its install location."""

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle('Install ' + APP_TITLE)
        if os.path.isfile(ICON_PATH):
            self.setWindowIcon(QIcon(ICON_PATH))
        self.setMinimumWidth(500)
        v = QVBoxLayout(self)

        # Branded header strip.
        head = QHBoxLayout()
        if os.path.isfile(ICON_PATH):
            from PySide6.QtGui import QPixmap
            ip = QLabel()
            pix = QPixmap(ICON_PATH).scaled(64, 72, Qt.KeepAspectRatio, Qt.SmoothTransformation)
            ip.setPixmap(pix)
            head.addWidget(ip)
        title = QLabel(f'<h2 style="margin:0">{APP_TITLE}</h2>'
                       '<div style="color:#666;font-size:12px">'
                       'One-click installer · per-user, no admin required'
                       '</div>')
        title.setTextFormat(Qt.RichText)
        head.addWidget(title, 1)
        v.addLayout(head)

        body = QLabel(
            'This will:'
            '<ul style="margin-top:6px">'
            f'<li>Copy the application to <code>{_install_target()}</code></li>'
            '<li>Add shortcuts (your choice below)</li>'
            '<li>Launch the app once installed</li>'
            '</ul>'
            '<p style="color:#666;font-size:12px;margin:8px 0 0">'
            'Installing for your user only — no Windows admin / UAC prompt.'
            '</p>'
        )
        body.setWordWrap(True)
        body.setTextFormat(Qt.RichText)
        v.addWidget(body)

        self.cb_startmenu = QCheckBox('Add to Start Menu')
        self.cb_startmenu.setChecked(True)
        v.addWidget(self.cb_startmenu)

        self.cb_desktop = QCheckBox('Add Desktop shortcut')
        self.cb_desktop.setChecked(True)
        v.addWidget(self.cb_desktop)

        bb = QDialogButtonBox()
        self.btn_install = bb.addButton('Install', QDialogButtonBox.AcceptRole)
        self.btn_run     = bb.addButton('Run without installing', QDialogButtonBox.ActionRole)
        self.btn_cancel  = bb.addButton(QDialogButtonBox.Cancel)
        bb.accepted.connect(self.accept)
        bb.rejected.connect(self.reject)
        # Run-once: leave dialog with "skip-install" outcome.
        self.run_only = False
        def _run_only():
            self.run_only = True
            self.accept()
        self.btn_run.clicked.connect(_run_only)
        v.addWidget(bb)


def _maybe_install_and_relaunch() -> bool:
    """Run the install flow if appropriate. Returns True if the caller
    should exit immediately (we already relaunched the installed copy);
    False to fall through to the normal app boot.

    Called BEFORE any QMainWindow is constructed so the install dialog
    is the first thing the user sees on a fresh download."""
    # Source / dev runs never install themselves.
    if not getattr(sys, 'frozen', False):
        return False
    # Already running from the install location? Nothing to do.
    if _is_frozen_running_from_install():
        return False
    # Show the wizard. We need a QApplication for any Qt UI.
    app = QApplication.instance() or QApplication(sys.argv)
    if os.path.isfile(ICON_PATH):
        app.setWindowIcon(QIcon(ICON_PATH))
    dlg = InstallDialog()
    if dlg.exec() != QDialog.Accepted:
        # User cancelled — exit cleanly.
        sys.exit(0)
    if dlg.run_only:
        return False
    # Perform install and relaunch.
    try:
        target = _install_self(
            create_desktop=dlg.cb_desktop.isChecked(),
            create_startmenu=dlg.cb_startmenu.isChecked(),
        )
    except Exception as e:
        QMessageBox.critical(
            None, 'Install failed',
            f'Could not install:\n\n{e}\n\nThe app will run from its '
            f'current location for this session.'
        )
        return False
    # Friendly confirmation.
    QMessageBox.information(
        None, 'Installed',
        f'{APP_TITLE} installed to:\n{target}\n\n'
        'Launching the installed copy now. You can run it any time '
        'from the Start Menu or Desktop shortcut.'
    )
    # Relaunch from install path. Using shell=False + a list arg avoids
    # quoting bugs on paths that contain spaces.
    try:
        subprocess.Popen([str(target)], close_fds=True)
    except Exception as e:
        QMessageBox.warning(None, 'Launch warning',
                            f'Installed but could not auto-launch: {e}')
    return True

# Status codes used in the table's Status column. Each maps to a
# row-color hint and the action the queue runner would take.
ST_OK         = 'OK'              # local mtime <= server mtime
ST_QUEUED     = 'Queued'          # local newer, will process
ST_NO_LOCAL   = 'Server only'     # no matching local file
ST_NO_SERVER  = 'Local only'      # local but server doesn't know about it
ST_PROCESSING = 'Processing…'
ST_PDF        = 'Word→PDF'
ST_UPLOADING  = 'Uploading'
ST_DONE       = 'Done'
ST_ERROR      = 'Error'


def fmt_dt(iso_or_ts):
    """Render an ISO-8601 string (or epoch float) as a short local-time
    string for the file table. Returns empty for None."""
    if iso_or_ts in (None, '', 0):
        return ''
    if isinstance(iso_or_ts, (int, float)):
        try:
            t = dt.datetime.fromtimestamp(iso_or_ts)
            return t.strftime('%Y-%m-%d %H:%M')
        except Exception:
            return ''
    try:
        # Python's fromisoformat handles "2026-05-02T17:21:00+00:00"
        # since 3.11; for 3.10 substitute Z.
        s = iso_or_ts.replace('Z', '+00:00')
        return dt.datetime.fromisoformat(s).astimezone().strftime('%Y-%m-%d %H:%M')
    except Exception:
        return iso_or_ts


def fmt_size(n):
    if n is None:
        return ''
    for u in ('B', 'KB', 'MB', 'GB'):
        if n < 1024:
            return f'{n:.0f} {u}' if u == 'B' else f'{n:.1f} {u}'
        n /= 1024
    return f'{n:.1f} TB'


# ── Settings dialog ───────────────────────────────────────────────────
class SettingsDialog(QDialog):
    """Lets the user enter the server URL and the bearer token. Source
    directory is set from the main window via the [Browse] button."""

    def __init__(self, parent, server: str, token: str):
        super().__init__(parent)
        self.setWindowTitle('Settings')
        self.setMinimumWidth(480)

        v = QVBoxLayout(self)

        intro = QLabel(
            'Server URL is the public root (e.g. https://yadayah.com). '
            'The author token is provisioned by an admin and stored in '
            "yy_setting (scope='config', code='author-upload-token'). "
            'Both values are saved locally so you only enter them once.'
        )
        intro.setWordWrap(True)
        v.addWidget(intro)

        grid = QGridLayout()
        grid.addWidget(QLabel('Server URL:'), 0, 0)
        self.server_in = QLineEdit(server)
        grid.addWidget(self.server_in, 0, 1)
        grid.addWidget(QLabel('Author token:'), 1, 0)
        self.token_in = QLineEdit(token)
        self.token_in.setEchoMode(QLineEdit.Password)
        grid.addWidget(self.token_in, 1, 1)
        self.show_token = QCheckBox('Show token')
        self.show_token.toggled.connect(
            lambda on: self.token_in.setEchoMode(QLineEdit.Normal if on else QLineEdit.Password)
        )
        grid.addWidget(self.show_token, 2, 1)
        v.addLayout(grid)

        bb = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
        bb.accepted.connect(self.accept)
        bb.rejected.connect(self.reject)
        v.addWidget(bb)

    def values(self):
        return self.server_in.text().strip(), self.token_in.text().strip()


# ── Worker thread ────────────────────────────────────────────────────
class JobWorker(QObject):
    """Runs the (PDF export → upload) pipeline for one docx at a time on
    a dedicated background thread. Single-threaded by design — Word COM
    is apartment-threaded and parallel COM sessions are unstable.

    Signals fire to the main thread for UI updates; never touch widgets
    directly from here.
    """
    started_job  = Signal(str)              # docx_path
    pdf_progress = Signal(str, int)         # docx_path, percent (0-100, Word phase)
    upload_progress = Signal(str, int)      # docx_path, percent (upload phase)
    finished_job = Signal(str, dict)        # docx_path, server response
    error_job    = Signal(str, str)         # docx_path, message
    log          = Signal(str)              # log line
    queue_empty  = Signal()

    def __init__(self, server: str, token: str):
        super().__init__()
        self.server = server.rstrip('/')
        self.token  = token
        self._queue: list[str] = []
        self._stop = False

    def configure(self, server: str, token: str):
        self.server = server.rstrip('/')
        self.token = token

    def enqueue(self, docx_paths: list[str]):
        for p in docx_paths:
            if p not in self._queue:
                self._queue.append(p)

    def stop(self):
        self._stop = True

    def run(self):
        # Lazy-load win32com so the rest of the app can be inspected on
        # non-Windows machines (e.g. running the CI lint check on Linux).
        try:
            import win32com.client  # noqa: F401
        except ImportError:
            self.log.emit('pywin32 not installed — `pip install pywin32`')
            return

        while not self._stop:
            if not self._queue:
                self.queue_empty.emit()
                # Idle loop — wake every 250ms to check for new work or stop.
                QThread.msleep(250)
                continue
            docx_path = self._queue.pop(0)
            self.started_job.emit(docx_path)
            try:
                pdf_path = self._export_pdf(docx_path)
                if not pdf_path:
                    continue
                self._upload(docx_path, pdf_path)
            except Exception as e:
                tb = traceback.format_exc(limit=4)
                self.error_job.emit(docx_path, f'{e}\n{tb}')
                self.log.emit(f'[{Path(docx_path).name}] FAIL: {e}')

    # ── Word COM PDF export ──
    def _export_pdf(self, docx_path: str) -> str | None:
        import win32com.client
        self.log.emit(f'[{Path(docx_path).name}] launching Word…')
        self.pdf_progress.emit(docx_path, 5)
        try:
            word = win32com.client.gencache.EnsureDispatch('Word.Application')
        except Exception as e:
            self.error_job.emit(docx_path, f'Word COM unavailable: {e}')
            return None
        word.Visible = False
        try:
            self.pdf_progress.emit(docx_path, 15)
            doc = word.Documents.Open(os.path.abspath(docx_path), ReadOnly=True)
            self.pdf_progress.emit(docx_path, 50)
            pdf_path = str(Path(docx_path).with_suffix('.pdf'))
            # ExportAsFixedFormat constants — see Microsoft docs:
            #   ExportFormat=17        wdExportFormatPDF
            #   OptimizeFor=0          wdExportOptimizeForPrint
            #   CreateBookmarks=1      wdExportCreateHeadingBookmarks
            doc.ExportAsFixedFormat(
                OutputFileName=os.path.abspath(pdf_path),
                ExportFormat=17,
                OptimizeFor=0,
                CreateBookmarks=1,
                DocStructureTags=True,
                BitmapMissingFonts=False,
                UseISO19005_1=False,
            )
            self.pdf_progress.emit(docx_path, 95)
            doc.Close(SaveChanges=False)
        finally:
            try:
                word.Quit()
            except Exception:
                pass
        sz = os.path.getsize(pdf_path)
        self.pdf_progress.emit(docx_path, 100)
        self.log.emit(f'[{Path(docx_path).name}] PDF written ({fmt_size(sz)})')
        return pdf_path

    # ── HTTP upload (multipart, with byte-counter for progress) ──
    def _upload(self, docx_path: str, pdf_path: str) -> None:
        url = self.server + '/api/admin-books-upload-pair.php'
        self.log.emit(f'[{Path(docx_path).name}] uploading to {url}')
        # Build a streaming-friendly multipart body so we can emit a
        # rough percentage as bytes ship out. requests' default
        # MultipartEncoder isn't streaming; we use requests-toolbelt if
        # available, fall back to a single-shot upload otherwise.
        try:
            from requests_toolbelt import MultipartEncoder, MultipartEncoderMonitor

            with open(docx_path, 'rb') as fdocx, open(pdf_path, 'rb') as fpdf:
                fields = {
                    'docx': (Path(docx_path).name, fdocx,
                             'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                    'pdf':  (Path(pdf_path).name,  fpdf,  'application/pdf'),
                }
                encoder = MultipartEncoder(fields=fields)
                total = encoder.len
                last_emit = [0]
                def cb(monitor):
                    pct = int(monitor.bytes_read * 100 / max(1, total))
                    if pct != last_emit[0]:
                        last_emit[0] = pct
                        self.upload_progress.emit(docx_path, pct)
                monitor = MultipartEncoderMonitor(encoder, cb)
                r = requests.post(
                    url,
                    data=monitor,
                    headers={
                        'Content-Type': monitor.content_type,
                        'X-Author-Token': self.token,
                    },
                    timeout=600,
                )
        except ImportError:
            # No streaming progress; just show indeterminate movement.
            self.upload_progress.emit(docx_path, 25)
            with open(docx_path, 'rb') as fdocx, open(pdf_path, 'rb') as fpdf:
                files = {
                    'docx': (Path(docx_path).name, fdocx,
                             'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                    'pdf':  (Path(pdf_path).name,  fpdf,  'application/pdf'),
                }
                r = requests.post(
                    url, files=files,
                    headers={'X-Author-Token': self.token},
                    timeout=600,
                )
            self.upload_progress.emit(docx_path, 100)

        if r.status_code != 200:
            self.error_job.emit(docx_path, f'HTTP {r.status_code}: {r.text[:300]}')
            return
        try:
            data = r.json()
        except Exception:
            self.error_job.emit(docx_path, f'Non-JSON response: {r.text[:300]}')
            return
        self.log.emit(
            f'[{Path(docx_path).name}] uploaded — pipeline job {data.get("job", "?")}'
        )
        self.finished_job.emit(docx_path, data)


# ── Main window ──────────────────────────────────────────────────────
class MainWindow(QMainWindow):
    COL_NAME, COL_LOCAL, COL_SERVER, COL_STATUS, COL_PROGRESS, COL_NOTE = range(6)

    def __init__(self):
        super().__init__()
        self.setWindowTitle(APP_TITLE)
        if os.path.isfile(ICON_PATH):
            self.setWindowIcon(QIcon(ICON_PATH))
        self.resize(1100, 700)

        self.settings = QSettings(APP_ORG, APP_NAME)
        self.server   = self.settings.value('server', DEFAULT_SERVER, type=str)
        self.token    = self.settings.value('token', '', type=str)
        self.src_dir  = self.settings.value('src_dir', '', type=str)

        # row state, keyed by abs docx path: {server_row, local_mtime,
        # server_mtime, status, volume_label, table_row}
        self._rows: dict[str, dict] = {}
        # maps server_code → docx filename so server rows without a
        # local match still appear in the table.
        self._server: dict[str, dict] = {}

        # ── Top bar ──
        top = QFrame()
        tl = QHBoxLayout(top)
        tl.setContentsMargins(8, 8, 8, 8)
        self.dir_label = QLabel(f'<b>Source:</b> {self.src_dir or "(not set)"}')
        self.dir_label.setTextInteractionFlags(Qt.TextSelectableByMouse)
        btn_browse = QPushButton('Browse…')
        btn_browse.clicked.connect(self.pick_source)
        btn_settings = QPushButton('Settings')
        btn_settings.clicked.connect(self.open_settings)
        btn_refresh = QPushButton('Refresh')
        btn_refresh.clicked.connect(self.refresh_status)
        btn_process = QPushButton('Process All Pending')
        btn_process.setStyleSheet(
            'QPushButton { background:#31345A; color:#fff; padding:6px 14px;'
            ' border-radius:4px; font-weight:600; }'
            'QPushButton:hover { background:#4a4e7a; }'
        )
        btn_process.clicked.connect(self.process_pending)
        self.btn_process = btn_process
        for w in (self.dir_label, btn_browse, btn_settings, btn_refresh):
            tl.addWidget(w)
        tl.addStretch(1)
        tl.addWidget(btn_process)

        # ── File table ──
        self.table = QTableWidget(0, 6)
        self.table.setHorizontalHeaderLabels(
            ['File', 'Local mtime', 'Server mtime', 'Status', 'Progress', 'Note']
        )
        self.table.horizontalHeader().setSectionResizeMode(self.COL_NAME,     QHeaderView.Stretch)
        self.table.horizontalHeader().setSectionResizeMode(self.COL_LOCAL,    QHeaderView.ResizeToContents)
        self.table.horizontalHeader().setSectionResizeMode(self.COL_SERVER,   QHeaderView.ResizeToContents)
        self.table.horizontalHeader().setSectionResizeMode(self.COL_STATUS,   QHeaderView.ResizeToContents)
        self.table.horizontalHeader().setSectionResizeMode(self.COL_PROGRESS, QHeaderView.Fixed)
        self.table.setColumnWidth(self.COL_PROGRESS, 220)
        self.table.horizontalHeader().setSectionResizeMode(self.COL_NOTE,     QHeaderView.Stretch)
        self.table.setSelectionBehavior(QAbstractItemView.SelectRows)
        self.table.setAlternatingRowColors(True)
        self.table.verticalHeader().setVisible(False)

        # ── Log pane ──
        self.log = QTextEdit()
        self.log.setReadOnly(True)
        f = QFont('Consolas, ui-monospace, monospace')
        f.setStyleHint(QFont.Monospace)
        self.log.setFont(f)
        self.log.setStyleSheet('QTextEdit { background:#16161a; color:#cfd6e0; }')

        split = QSplitter(Qt.Vertical)
        split.addWidget(self.table)
        split.addWidget(self.log)
        split.setStretchFactor(0, 4)
        split.setStretchFactor(1, 1)

        central = QWidget()
        cl = QVBoxLayout(central)
        cl.setContentsMargins(0, 0, 0, 0)
        cl.addWidget(top)
        cl.addWidget(split, 1)
        self.setCentralWidget(central)

        sb = QStatusBar()
        self.setStatusBar(sb)
        self.status_text = QLabel('Idle')
        sb.addWidget(self.status_text, 1)

        # ── Worker on its own thread ──
        self._thread = QThread(self)
        self._worker = JobWorker(self.server, self.token)
        self._worker.moveToThread(self._thread)
        self._thread.started.connect(self._worker.run)
        self._worker.started_job.connect(self.on_started)
        self._worker.pdf_progress.connect(lambda p, pct: self.on_progress(p, pct, ST_PDF))
        self._worker.upload_progress.connect(lambda p, pct: self.on_progress(p, pct, ST_UPLOADING))
        self._worker.finished_job.connect(self.on_finished)
        self._worker.error_job.connect(self.on_error)
        self._worker.log.connect(self.append_log)
        self._worker.queue_empty.connect(self.on_queue_empty)
        self._thread.start()

        # ── First-run flow ──
        if not self.token:
            QTimer.singleShot(150, self.open_settings)
        elif not self.src_dir:
            QTimer.singleShot(150, self.pick_source)
        else:
            QTimer.singleShot(150, self.refresh_status)

    # ── Logging helper ──
    def append_log(self, line: str):
        ts = dt.datetime.now().strftime('%H:%M:%S')
        self.log.append(f'<span style="color:#888">[{ts}]</span> {line}')

    # ── Source picker ──
    def pick_source(self):
        start = self.src_dir or os.path.expanduser('~')
        d = QFileDialog.getExistingDirectory(self, 'Pick the folder containing your .docx files', start)
        if not d:
            return
        self.src_dir = d
        self.settings.setValue('src_dir', d)
        self.dir_label.setText(f'<b>Source:</b> {d}')
        self.refresh_status()

    # ── Settings ──
    def open_settings(self):
        dlg = SettingsDialog(self, self.server, self.token)
        if dlg.exec() != QDialog.Accepted:
            return
        srv, tok = dlg.values()
        if not srv or not tok:
            QMessageBox.warning(self, APP_TITLE, 'Server URL and token are required.')
            return
        self.server, self.token = srv, tok
        self.settings.setValue('server', srv)
        self.settings.setValue('token', tok)
        self._worker.configure(srv, tok)
        self.append_log(f'Settings saved (server={srv})')
        if not self.src_dir:
            self.pick_source()
        else:
            self.refresh_status()

    # ── Refresh: GET server status, walk source dir, fill table ──
    def refresh_status(self):
        if not self.token:
            self.append_log('No token — open Settings first.')
            return
        if not self.src_dir or not os.path.isdir(self.src_dir):
            self.append_log('Source dir not set or missing.')
            return

        self.status_text.setText('Fetching server status…')
        try:
            r = requests.get(
                self.server.rstrip('/') + '/api/admin-books-status.php',
                headers={'X-Author-Token': self.token},
                timeout=30,
            )
        except Exception as e:
            QMessageBox.warning(self, APP_TITLE, f'Could not reach server:\n{e}')
            self.status_text.setText('Disconnected')
            return
        if r.status_code != 200:
            QMessageBox.warning(self, APP_TITLE, f'Server returned HTTP {r.status_code}:\n{r.text[:300]}')
            self.status_text.setText(f'Server error {r.status_code}')
            return
        data = r.json()
        server_books = data.get('books', [])
        # map by docx name (and by stem so the docx-on-disk lookup is
        # robust to whichever the user named their files)
        self._server = {}
        for b in server_books:
            self._server[b['volume_docx']] = b
        self.append_log(f'Server: {len(server_books)} active books')

        # walk source dir
        local_files = sorted(
            p for p in Path(self.src_dir).iterdir()
            if p.is_file() and p.suffix.lower() == '.docx' and not p.name.startswith('~$')
        )
        self.append_log(f'Local: {len(local_files)} .docx in {self.src_dir}')

        self._rebuild_table(local_files)
        self.status_text.setText(
            f'Idle — {len(local_files)} local, {len(server_books)} server, '
            f'{self._count_queued()} queued'
        )

    def _rebuild_table(self, local_files: list[Path]):
        """Fill the table from current server + local state. Each row
        represents either a file present locally or a server-side book
        (server-only rows show `Server only` so the author can see what
        exists upstream)."""
        # Build a full set of rows: server rows + local-only rows.
        rows: list[tuple[str, str | None]] = []  # (docx_name, abs_local_path)
        local_by_name = {p.name: str(p) for p in local_files}
        for name in sorted(set(list(self._server.keys()) + list(local_by_name.keys()))):
            rows.append((name, local_by_name.get(name)))

        self.table.setRowCount(0)
        self._rows.clear()

        for docx_name, local_path in rows:
            row = self.table.rowCount()
            self.table.insertRow(row)

            srv = self._server.get(docx_name)
            srv_mt = srv['docx_mtime'] if srv else None
            local_mt = None
            if local_path and os.path.isfile(local_path):
                local_mt = os.path.getmtime(local_path)

            # Compute initial status.
            if not local_path:
                status = ST_NO_LOCAL
            elif not srv:
                status = ST_NO_SERVER
            elif srv_mt and local_mt:
                # Compare with a small slop; filesystems often round
                # timestamps to even seconds, and some authors save with
                # sub-second drift relative to upload.
                try:
                    server_epoch = dt.datetime.fromisoformat(srv_mt.replace('Z', '+00:00')).timestamp()
                except Exception:
                    server_epoch = 0
                status = ST_QUEUED if local_mt > server_epoch + 2 else ST_OK
            else:
                status = ST_QUEUED

            self.table.setItem(row, self.COL_NAME, QTableWidgetItem(docx_name))
            self.table.setItem(row, self.COL_LOCAL, QTableWidgetItem(fmt_dt(local_mt)))
            self.table.setItem(row, self.COL_SERVER, QTableWidgetItem(fmt_dt(srv_mt)))
            self.table.setItem(row, self.COL_STATUS, QTableWidgetItem(status))

            pb = QProgressBar()
            pb.setRange(0, 100)
            pb.setValue(0)
            pb.setTextVisible(True)
            pb.setFormat('—')
            self.table.setCellWidget(row, self.COL_PROGRESS, pb)

            note = ''
            if srv and srv.get('pipeline_status'):
                note = f"pipeline: {srv['pipeline_status']}"
            self.table.setItem(row, self.COL_NOTE, QTableWidgetItem(note))

            self._color_row(row, status)

            self._rows[docx_name] = {
                'docx_name':   docx_name,
                'local_path':  local_path,
                'local_mtime': local_mt,
                'server_mtime': srv_mt,
                'status':      status,
                'volume_label': (srv or {}).get('volume_label', ''),
                'progress_bar': pb,
                'table_row':   row,
            }

    def _color_row(self, row: int, status: str):
        palette = {
            ST_QUEUED:     QColor('#fff7d6'),
            ST_PROCESSING: QColor('#e0ecff'),
            ST_PDF:        QColor('#e0ecff'),
            ST_UPLOADING:  QColor('#e0ecff'),
            ST_DONE:       QColor('#e2f5e2'),
            ST_ERROR:      QColor('#fde0e0'),
            ST_NO_LOCAL:   QColor('#f5f5f5'),
            ST_NO_SERVER:  QColor('#fff'),
            ST_OK:         QColor('#fff'),
        }
        c = palette.get(status, QColor('#fff'))
        for col in range(self.table.columnCount()):
            it = self.table.item(row, col)
            if it:
                it.setBackground(c)

    def _count_queued(self) -> int:
        return sum(1 for r in self._rows.values() if r['status'] == ST_QUEUED)

    # ── Process all rows currently flagged Queued ──
    def process_pending(self):
        if not self.token:
            QMessageBox.warning(self, APP_TITLE, 'Set the author token in Settings first.')
            return
        queued = [r for r in self._rows.values() if r['status'] == ST_QUEUED and r['local_path']]
        if not queued:
            self.append_log('Nothing pending.')
            return
        self.append_log(f'Queuing {len(queued)} file(s).')
        for r in queued:
            self._set_status(r['docx_name'], ST_PROCESSING)
            self._worker.enqueue([r['local_path']])
        self.status_text.setText(f'Processing {len(queued)} file(s)…')

    def _set_status(self, docx_name: str, status: str):
        r = self._rows.get(docx_name)
        if not r:
            return
        r['status'] = status
        item = self.table.item(r['table_row'], self.COL_STATUS)
        if item:
            item.setText(status)
        self._color_row(r['table_row'], status)

    # ── Worker callbacks ──
    def _row_for_path(self, docx_path: str) -> dict | None:
        name = Path(docx_path).name
        return self._rows.get(name)

    def on_started(self, docx_path: str):
        r = self._row_for_path(docx_path)
        if not r:
            return
        self._set_status(r['docx_name'], ST_PROCESSING)
        pb = r['progress_bar']
        pb.setValue(0)
        pb.setFormat('starting…')

    def on_progress(self, docx_path: str, pct: int, phase: str):
        r = self._row_for_path(docx_path)
        if not r:
            return
        self._set_status(r['docx_name'], phase)
        pb = r['progress_bar']
        pb.setValue(pct)
        pb.setFormat(f'{phase} %p%')

    def on_finished(self, docx_path: str, server_response: dict):
        r = self._row_for_path(docx_path)
        if not r:
            return
        r['progress_bar'].setValue(100)
        r['progress_bar'].setFormat('done')
        self._set_status(r['docx_name'], ST_DONE)
        note = self.table.item(r['table_row'], self.COL_NOTE)
        if note:
            note.setText(f"queued (job {server_response.get('job', '?')})")
        self.append_log(f'[{r["docx_name"]}] ✅ uploaded; pipeline kicked off.')

    def on_error(self, docx_path: str, message: str):
        r = self._row_for_path(docx_path)
        if not r:
            return
        self._set_status(r['docx_name'], ST_ERROR)
        note = self.table.item(r['table_row'], self.COL_NOTE)
        if note:
            note.setText(message.split('\n', 1)[0][:200])
        r['progress_bar'].setFormat('error')

    def on_queue_empty(self):
        # Don't spam the log; only update the status bar.
        if any(r['status'] in (ST_PROCESSING, ST_PDF, ST_UPLOADING) for r in self._rows.values()):
            return
        self.status_text.setText('Idle')

    # ── Shutdown: stop worker + persist UI state ──
    def closeEvent(self, ev):
        self._worker.stop()
        self._thread.quit()
        self._thread.wait(2000)
        super().closeEvent(ev)


def main():
    # Self-install gate: when the .exe is run from anywhere other than
    # its canonical install path (e.g. fresh out of the Downloads
    # folder), prompt the user to install. If they accept, we copy
    # ourselves into %LOCALAPPDATA%\Programs\…, create shortcuts,
    # relaunch the installed copy, and exit. Returns True to mean
    # "we relaunched, exit now"; False to continue booting the app.
    if _maybe_install_and_relaunch():
        sys.exit(0)

    app = QApplication.instance() or QApplication(sys.argv)
    app.setApplicationName(APP_NAME)
    app.setOrganizationName(APP_ORG)
    app.setApplicationDisplayName(APP_TITLE)
    if os.path.isfile(ICON_PATH):
        app.setWindowIcon(QIcon(ICON_PATH))

    # Light theme tuned to match yadayah.com brand colors.
    app.setStyleSheet("""
        QMainWindow { background: #f5f5f5; }
        QFrame { background: transparent; }
        QTableWidget { gridline-color: #e0e0e0; selection-background-color: #d8e0f5; }
        QHeaderView::section { background: #31345A; color: #fff; padding: 6px; border: 0; font-weight: 600; }
        QPushButton { padding: 6px 12px; border: 1px solid #ccc; background: #fff; border-radius: 4px; }
        QPushButton:hover { background: #f0f0f0; }
        QProgressBar { border: 1px solid #c8c8c8; border-radius: 3px; text-align: center; height: 18px; background: #fafafa; }
        QProgressBar::chunk { background: #5b8def; }
    """)

    w = MainWindow()
    w.show()
    sys.exit(app.exec())


if __name__ == '__main__':
    main()
