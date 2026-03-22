"""Final verification: check all TOC entries for remaining Hebrew transliteration quotes."""
from docx import Document
from docx.oxml.ns import qn
from pathlib import Path

DOCS_DIR = Path(r'C:\Users\Joe\Work\dev\yada\docs')
QUOTE_CHARS = set("'\u2018\u2019`\u00B4\u02BC")

ENGLISH_SKIP_WORDS = {
    "allah's", "don't", "dowd's", "fool's", "god's", "heaven's",
    "islam's", "i'm", "lord's", "paul's", "satan's", "who's",
    "yahowah's", "yah's", "'bout", "noah's", "i", "don",
}

def get_all_runs(para):
    runs = []
    for child in para._element:
        tag = child.tag.split('}')[-1] if '}' in child.tag else child.tag
        if tag == 'r':
            runs.append(child)
        elif tag == 'hyperlink':
            for r in child.findall(qn('w:r')):
                runs.append(r)
    return runs

def get_run_text(r):
    return ''.join(t.text for t in r.findall(qn('w:t')) if t.text)

files = sorted(DOCS_DIR.glob('YY*.docx'))
files = [f for f in files if '_backup' not in f.name and '_updated' not in f.name
         and '_halfring' not in f.name]

hebrew_remaining = 0
english_remaining = 0

for fp in files:
    doc = Document(str(fp))
    for i, para in enumerate(doc.paragraphs[:80]):
        has_field = bool(para._element.findall('.//' + qn('w:instrText')))
        style_name = para.style.name if para.style else ''
        is_toc = has_field or 'toc' in style_name.lower()
        if not is_toc:
            continue

        all_runs = get_all_runs(para)
        text = ''.join(get_run_text(r) for r in all_runs)

        # Check for remaining quotes
        words_with_quotes = []
        for w in text.split():
            clean = w.strip('\t0123456789 ~')
            if clean and any(ch in QUOTE_CHARS for ch in clean):
                words_with_quotes.append(clean)

        if words_with_quotes:
            # Classify as English or Hebrew
            is_eng = all(
                any(clean.lower().startswith(e.rstrip("'s")) or clean.lower() == e
                    for e in ENGLISH_SKIP_WORDS)
                or clean.lower().endswith("'s") and "'" not in clean[:-2]
                or clean.lower() in ("'bout", "don't", "i'm")
                for clean in words_with_quotes
            )
            if is_eng:
                english_remaining += 1
            else:
                hebrew_remaining += 1
                print(f'  HEBREW: {fp.name} para[{i}]: {words_with_quotes}')
                print(f'    Full: "{text[:80]}"')

print(f'\nHebrew transliteration quotes remaining in TOC: {hebrew_remaining}')
print(f'English possessive/contraction quotes in TOC: {english_remaining} (correct)')
