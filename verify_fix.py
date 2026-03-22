import sys
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
from docx.shared import Pt

doc = Document(r'C:\users\joe\work\dev\yada\docs\YY-s02v05-Yada Yahowah-Qatsyr-Harvests.docx')

# Find the paragraph mentioned
target_text = 'The fourth Miqra'
for i, para in enumerate(doc.paragraphs):
    if target_text in para.text:
        print(f'Paragraph {i}: "{para.text[:80]}..."')
        print(f'  Alignment: {para.alignment}')
        indent = para.paragraph_format.first_line_indent
        if indent:
            print(f'  First-line indent: {indent.pt}pt ({indent.inches}" inches)')
        else:
            print(f'  First-line indent: None')

        # Check if it's justified and indented
        from docx.enum.text import WD_ALIGN_PARAGRAPH
        is_justified = para.alignment == WD_ALIGN_PARAGRAPH.JUSTIFY
        is_indented = indent and indent.pt >= 36

        print(f'  ✓ Justified: {is_justified}')
        print(f'  ✓ Indented (36pt): {is_indented}')

        if is_justified and is_indented:
            print('\n✓ FIX SUCCESSFUL - Paragraph is now justified and indented!')
        else:
            print('\n✗ FIX INCOMPLETE - Paragraph still needs adjustment')

        break
