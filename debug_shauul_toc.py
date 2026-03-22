"""Debug: check exact run contents for Shaʿuwl TOC entry."""
from docx import Document
from docx.oxml.ns import qn
from pathlib import Path

DOCS_DIR = Path(r'C:\Users\Joe\Work\dev\yada\docs')
fp = DOCS_DIR / "YY-s02v08-Yada Yahowah-'Azab-Separation.docx"
doc = Document(str(fp))

para = doc.paragraphs[57]
print(f'para.text: "{para.text[:80]}"')
print(f'para.runs count: {len(para.runs)}')

# Check all child elements
for child in para._element:
    tag = child.tag.split('}')[-1] if '}' in child.tag else child.tag
    if tag == 'r':
        t_elems = child.findall(qn('w:t'))
        text = ''.join(t.text for t in t_elems if t.text)
        print(f'  <w:r> text: {repr(text)}')
    elif tag == 'hyperlink':
        for r in child.findall(qn('w:r')):
            t_elems = r.findall(qn('w:t'))
            text = ''.join(t.text for t in t_elems if t.text)
            print(f'  <w:hyperlink>/<w:r> text: {repr(text)}')
    elif tag == 'fldSimple':
        for r in child.findall(qn('w:r')):
            t_elems = r.findall(qn('w:t'))
            text = ''.join(t.text for t in t_elems if t.text)
            print(f'  <w:fldSimple>/<w:r> text: {repr(text)}')
    else:
        print(f'  <{tag}>')
