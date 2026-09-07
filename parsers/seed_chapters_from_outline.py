#!/usr/bin/env python3
"""
seed_chapters_from_outline.py — create yy_chapter rows from a PDF outline.

Usage: seed_chapters_from_outline.py <pdf-path> <volume-key> [--apply]

parse_volume_from_bundle.py only MAPS paragraphs onto yy_chapter rows that
already exist; it never creates them (only the older DOCX-based
parse_volume.py does, and that one re-extracts paragraphs from the .docx,
which would overwrite the PDF-derived rows the live pipeline depends on).
So a volume whose chapters were never seeded parses to 3600 paragraphs with
chapter_key NULL across the board.

This seeds those rows from the PDF's own outline, which is the same source
extract_toc.py uses for the flipbook, so the two agree by construction.

  chapter_number / chapter_name  <- outline title "N  Title"
  chapter_sort                   <- N * 10 (the historical convention)
  chapter_page                   <- the PRINTED page number, read off the
                                    chapter's opening page rather than
                                    assumed to be physical minus a fixed
                                    front-matter offset, since a re-rendered
                                    book can shift by a page.

Unnumbered outline entries (RESOURCES and other back matter) are skipped:
no volume in this corpus carries a chapter row for them.

Dry-run by default; pass --apply to write. Existing chapter_numbers are
never modified or deleted -- they are reported and left alone, because
yy_paragraph, yy_translation and yy_tts_audio all hold foreign keys into
yy_chapter and deleting a row would cascade into narrated audio.
"""
import re
import subprocess
import sys

import fitz  # PyMuPDF

PG = "yada-postgres-prod"


def psql(sql, quiet=False):
    out = subprocess.check_output([
        "docker", "exec", PG, "psql", "-U", "postgres", "-d", "yada",
        "-At", "-F", "\t", "-c", sql,
    ], text=True)
    return out


def printed_page_of(doc, physical):
    """Read the printed folio off a chapter's opening page.

    Chapter pages lead with the printed number, then the chapter number,
    then the title. Fall back to None if the leading line isn't a number.
    """
    lines = [l.strip() for l in doc[physical - 1].get_text().split("\n")
             if l.strip()]
    if lines and re.fullmatch(r"\d{1,4}", lines[0]):
        return int(lines[0])
    return None


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    apply_ = "--apply" in sys.argv[1:]
    if len(args) != 2:
        sys.stderr.write(
            "usage: seed_chapters_from_outline.py <pdf> <volume-key> [--apply]\n")
        return 2
    pdf_path, vol_key = args[0], int(args[1])

    doc = fitz.open(pdf_path)
    toc = doc.get_toc()
    if not toc:
        sys.stderr.write("[seed] %s has no outline - run "
                         "derive_pdf_outline.py first\n" % pdf_path)
        return 1

    wanted = []
    for _level, title, physical in toc:
        m = re.match(r"^(\d+)\s+(.*\S)\s*$", title)
        if not m:
            print("[seed] skipping unnumbered entry: %s" % title[:50])
            continue
        number = int(m.group(1))
        name = m.group(2)
        printed = printed_page_of(doc, physical)
        wanted.append((number, name, number * 10, printed, physical))

    existing = {}
    for line in psql("SELECT chapter_number, chapter_name FROM yy_chapter "
                     "WHERE volume_key = %d" % vol_key).splitlines():
        if not line.strip():
            continue
        parts = line.split("\t")
        existing[int(parts[0])] = parts[1] if len(parts) > 1 else ""

    to_insert = [w for w in wanted if w[0] not in existing]
    skipped = [w for w in wanted if w[0] in existing]

    print("[seed] volume %d: outline has %d numbered chapters; "
          "%d already in yy_chapter" % (vol_key, len(wanted), len(skipped)))
    for number, name, sort, printed, physical in wanted:
        mark = "EXISTS" if number in existing else "insert"
        note = ""
        if number in existing:
            note = "  (db has %r)" % existing[number]
        if printed is None:
            note += "  [printed page unreadable - storing NULL]"
        print("  %-6s %2d  %-42s sort=%-4d printed=%-5s physical=%d%s"
              % (mark, number, name[:42], sort,
                 printed if printed is not None else "-", physical, note))

    if not apply_:
        print("[seed] dry run - pass --apply to write %d row(s)"
              % len(to_insert))
        return 0
    if not to_insert:
        print("[seed] nothing to insert")
        return 0

    values = []
    for number, name, sort, printed, _physical in to_insert:
        safe = name.replace("'", "''")
        page = str(printed) if printed is not None else "NULL"
        values.append("(%d, %d, '%s', %d, %s)"
                      % (vol_key, number, safe, sort, page))
    sql = ("INSERT INTO yy_chapter "
           "(volume_key, chapter_number, chapter_name, chapter_sort, "
           "chapter_page) VALUES " + ", ".join(values))
    psql(sql)
    print("[seed] inserted %d chapter row(s) for volume %d"
          % (len(to_insert), vol_key))
    return 0


if __name__ == "__main__":
    sys.exit(main())
