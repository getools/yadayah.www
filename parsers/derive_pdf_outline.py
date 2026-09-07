#!/usr/bin/env python3
"""
derive_pdf_outline.py — give a PDF an outline when its renderer didn't.

Usage: derive_pdf_outline.py <docx-path> <pdf-path> [--force] [--dry-run]

Word (and LibreOffice) emit PDF bookmarks from heading styles; ONLYOFFICE
does not. Several downstream steps depend on that outline — extract_toc.py
builds the flipbook's toc.json from it, and the bundle parser reads chapters
from the embedded TOC — so an outline-less PDF silently yields a book with
no chapters.

This rebuilds the outline from two sources that do survive conversion:

  * the DOCX's `yychapter`-styled paragraphs, which are the chapter titles
    in document order (chapter N is the Nth such paragraph), and
  * the DOCX's `TOC1` entries, which additionally cover unnumbered back
    matter such as RESOURCES.

Page numbers are found by scanning the RENDERED PDF rather than trusting
the page numbers Word cached in the TOC field: a different renderer
re-paginates, and those cached numbers drift (ONLYOFFICE put s04v05's
chapter 2 one printed page later than Word did). A chapter's opening page
is the first page whose leading lines are the chapter number followed by
the chapter title — running headers repeat the title on every page, but
never preceded by the bare chapter number, so this does not false-match.

Idempotent: exits 0 without touching a PDF that already has an outline
unless --force is given.
"""
import io
import re
import sys
import zipfile

import fitz  # PyMuPDF


def docx_paragraphs(docx_path):
    """Yield (style, text) for each paragraph in the document body."""
    with zipfile.ZipFile(docx_path) as z:
        xml = z.read("word/document.xml").decode("utf-8", "replace")
    for p in re.findall(r"<w:p[ >].*?</w:p>", xml, re.S):
        m = re.search(r'w:pStyle w:val="([^"]+)"', p)
        if not m:
            continue
        text = "".join(re.findall(r"<w:t[^>]*>([^<]*)</w:t>", p)).strip()
        if text:
            yield m.group(1), text


def outline_targets(docx_path):
    """Return ([chapter titles in order], [back-matter titles])."""
    chapters, toc_entries = [], []
    for style, text in docx_paragraphs(docx_path):
        if style == "yychapter":
            chapters.append(text)
        elif style == "TOC1":
            toc_entries.append(text)

    # A TOC1 line is "<title><tab><printed page>"; strip the trailing page
    # number so the titles can be compared against the chapter list.
    def strip_page(s):
        return re.sub(r"\s+\d+\s*$", "", s).strip()

    seen = set()
    for c in chapters:
        seen.add(strip_page(c))

    back_matter = []
    for entry in toc_entries:
        title = strip_page(entry)
        # Numbered chapters already come from yychapter; keep only extras
        # (RESOURCES and friends), which carry no leading chapter number.
        if title not in seen and not re.match(r"^\d+", title):
            back_matter.append(title)
    return chapters, back_matter


def norm(s):
    """Fold whitespace so PDF line breaks don't defeat comparison."""
    return re.sub(r"\s+", " ", s).strip()


def head_lines(page, n=4):
    lines = [l.strip() for l in page.get_text().split("\n") if l.strip()]
    return lines[:n], lines


def numbered_chapters(chapters):
    """Return [(chapter_number, bare_title)] for the yychapter paragraphs.

    The chapter's own number is taken from the title text ("1Gibowr ~ ..."),
    NOT from its position in the list. Those differ: s04v04 numbers its
    chapters 1,3,4..15 because chapter 2 was authored without the yychapter
    style, so positional numbering would silently shift every chapter after
    the gap by one.
    """
    out = []
    for pos, raw in enumerate(chapters, 1):
        m = re.match(r"^(\d+)\s*(.*\S)\s*$", raw)
        if m:
            out.append((int(m.group(1)), norm(m.group(2))))
        else:
            out.append((pos, norm(raw)))
    return out


def find_chapter_pages(doc, numbered):
    """Map chapter number -> physical page number (both 1-based)."""
    found = {}
    for pno in range(doc.page_count):
        head, _ = head_lines(doc[pno])
        if len(head) < 2:
            continue
        for number, title in numbered:
            if number in found:
                continue
            probe = title[:18]
            if not probe:
                continue
            for j in range(len(head) - 1):
                if (head[j] == str(number)
                        and norm(head[j + 1]).startswith(probe)):
                    found[number] = pno + 1
                    break
    return found


def find_back_matter_pages(doc, back_matter, after_page):
    """Locate unnumbered back matter, searching only the tail of the book."""
    found = {}
    start = max(after_page, int(doc.page_count * 0.5))
    for pno in range(start, doc.page_count):
        head, _ = head_lines(doc[pno], 3)
        for title in back_matter:
            if title in found:
                continue
            probe = norm(title)[:18]
            if probe and any(norm(l).startswith(probe) for l in head):
                found[title] = pno + 1
    return found


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    flags = {a for a in sys.argv[1:] if a.startswith("--")}
    if len(args) != 2:
        sys.stderr.write(
            "usage: derive_pdf_outline.py <docx> <pdf> [--force] [--dry-run]\n")
        return 2
    docx_path, pdf_path = args
    force = "--force" in flags
    dry = "--dry-run" in flags

    doc = fitz.open(pdf_path)
    existing = doc.get_toc()
    if existing and not force:
        print("[outline] PDF already has %d entries - nothing to do"
              % len(existing))
        return 0

    chapters, back_matter = outline_targets(docx_path)
    if not chapters:
        sys.stderr.write("[outline] no yychapter paragraphs in %s - "
                         "cannot derive an outline\n" % docx_path)
        return 1

    numbered = numbered_chapters(chapters)
    ch_pages = find_chapter_pages(doc, numbered)
    last = max(ch_pages.values()) if ch_pages else 0
    bm_pages = find_back_matter_pages(doc, back_matter, last)

    # Word writes chapter bookmarks as "N  Title" (number, two spaces), and
    # the bundle parser splits chapter_number from chapter_name on exactly
    # that shape. The DOCX run text runs them together ("1Gibowr ~ ..."),
    # so re-insert the separator rather than leaving a title the parser
    # would read as one unnumbered blob.
    toc = []
    for number, bare in numbered:
        if number in ch_pages:
            toc.append([1, "%d  %s" % (number, bare), ch_pages[number]])
        else:
            sys.stderr.write("[outline] WARNING chapter %d not located: %s\n"
                             % (number, bare[:60]))
    for title in back_matter:
        if title in bm_pages:
            toc.append([1, norm(title), bm_pages[title]])

    toc.sort(key=lambda e: e[2])

    missing = len(chapters) - len(ch_pages)
    print("[outline] %s: %d/%d chapters located, %d back-matter entries"
          % (pdf_path.rsplit("/", 1)[-1], len(ch_pages), len(chapters),
             len(bm_pages)))
    for level, title, page in toc:
        print("   p%-5d %s" % (page, title[:60]))

    if missing:
        # A partial outline is still better than none, but say so loudly:
        # a chapter the parser can't see is a chapter that won't narrate.
        sys.stderr.write("[outline] %d chapter(s) could not be located\n"
                         % missing)
    if dry:
        print("[outline] --dry-run, not writing")
        return 0
    if not toc:
        sys.stderr.write("[outline] nothing to write\n")
        return 1

    doc.set_toc(toc)
    doc.saveIncr()
    print("[outline] wrote %d entries into %s" % (len(toc), pdf_path))
    return 0


if __name__ == "__main__":
    sys.exit(main())
