#!/usr/bin/env python3
"""
pdf_pagination_check.py — does a rendered book PDF paginate like Word did?

Usage: pdf_pagination_check.py <docx> <pdf> [--json <report.json>]

Reference numbers come from the DOCX, as Word last saved them:
  * the TOC1 field result: "<num>\\t<title>\\t<printed page>" per chapter,
    plus unnumbered back matter such as "RESOURCES\\t<page>";
  * docProps/app.xml <Pages>: Word's total page count.

Actual numbers come from scanning the PDF for each chapter's opening page
(same locator as derive_pdf_outline.py). Printed folios restart at chapter 1,
so a chapter's actual printed page is
    TOC(first anchor) + (physical(anchor) - physical(first anchor)).

This is the number the reader sees: the book's own printed TOC is Word's
cached field, so any mismatch here means the printed TOC points at the wrong
page. Exit 0 = every located anchor matches (and the page count, when
known); 1 = mismatch; 2 = usage/error; 3 = nothing to check (no TOC page
numbers, or fewer than two anchors located).

Library use: check(docx, pdf) -> report dict (see build_report).
"""
import html as html_mod
import json
import os
import re
import sys
import zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fitz  # noqa: E402  PyMuPDF
from derive_pdf_outline import (  # noqa: E402
    find_back_matter_pages, norm)


def _para_text_with_tabs(p):
    out = []
    for t, tab in re.findall(r"<w:t[^>]*>([^<]*)</w:t>|<w:(tab)/>", p):
        out.append("\t" if tab else t)
    return html_mod.unescape("".join(out))


def toc_targets(docx_path):
    """Return (anchors, word_pages).

    anchors: ordered list of dicts {key, kind, number, title, page} from TOC1.
      kind "chapter" (leading number) or "back" (unnumbered back matter).
    word_pages: int from docProps/app.xml, or None.
    """
    with zipfile.ZipFile(docx_path) as z:
        xml = z.read("word/document.xml").decode("utf-8", "replace")
        try:
            app = z.read("docProps/app.xml").decode("utf-8", "replace")
        except KeyError:
            app = ""
    anchors, seen = [], set()
    for p in re.findall(r"<w:p[ >].*?</w:p>", xml, re.S):
        if 'w:pStyle w:val="TOC1"' not in p:
            continue
        text = _para_text_with_tabs(p)
        m = re.search(r"(\d+)\s*$", text)
        if not m:
            continue
        page = int(m.group(1))
        body = text[:m.start()].strip()
        fields = [f.strip() for f in body.split("\t") if f.strip()]
        num = re.match(r"^(\d+)\s*(.*)$", body)
        if num and (len(fields) >= 2 or num.group(2)):
            number = int(num.group(1))
            title = norm(num.group(2) or (fields[1] if len(fields) > 1 else ""))
            key = "ch%d" % number
            kind = "chapter"
        else:
            number, title, kind = None, norm(body), "back"
            key = "bm:" + title
        if key in seen:
            continue
        seen.add(key)
        anchors.append({"key": key, "kind": kind, "number": number,
                        "title": title, "page": page})
    m = re.search(r"<Pages>(\d+)</Pages>", app)
    return anchors, (int(m.group(1)) if m else None)


BIDI = re.compile("[‎‏‪-‮⁦-⁩]")


def _chapter_pages(doc, chapters):
    """Chapter number -> physical page of its opening heading.

    Same rule as derive_pdf_outline.find_chapter_pages (a line that is the
    bare chapter number, followed by a line starting with the title), but
    pages where three or more chapters "start" are skipped: that is the
    book's own contents page, which lists "1" above "Shemowth ~ Names" too.
    Also handles the number ending one page and the title opening the next.
    """
    pages = [[l.strip() for l in doc[i].get_text().split("\n") if l.strip()]
             for i in range(doc.page_count)]
    hits = {}
    for pno, lines in enumerate(pages):
        on_page = []
        for number, title in chapters:
            probe = title[:18]
            if not probe:
                continue
            # Hebrew halves ("יהוה ~ YaHoWaH") come out of the PDF reversed;
            # the English half after "~" survives, so accept that too.
            tail = norm(title.split("~", 1)[1])[:18] if "~" in title else ""

            def is_title(line):
                line = norm(BIDI.sub("", line))
                return line.startswith(probe) or bool(tail and tail in line)

            for j in range(len(lines) - 1):
                if lines[j] == str(number) and is_title(lines[j + 1]):
                    on_page.append(number)
                    break
            else:
                nxt = pages[pno + 1] if pno + 1 < len(pages) else []
                if (lines and lines[-1] == str(number) and len(nxt) > 1
                        and is_title(nxt[1])):
                    hits.setdefault(number, []).append(pno + 2)
        if len(on_page) >= 3:
            continue
        for n in on_page:
            hits.setdefault(n, []).append(pno + 1)
    return {n: min(p) for n, p in hits.items()}


def locate(doc, anchors):
    """Map anchor key -> physical page (1-based) in the PDF."""
    chapters = [(a["number"], a["title"]) for a in anchors if a["kind"] == "chapter"]
    ch = _chapter_pages(doc, chapters) if chapters else {}
    out = {"ch%d" % n: p for n, p in ch.items()}
    back = [a["title"] for a in anchors if a["kind"] == "back"]
    if back:
        last = max(ch.values()) if ch else 0
        for t, p in find_back_matter_pages(doc, back, last).items():
            out["bm:" + t] = p
    return out


def build_report(anchors, word_pages, phys, pdf_pages):
    located = [a for a in anchors if a["key"] in phys]
    # <Pages> is only as fresh as Word's last save; files rewritten by
    # scripts carry junk (1, 7, 62 for 450-page books). Ignore implausible.
    saved_pages = word_pages
    if word_pages and not (0.85 <= pdf_pages / float(word_pages) <= 1.15):
        word_pages = None
    rep = {"anchors": [], "mismatches": [], "word_pages": word_pages,
           "word_pages_saved": saved_pages,
           "pdf_pages": pdf_pages, "located": len(located),
           "total": len(anchors), "checkable": len(located) >= 2}
    if not located:
        return rep
    base = located[0]
    for a in anchors:
        row = dict(a)
        if a["key"] in phys:
            row["physical"] = phys[a["key"]]
            row["actual"] = base["page"] + (phys[a["key"]] - phys[base["key"]])
            if row["actual"] != a["page"]:
                rep["mismatches"].append(row)
        rep["anchors"].append(row)
    rep["page_delta"] = (pdf_pages - word_pages) if word_pages else None
    return rep


def check(docx_path, pdf_path):
    anchors, word_pages = toc_targets(docx_path)
    doc = fitz.open(pdf_path)
    try:
        phys = locate(doc, anchors)
        return build_report(anchors, word_pages, phys, doc.page_count)
    finally:
        doc.close()


def is_ok(rep):
    return (rep["checkable"] and not rep["mismatches"]
            and not rep.get("page_delta"))


def summary(rep):
    if not rep["checkable"]:
        return "pagination unchecked (%d/%d TOC anchors located)" % (
            rep["located"], rep["total"])
    parts = []
    if rep["mismatches"]:
        parts.append("%d/%d chapters off: %s" % (
            len(rep["mismatches"]), rep["located"], ", ".join(
                "%s TOC %d got %d" % (
                    ("ch%d" % m["number"]) if m["number"] is not None else m["title"],
                    m["page"], m["actual"]) for m in rep["mismatches"][:8])))
    if rep.get("page_delta"):
        parts.append("pages %d vs Word %d" % (rep["pdf_pages"], rep["word_pages"]))
    return "; ".join(parts) or "pagination matches Word (%d anchors, %s pages)" % (
        rep["located"], rep["pdf_pages"])


def main():
    args = sys.argv[1:]
    out_json = None
    if "--json" in args:
        i = args.index("--json")
        out_json = args[i + 1]
        del args[i:i + 2]
    if len(args) != 2:
        sys.stderr.write(__doc__.split("\n\n")[1] + "\n")
        return 2
    rep = check(*args)
    if out_json:
        with open(out_json, "w") as f:
            json.dump(rep, f, indent=1, ensure_ascii=False)
    print("[pagination] " + summary(rep))
    if not rep["checkable"]:
        return 3
    return 0 if is_ok(rep) else 1


if __name__ == "__main__":
    sys.exit(main())
