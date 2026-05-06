#!/usr/bin/env python3
"""
Companion to cron-fliphtml5-match.sh — takes the JSON output of
fliphtml5-match.cjs (via MATCH_JSON env var), fuzzy-matches each FlipHTML5
book to a yy_volume row, and writes volume_flip_code via docker exec psql.

Stdout: a one-line summary like "wrote:5 assigned:5 candidates:26".
Stderr: per-match debug lines.
"""
import json, os, re, subprocess, sys

raw = os.environ.get("MATCH_JSON", "")
if not raw:
    print("error:no-input")
    sys.exit(1)

try:
    data = json.loads(raw)
except Exception as e:
    print(f"error:invalid-json:{e}")
    sys.exit(1)

if not data.get("ok"):
    print(f"error:{data.get('error', 'unknown')}")
    sys.exit(0)

matches = data.get("matches", [])
print(f"FlipHTML5 books found: {len(matches)}", file=sys.stderr)


def norm(s):
    s = (s or "").lower()
    s = re.sub(r"\.(pdf|docx?)$", "", s, flags=re.I)
    s = re.sub(r"[\s_.\-'’]", "", s)
    return s


PSQL = ["docker", "exec", "-i", "yada-postgres-prod",
        "psql", "-U", "postgres", "-d", "yada", "-tAF|", "-c"]


def psql_query(sql):
    return subprocess.run(PSQL + [sql], capture_output=True, text=True, check=True).stdout.strip()


def psql_exec(sql):
    return subprocess.run(PSQL + [sql], capture_output=True, text=True)


# Pull volumes that are missing a flip_code.
rows = psql_query(
    "SELECT volume_key, COALESCE(volume_pdf,''), COALESCE(volume_code,''), COALESCE(volume_label,'') "
    "FROM yy_volume "
    "WHERE volume_pdf IS NOT NULL "
    "  AND (volume_flip_code IS NULL OR volume_flip_code = '')"
).splitlines()

candidates = []
for line in rows:
    parts = line.split("|")
    if len(parts) < 4:
        continue
    vk, pdf, code, label = parts[0], parts[1], parts[2], parts[3]
    candidates.append({
        "vk": vk, "pdf": pdf, "code": code, "label": label,
        "norms": [n for n in [norm(pdf), norm(code), norm(label)] if n],
        "matched": False,
    })

print(f"Volumes needing a flip_code: {len(candidates)}", file=sys.stderr)

# Match: each FlipHTML5 book gets at most one volume; each volume gets at
# most one book. We accept a match if either normalized name is a prefix
# of the other (handles "YY-s01v01" vs "YY-s01v01-Intro").
used_flip_ids = set()
assigned = []
for m in matches:
    fid = (m.get("flip_id") or "").strip()
    if not fid or fid in used_flip_ids:
        continue
    cands = [norm(m.get("book_name", "")), norm(m.get("pdf_name", ""))]
    cands = [c for c in cands if c]
    if not cands:
        continue

    for v in candidates:
        if v["matched"]:
            continue
        hit = False
        for c in cands:
            for vn in v["norms"]:
                if c == vn or c.startswith(vn) or vn.startswith(c):
                    hit = True
                    break
            if hit:
                break
        if hit:
            assigned.append({
                "vk": v["vk"], "flip_id": fid,
                "pdf": v["pdf"], "fliphtml5_name": m.get("book_name", ""),
            })
            v["matched"] = True
            used_flip_ids.add(fid)
            break

# Write each assignment. flip_id is alphanumeric (digits or hex from
# FlipHTML5) — sanity-check before interpolating.
written = 0
for a in assigned:
    if not re.fullmatch(r"[A-Za-z0-9_-]+", a["flip_id"]):
        print(f"  skip: flip_id has unexpected chars: {a['flip_id']!r}", file=sys.stderr)
        continue
    sql = (
        f"UPDATE yy_volume SET "
        f"volume_flip_code='{a['flip_id']}', "
        f"volume_pipeline_status='queued', "
        f"volume_pipeline_retry_count=0, "
        f"volume_pipeline_message='Auto-matched to FlipHTML5 book {a['flip_id']}' "
        f"WHERE volume_key={int(a['vk'])} "
        f"  AND (volume_flip_code IS NULL OR volume_flip_code = '')"
    )
    r = psql_exec(sql)
    if r.returncode == 0:
        written += 1
        print(f"  matched vk={a['vk']} ({a['pdf']}) -> {a['flip_id']} ({a['fliphtml5_name']})", file=sys.stderr)
    else:
        print(f"  psql error for vk={a['vk']}: {r.stderr.strip()}", file=sys.stderr)

print(f"wrote:{written} assigned:{len(assigned)} candidates:{len(candidates)} fliphtml5books:{len(matches)}")
