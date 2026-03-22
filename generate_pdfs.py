"""
Two-phase PDF generation for all YY Word documents.

Phase 1 (PDF): Inside/Outside margins = 0.88", Gutter = 0" -> docs/PDF/
Phase 2 (KDP): Inside/Outside margins = 0.56", Gutter = 0.64" -> docs/KDP/

After both phases, margins are restored to Phase 1 values (0.88"/0").

Usage:
    python generate_pdfs.py [--phase 1|2] [--dry-run] [--file PATTERN]
"""

import os
import sys
import glob
import subprocess
import argparse
from docx import Document
from docx.shared import Inches

DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'
SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
EXPORT_VBS = os.path.join(SCRIPTS_DIR, 'export_pdf_dir.vbs')

# Phase configurations: (left_margin, right_margin, gutter, output_subdir)
PHASES = {
    1: {
        'name': 'PDF',
        'left': Inches(0.88),
        'right': Inches(0.88),
        'gutter': 0,
        'outdir': os.path.join(DOCS_DIR, 'PDF'),
    },
    2: {
        'name': 'KDP',
        'left': Inches(0.56),
        'right': Inches(0.56),
        'gutter': Inches(0.64),
        'outdir': os.path.join(DOCS_DIR, 'KDP'),
    },
}


def get_docx_files(pattern=None):
    """Get sorted list of YY-s*.docx files, optionally filtered by pattern."""
    files = sorted(glob.glob(os.path.join(DOCS_DIR, 'YY-s*.docx')))
    files = [f for f in files if '_backup' not in f and '_updated' not in f]
    if pattern:
        files = [f for f in files if pattern in os.path.basename(f)]
    return files


def set_margins(files, left, right, gutter, phase_name, dry_run=False):
    """Set margins on all documents. Returns count of files modified."""
    modified = 0
    for i, fpath in enumerate(files):
        fname = os.path.basename(fpath)
        doc = Document(fpath)
        changed = False
        for sec in doc.sections:
            if sec.left_margin != left or sec.right_margin != right or sec.gutter != gutter:
                sec.left_margin = left
                sec.right_margin = right
                sec.gutter = gutter
                changed = True
        if changed:
            if not dry_run:
                doc.save(fpath)
            modified += 1
            print(f'    [{i+1}/{len(files)}] {fname}: margins updated')
        else:
            print(f'    [{i+1}/{len(files)}] {fname}: already correct')
    return modified


def export_pdfs(outdir, dry_run=False):
    """Export all YY docs to PDF in the specified directory via VBScript."""
    if dry_run:
        print(f'    [DRY RUN] Would export PDFs to: {outdir}')
        return True

    os.makedirs(outdir, exist_ok=True)
    print(f'    Running: cscript {EXPORT_VBS} "{outdir}"')
    result = subprocess.run(
        ['cscript', '//NoLogo', EXPORT_VBS, outdir],
        capture_output=True, text=True, timeout=7200
    )
    print(result.stdout)
    if result.stderr:
        print(result.stderr, file=sys.stderr)
    return result.returncode == 0


def run_phase(phase_num, files, dry_run=False):
    """Run a single phase: set margins then export PDFs."""
    cfg = PHASES[phase_num]
    name = cfg['name']
    left_in = cfg['left'] / 914400
    right_in = cfg['right'] / 914400
    gutter_in = cfg['gutter'] / 914400 if cfg['gutter'] else 0

    print(f'\n{"="*60}')
    print(f'Phase {phase_num}: {name}')
    print(f'  Margins: Inside={left_in:.2f}", Outside={right_in:.2f}", Gutter={gutter_in:.2f}"')
    print(f'  Output:  {cfg["outdir"]}')
    print(f'{"="*60}')

    print(f'\n  Setting margins on {len(files)} documents...')
    modified = set_margins(files, cfg['left'], cfg['right'], cfg['gutter'], name, dry_run)
    print(f'  {modified} files updated')

    print(f'\n  Exporting PDFs to {cfg["outdir"]}...')
    success = export_pdfs(cfg['outdir'], dry_run)
    if not success:
        print(f'  WARNING: PDF export returned errors')
    return success


def main():
    parser = argparse.ArgumentParser(description='Two-phase PDF generation for YY documents')
    parser.add_argument('--phase', type=int, choices=[1, 2], help='Run only phase 1 (PDF) or 2 (KDP)')
    parser.add_argument('--dry-run', action='store_true', help='Show what would be done without making changes')
    parser.add_argument('--file', type=str, help='Only process files matching this pattern')
    parser.add_argument('--no-restore', action='store_true', help='Skip restoring Phase 1 margins after Phase 2')
    args = parser.parse_args()

    files = get_docx_files(args.file)
    if not files:
        print('No matching .docx files found.')
        sys.exit(1)

    print(f'=== YY PDF Generator ===')
    print(f'Found {len(files)} documents')
    if args.dry_run:
        print('[DRY RUN MODE]')

    phases_to_run = [args.phase] if args.phase else [1, 2]

    for phase_num in phases_to_run:
        run_phase(phase_num, files, args.dry_run)

    # Restore margins to Phase 1 values if we ran Phase 2
    if 2 in phases_to_run and not args.no_restore:
        cfg1 = PHASES[1]
        print(f'\n{"="*60}')
        print(f'Restoring margins to Phase 1 values (0.88"/0")...')
        print(f'{"="*60}')
        set_margins(files, cfg1['left'], cfg1['right'], cfg1['gutter'], 'restore', args.dry_run)
        print('  Margins restored.')

    print(f'\nAll done.')


if __name__ == '__main__':
    main()
