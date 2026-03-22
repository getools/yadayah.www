"""
Debug version - shows exactly what's being found and replaced.
"""

import sys, os
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
import re

AYIN = chr(0x02BF)

doc_path = r'C:\users\joe\work\dev\yada\docs\YY-s02v05-Yada Yahowah-Qatsyr-Harvests.docx'
print(f"Processing: {os.path.basename(doc_path)}\n")

doc = Document(doc_path)
ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}

found_count = 0
changed_count = 0

for element in doc.element.iter():
    if element.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t':
        if element.text and ('shabuw' in element.text.lower() or 'taruw' in element.text.lower()):
            original = element.text
            found_count += 1

            # Show what we found
            print(f"FOUND #{found_count}: {repr(original)}")

            # Check if it matches our pattern
            shabuw_match = re.search(r"Shabuw['\u2018\u2019\u2032]ah", original, re.IGNORECASE)
            taruw_match = re.search(r"Taruw['\u2018\u2019\u2032]ah", original, re.IGNORECASE)

            if shabuw_match:
                print(f"  → Shabuw match found: {repr(shabuw_match.group())}")
            if taruw_match:
                print(f"  → Taruw match found: {repr(taruw_match.group())}")

            # Try replacement
            text = re.sub(r"Shabuw['\u2018\u2019\u2032]ah",
                         lambda m: f'Shabuw{AYIN}ah' if m.group()[0].isupper() else f'shabuw{AYIN}ah',
                         original, flags=re.IGNORECASE)

            text = re.sub(r"Taruw['\u2018\u2019\u2032]ah",
                         lambda m: f'Taruw{AYIN}ah' if m.group()[0].isupper() else f'taruw{AYIN}ah',
                         text, flags=re.IGNORECASE)

            if text != original:
                changed_count += 1
                print(f"  ✓ CHANGED TO: {repr(text)}")
                element.text = text
            else:
                print(f"  ✗ NO CHANGE")

            print()

print("=" * 80)
print(f"Found {found_count} text elements with Shabuw/Taruw")
print(f"Changed {changed_count} text elements")
print()

if changed_count > 0:
    output_path = r'C:\users\joe\work\dev\yada\docs\YY-s02v05-Yada Yahowah-Qatsyr-Harvests_debug.docx'
    doc.save(output_path)
    print(f"Saved to: {output_path}")
else:
    print("No changes to save")
