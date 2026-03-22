"""
DEBUG version: Replace ALL apostrophe variants in Hebrew transliterations.
"""

import sys, os
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document

ALEPH = chr(0x02BE)  # ʾ
AYIN = chr(0x02BF)   # ʿ
LEFT_SINGLE = chr(0x2018)  # '

# Test the specific patterns
TEST_PATTERNS = [
    (f"{LEFT_SINGLE}Adam", "ʾAdam"),
    (f"{LEFT_SINGLE}Abraham", "ʾAbraham"),
]

doc_path = r'C:\users\joe\work\dev\yada\docs\YY-s04v01-Coming Home-Qowl-A Voice.docx'
print(f"Testing: {os.path.basename(doc_path)}\n")

doc = Document(doc_path)

found_count = 0
for i, para in enumerate(doc.paragraphs):
    for run in para.runs:
        if not run.text:
            continue

        # Check if this run contains our target patterns
        if LEFT_SINGLE in run.text and ('Abraham' in run.text or 'Adam' in run.text):
            print(f"Para {i}, Run text: {repr(run.text)}")

            # Try matching
            for old_pattern, new_pattern in TEST_PATTERNS:
                if old_pattern in run.text:
                    print(f"  MATCH: {repr(old_pattern)} found!")
                    print(f"  Would replace with: {repr(new_pattern)}")
                    found_count += 1

print(f"\nTotal matches found: {found_count}")
