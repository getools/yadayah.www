"""
Undo paragraph formatting changes - revert justified/indented paragraphs after taglines
back to left-aligned without indent.
"""

import sys, os
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.shared import Pt
import time

def is_tagline_paragraph(para):
    """
    Detect if paragraph is a tagline.
    Taglines are typically centered, bold, or have specific formatting.
    """
    if not para.text.strip():
        return False

    # Check if centered
    if para.alignment == WD_ALIGN_PARAGRAPH.CENTER:
        return True

    # Check if all text is bold
    if para.runs:
        all_bold = all(run.bold for run in para.runs if run.text.strip())
        if all_bold and len(para.text.strip()) < 100:  # Short bold text
            return True

    return False

def undo_paragraph_formatting(doc, stats):
    """Undo formatting on first paragraph after taglines - revert to left-aligned, no indent."""
    paragraphs = doc.paragraphs

    for i in range(len(paragraphs) - 1):
        para = paragraphs[i]
        next_para = paragraphs[i + 1]

        # If current is tagline and next is content paragraph
        if is_tagline_paragraph(para):
            # Check if next paragraph is justified with indent (our previous changes)
            if (next_para.text.strip() and
                next_para.alignment == WD_ALIGN_PARAGRAPH.JUSTIFY):

                # Revert to left-aligned
                next_para.alignment = WD_ALIGN_PARAGRAPH.LEFT

                # Remove first-line indent
                if next_para.paragraph_format.first_line_indent:
                    next_para.paragraph_format.first_line_indent = Pt(0)
                    stats['para_reverts'] += 1

def process_document(docx_path):
    """Process a document to undo paragraph formatting."""
    print(f"\nProcessing: {os.path.basename(docx_path)}")
    start_time = time.time()

    try:
        doc = Document(docx_path)
    except Exception as e:
        print(f"  ERROR: {e}")
        return {'para_reverts': 0}

    stats = {'para_reverts': 0}

    # Undo paragraph formatting
    undo_paragraph_formatting(doc, stats)

    elapsed = time.time() - start_time
    reverts = stats['para_reverts']

    if reverts > 0:
        base, ext = os.path.splitext(docx_path)
        output_path = f"{base}_reverted{ext}"

        try:
            doc.save(output_path)
            print(f"  ✓ Reverted {reverts} paragraphs ({elapsed:.1f}s)")
        except Exception as e:
            print(f"  ERROR: {e}")
            return {'para_reverts': 0}
    else:
        print(f"  - No changes ({elapsed:.1f}s)")

    return {'para_reverts': reverts}

# Main
docs_dir = r'C:\users\joe\work\dev\yada\docs'
print("Undoing paragraph formatting changes...")
print()

docx_files = [
    os.path.join(docs_dir, f)
    for f in os.listdir(docs_dir)
    if f.startswith('YY-') and f.endswith('.docx')
    and '_reverted' not in f and not f.startswith('~$')
]

print(f"Found {len(docx_files)} documents\n" + "=" * 80)

total_updated = 0
total_reverts = 0

for path in docx_files:
    result = process_document(path)
    if result['para_reverts'] > 0:
        total_updated += 1
        total_reverts += result['para_reverts']

print("\n" + "=" * 80)
print("COMPLETE")
print("=" * 80)
print(f"Files processed: {len(docx_files)}")
print(f"Files updated: {total_updated}")
print(f"Paragraphs reverted: {total_reverts}")
print(f"\nReverted files: {docs_dir}/*_reverted.docx")
