"""Check current state of specific run."""

import sys
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document

LEFT_SINGLE = chr(0x2018)  # '
ALEPH = chr(0x02BE)  # ʾ
AYIN = chr(0x02BF)   # ʿ

doc = Document(r'C:\users\joe\work\dev\yada\docs\YY-s01v02-An Intro to God-Mitswah-Instructions.docx')

# Check para 990, run 10
para = doc.paragraphs[990]
run = para.runs[10]

print("Para 990, Run 10:")
print(f"Text: {run.text[:200]}...")
print()

# Count occurrences
left_count = run.text.count(LEFT_SINGLE)
aleph_count = run.text.count(ALEPH)
ayin_count = run.text.count(AYIN)

print(f"LEFT SINGLE quotes: {left_count}")
print(f"ALEPH modifiers: {aleph_count}")
print(f"AYIN modifiers: {ayin_count}")

# Find specific patterns
if "'Eden" in run.text:
    print("\n✗ 'Eden still has LEFT SINGLE quote (not converted)")
if "ʿEden" in run.text:
    print("\n✓ ʿEden has AYIN modifier (converted!)")

if "'Abraham" in run.text:
    print("✗ 'Abraham still has LEFT SINGLE quote (not converted)")
if "ʾAbraham" in run.text:
    print("✓ ʾAbraham has ALEPH modifier (converted!)")
