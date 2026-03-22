"""Debug: inspect TOC paragraphs in s01v01 for Yada' occurrences."""
import os
from docx import Document
from docx.oxml.ns import qn

QUOTE_CHARS = set("'\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b")

fp = r'C:\Users\Joe\Work\dev\yada\docs\YY-s01v01-An Intro to God-Dabarym-Words.docx'
doc = Document(fp)

print("=== First 100 paragraphs (body) - looking for Yada with quotes/modifiers ===\n")
for i, p_elem in enumerate(doc.element.body.iter(qn('w:p'))):
    if i >= 100:
        break
    # Get full text from all runs (including nested in hyperlinks etc.)
    text = ''
    run_details = []
    for r in p_elem.iter(qn('w:r')):
        for t in r.findall(qn('w:t')):
            seg = t.text or ''
            text += seg
            if seg:
                # Get font info
                rpr = r.find(qn('w:rPr'))
                rf = rpr.find(qn('w:rFonts')) if rpr is not None else None
                font = rf.get(qn('w:ascii'), '?') if rf is not None else '?'
                run_details.append((seg, font))

    if 'yada' in text.lower() or 'Yada' in text:
        has_quote = any(ch in text for ch in QUOTE_CHARS)
        has_hr = '\u02be' in text or '\u02bf' in text
        marker = ''
        if has_quote:
            marker = ' *** HAS QUOTE ***'
        if has_hr:
            marker = ' [has half-ring]'
        # Show style
        style_elem = p_elem.find(qn('w:pPr'))
        style = ''
        if style_elem is not None:
            ps = style_elem.find(qn('w:pStyle'))
            if ps is not None:
                style = ps.get(qn('w:val'), '')

        # Check for field instructions (TOC indicator)
        has_field = len(p_elem.findall('.//' + qn('w:fldChar'))) > 0

        try:
            print(f"  P{i} [style={style}] [field={has_field}]{marker}")
            print(f"    Text: {text[:120]}")
            # Show run-by-run breakdown
            for seg, font in run_details:
                if 'yada' in seg.lower() or 'Yada' in seg:
                    chars = ' '.join(f'U+{ord(c):04X}' for c in seg[:30])
                    print(f"    Run: [{seg[:60]}] font={font}")
                    print(f"         Codepoints: {chars}")
        except UnicodeEncodeError:
            print(f"    (display error)")
        print()
