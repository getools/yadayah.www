"""
Directly fix TOC entries by modifying XML text nodes.
"""

import sys, os, glob
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
import time
import re

AYIN = chr(0x02BF)

def fix_toc_and_all_text(docx_path):
    print(f"\\nProcessing: {os.path.basename(docx_path)}")
    start_time = time.time()

    try:
        doc = Document(docx_path)
    except Exception as e:
        print(f"  ERROR: {e}")
        return 0

    ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
    replacements = 0

    # Process ALL text elements in the entire document
    for element in doc.element.iter():
        # Find all w:t (text) elements
        if element.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t':
            if element.text:
                original = element.text

                # Replace Shabuw'ah with Shabuwʿah
                text = re.sub(r"Shabuw['\\u2018\\u2019\\u2032]ah",
                             lambda m: f'Shabuw{AYIN}ah' if m.group()[0].isupper() else f'shabuw{AYIN}ah',
                             original, flags=re.IGNORECASE)

                # Replace Taruw'ah with Taruwʿah
                text = re.sub(r"Taruw['\\u2018\\u2019\\u2032]ah",
                             lambda m: f'Taruw{AYIN}ah' if m.group()[0].isupper() else f'taruw{AYIN}ah',
                             text, flags=re.IGNORECASE)

                if text != original:
                    element.text = text
                    replacements += 1

    elapsed = time.time() - start_time

    if replacements > 0:
        base, ext = os.path.splitext(docx_path)
        output_path = f"{base}_updated{ext}"
        doc.save(output_path)
        print(f"  ✓ {replacements} text nodes updated ({elapsed:.1f}s)")
    else:
        print(f"  - No changes ({elapsed:.1f}s)")

    return replacements

# Main
docs_dir = r'C:\\users\\joe\\work\\dev\\yada\\docs'
print("Fixing Shabuw'ah and Taruw'ah in ALL text nodes (including TOC)...")
print()

all_files = glob.glob(os.path.join(docs_dir, 'YY-s*.docx'))
docx_files = [f for f in all_files if not any(x in os.path.basename(f).lower()
    for x in ['_updated', '_final', '_fmt', '_formatted', '_fixed', '_test', '_reverted', '_tagline'])]

print(f"Found {len(docx_files)} documents\\n" + "=" * 80)

total_repl = 0
for path in docx_files:
    total_repl += fix_toc_and_all_text(path)

print("\\n" + "=" * 80)
print(f"Total text nodes updated: {total_repl}")
