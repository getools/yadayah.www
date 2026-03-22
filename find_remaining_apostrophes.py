"""Find remaining unconverted apostrophe instances."""

import sys, os, glob
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document

LEFT_SINGLE = chr(0x2018)  # '
RIGHT_SINGLE = chr(0x2019)  # '
REGULAR = chr(0x0027)  # '

# Patterns to search for
PATTERNS = [
    'Abram',
    'Abraham',
    'Amnown',
    'Amown',
    'Abshalowm',
]

docs_dir = r'C:\users\joe\work\dev\yada\docs'
all_files = glob.glob(os.path.join(docs_dir, 'YY*.docx'))
docx_files = []
for f in all_files:
    name = os.path.basename(f).lower()
    if any(x in name for x in ['_updated', '_final', '_fmt', '_formatted', '_fixed', '_test', '_reverted', '_tagline', '_debug', '_backup']):
        continue
    docx_files.append(f)

print(f"Searching {len(docx_files)} documents for unconverted apostrophes...\n")

total_found = 0

for doc_path in sorted(docx_files)[:5]:  # Check first 5 docs for now
    doc = Document(doc_path)
    doc_name = os.path.basename(doc_path)
    found_in_doc = 0

    for i, para in enumerate(doc.paragraphs):
        text = para.text

        for pattern in PATTERNS:
            # Check for apostrophe + pattern
            for apos_char, apos_name in [(LEFT_SINGLE, 'LEFT'), (RIGHT_SINGLE, 'RIGHT'), (REGULAR, 'REGULAR')]:
                search_pattern = f"{apos_char}{pattern}"
                if search_pattern in text:
                    # Find the context
                    idx = text.index(search_pattern)
                    context = text[max(0, idx-20):min(len(text), idx+len(search_pattern)+20)]

                    print(f"{doc_name} - Para {i}")
                    print(f"  Pattern: {apos_name} + {pattern}")
                    print(f"  Context: ...{context}...")
                    print()
                    found_in_doc += 1
                    total_found += 1

    if found_in_doc > 0:
        print(f"  Subtotal for {doc_name}: {found_in_doc}\n")

print(f"\nTotal unconverted instances found: {total_found}")
