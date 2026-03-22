"""
Fix fragmented Shabuw'ah and Taruw'ah in TOC entries.

Handles cases where text is split across XML elements like:
- ['Shabuw', ''', 'ah'] → ['Shabuw', 'ʿ', 'ah']
- ['Taruw', ''', 'ah'] → ['Taruw', 'ʿ', 'ah']
"""

import sys, os, glob
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
import time

AYIN = chr(0x02BF)  # ʿ
APOSTROPHES = ["'", chr(0x2018), chr(0x2019), chr(0x2032)]  # Various apostrophe types

def fix_fragmented_words(doc):
    """Fix words split across XML elements."""
    fixes = 0
    ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}

    # Get all text elements as a list
    all_text_elements = []
    for element in doc.element.iter():
        if element.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t':
            all_text_elements.append(element)

    # Check for pattern: [Shabuw/Taruw] + [apostrophe] + [ah]
    for i in range(len(all_text_elements) - 2):
        elem1 = all_text_elements[i]
        elem2 = all_text_elements[i + 1]
        elem3 = all_text_elements[i + 2]

        if not (elem1.text and elem2.text and elem3.text):
            continue

        # Check for Shabuw + apostrophe + ah
        if (elem1.text.lower().endswith('shabuw') and
            elem2.text in APOSTROPHES and
            elem3.text.lower().startswith('ah')):

            # Replace the apostrophe with Ayin
            elem2.text = AYIN
            fixes += 1

        # Check for Taruw + apostrophe + ah
        elif (elem1.text.lower().endswith('taruw') and
              elem2.text in APOSTROPHES and
              elem3.text.lower().startswith('ah')):

            # Replace the apostrophe with Ayin
            elem2.text = AYIN
            fixes += 1

    return fixes

def apply_yada_font_to_modifiers(doc):
    """Apply Yada Towrah font to standalone ʿ text elements."""
    from docx.oxml import OxmlElement
    from docx.oxml.ns import qn

    font_fixes = 0

    for element in doc.element.iter():
        if element.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t':
            if element.text == AYIN:
                # Get the parent run element
                run_elem = element.getparent()
                if run_elem.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}r':
                    # Get or create rPr (run properties)
                    rPr = run_elem.find('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}rPr')
                    if rPr is None:
                        rPr = OxmlElement('w:rPr')
                        run_elem.insert(0, rPr)

                    # Set font to Yada Towrah
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
        return {'fixes': 0, 'fonts': 0}

    # Fix fragmented words
    fixes = fix_fragmented_words(doc)

    # Apply Yada Towrah font to the ʿ modifiers
    fonts = apply_yada_font_to_modifiers(doc)

    elapsed = time.time() - start_time

    if fixes > 0 or fonts > 0:
        # Overwrite the file
        try:
            doc.save(docx_path)
            print(f"  ✓ Fixes: {fixes}, Fonts: {fonts} ({elapsed:.1f}s)")
        except Exception as e:
            print(f"  ERROR: {e}")
            return {'fixes': 0, 'fonts': 0}
    else:
        print(f"  - No changes ({elapsed:.1f}s)")

    return {'fixes': fixes, 'fonts': fonts}

# Main
docs_dir = r'C:\users\joe\work\dev\yada\docs'
print("Fixing fragmented Shabuw'ah and Taruw'ah in TOC entries...")
print()

# Get all _updated files
all_files = glob.glob(os.path.join(docs_dir, 'YY-s*_updated.docx'))

print(f"Found {len(all_files)} updated documents\n" + "=" * 80 + "\n")

total_fixes = 0
total_fonts = 0

for path in all_files:
    result = process_document(path)
    total_fixes += result['fixes']
    total_fonts += result['fonts']

print("\n" + "=" * 80)
print("COMPLETE")
print("=" * 80)
print(f"Files processed: {len(all_files)}")
print(f"Total fixes: {total_fixes}")
print(f"Total fonts: {total_fonts}")
