"""Check exact characters around ba'al."""
import sys
sys.stdout.reconfigure(encoding='utf-8')
from docx import Document
from docx.oxml.ns import qn

doc = Document(r'C:\users\joe\work\dev\yada\docs\YY-s04v04-Coming Home-Basar-Herald.docx')

para = doc.paragraphs[1884]
text = para.text

# Find 'ba' followed by anything followed by 'al'
for i in range(len(text) - 4):
    if text[i:i+2] == 'ba' and text[i+3:i+5] == 'al':
        ch = text[i+2]
        print(f"Position {i}: ba[{repr(ch)} U+{ord(ch):04X}]al")
        ctx = text[max(0,i-10):i+15]
        print(f"  Context: {ctx}")
        print()

# Also show all runs in the area
p_elem = para._element
all_r = p_elem.findall('.//' + qn('w:r'))
print(f"Total XML runs: {len(all_r)}")
# Print runs containing 'ba' or 'al' or apostrophe-like chars
for ri, r in enumerate(all_r):
    te = r.find(qn('w:t'))
    t = (te.text or '') if te is not None else ''
    if 'ba' in t.lower() or 'al' in t.lower() or 'husband' in t.lower() or 'lord' in t.lower():
        parent_tag = r.getparent().tag.split('}')[-1]
        print(f"  Run {ri} ({parent_tag}): {repr(t)}")
