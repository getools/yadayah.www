"""
Create _debug versions for all YY files using the working debug approach.
Phase 1: Replace text via direct XML iteration
Phase 2: Apply Yada Towrah font to all ʿ modifiers
"""

import sys, os, glob
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
import time
import re

AYIN = chr(0x02BF)  # ʿ

def phase1_replace_text(doc):
    """Replace apostrophes with Ayin in ALL text elements."""
    replacements = 0

    for element in doc.element.iter():
        if element.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t':
            if element.text:
                original = element.text

                # Replace Shabuw'ah → Shabuwʿah
                text = re.sub(r"Shabuw['\u2018\u2019\u2032]ah",
                             lambda m: f'Shabuw{AYIN}ah' if m.group()[0].isupper() else f'shabuw{AYIN}ah',
                             original, flags=re.IGNORECASE)

                # Replace Taruw'ah → Taruwʿah
                text = re.sub(r"Taruw['\u2018\u2019\u2032]ah",
                             lambda m: f'Taruw{AYIN}ah' if m.group()[0].isupper() else f'taruw{AYIN}ah',
                             text, flags=re.IGNORECASE)

                if text != original:
                    element.text = text
                    replacements += 1

    return replacements

def phase2_apply_fonts(doc):
    """Apply Yada Towrah font to standalone ʿ elements."""
    font_fixes = 0

    for element in doc.element.iter():
        if element.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t':
            if element.text and AYIN in element.text:
                # Get parent run element
                run_elem = element.getparent()
                if run_elem.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}r':
                    # Get or create rPr
                    rPr = run_elem.find('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}rPr')
                    if rPr is None:
                        rPr = OxmlElement('w:rPr')
                        run_elem.insert(0, rPr)

                    # Set font
                    rFonts = rPr.find('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}rFonts')
                    if rFonts is None:
                        rFonts = OxmlElement('w:rFonts')
                        rPr.append(rFonts)

                    rFonts.set(qn('w:ascii'), 'Yada Towrah')
                    rFonts.set(qn('w:hAnsi'), 'Yada Towrah')
                    font_fixes += 1

    return font_fixes

def process_document(docx_path):
    """Process a single document."""
    print(f"Processing: {os.path.basename(docx_path)}")
    start_time = time.time()

    try:
        doc = Document(docx_path)
    except Exception as e:
        print(f"  ERROR: {e}")
        return {'replacements': 0, 'fonts': 0}

    # Phase 1: Replace text
    replacements = phase1_replace_text(doc)

    # Phase 2: Apply fonts
    fonts = phase2_apply_fonts(doc)

    elapsed = time.time() - start_time

    # Save as _debug.docx
    base, ext = os.path.splitext(docx_path)
    output_path = f"{base}_debug{ext}"

    try:
        doc.save(output_path)
        print(f"  ✓ Text: {replacements}, Fonts: {fonts} ({elapsed:.1f}s)")
    except Exception as e:
        print(f"  ERROR: {e}")
        return {'replacements': 0, 'fonts': 0}

    return {'replacements': replacements, 'fonts': fonts}

# Main
docs_dir = r'C:\users\joe\work\dev\yada\docs'
print("Creating _debug versions with text replacement and font fixes...")
print()

# Get all YY files (excluding generated files)
all_files = glob.glob(os.path.join(docs_dir, 'YY-s*.docx'))
docx_files = []
for f in all_files:
    name = os.path.basename(f).lower()
    if any(x in name for x in ['_updated', '_final', '_fmt', '_formatted', '_fixed', '_test', '_reverted', '_tagline', '_debug', '_backup']):
        continue
    docx_files.append(f)

print(f"Found {len(docx_files)} documents\n" + "=" * 80 + "\n")

total_repl = 0
total_fonts = 0

for path in docx_files:
    result = process_document(path)
    total_repl += result['replacements']
    total_fonts += result['fonts']

print("\n" + "=" * 80)
print("COMPLETE")
print("=" * 80)
print(f"Files processed: {len(docx_files)}")
print(f"Text replacements: {total_repl}")
print(f"Font fixes: {total_fonts}")
print(f"\nDebug files: {docs_dir}\\*_debug.docx")
