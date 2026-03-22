"""Fix margins on all YY Word docs: inside/outside 0.88", gutter 0"."""
from docx import Document
from docx.shared import Emu
import os, glob

DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'
TARGET_MARGIN = 804545  # 0.88 inches in EMU


def main():
    files = sorted(glob.glob(os.path.join(DOCS_DIR, 'YY-*.docx')))
    files = [f for f in files if '_backup' not in f and '_updated' not in f]
    print(f'Found {len(files)} documents')

    total_fixed = 0
    for i, fpath in enumerate(files):
        doc = Document(fpath)
        fname = os.path.basename(fpath)
        fixed = 0
        for sec in doc.sections:
            changed = False
            if sec.left_margin != TARGET_MARGIN:
                sec.left_margin = TARGET_MARGIN
                changed = True
            if sec.right_margin != TARGET_MARGIN:
                sec.right_margin = TARGET_MARGIN
                changed = True
            if sec.gutter != 0:
                sec.gutter = 0
                changed = True
            if changed:
                fixed += 1
        if fixed:
            doc.save(fpath)
            print(f'  [{i+1}/{len(files)}] {fname}: {fixed} sections fixed')
        else:
            print(f'  [{i+1}/{len(files)}] {fname}: OK')
        total_fixed += fixed

    print(f'\nTotal sections fixed: {total_fixed}')


if __name__ == '__main__':
    main()
