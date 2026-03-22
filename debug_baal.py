"""Debug the remaining ba'al instances."""
import sys
sys.stdout.reconfigure(encoding='utf-8')
from docx import Document
from docx.oxml.ns import qn

LEFT = chr(0x2018)
RIGHT = chr(0x2019)
REG = chr(0x0027)
APOS_CHARS = [REG, RIGHT, LEFT, chr(0x0060), chr(0x00B4), chr(0x02BC)]

doc = Document(r'C:\users\joe\work\dev\yada\docs\YY-s04v04-Coming Home-Basar-Herald.docx')

for pi in [1884, 2050]:
    para = doc.paragraphs[pi]
    print(f"Para {pi} text snippet: ...{para.text[para.text.find('ba'):para.text.find('ba')+20]}...")
    print(f"  Runs: {len(para.runs)}")

    # Check runs around the match
    for ri, run in enumerate(para.runs):
        t = run.text or ''
        for ac in APOS_CHARS:
            if f'ba{ac}al' in t:
                print(f"  Run {ri} SAME-RUN match: {repr(t[:60])}")
        if t.endswith('ba') or any(t.endswith(f'ba{ac}') for ac in APOS_CHARS):
            next_t = para.runs[ri+1].text if ri+1 < len(para.runs) else ''
            if next_t.startswith('al') or any(next_t.startswith(f'{ac}al') for ac in APOS_CHARS):
                print(f"  Run {ri} CROSS-RUN: {repr(t[-10:])} + {repr(next_t[:10])}")

    # Check ALL runs in XML (including hyperlinks)
    p_elem = para._element
    all_r = p_elem.findall('.//' + qn('w:r'))
    for ri, r in enumerate(all_r):
        te = r.find(qn('w:t'))
        t = (te.text or '') if te is not None else ''
        for ac in APOS_CHARS:
            if f'ba{ac}al' in t:
                parent_tag = r.getparent().tag.split('}')[-1]
                print(f"  XML run {ri} (parent={parent_tag}): {repr(t[:80])}")
    print()
