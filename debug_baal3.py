"""Print all runs sequentially to find ba'al split."""
import sys
sys.stdout.reconfigure(encoding='utf-8')
from docx import Document
from docx.oxml.ns import qn

doc = Document(r'C:\users\joe\work\dev\yada\docs\YY-s04v04-Coming Home-Basar-Herald.docx')

para = doc.paragraphs[1884]
p_elem = para._element

# Print ALL direct children (not just runs)
print("All XML children of paragraph:")
for i, child in enumerate(p_elem):
    tag = child.tag.split('}')[-1]
    if tag == 'r':
        te = child.find(qn('w:t'))
        t = (te.text or '') if te is not None else ''
        print(f"  [{i}] <r>: {repr(t)}")
    elif tag == 'hyperlink':
        for j, sub in enumerate(child):
            st = sub.tag.split('}')[-1]
            if st == 'r':
                te = sub.find(qn('w:t'))
                t = (te.text or '') if te is not None else ''
                print(f"  [{i}] <hyperlink>/<r>[{j}]: {repr(t)}")
    else:
        print(f"  [{i}] <{tag}>")
