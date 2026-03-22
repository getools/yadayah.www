"""Find italic words containing single-quote/apostrophe characters
in YY Word documents (excluding s07 series).
Saves: word, filename, page number."""

import os
import re
from docx import Document
from docx.oxml.ns import qn

DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'
OUTPUT = r'C:\Users\Joe\Work\dev\yada\translations\italic_quote_words.txt'

QUOTE_CHARS = frozenset("'\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b")

# Word-like tokens (letters + quote variants + hyphens)
WORD_RE = re.compile(
    r"[a-zA-Z'\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b\u02be\u02bf\-]+"
)

# Contraction endings: 's, n't, 'm, 'll, 've, 're, 'd
CONTRACTION_RE = re.compile(
    r"['\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b]"
    r"(?:s|t|m|ll|ve|re|d)$",
    re.IGNORECASE
)
# Plural possessive: ends with s + quote
PLURAL_POSS_RE = re.compile(
    r"s['\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b]$"
)


def is_only_possessive_or_contraction(word):
    """Return True if ALL quote chars in word are part of possessives/contractions."""
    stripped = word
    # Strip one contraction ending (e.g., 's, n't, 'm, 'll, 've, 're, 'd)
    stripped = CONTRACTION_RE.sub('', stripped)
    # Strip plural possessive ending (e.g., s')
    stripped = PLURAL_POSS_RE.sub('s', stripped)
    # If no quote characters remain, it was only possessive/contraction
    return not any(ch in stripped for ch in QUOTE_CHARS)


# Hebrew transliteration indicators (patterns common in YY transliterations)
HEBREW_INDICATORS = re.compile(
    r'(?:sh|ch|ts|ph|th|ow|uw|ey|ah|yh|ym|wn|wr|'
    r'\u02be|\u02bf|'                            # half-ring modifiers
    r'[qQ]|'                                     # qoph
    r'[wW])',                                    # waw as vowel
    re.IGNORECASE
)

# Known non-Hebrew terms (Greek, Latin, Arabic, etc.)
NON_HEBREW_TERMS = {
    'subintroductos', 'subintroierunt', 'stenochoria', 'anomos',
    'iesous', 'iesou', 'nomos', 'logos', 'etat', 'etre',
}

# English words that appear after an opening quotation mark (not Aleph/Ayin)
ENGLISH_AT_BOUNDARY = {
    'truth', 'therefore', 'encyclop', 'in', 'h',
}


def should_exclude_non_hebrew(word):
    """Return True if word is not a Hebrew transliteration."""
    # Get alphabetic core (letters + half-rings only)
    core = ''.join(ch for ch in word if ch.isalpha() or ch in '\u02be\u02bf')
    core_lower = core.lower()

    # 1. Isolated quotes or single-letter fragments (a', e', 'H, d')
    if len(core) <= 1:
        return True

    # 2. French d' patterns (d'etat, d'etre, d')
    if len(word) >= 2 and word[0].lower() == 'd' and word[1] in QUOTE_CHARS:
        return True

    # 3. English colloquial: -in' (dyin', headin', feelin') or -ie' (die')
    for q in QUOTE_CHARS:
        if word.endswith(f"in{q}") and not HEBREW_INDICATORS.search(core_lower):
            return True
        if word.endswith(f"ie{q}") and not HEBREW_INDICATORS.search(core_lower):
            return True

    # 4. Known Greek/Latin/Arabic terms
    if core_lower in NON_HEBREW_TERMS:
        return True

    # 5. Arabic al- prefix without Hebrew indicators
    if core_lower.startswith('al') and '-' in word and not HEBREW_INDICATORS.search(core_lower):
        return True

    # 6. Song lyric fragments (hyphenated with 'll/'il)
    if '-' in word and ("'ll" in word.lower() or "'il" in word.lower()
                        or "\u2019ll" in word.lower() or "\u2019il" in word.lower()):
        return True

    # 7. Words starting with quote where the rest is a known English word
    if word[0] in QUOTE_CHARS:
        rest = core_lower
        if rest in ENGLISH_AT_BOUNDARY:
            return True

    return False


def is_run_italic(r_elem):
    """Check if a run element has italic formatting."""
    rpr = r_elem.find(qn('w:rPr'))
    if rpr is None:
        return False
    i_elem = rpr.find(qn('w:i'))
    if i_elem is None:
        return False
    val = i_elem.get(qn('w:val'))
    if val is not None and val.lower() in ('0', 'false'):
        return False
    return True


def build_page_map(body):
    """Map each w:p element id to a physical page number using lastRenderedPageBreak."""
    page = 1
    pmap = {}
    for p in body.iter(qn('w:p')):
        # Check if any run in this paragraph starts a new page
        for r in p.iter(qn('w:r')):
            if r.findall(qn('w:lastRenderedPageBreak')):
                page += 1
                break
        pmap[id(p)] = page
    return pmap


def find_italic_quote_words(filepath):
    """Return list of (word, page) for italic words with quote chars."""
    doc = Document(filepath)
    pmap = build_page_map(doc.element.body)
    results = []

    for p in doc.element.body.iter(qn('w:p')):
        page = pmap.get(id(p), 1)

        # Build character list with italic flag (handles cross-run words)
        chars = []  # (char, is_italic)
        for r in p.iter(qn('w:r')):
            italic = is_run_italic(r)
            for t in r.findall(qn('w:t')):
                for ch in (t.text or ''):
                    chars.append((ch, italic))

        if not chars:
            continue

        text = ''.join(ch for ch, _ in chars)

        for m in WORD_RE.finditer(text):
            word = m.group()
            start, end = m.start(), m.end()

            # Must contain a quote character
            if not any(ch in word for ch in QUOTE_CHARS):
                continue

            # At least one character in the word must be italic
            if not any(chars[i][1] for i in range(start, end)):
                continue

            # Skip possessives and contractions
            if is_only_possessive_or_contraction(word):
                continue

            # Skip non-Hebrew words (French, Greek, English at quote boundaries, etc.)
            if should_exclude_non_hebrew(word):
                continue

            results.append((word, page))

    return results


def main():
    docs = sorted([
        os.path.join(DOCS_DIR, f)
        for f in os.listdir(DOCS_DIR)
        if f.startswith('YY-') and f.endswith('.docx')
        and not f.startswith('YY-s07')
        and '_backup' not in f and '_updated' not in f
        and '_fixed' not in f and '_final' not in f
        and not f.startswith('~$')
    ])

    print(f"Scanning {len(docs)} documents ...\n")

    all_results = []
    for fp in docs:
        fname = os.path.basename(fp)
        hits = find_italic_quote_words(fp)
        if hits:
            print(f"  {fname}: {len(hits)} words")
        else:
            print(f"  {fname}: 0")
        for word, page in hits:
            all_results.append((word, fname, page))

    # Write output
    with open(OUTPUT, 'w', encoding='utf-8') as f:
        f.write(f"{'Word':<40} {'File':<70} {'Page':>5}\n")
        f.write('-' * 117 + '\n')
        for word, fname, page in all_results:
            f.write(f"{word:<40} {fname:<70} {page:>5}\n")

    print(f"\nTotal: {len(all_results)} italic words with quotes")
    print(f"Saved to: {OUTPUT}")


if __name__ == '__main__':
    main()
