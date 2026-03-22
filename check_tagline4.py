import sys
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document

doc = Document(r'C:\users\joe\work\dev\yada\docs\YY-s02v05-Yada Yahowah-Qatsyr-Harvests.docx')

# Check paragraph 65 and surrounding paragraphs
for idx in [65, 67]:
    para = doc.paragraphs[idx]
    print(f'\nParagraph {idx}: "{para.text[:50]}..."')
    print(f'  Style: {para.style.name if para.style else None}')
    print(f'  Alignment: {para.alignment}')

    # Check if style has italic
    if para.style and hasattr(para.style, 'font'):
        print(f'  Style font italic: {para.style.font.italic}')

    print(f'  Runs:')
    for i, run in enumerate(para.runs):
        if run.text.strip():
            # Check actual rendering by looking at XML
            rPr = run._element.find('.//{http://schemas.openxmlformats.org/wordprocessingml/2006/main}rPr')
            has_italic_xml = False
            if rPr is not None:
                i_elem = rPr.find('.//{http://schemas.openxmlformats.org/wordprocessingml/2006/main}i')
                has_italic_xml = i_elem is not None

            print(f'    Run {i}: italic={run.italic}, has_italic_xml={has_italic_xml}, text="{run.text[:30]}"')
