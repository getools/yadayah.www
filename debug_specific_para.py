"""Debug a specific paragraph that wasn't converted."""

import sys
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document

LEFT_SINGLE = chr(0x2018)  # '
ALEPH = chr(0x02BE)  # ʾ

doc = Document(r'C:\users\joe\work\dev\yada\docs\YY-s01v03-An Intro to God-Towrah Mizmowr.docx')

# Check para 130 which still has 'Abram
para = doc.paragraphs[130]
print(f"Para 130 text: {para.text[:150]}...\n")
print(f"Number of runs: {len(para.runs)}\n")

# Show all runs
for i, run in enumerate(para.runs):
    if run.text:
        # Check for apostrophe or target words
        has_left = LEFT_SINGLE in run.text
        has_abram = 'Abram' in run.text or 'Abraham' in run.text
        has_aleph = ALEPH in run.text

        if has_left or has_abram or has_aleph:
            print(f"Run {i}:")
            print(f"  Text: {repr(run.text[:100])}")
            print(f"  Has LEFT SINGLE: {has_left}")
            print(f"  Has Abram/Abraham: {has_abram}")
            print(f"  Has ALEPH: {has_aleph}")
            print()

# Check if it's split across runs
print("\nChecking if apostrophe and name are in different runs:")
for i in range(len(para.runs) - 1):
    run1 = para.runs[i]
    run2 = para.runs[i + 1]

    if run1.text and run2.text:
        if run1.text.endswith(LEFT_SINGLE) and (run2.text.startswith('Abram') or run2.text.startswith('Abraham')):
            print(f"  FOUND SPLIT: Run {i} ends with ' and Run {i+1} starts with Abram/Abraham")
            print(f"    Run {i}: ...{repr(run1.text[-20:])}")
            print(f"    Run {i+1}: {repr(run2.text[:20])}...")
