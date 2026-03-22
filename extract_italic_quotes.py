"""
Find words with italic single quotes in YY .docx files.
Outputs: word, filename, page
"""
import sys
sys.path.insert(0, r'C:\Users\Joe\Work\dev\yada\translations')

from docx import Document
from pathlib import Path
from parse_word_translations import build_footer_page_map

DOCS_DIR = Path(r'C:\Users\Joe\Work\dev\yada\docs')
OUTPUT = Path(r'C:\Users\Joe\Work\dev\yada\translations\italic_quote_words.txt')
SINGLE_QUOTES = {"'", "\u2018", "\u2019"}


def is_italic(run):
    if run.font.italic is True:
        return True
    if run.font.italic is False:
        return False
    # Check character style
    if run.style and hasattr(run.style, 'font') and run.style.font.italic:
        return True
    return False


def extract_from_doc(doc_path):
    page_map, content_start, last_page = build_footer_page_map(doc_path)
    doc = Document(str(doc_path))
    results = []

    for para_idx, para in enumerate(doc.paragraphs):
        page = page_map.get(para_idx, 0)

        # Build character list with italic flag
        chars = []
        for run in para.runs:
            italic = is_italic(run)
            for ch in run.text:
                chars.append((ch, italic))

        if not chars:
            continue

        # Split into words, track italic per char
        words = []
        current = []
        for ch, it in chars:
            if ch in (' ', '\t', '\n', '\r'):
                if current:
                    words.append(current)
                    current = []
            else:
                current.append((ch, it))
        if current:
            words.append(current)

        for word_chars in words:
            has_italic_quote = any(
                ch in SINGLE_QUOTES and it
                for ch, it in word_chars
            )
            if has_italic_quote:
                word_text = ''.join(ch for ch, _ in word_chars)
                results.append((word_text, page))

    return results


def main():
    files = sorted(DOCS_DIR.glob('YY*.docx'))
    files = [f for f in files if '_backup' not in f.name and '_updated' not in f.name]
    print(f'Processing {len(files)} files...', flush=True)

    all_results = []
    for i, doc_path in enumerate(files):
        fname = doc_path.name
        print(f'  [{i+1}/{len(files)}] {fname}', end='', flush=True)
        try:
            results = extract_from_doc(doc_path)
            for word, page in results:
                all_results.append((word, fname, page))
            print(f' -> {len(results)} words', flush=True)
        except Exception as e:
            print(f' ERROR: {e}', flush=True)

    # Deduplicate: unique (word, filename, page) combos
    seen = set()
    unique = []
    for word, fname, page in all_results:
        key = (word, fname, page)
        if key not in seen:
            seen.add(key)
            unique.append(key)

    # Sort by filename, page, word
    unique.sort(key=lambda x: (x[1], x[2], x[0].lower()))

    with open(OUTPUT, 'w', encoding='utf-8') as f:
        f.write(f'{"Word":<40} {"File":<65} {"Page"}\n')
        f.write(f'{"-"*40} {"-"*65} {"-"*6}\n')
        for word, fname, page in unique:
            f.write(f'{word:<40} {fname:<65} {page}\n')

    print(f'\nDone! {len(unique)} unique word/file/page combos from {len(all_results)} total occurrences')
    print(f'Saved to: {OUTPUT}')


if __name__ == '__main__':
    main()
