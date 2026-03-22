"""Verify batch 3 patterns are all converted."""
import sys, os, glob
sys.stdout.reconfigure(encoding='utf-8')
from docx import Document
from docx.oxml.ns import qn

APOSTROPHE_VARIANTS = [
    chr(0x0027), chr(0x2019), chr(0x2018),
    chr(0x0060), chr(0x00B4), chr(0x02BC),
]

CHECK_PATTERNS = [
    "'ElYah", "'anachnuw", "L'Chayim",
    "Ba'al", "ba'al", "Hora'ah", "hora'ah",
    "ra'ah", "Ra'ah",
]

docs_dir = r'C:\users\joe\work\dev\yada\docs'
all_files = glob.glob(os.path.join(docs_dir, 'YY*.docx'))
docx_files = [f for f in all_files
              if not any(x in os.path.basename(f).lower()
                         for x in ['_updated', '_final', '_fmt', '_formatted',
                                   '_fixed', '_test', '_reverted', '_tagline',
                                   '_debug', '_backup'])]

print(f"Checking {len(docx_files)} documents...\n")
total = 0

for doc_path in sorted(docx_files):
    doc = Document(doc_path)
    doc_name = os.path.basename(doc_path)

    # Check body paragraphs (includes hyperlink text via para.text)
    for i, para in enumerate(doc.paragraphs):
        text = para.text
        for bp in CHECK_PATTERNS:
            for apos in APOSTROPHE_VARIANTS:
                search = bp.replace("'", apos)
                if search in text:
                    pos = text.index(search)
                    ctx = text[max(0,pos-15):min(len(text),pos+len(search)+15)]
                    print(f"{doc_name} Para {i}: {bp} -> ...{ctx}...")
                    total += 1

    # Also check hyperlink runs directly
    body = doc.element.body
    for r in body.findall('.//' + qn('w:r')):
        t_el = r.find(qn('w:t'))
        if t_el is None:
            continue
        text = t_el.text or ''
        for bp in CHECK_PATTERNS:
            for apos in APOSTROPHE_VARIANTS:
                search = bp.replace("'", apos)
                if search in text:
                    print(f"{doc_name} (hyperlink run): {bp} -> {text[:60]}")
                    total += 1

if total == 0:
    print("ALL BATCH 3 PATTERNS CONVERTED SUCCESSFULLY!")
else:
    print(f"\nRemaining: {total}")
