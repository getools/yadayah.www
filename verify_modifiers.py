"""Verify half-ring modifier replacements and Yada Towrah font in YY documents."""

import os
from docx import Document
from docx.oxml.ns import qn

DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'
HALF_RINGS = frozenset('\u02be\u02bf')
QUOTE_CHARS = frozenset("'\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b")


def check_document(filepath):
    fname = os.path.basename(filepath)
    doc = Document(filepath)

    hr_count = 0
    hr_with_font = 0
    hr_without_font = 0
    remaining_quotes_in_hr_words = 0
    sample_hr_words = []
    sample_bad_font = []

    # Check body paragraphs
    for p in doc.element.body.iter(qn('w:p')):
        for r in p.iter(qn('w:r')):
            for t in r.findall(qn('w:t')):
                text = t.text or ''
                for ch in text:
                    if ch in HALF_RINGS:
                        hr_count += 1
                        # Check font
                        rpr = r.find(qn('w:rPr'))
                        rf = rpr.find(qn('w:rFonts')) if rpr is not None else None
                        has_yt = False
                        if rf is not None:
                            ascii_font = rf.get(qn('w:ascii'), '')
                            if ascii_font == 'Yada Towrah':
                                has_yt = True
                        if has_yt:
                            hr_with_font += 1
                        else:
                            hr_without_font += 1
                            if len(sample_bad_font) < 3:
                                # Get surrounding context
                                sample_bad_font.append(text[:50])

                # Collect sample words with half-rings
                if any(ch in text for ch in HALF_RINGS) and len(sample_hr_words) < 5:
                    sample_hr_words.append(text[:60])

    # Check headers
    for section in doc.sections:
        for hdr_attr in ('header', 'first_page_header', 'even_page_header'):
            try:
                hdr = getattr(section, hdr_attr)
                if hdr is None or hdr.is_linked_to_previous:
                    continue
                for p in hdr._element.iter(qn('w:p')):
                    for r in p.iter(qn('w:r')):
                        for t in r.findall(qn('w:t')):
                            text = t.text or ''
                            for ch in text:
                                if ch in HALF_RINGS:
                                    hr_count += 1
                                    rpr = r.find(qn('w:rPr'))
                                    rf = rpr.find(qn('w:rFonts')) if rpr is not None else None
                                    has_yt = False
                                    if rf is not None:
                                        if rf.get(qn('w:ascii'), '') == 'Yada Towrah':
                                            has_yt = True
                                    if has_yt:
                                        hr_with_font += 1
                                    else:
                                        hr_without_font += 1
            except Exception:
                pass

    print(f"\n{fname}:")
    print(f"  Half-ring chars: {hr_count}")
    print(f"  With Yada Towrah font: {hr_with_font}")
    print(f"  WITHOUT Yada Towrah font: {hr_without_font}")
    if sample_hr_words:
        print(f"  Sample words with half-rings:")
        for w in sample_hr_words:
            try:
                print(f"    {w}")
            except UnicodeEncodeError:
                print(f"    (cannot display - contains special chars)")
    if sample_bad_font:
        print(f"  Samples WITHOUT correct font:")
        for w in sample_bad_font:
            try:
                print(f"    {w}")
            except UnicodeEncodeError:
                print(f"    (cannot display)")

    return hr_count, hr_with_font, hr_without_font


def main():
    # Check first 5 documents as a sample
    docs = sorted([
        os.path.join(DOCS_DIR, f)
        for f in os.listdir(DOCS_DIR)
        if f.startswith('YY-') and f.endswith('.docx')
        and '_backup' not in f and '_updated' not in f
        and '_fixed' not in f and '_final' not in f
        and not f.startswith('~$')
    ])

    total_hr = 0
    total_with = 0
    total_without = 0

    # Check 5 representative docs (first, middle, last)
    indices = [0, len(docs)//4, len(docs)//2, 3*len(docs)//4, len(docs)-1]
    for idx in indices:
        if idx < len(docs):
            hr, w, wo = check_document(docs[idx])
            total_hr += hr
            total_with += w
            total_without += wo

    print(f"\n{'='*50}")
    print(f"SAMPLE TOTAL: {total_hr} half-rings, {total_with} with font, {total_without} without font")


if __name__ == '__main__':
    main()
