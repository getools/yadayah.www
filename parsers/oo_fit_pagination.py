#!/usr/bin/env python3
"""
oo_fit_pagination.py — render a DOCX through ONLYOFFICE so it paginates like Word.

Usage: oo_fit_pagination.py --docx <staged.docx> --ref-docx <original.docx>
                            --out <dest.pdf> [--report <report.json>]

ONLYOFFICE ignores <w:kern>; every YY book kerns all text (docDefaults
kern=2), so ONLYOFFICE lines run ~1-2% wide, paragraphs gain lines, and
chapters drift late against the page numbers Word printed in the book's own
TOC (s03v99: 275 pages vs Word's 271, chapters up to 5 pages late).
Tightening character spacing by 0.05pt (w:spacing -1, in twentieths of a
point) emulates the kerning: s03v99 then lands within a page of Word.

Every chapter starts with a section break, so each chapter's page length is
independent of the others. That makes a per-chapter fit possible:

  1. render with -1 everywhere; check against Word's TOC (pdf_pagination_check);
  2. if anything is off, render 0, -2, -3 everywhere too and measure each
     chapter's page length under each value;
  3. give each chapter the first value (preference -1, 0, -2, -3) whose length
     matches Word's; render that combination and check it;
  4. publish whichever render scored best (fewest chapters off, then smallest
     page-count difference).

The spacing is injected per run (<w:rPr><w:spacing/>), skipping runs whose
paragraph or character style, or own rPr, already sets letter spacing (Title,
yyRESOURCES, ...), so styled spacing is left exactly as authored. The
original DOCX is never modified; variants are staged in --stage-dir only for
the ONLYOFFICE fetch and removed afterwards.

Exit codes: 0 = PDF written and pagination matches Word; 10 = PDF written but
some chapters/pages still differ (see report); 11 = PDF written, pagination
could not be checked (no TOC page numbers); other non-zero = no PDF (caller
falls back to a plain conversion).
"""
import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fitz  # noqa: E402
import pdf_pagination_check as ppc  # noqa: E402

CONTAINER = "yada-www-onlyoffice-1"
JWT_FILE = "/opt/yada-www/.oojwt"
STAGE_DIR = "/opt/yada-www/public/u/books-word"
OO_HELPER = "/opt/yada-www/oo-convert.py"
CANDIDATES = [-1, 0, -2, -3]          # order = preference on ties
LATER_THAN_SPACING = ("w", "kern", "position", "sz", "szCs", "highlight", "u",
                      "effect", "bdr", "shd", "fitText", "vertAlign", "rtl",
                      "cs", "em", "lang", "eastAsianLayout", "specVanish",
                      "oMath", "rPrChange")
LATER_RE = re.compile(r"<w:(?:%s)[ />]" % "|".join(LATER_THAN_SPACING))


def log(msg):
    print("[oo-fit] " + msg, file=sys.stderr, flush=True)


# ── styles ────────────────────────────────────────────────────────────
def spaced_styles(styles_xml):
    """Style ids whose effective run properties set w:spacing (via basedOn)."""
    own, based = {}, {}
    for m in re.finditer(r'<w:style [^>]*w:styleId="([^"]+)"[^>]*>(.*?)</w:style>',
                         styles_xml, re.S):
        sid, body = m.group(1), m.group(2)
        r = re.search(r"<w:rPr>(.*?)</w:rPr>", body, re.S)
        own[sid] = bool(r and "<w:spacing " in r.group(1))
        b = re.search(r'<w:basedOn w:val="([^"]+)"', body)
        based[sid] = b.group(1) if b else None
    out = set()
    for sid in own:
        cur, hops = sid, 0
        while cur and hops < 20:
            if own.get(cur):
                out.add(sid)
                break
            cur, hops = based.get(cur), hops + 1
    return out


# ── regions ───────────────────────────────────────────────────────────
def region_starts(xml, anchors):
    """Character offsets in document.xml where each anchor's region begins.

    Chapters begin at their yychapter paragraph (number taken from the
    heading text); back matter at the first non-TOC paragraph whose text is
    the title. Returns [(offset, anchor_key)] sorted, plus ("front", 0)."""
    out = [(0, "front")]
    want_ch = {a["number"]: a["key"] for a in anchors if a["kind"] == "chapter"}
    want_bm = {ppc.norm(a["title"]).upper(): a["key"]
               for a in anchors if a["kind"] == "back"}
    got = set()
    for m in re.finditer(r"<w:p[ >].*?</w:p>", xml, re.S):
        p = m.group(0)
        st = re.search(r'w:pStyle w:val="([^"]+)"', p)
        st = st.group(1) if st else "Normal"
        if st.startswith("TOC"):
            continue
        text = ppc.norm(ppc.html_mod.unescape(
            "".join(re.findall(r"<w:t[^>]*>([^<]*)</w:t>", p))))
        key = None
        if st == "yychapter":
            n = re.match(r"^(\d+)", text)
            if n and int(n.group(1)) in want_ch:
                key = want_ch[int(n.group(1))]
        elif text.upper() in want_bm:
            key = want_bm[text.upper()]
        if key and key not in got:
            got.add(key)
            out.append((m.start(), key))
    out.sort()
    return out


def inject(xml_slice, val, skip_styles):
    """Add <w:spacing w:val=val/> to each run in the slice that doesn't
    already get letter spacing from its own rPr or its styles."""
    if val == 0:
        return xml_slice
    tag = '<w:spacing w:val="%d"/>' % val
    out, pos, pstyle = [], 0, "Normal"
    tok = re.compile(r'<w:p(?=[ >])[^>]*>|<w:pStyle w:val="([^"]+)"/>|<w:r(?=[ >])[^>]*>')
    for m in tok.finditer(xml_slice):
        t = m.group(0)
        if t.startswith("<w:p") and not t.startswith("<w:pStyle"):
            pstyle = "Normal"
            continue
        if m.group(1):
            pstyle = m.group(1)
            continue
        if t.endswith("/>") or pstyle in skip_styles:
            continue
        end = m.end()
        out.append(xml_slice[pos:end])
        pos = end
        if xml_slice.startswith("<w:rPr>", end):
            close = xml_slice.find("</w:rPr>", end)
            chg = xml_slice.find("<w:rPrChange", end)
            if chg != -1 and chg < close:   # nested rPr inside rPrChange
                close = xml_slice.find("</w:rPr>", xml_slice.find("</w:rPrChange>", chg))
            body = xml_slice[end + 7:close]
            top = body.split("<w:rPrChange", 1)[0]
            rs = re.search(r'<w:rStyle w:val="([^"]+)"', top)
            if "<w:spacing " in top or (rs and rs.group(1) in skip_styles):
                continue
            lm = LATER_RE.search(body)
            ins = end + 7 + (lm.start() if lm else len(body))
            out.append(xml_slice[pos:ins] + tag)
            pos = ins
        else:
            out.append("<w:rPr>" + tag + "</w:rPr>")
    out.append(xml_slice[pos:])
    return "".join(out)


def make_variant(src_docx, dst_docx, starts, values, skip_styles):
    """values: region key -> spacing. Writes dst_docx."""
    with zipfile.ZipFile(src_docx) as zi:
        xml = zi.read("word/document.xml").decode("utf-8")
        bounds = [o for o, _ in starts] + [len(xml)]
        parts = [xml[:starts[0][0]]]
        for i, (off, key) in enumerate(starts):
            parts.append(inject(xml[off:bounds[i + 1]], values.get(key, -1),
                                skip_styles))
        new = "".join(parts)
        with zipfile.ZipFile(dst_docx, "w", zipfile.ZIP_DEFLATED) as zo:
            for it in zi.infolist():
                data = new.encode("utf-8") if it.filename == "word/document.xml" \
                    else zi.read(it.filename)
                zo.writestr(it, data)


# ── ONLYOFFICE ────────────────────────────────────────────────────────
def oo_render(docx, pdf_out, jwt, tag):
    name = "_oo-fit-%d-%s.docx" % (os.getpid(), tag)
    staged = os.path.join(STAGE_DIR, name)
    shutil.copyfile(docx, staged)
    os.chmod(staged, 0o644)
    try:
        r = subprocess.run(["docker", "exec", "-e", "JWT_SECRET=" + jwt, CONTAINER,
                            "python3", "/tmp/oo-convert.py", name],
                           capture_output=True, text=True, timeout=1900)
        if r.returncode != 0:
            log("render %s failed rc=%d: %s" % (tag, r.returncode, r.stderr[-400:]))
            return False
        with open(pdf_out, "wb") as f:
            c = subprocess.run(["docker", "exec", CONTAINER, "cat", "/tmp/oo-out.pdf"],
                               stdout=f, timeout=300)
        return c.returncode == 0 and os.path.getsize(pdf_out) > 10000
    finally:
        try:
            os.remove(staged)
        except OSError:
            pass


def measure(ref_docx, pdf):
    rep = ppc.check(ref_docx, pdf)
    doc = fitz.open(pdf)
    try:
        phys = ppc.locate(doc, ppc.toc_targets(ref_docx)[0])
    finally:
        doc.close()
    return rep, phys


def score(rep):
    return (len(rep["mismatches"]), abs(rep.get("page_delta") or 0))


def lengths(anchors, phys, pdf_pages, word_pages):
    """Per located anchor: (measured pages, Word pages) up to the next
    located anchor. The last anchor runs to the end of the book; its Word
    length comes from the Word page count, assuming the front matter is the
    same length in both renders."""
    loc = [a for a in anchors if a["key"] in phys]
    out = {}
    for a, b in zip(loc, loc[1:]):
        out[a["key"]] = (phys[b["key"]] - phys[a["key"]], b["page"] - a["page"])
    if loc and word_pages:
        a0, last = loc[0], loc[-1]
        word_last_phys = phys[a0["key"]] + (last["page"] - a0["page"])
        out[last["key"]] = (pdf_pages - phys[last["key"]] + 1,
                            word_pages - word_last_phys + 1)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--docx", required=True, help="staged docx to render")
    ap.add_argument("--ref-docx", required=True, help="original docx (TOC/page refs)")
    ap.add_argument("--out", required=True)
    ap.add_argument("--report")
    a = ap.parse_args()

    jwt = open(JWT_FILE).read().strip()
    subprocess.run(["docker", "cp", OO_HELPER, CONTAINER + ":/tmp/oo-convert.py"],
                   capture_output=True)
    work = tempfile.mkdtemp(prefix="oofit-")
    try:
        anchors, _ = ppc.toc_targets(a.ref_docx)
        with zipfile.ZipFile(a.docx) as z:
            skip = spaced_styles(z.read("word/styles.xml").decode("utf-8"))
            xml = z.read("word/document.xml").decode("utf-8")
        starts = region_starts(xml, anchors)
        keys = [k for _, k in starts]

        renders = {}   # tag -> (pdf, rep, phys)

        def run(tag, values):
            v_docx = os.path.join(work, tag + ".docx")
            v_pdf = os.path.join(work, tag + ".pdf")
            make_variant(a.docx, v_docx, starts, values, skip)
            if not oo_render(v_docx, v_pdf, jwt, tag):
                return None
            rep, phys = measure(a.ref_docx, v_pdf)
            renders[tag] = (v_pdf, rep, phys, values)
            log("%s: %s" % (tag, ppc.summary(rep)))
            return rep

        rep = run("s-1", {k: -1 for k in keys})
        if rep is None:
            return 4
        if rep["checkable"] and not ppc.is_ok(rep) and len(starts) > 1:
            for v in CANDIDATES[1:]:
                run("s%d" % v, {k: v for k in keys})
            # Per-region choice: first candidate whose length matches Word's.
            choice, matched = {}, 0
            for k in keys:
                choice[k] = -1
                for v in CANDIDATES:
                    r = renders.get("s%d" % v)
                    if not r:
                        continue
                    ln = lengths(anchors, r[2], r[1]["pdf_pages"],
                                 r[1]["word_pages"]).get(k)
                    if ln and ln[0] == ln[1]:
                        choice[k] = v
                        matched += 1
                        break
            if any(v != -1 for v in choice.values()):
                log("per-chapter spacing: " + ", ".join(
                    "%s=%d" % (k, v) for k, v in choice.items() if k != "front"))
                run("mix", choice)

        rank = ["mix"] + ["s%d" % v for v in CANDIDATES]
        best = min(renders, key=lambda t: score(renders[t][1]) + (rank.index(t),))
        pdf, rep, _, values = renders[best]
        shutil.copyfile(pdf, a.out)
        rep["chosen"] = best
        rep["spacing"] = values
        if a.report:
            with open(a.report, "w") as f:
                json.dump(rep, f, indent=1, ensure_ascii=False)
        print("[oo-fit] chose %s: %s" % (best, ppc.summary(rep)))
        if not rep["checkable"]:
            return 11
        return 0 if ppc.is_ok(rep) else 10
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
