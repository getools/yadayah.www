import sys
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document

doc = Document(r'C:\users\joe\work\dev\yada\docs\YY-s02v05-Yada Yahowah-Qatsyr-Harvests.docx')

# Check paragraph 65
para = doc.paragraphs[65]
print(f'Paragraph 65: "{para.text}"')
print(f'Ends with ...: {para.text.strip().endswith("...")}')
print(f'Ends with …: {para.text.strip().endswith("…")}')
print(f'Alignment: {para.alignment}')
print()
print('Runs:')
for i, run in enumerate(para.runs):
    print(f'  Run {i}:')
    print(f'    Text: "{run.text}"')
    print(f'    Italic: {run.italic}')
    print(f'    Bold: {run.bold}')
    print(f'    Font: {run.font.name}')
