"""
Cleanup script: Remove old apostrophes that appear immediately before ʿ modifiers.

This handles cases where text is split across XML elements like:
- "Shabuw" + "'" + "ʿ" + "ah" → should be "Shabuw" + "ʿ" + "ah"
"""

import sys, os, glob
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
import time

AYIN = chr(0x02BF)  # ʿ
RIGHT_CURLY = chr(0x2019)  # '

def cleanup_duplicate_apostrophes(doc):
    """Remove apostrophes that appear right before ʿ modifiers."""
    cleanups = 0
    ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}

    # Get all text elements as a list so we can look ahead
    all_text_elements = []
    for element in doc.element.iter():
        if element.tag == '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t':
            all_text_elements.append(element)

    # Check each element and look ahead to the next
    for i in range(len(all_text_elements) - 1):
        current = all_text_elements[i]
        next_elem = all_text_elements[i + 1]

        if current.text and next_elem.text:
            # If current element ends with apostrophe and next starts with ʿ
            if current.text == RIGHT_CURLY and next_elem.text.startswith(AYIN):
                # Remove the apostrophe element entirely
                current.text = ""
                cleanups += 1
            elif current.text.endswith(RIGHT_CURLY) and next_elem.text.startswith(AYIN):
                # Remove just the trailing apostrophe
                current.text = current.text[:-1]
                cleanups += 1

    return cleanups

def process_document(docx_path):
    """Process a single document."""
    print(f"Processing: {os.path.basename(docx_path)}")
    start_time = time.time()

    try:
        doc = Document(docx_path)
    except Exception as e:
        print(f"  ERROR: {e}")
        return 0

    cleanups = cleanup_duplicate_apostrophes(doc)

    elapsed = time.time() - start_time

    if cleanups > 0:
        # Overwrite the file (these are already _updated files)
        try:
            doc.save(docx_path)
            print(f"  ✓ Cleaned {cleanups} duplicate apostrophes ({elapsed:.1f}s)")
        except Exception as e:
            print(f"  ERROR: {e}")
            return 0
    else:
        print(f"  - No cleanups needed ({elapsed:.1f}s)")

    return cleanups

# Main
docs_dir = r'C:\users\joe\work\dev\yada\docs'
print("Cleaning up duplicate apostrophes before ʿ modifiers...")
print()

# Get all _updated files
all_files = glob.glob(os.path.join(docs_dir, 'YY-s*_updated.docx'))

print(f"Found {len(all_files)} updated documents\n" + "=" * 80 + "\n")

total_cleanups = 0

for path in all_files:
    total_cleanups += process_document(path)

print("\n" + "=" * 80)
print("COMPLETE")
print("=" * 80)
print(f"Files processed: {len(all_files)}")
print(f"Total cleanups: {total_cleanups}")
