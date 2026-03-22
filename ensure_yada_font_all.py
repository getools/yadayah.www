"""
Final pass: Ensure ALL ALEPH (ʾ) and AYIN (ʿ) characters in documents
have Yada Towrah font. Checks body, tables, headers, footers, AND hyperlinks.
"""

import sys, os, glob, time, copy
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

ALEPH = chr(0x02BE)
AYIN = chr(0x02BF)


def split_and_font(r_elem):
    """Split run at modifier positions and apply Yada Towrah font."""
    t_elem = r_elem.find(qn('w:t'))
    if t_elem is None:
        return 0
    text = t_elem.text or ''
    if not text or (ALEPH not in text and AYIN not in text):
        return 0

    # Single modifier character - just set font
    if text in (ALEPH, AYIN):
        rPr = r_elem.find(qn('w:rPr'))
        if rPr is None:
            rPr = OxmlElement('w:rPr')
            r_elem.insert(0, rPr)
        rFonts = rPr.find(qn('w:rFonts'))
        if rFonts is not None:
            if rFonts.get(qn('w:ascii'), '') == 'Yada Towrah':
                return 0  # Already correct
        if rFonts is None:
            rFonts = OxmlElement('w:rFonts')
            rPr.append(rFonts)
        rFonts.set(qn('w:ascii'), 'Yada Towrah')
        rFonts.set(qn('w:hAnsi'), 'Yada Towrah')
        return 1

    # Mixed text + modifiers - need to split
    parent = r_elem.getparent()
    if parent is None:
        return 0

    idx = list(parent).index(r_elem)
    rPr = r_elem.find(qn('w:rPr'))

    segments = []
    buf = []
    for ch in text:
        if ch in (ALEPH, AYIN):
            if buf:
                segments.append(('text', ''.join(buf)))
                buf = []
            segments.append(('modifier', ch))
        else:
            buf.append(ch)
    if buf:
        segments.append(('text', ''.join(buf)))

    if len(segments) <= 1:
        return 0

    def clone_rPr(exclude_font=False):
        new_rPr = OxmlElement('w:rPr')
        if rPr is not None:
            for child in rPr:
                if exclude_font and child.tag == qn('w:rFonts'):
                    continue
                new_rPr.append(copy.deepcopy(child))
        return new_rPr

    def make_run(content, is_modifier=False):
        r = OxmlElement('w:r')
        p = clone_rPr(exclude_font=is_modifier)
        if is_modifier:
            rFonts = OxmlElement('w:rFonts')
            rFonts.set(qn('w:ascii'), 'Yada Towrah')
            rFonts.set(qn('w:hAnsi'), 'Yada Towrah')
            p.append(rFonts)
        r.append(p)
        t = OxmlElement('w:t')
        t.set(qn('xml:space'), 'preserve')
        t.text = content
        r.append(t)
        return r

    parent.remove(r_elem)
    cur = idx
    modifier_count = 0
    for seg_type, seg_text in segments:
        new_run = make_run(seg_text, is_modifier=(seg_type == 'modifier'))
        parent.insert(cur, new_run)
        cur += 1
        if seg_type == 'modifier':
            modifier_count += 1

    return modifier_count


def process_element(elem):
    """Process all w:r elements within any XML container."""
    fixes = 0
    # Find ALL w:r elements recursively
    all_runs = elem.findall('.//' + qn('w:r'))

    i = 0
    while i < len(all_runs):
        r = all_runs[i]
        t_elem = r.find(qn('w:t'))
        if t_elem is not None:
            text = t_elem.text or ''
            if ALEPH in text or AYIN in text:
                result = split_and_font(r)
                if result > 0:
                    fixes += result
                    # Re-scan since structure changed
                    all_runs = elem.findall('.//' + qn('w:r'))
                    continue
        i += 1

    return fixes


def process_document(docx_path):
    doc_name = os.path.basename(docx_path)
    print(f"Processing: {doc_name}")
    start = time.time()

    try:
        doc = Document(docx_path)
    except Exception as e:
        print(f"  ERROR: {e}")
        return 0

    fixes = 0

    # Process body element (covers all paragraphs, tables, hyperlinks)
    body = doc.element.body
    fixes += process_element(body)

    # Process headers and footers
    for section in doc.sections:
        for part in [section.header, section.footer]:
            if part:
                fixes += process_element(part._element)

    elapsed = time.time() - start

    if fixes > 0:
        try:
            doc.save(docx_path)
            print(f"  Font fixed: {fixes} modifiers ({elapsed:.1f}s)")
        except Exception as e:
            print(f"  ERROR saving: {e}")
            return 0
    else:
        print(f"  All fonts correct ({elapsed:.1f}s)")

    return fixes


# Main
docs_dir = r'C:\users\joe\work\dev\yada\docs'
print("Ensuring ALL modifiers have Yada Towrah font...")
print("=" * 80)

all_files = glob.glob(os.path.join(docs_dir, 'YY*.docx'))
docx_files = [f for f in all_files
              if not any(x in os.path.basename(f).lower()
                         for x in ['_updated', '_final', '_fmt', '_formatted',
                                   '_fixed', '_test', '_reverted', '_tagline',
                                   '_debug', '_backup'])]

print(f"Found {len(docx_files)} documents\n")

total = 0
for path in sorted(docx_files):
    total += process_document(path)

print("\n" + "=" * 80)
print(f"COMPLETE: {total} font fixes across {len(docx_files)} documents")
