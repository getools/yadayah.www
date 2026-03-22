"""
Find cite references with curly apostrophes in the Word documents.
"""

import sys
import os
import glob

sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
import re

CURLY_APOSTROPHE = chr(0x2019)  # '
AYIN = chr(0x02BF)  # ʿ

docs_dir = r'C:\users\joe\work\dev\yada\docs'
pattern = os.path.join(docs_dir, 'YY*.docx')
all_files = glob.glob(pattern)

# Filter out temp and backup files
docx_files = []
for f in all_files:
    name = os.path.basename(f)
    if name.startswith('~$'):
        continue
    name_lower = name.lower()
    if any(x in name_lower for x in ['_backup', '_updated', '_final', '_test']):
        continue
    docx_files.append(f)

print("Searching for Samuel references with curly apostrophes or AYIN modifiers...\n")

# Pattern to find citations in parentheses
cite_pattern = re.compile(r'\(([^)]+)\)')

results = {}

for doc_path in sorted(docx_files):
    doc_name = os.path.basename(doc_path)

    try:
        doc = Document(doc_path)
    except:
        continue

    found_cites = set()

    # Search all paragraphs
    for para in doc.paragraphs:
        text = para.text

        # Find citations
        for match in cite_pattern.finditer(text):
            cite_text = match.group(1)

            # Check if it contains Shamuw with either curly apostrophe or AYIN
            if 'Shamuw' in cite_text:
                if CURLY_APOSTROPHE in cite_text or AYIN in cite_text:
                    found_cites.add(cite_text)

            # Also check for other problematic books
            if any(x in cite_text for x in ['Dany', 'Yachezq', 'Yow']):
                if CURLY_APOSTROPHE in cite_text or AYIN in cite_text:
                    found_cites.add(cite_text)

    if found_cites:
        results[doc_name] = sorted(found_cites)

# Print results
for doc_name, cites in results.items():
    print(f"\n{doc_name}:")
    for cite in cites:
        # Show the actual characters
        has_curly = CURLY_APOSTROPHE in cite
        has_ayin = AYIN in cite
        markers = []
        if has_curly:
            markers.append("curly apostrophe '")
        if has_ayin:
            markers.append("AYIN ʿ")

        print(f"  {cite} [{', '.join(markers)}]")

print(f"\n\nSummary:")
print(f"Documents with curly apostrophe/AYIN cites: {len(results)}")
print(f"Total unique cite variants: {sum(len(cites) for cites in results.values())}")
