#!/usr/bin/env python3
"""
pipeline_font_install.py — install fonts uploaded from Admin → Site → Fonts
into the PDF pipeline's font folder.

The web font (public/fonts/*) and the pipeline font are separate copies.
The pipeline copy lives in /usr/local/share/fonts/yada, which is:
  * read by embed_fonts.py (fonts injected into a docx before Graph / Word
    Online render it), and
  * bind-mounted into the ONLYOFFICE container, which renders with its
    INSTALLED fonts (it ignores fonts embedded in the docx).
An upload to public/fonts never reached it, so ONLYOFFICE PDFs kept the old
glyphs (2026-10-13, Written in Stone).

Flow: api/admin-pipeline-font.php writes the font to
public/jobs/pipeline-fonts/files/<id>.<ext> and then the job
public/jobs/pipeline-fonts/incoming/<id>.json. pipeline-font.path
(DirectoryNotEmpty on incoming/) starts pipeline-font.service → this script:
  1. validate the font (fontTools), back up the current file(s) to
     /opt/yada-www/_backups/pipeline-fonts/<ts>/,
  2. install <base>.<ext> (.ttf or .ttc), removing the other-type copy,
  3. write alias copies (family renamed) if a font lists any (none do now),
  4. regenerate ONLYOFFICE's font list (restarts its converter),
  5. write public/jobs/pipeline-fonts/status.json (read by the admin page)
     and a yy_monitor_event row.

Usage:  pipeline_font_install.py            process queued jobs
        pipeline_font_install.py --status   only rewrite status.json
"""

import datetime as dt
import fcntl
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

from fontTools.ttLib import TTCollection, TTFont

FONTS_DIR = Path("/usr/local/share/fonts/yada")
JOB_DIR = Path("/opt/yada-www/public/jobs/pipeline-fonts")
INCOMING = JOB_DIR / "incoming"
FILES = JOB_DIR / "files"
DONE = JOB_DIR / "done"
STATUS = JOB_DIR / "status.json"
BACKUP_ROOT = Path("/opt/yada-www/_backups/pipeline-fonts")
LOCK = "/var/lock/pipeline-font-install.lock"
ONLYOFFICE = "yada-www-onlyoffice-1"
PG = "yada-postgres-prod"
EXTS = ("ttf", "ttc", "otf")

# Pipeline fonts by file base name. ONLYOFFICE matches fonts by family name
# only, and the two Yada Towrah generations are DIFFERENT fonts:
#   * "Yada Towrah" (v7.11, YadaTowrah.ttf) draws plain ASCII letters as the
#     paleo-Hebrew glyphs (`hwhy` = the divine name). 36 of 37 book docx
#     (2026-10-13) are set in it and depend on that.
#   * "Yada Towrah-Times" (v7.12+, YadaTowrah-Times.ttc/.ttf) draws ASCII as
#     ordinary Times letters; restyled docs (Written in Stone) use it.
# So never alias one family onto the other's file. "aliases" stays available
# for a rename that really is the same design.
PIPELINE_FONTS = {
    "YadaTowrah-Times":    {"label": "Yada Towrah-Times", "aliases": []},
    "YadaTowrah":          {"label": "Yada Towrah (v7.11, older docs)", "aliases": []},
    "JupiterYada-Regular": {"label": "Jupiter-Yada", "aliases": []},
    "SemiticEarly":        {"label": "Semitic Early", "aliases": []},
}


def log(msg):
    print(f"[{dt.datetime.now():%Y-%m-%d %H:%M:%S}] {msg}", flush=True)


def now_iso():
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")


def load_faces(path):
    """[TTFont, ...] — one per face (a .ttc holds several)."""
    with open(path, "rb") as f:
        magic = f.read(4)
    if magic == b"ttcf":
        return list(TTCollection(str(path)).fonts)
    if magic in (b"\x00\x01\x00\x00", b"OTTO", b"true"):
        return [TTFont(str(path))]
    raise ValueError("not a TrueType/OpenType font or collection")


def face_info(font):
    n = font["name"]
    return {
        "family": n.getDebugName(16) or n.getDebugName(1) or "",
        "style": n.getDebugName(17) or n.getDebugName(2) or "",
        "version": (n.getDebugName(5) or "").replace("Version ", ""),
        "ps": n.getDebugName(6) or "",
    }


def ps_prefix(family):
    return re.sub(r"[^A-Za-z0-9]", "", family)


def rename_family(font, old_family, new_family):
    """Rename a face's family in every name record (1, 3, 4, 6, 16)."""
    old_ps, new_ps = ps_prefix(old_family), ps_prefix(new_family)
    old_ps_hyph = old_family.replace(" ", "")          # "YadaTowrah-Times"
    for rec in font["name"].names:
        if rec.nameID not in (1, 3, 4, 6, 16):
            continue
        try:
            s = rec.toUnicode()
        except UnicodeDecodeError:
            continue
        if rec.nameID in (1, 16):
            s = new_family
        elif rec.nameID == 6:
            s = s.replace(old_ps_hyph, new_ps, 1) if s.startswith(old_ps_hyph) else new_ps + "-" + s.split("-")[-1]
        else:
            s = s.replace(old_family, new_family).replace(old_ps_hyph, new_ps)
        rec.string = s


def installed_files(base):
    """Current pipeline files for a base: main file(s) + alias copies."""
    out = []
    for p in sorted(FONTS_DIR.iterdir()):
        if p.is_file() and p.suffix[1:].lower() in EXTS and (
                p.stem == base or p.name.startswith(base + ".alias-")):
            out.append(p)
    return out


def write_status(jobs_result=None):
    fonts = {}
    for base, cfg in PIPELINE_FONTS.items():
        files = []
        for p in installed_files(base):
            try:
                faces = [face_info(f) for f in load_faces(p)]
            except Exception as e:                      # noqa: BLE001
                faces = [{"error": str(e)}]
            st = p.stat()
            files.append({
                "name": p.name,
                "alias": ".alias-" in p.name,
                "size": st.st_size,
                "mtime": dt.datetime.fromtimestamp(st.st_mtime, dt.timezone.utc).isoformat(timespec="seconds"),
                "faces": faces,
            })
        fonts[base] = {"label": cfg["label"], "aliases": cfg["aliases"], "files": files}
    prev = {}
    try:
        prev = json.loads(STATUS.read_text())
    except Exception:                                   # noqa: BLE001
        pass
    jobs = (jobs_result or []) + prev.get("jobs", [])
    data = {"updated": now_iso(), "fonts": fonts, "jobs": jobs[:20]}
    tmp = STATUS.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(data, indent=1))
    os.chmod(tmp, 0o664)
    shutil.chown(tmp, "www-data", "claudefix")
    tmp.replace(STATUS)


def monitor_event(severity, message, detail):
    sql = ("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, "
           "event_resolved_flag) VALUES ('pipeline-font', :'sev', :'msg', :'det', true);")
    try:
        subprocess.run(["docker", "exec", "-i", PG, "psql", "-U", "postgres", "-d", "yada", "-q",
                        "-v", f"sev={severity}", "-v", f"msg={message}", "-v", f"det={detail}"],
                       input=sql, text=True, capture_output=True, timeout=30)
    except Exception as e:                              # noqa: BLE001
        log(f"monitor event failed: {e}")


def install(job):
    base, ext = job["base"], job["ext"]
    if base not in PIPELINE_FONTS:
        raise ValueError(f"unknown pipeline font {base!r}")
    if ext not in EXTS:
        raise ValueError(f"unsupported type .{ext}")
    if not re.fullmatch(r"[A-Za-z0-9_-]+\.(ttf|ttc|otf)", job.get("file") or ""):
        raise ValueError("bad file name in job")
    src = FILES / job["file"]
    if not src.is_file():
        raise FileNotFoundError(f"uploaded file missing: {src.name}")
    faces = load_faces(src)
    for f in faces:                                     # parse the tables that matter
        f["cmap"].getBestCmap()
        f["head"]
    infos = [face_info(f) for f in faces]
    warnings = []
    current = [face_info(f)["family"] for p in installed_files(base) if ".alias-" not in p.name
               for f in load_faces(p)]
    new_fams = sorted({i["family"] for i in infos})
    if current and not set(new_fams) & set(current):
        warnings.append(f"family changed: {sorted(set(current))} → {new_fams}")

    # 1. back up everything currently installed for this base
    stamp = dt.datetime.now().strftime("%Y%m%d-%H%M%S")
    bak = BACKUP_ROOT / stamp
    old = installed_files(base)
    if old:
        bak.mkdir(parents=True, exist_ok=True)
        for p in old:
            shutil.copy2(p, bak / p.name)

    # 2. install the main file atomically, then drop stale siblings/aliases
    dest = FONTS_DIR / f"{base}.{ext}"
    tmp = FONTS_DIR / f".{base}.{ext}.tmp"
    shutil.copyfile(src, tmp)
    os.chmod(tmp, 0o644)
    tmp.replace(dest)
    for p in old:
        if p != dest and p.exists():
            p.unlink()

    # 3. alias copies (family renamed) for older docx font names
    main_family = infos[0]["family"]
    written = [dest.name]
    for alias in PIPELINE_FONTS[base]["aliases"]:
        if alias.lower() == main_family.lower():
            continue
        afaces = load_faces(dest)
        for f in afaces:
            rename_family(f, face_info(f)["family"], alias)
        apath = FONTS_DIR / f"{base}.alias-{ps_prefix(alias)}.{ext}"
        atmp = FONTS_DIR / f".{apath.name}.tmp"
        if ext == "ttc":
            coll = TTCollection()
            coll.fonts = afaces
            coll.save(str(atmp))
        else:
            afaces[0].save(str(atmp))
        os.chmod(atmp, 0o644)
        atmp.replace(apath)
        written.append(apath.name)

    return {
        "installed": written,
        "faces": infos,
        "backup": str(bak) if old else None,
        "warnings": warnings,
    }


def regenerate_onlyoffice(families):
    """Rebuild ONLYOFFICE's font list (it restarts docservice + converter),
    then confirm each family is in the converter's AllFonts.js."""
    r = subprocess.run(["docker", "exec", ONLYOFFICE, "documentserver-generate-allfonts.sh"],
                       capture_output=True, text=True, timeout=900)
    if r.returncode != 0:
        raise RuntimeError(f"generate-allfonts rc={r.returncode}: {(r.stderr or r.stdout)[-400:]}")
    allfonts = subprocess.run(
        ["docker", "exec", ONLYOFFICE, "cat",
         "/var/www/onlyoffice/documentserver/server/FileConverter/bin/AllFonts.js"],
        capture_output=True, text=True, timeout=60).stdout
    return [f for f in families if f'"{f}"' not in allfonts]


def main():
    for d in (INCOMING, FILES, DONE):
        d.mkdir(parents=True, exist_ok=True)
    if "--status" in sys.argv:
        write_status()
        return 0

    lock = open(LOCK, "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        log("another install is running; it will pick up queued jobs")
        return 0

    results, installed_any, families = [], False, set()
    while True:
        jobs = sorted(INCOMING.glob("*.json"))
        if not jobs:
            break
        for jp in jobs:
            res = {"id": jp.stem, "finished": None}
            job = {}
            try:
                job = json.loads(jp.read_text())
                res.update({"base": job.get("base"), "ext": job.get("ext"),
                            "orig_name": job.get("orig_name"), "user": job.get("user"),
                            "queued": job.get("queued")})
                log(f"job {jp.stem}: {job.get('orig_name')} → {job.get('base')}.{job.get('ext')}")
                out = install(job)
                installed_any = True
                families.update(i["family"] for i in out["faces"])
                families.update(PIPELINE_FONTS[job["base"]]["aliases"])
                res.update(state="installed", **out)
                log(f"  installed {out['installed']}; backup {out['backup']}")
            except Exception as e:                      # noqa: BLE001
                res.update(state="error", message=str(e))
                log(f"  ERROR {e}")
            res["finished"] = now_iso()
            results.append(res)
            # retire the job + its uploaded file
            stamp = dt.datetime.now().strftime("%Y%m%d-%H%M%S")
            jp.replace(DONE / f"{stamp}-{jp.name}")
            fname = job.get("file") or ""
            if re.fullmatch(r"[A-Za-z0-9_-]+\.(ttf|ttc|otf)", fname) and (FILES / fname).is_file():
                (FILES / fname).replace(DONE / f"{stamp}-{fname}")

    if installed_any:
        log("regenerating ONLYOFFICE font list…")
        try:
            missing = regenerate_onlyoffice(sorted(families))
            msg = "ONLYOFFICE font list rebuilt" + (f"; MISSING {missing}" if missing else "")
            for r in results:
                if r["state"] == "installed":
                    r["onlyoffice"] = msg
                    if missing:
                        r.setdefault("warnings", []).append(f"ONLYOFFICE does not list {missing}")
            log("  " + msg)
        except Exception as e:                          # noqa: BLE001
            for r in results:
                if r["state"] == "installed":
                    r["state"] = "error"
                    r["message"] = f"installed, but ONLYOFFICE refresh failed: {e}"
            log(f"  ERROR {e}")

    # prune old done/ entries (keep 40 newest files)
    for p in sorted(DONE.iterdir(), key=lambda p: p.stat().st_mtime)[:-40]:
        p.unlink(missing_ok=True)
    # orphaned uploads (no job arrived) older than an hour
    for p in FILES.iterdir():
        if p.stat().st_mtime < dt.datetime.now().timestamp() - 3600:
            p.unlink(missing_ok=True)

    write_status(results)
    for r in results:
        sev = "error" if r["state"] == "error" else ("warning" if r.get("warnings") else "info")
        monitor_event(sev, f"Pipeline font {r.get('base')}.{r.get('ext')}: {r['state']}",
                      json.dumps(r, default=str))
    return 0


if __name__ == "__main__":
    sys.exit(main())
