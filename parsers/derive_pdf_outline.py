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

  * the DOCX's `yychapter`-styled paragraphs (or `yycnum`+`yychap` paragraph
    pairs used by older books), which are the chapter titles in document order,
    and
  * the DOCX's `TOC1` entries, which additionally cover unnumbered back
    matter such as RESOURCES.

For books without `yychapter`/`yychap` headings (e.g. companion/reference
books that use `yyheadingsection` or `yysec` for all sections), sections are
detected by those styles and located via case-insensitive title matching in
the top lines of each PDF page. The TOC entries are emitted as unnumbered
(no leading "N  ") so the bundle parser resolves them by chapter_name rather
than chapter_number.

For books that use no YY heading styles at all (authored before the style
convention), chapters are detected from consecutive Normal-style paragraph
pairs where a bare digit (the chapter number) is immediately followed by the
chapter title.  The PDF scan also handles three layout variants:

  * Chapter number and title on the same page anywhere (mid-page chapter
    start after the previous chapter's text ends).
  * Chapter number on the last line of page N, title on the first content
    line of page N+1 (page break fell between them).

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
import html as html_mod
import io
import re
import sys
import zipfile

import fitz  # PyMuPDF


def docx_paragraphs(docx_path):
    """Yield (style, text) for each paragraph in the document body.

    Paragraphs without an explicit style tag are yielded as ("Normal", text).
    HTML entities (e.g. &amp;) are decoded so titles compare cleanly against
    PDF-extracted text.
    """
    with zipfile.ZipFile(docx_path) as z:
        xml = z.read("word/document.xml").decode("utf-8", "replace")
    for p in re.findall(r"<w:p[ >].*?</w:p>", xml, re.S):
        m = re.search(r'w:pStyle w:val="([^"]+)"', p)
        style = m.group(1) if m else "Normal"
        text = html_mod.unescape(
            "".join(re.findall(r"<w:t[^>]*>([^<]*)</w:t>", p))).strip()
        if text:
            yield style, text


def outline_targets(docx_path):
    """Return ([chapter titles], [back-matter titles], [section titles]).

    chapters    — yychapter-styled paragraphs (numbered YY chapters), or
                  yycnum+yychap paragraph pairs (older split-style convention),
                  or consecutive Normal digit+title pairs (pre-style books)
    back_matter — TOC1 entries not already covered by chapters
    sections    — yyheadingsection- or yysec-styled paragraphs (unnumbered
                  sections used in companion/reference books that lack yychapter)

    Fallback: if neither yychapter nor yyheadingsection/yysec paragraphs are
    found, chapters are detected from consecutive Normal-style paragraph pairs
    where a bare digit immediately precedes a title text.  This handles books
    authored before the heading-style convention (e.g. s04v06).
    """
    chapters, toc_entries, sections = [], [], []
    all_paras = list(docx_paragraphs(docx_path))
    prev_cnum = None  # holds a pending yycnum value until yychap consumes it
    for style, text in all_paras:
        if style == "yychapter":
            chapters.append(text)
            prev_cnum = None
        elif style == "yycnum" and re.match(r"^\d+$", text):
            # Split-style chapter number paragraph — wait for the yychap title.
            prev_cnum = text
        elif style == "yychap":
            # Combine with the preceding yycnum (if any) into "NTitle" shape.
            chapters.append((prev_cnum or "") + text)
            prev_cnum = None
        elif style in ("yyheadingsection", "yysec"):
            sections.append(text)
            prev_cnum = None
        elif style == "TOC1":
            toc_entries.append(text)
            prev_cnum = None
        else:
            prev_cnum = None

    # A TOC1 line is "<title><tab><printed page>"; strip the trailing page
    # number so the titles can be compared against the chapter list.
    def strip_page(s):
        return re.sub(r"\s+\d+\s*$", "", s).strip()

    seen = set()
    for c in chapters:
        seen.add(strip_page(c))

    # Match TOC1 chapters to yychapter ones by NUMBER, not text. The two
    # never agree verbatim: the heading runs number and title together
    # ("1`Eden ~ Joy") while the TOC line has tab spacing and often a
    # different glyph for the same letter ("1   ʿEden ~ Joy"). Comparing
    # text re-added every chapter, doubling the flipbook TOC.
    seen_nums = set()
    for c in chapters:
        m = re.match(r"^(\d+)", c)
        if m:
            seen_nums.add(int(m.group(1)))

    back_matter = []
    for entry in toc_entries:
        title = strip_page(entry)
        if title in seen:
            continue
        m = re.match(r"^(\d+)", title)
        if m:
            if int(m.group(1)) in seen_nums:
                continue
            # Numbered chapter present in TOC1 but missing from yychapter
            # (e.g. the heading was not styled yychapter in the DOCX, so
            # Word did not bookmark it). Recover it so find_chapter_pages()
            # can locate it in the PDF.
            chapters.append(title)
            seen.add(title)
            seen_nums.add(int(m.group(1)))
        else:
            # Unnumbered back matter (RESOURCES and friends).
            back_matter.append(title)

    # Fallback for books with no YY heading styles: detect chapters from
    # consecutive Normal paragraphs where a bare digit is immediately
    # followed by a title text (the chapter number and title live in
    # separate paragraphs rather than one yychapter paragraph).
    if not chapters and not sections:
        for i in range(len(all_paras) - 1):
            c_style, c_text = all_paras[i]
            n_style, n_text = all_paras[i + 1]
            if (c_style == "Normal" and n_style == "Normal"
                    and re.match(r"^\d+$", c_text)
                    and n_text and not re.match(r"^\d", n_text)):
                # Combine into the same "NTitle" shape that numbered_chapters()
                # expects (e.g. "1aOwth ~ Signs", "2Beryth ~ Covenant").
                chapters.append(c_text + n_text)

    return chapters, back_matter, sections


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

    Returns (numbered, unnumbered). Positional numbers are only a fallback
    for books where NO heading carries a number; otherwise unnumbered
    headings (s02v01's "Prelude" ... "Prophecy") come back separately. Giving
    them positions 1..4 collided with the real chapters 1..4 and pointed
    those chapters at the prelude pages.
    """
    parsed = [(raw, re.match(r"^(\d+)\s*(.*\S)\s*$", raw)) for raw in chapters]
    if not any(m for _, m in parsed):
        return [(pos, norm(raw)) for pos, raw in enumerate(chapters, 1)], []
    out, unnumbered = [], []
    for raw, m in parsed:
        if m:
            out.append((int(m.group(1)), norm(m.group(2))))
        else:
            unnumbered.append(norm(raw))
    return out, unnumbered


def find_unnumbered_pages(doc, titles):
    """Locate unnumbered yychapter headings: first page whose leading lines
    contain the title as a whole line NOT followed by a bare page number
    (that is a contents listing; s02v01's half-title page lists "Prelude"
    above "1", one page before the real heading)."""
    found = {}
    for pno in range(doc.page_count):
        head, lines = head_lines(doc[pno], 4)
        for j, line in enumerate(head):
            title = norm(line)
            if title not in titles or title in found:
                continue
            if j + 1 < len(lines) and re.match(r"^\d+$", lines[j + 1]):
                continue
            found[title] = pno + 1
    return found


_BIDI = re.compile("[\u200e\u200f\u202a-\u202e\u2066-\u2069]")


def _is_title(line, title):
    """Does a PDF line open with this chapter title?

    Hebrew halves ("יהוה ~ YaHoWaH") come out of the PDF bidi-reversed with
    direction marks, so the English half after "~" is accepted anywhere in
    the line as well (s03v99 ch12 was never bookmarked)."""
    # Spaces are ignored: extraction sometimes drops one ("Can QusayyScam?",
    # s07v02 ch1, which then fell back to the contents page).
    line = re.sub(r"\s+", "", _BIDI.sub("", line))
    flat = re.sub(r"\s+", "", title)
    probe = flat[:15]
    if probe and line.startswith(probe):
        return True
    tail = re.sub(r"\s+", "", title.split("~", 1)[1])[:15] if "~" in title else ""
    return bool(tail) and tail in line


def find_chapter_pages(doc, numbered):
    """Map chapter number -> physical page number (both 1-based).

    Three passes to handle the layout variants produced by ONLYOFFICE:

    Pass 1 (head lines, n=4): fast path for standard books where chapter
      numbers appear near the top of the page.

    Pass 2 (all lines, single page): mid-page chapter starts where the
      previous chapter's text ends, then the divider + number + title all
      appear on the same page but well past line 4.

    Pass 3 (cross-page): the page break falls between the chapter number
      and the title, so the number is the last non-empty line on page N
      and the title is the second non-empty line on page N+1 (right after
      the page number).
    """
    # Pre-compute all pages' line lists once (saves repeated get_text calls).
    page_lines = []
    for pno in range(doc.page_count):
        lines = [l.strip() for l in doc[pno].get_text().split("\n") if l.strip()]
        page_lines.append(lines)

    found = {}

    # The book's own contents page lists "1" above "Shemowth ~ Names" too,
    # which pass 1 took for chapter 1's opening (s03v99 ch1 -> p6, 2026-10-14).
    # A page where three or more chapters "start" is a contents page: skip it.
    toc_pages = set()
    for pno, lines in enumerate(page_lines):
        hits = 0
        for number, title in numbered:
            if any(lines[j] == str(number) and _is_title(lines[j + 1], title)
                   for j in range(len(lines) - 1)):
                hits += 1
        if hits >= 3:
            toc_pages.add(pno)

    # Pass 1: head lines (n=4) — fast path, preserves original behaviour
    for pno, lines in enumerate(page_lines):
        if pno in toc_pages:
            continue
        head = lines[:4]
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
                        and _is_title(head[j + 1], title)):
                    found[number] = pno + 1
                    break

    # Pass 2: all lines on a single page — mid-page chapter starts
    if len(found) < len(numbered):
        for pno, lines in enumerate(page_lines):
            if len(lines) < 2 or pno in toc_pages:
                continue
            for number, title in numbered:
                if number in found:
                    continue
                probe = title[:18]
                if not probe:
                    continue
                for j in range(len(lines) - 1):
                    if (lines[j] == str(number)
                            and _is_title(lines[j + 1], title)):
                        found[number] = pno + 1
                        break

    # Pass 3: cross-page — chapter number ends page N, title begins page N+1
    if len(found) < len(numbered):
        for pno in range(doc.page_count - 1):
            lines_curr = page_lines[pno]
            lines_next = page_lines[pno + 1]
            if not lines_curr or len(lines_next) < 2:
                continue
            last_line = lines_curr[-1]
            # lines_next[0] is the page number; real content starts at [1]
            first_content = lines_next[1]
            for number, title in numbered:
                if number in found:
                    continue
                probe = title[:18]
                if not probe:
                    continue
                if (last_line == str(number)
                        and _is_title(first_content, title)):
                    found[number] = pno + 2  # title is on the next page
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


def find_section_pages(doc, sections, n=12):
    """Find pages for yyheadingsection entries.

    These render in ALL CAPS at the top of their opening page (but may follow
    a book title/subtitle on the first section's page). Matches case-insensitively
    by checking the first n non-empty lines of each page so the first section
    (which appears after a cover title/subtitle) is still found.
    n=12 to tolerate ONLYOFFICE PDFs that put running headers/footers before
    the section title in the extracted text stream.
    """
    def match(title, line, idx):
        whole = norm(title).upper()
        ln = norm(line).upper()
        # Short titles must be the whole line: "Conclusion" matched a body
        # line "conclusions that I am going..." (s03v99 p9). An exact line
        # may sit deeper (justified text extracts one word per line, so
        # s03v99's "Introduction" is line 19); prefixes stay within n.
        if ln == whole:
            if idx < n:
                return True
            # Deeper lines must match case too: a lone justified body word
            # ("torture", s07v05 p368) is not the heading "Torture".
            return idx < max(n, 25) and norm(line) == norm(title)
        return len(whole) > 16 and idx < n and ln.startswith(whole[:16])

    found = {}
    for pno in range(doc.page_count):
        lines = [l.strip() for l in doc[pno].get_text().split("\n") if l.strip()]
        hits = []          # (line index, title) in page order
        for title in sections:
            if not norm(title):
                continue
            for i, l in enumerate(lines[:max(n, 25)]):
                # "TITLE" then a bare page number is a contents listing
                # (s07v05 p6: "AFTERWORD", "510"), not the heading itself.
                if i + 1 < len(lines) and re.match(r"^\d+$", lines[i + 1]):
                    continue
                if match(title, l, i):
                    hits.append((i, title))
                    break
        hits.sort()
        # A page listing three or more section titles is a contents/appendix
        # list (s07v05 p531: TOPICAL APPENDIX, then Fighting, Terrorism,
        # War...). Only its first title, the page's own heading, opens there.
        # A contents page (s07v05 p6) lists them below its own heading, so
        # the kept title must also be at the top of the page.
        if len(hits) >= 3:
            hits = hits[:1] if hits[0][0] <= 3 else []
        for _, title in hits:
            found.setdefault(title, pno + 1)
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

    chapters, back_matter, sections = outline_targets(docx_path)
    if not chapters and not sections:
        sys.stderr.write("[outline] no chapter or section headings found in %s - "
                         "cannot derive an outline\n" % docx_path)
        return 1

    toc = []

    if chapters:
        numbered, unnumbered = numbered_chapters(chapters)
        ch_pages = find_chapter_pages(doc, numbered)
        un_pages = find_unnumbered_pages(doc, unnumbered)
        for title in unnumbered:
            if title in un_pages:
                toc.append([1, title, un_pages[title]])
            else:
                sys.stderr.write("[outline] WARNING heading not located: %s\n"
                                 % title[:60])
        last = max(ch_pages.values()) if ch_pages else 0
        bm_pages = find_back_matter_pages(doc, back_matter, last)

        # Word writes chapter bookmarks as "N  Title" (number, two spaces), and
        # the bundle parser splits chapter_number from chapter_name on exactly
        # that shape. The DOCX run text runs them together ("1Gibowr ~ ..."),
        # so re-insert the separator rather than leaving a title the parser
        # would read as one unnumbered blob.
        for number, bare in numbered:
            if number in ch_pages:
                toc.append([1, "%d  %s" % (number, bare), ch_pages[number]])
            else:
                sys.stderr.write("[outline] WARNING chapter %d not located: %s\n"
                                 % (number, bare[:60]))
        for title in back_matter:
            if title in bm_pages:
                toc.append([1, norm(title), bm_pages[title]])

        missing_ch = len(chapters) - len(ch_pages) - len(un_pages)
        print("[outline] %s: %d/%d chapters located, %d back-matter entries"
              % (pdf_path.rsplit("/", 1)[-1], len(ch_pages) + len(un_pages),
                 len(chapters),
                 len(bm_pages)))
        if missing_ch:
            sys.stderr.write("[outline] %d chapter(s) could not be located\n"
                             % missing_ch)

    if sections:
        sec_pages = find_section_pages(doc, sections)
        missing_sec = 0
        for title in sections:
            if title in sec_pages:
                toc.append([1, norm(title), sec_pages[title]])
            else:
                sys.stderr.write("[outline] WARNING section not located: %s\n"
                                 % title[:60])
                missing_sec += 1
        print("[outline] %s: %d/%d yyheadingsection entries located"
              % (pdf_path.rsplit("/", 1)[-1], len(sec_pages), len(sections)))
        if missing_sec:
            sys.stderr.write("[outline] %d section(s) could not be located\n"
                             % missing_sec)

    # The same heading can arrive twice, e.g. RESOURCES as both a TOC1 back-
    # matter line and a yyheadingsection paragraph.
    uniq, seen_entries = [], set()
    for entry in toc:
        key = (norm(entry[1]), entry[2])
        if key not in seen_entries:
            seen_entries.add(key)
            uniq.append(entry)
    toc = uniq
    toc.sort(key=lambda e: e[2])

    for level, title, page in toc:
        print("   p%-5d %s" % (page, title[:60]))

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
