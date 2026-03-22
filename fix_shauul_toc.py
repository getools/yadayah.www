"""Fix Shaʿuwlʿs in TOC hyperlink runs back to Shaʿuwl's."""
import re
from pathlib import Path
from docx import Document
from docx.oxml.ns import qn

DOCS_DIR = Path(r'C:\Users\Joe\Work\dev\yada\docs')

def get_all_runs(para):
    runs = []
    for child in para._element:
        tag = child.tag.split('}')[-1] if '}' in child.tag else child.tag
        if tag == 'r':
            runs.append(child)
        elif tag == 'hyperlink':
            for r in child.findall(qn('w:r')):
                runs.append(r)
    return runs

def get_run_text(r):
    text = ''
    for t in r.findall(qn('w:t')):
        if t.text:
            text += t.text
    return text

def set_run_text(r, new_text):
    t_elems = r.findall(qn('w:t'))
    if t_elems:
        t_elems[0].text = new_text
        t_elems[0].set(qn('xml:space'), 'preserve')

files = sorted(DOCS_DIR.glob('YY*.docx'))
files = [f for f in files if '_backup' not in f.name and '_updated' not in f.name
         and '_halfring' not in f.name]

total = 0
for fp in files:
    doc = Document(str(fp))
    changed = False
    count = 0

    for para in doc.paragraphs:
        for run_elem in get_all_runs(para):
            text = get_run_text(run_elem)
            if '\u02BFuwl\u02BFs' in text:
                new_text = text.replace('\u02BFuwl\u02BFs', '\u02BFuwl\u2019s')
                set_run_text(run_elem, new_text)
                count += new_text != text
                changed = True

    if changed:
        doc.save(str(fp))
        if count:
            print(f'  {fp.name}: fixed {count}')
            total += count

print(f'\nFixed {total} instances in TOC hyperlinks')
