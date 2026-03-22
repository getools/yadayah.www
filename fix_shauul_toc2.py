"""Fix Shaʿuwlʿs in all paragraphs (including hyperlink runs) using cross-run matching."""
import re
from pathlib import Path
from docx import Document
from docx.oxml.ns import qn

DOCS_DIR = Path(r'C:\Users\Joe\Work\dev\yada\docs')
HALF_RING_AYIN = '\u02BF'
RIGHT_QUOTE = '\u2019'

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

    for para in doc.paragraphs:
        all_runs = get_all_runs(para)
        if not all_runs:
            continue

        # Build text + cmap
        text = ''
        cmap = []
        run_texts = []
        for ri, run in enumerate(all_runs):
            rt = get_run_text(run)
            run_texts.append(rt)
            for ci, ch in enumerate(rt):
                text += ch
                cmap.append((ri, ci))

        # Find Sha + ʿ + uwl + ʿ + s pattern (the second ʿ before 's' is wrong)
        pattern = re.compile(r'(?i)sha\u02BFuwl\u02BFs')
        for m in pattern.finditer(text):
            # Position of the second ʿ: start + 7 (S=0,h=1,a=2,ʿ=3,u=4,w=5,l=6,ʿ=7)
            pos = m.start() + 7
            if pos < len(cmap):
                ri, ci = cmap[pos]
                t = list(run_texts[ri])
                t[ci] = RIGHT_QUOTE
                run_texts[ri] = ''.join(t)
                set_run_text(all_runs[ri], run_texts[ri])

                # Also remove Yada Towrah font from this run if it only has the quote
                if run_texts[ri] == RIGHT_QUOTE:
                    rPr = all_runs[ri].find(qn('w:rPr'))
                    if rPr is not None:
                        rFonts = rPr.find(qn('w:rFonts'))
                        if rFonts is not None:
                            rPr.remove(rFonts)

                total += 1
                changed = True

    if changed:
        doc.save(str(fp))
        print(f'  {fp.name}')

print(f'\nFixed {total} instances')
