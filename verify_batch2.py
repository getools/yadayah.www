"""Verify that all batch 2 patterns have been converted."""

import sys, os, glob
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document

APOSTROPHE_VARIANTS = [
    chr(0x0027),  # Regular apostrophe
    chr(0x2019),  # Right single quote
    chr(0x2018),  # Left single quote
    chr(0x0060),  # Backtick
    chr(0x00B4),  # Acute accent
    chr(0x02BC),  # Modifier letter apostrophe
]

# All patterns to check (base patterns with regular apostrophe)
CHECK_PATTERNS = [
    # New batch 2 patterns
    "'eth", "'Amowts", "'amowts", "showa'", "'Uzyahuw", "'uzyahuw",
    "'Achaz", "'achaz", "'Amorah", "'amorah", "'Uwryah", "'uwryah",
    "'Ashur", "'ashur", "'El", "'Ephraym", "'enowsh", "'Adony", "'adony",
    "'iysh", "'iwer", "'Adonai", "'adonai", "'adamah",
    "L'Chayim", "Mal'ak", "Mal'aky", "Phar'oah",
    "Sha'a", "sha'a", "Yahowyada'", "Yasha'yah", "Yirma'yah",
    "luwle'", "mala'", "ma'alal", "ma'owz", "me'od",
    "n-'anah-ythy", "naba'", "naby'", "pito'm", "qara'",
    "raga'", "ra'a'", "rea'", "ro'eh", "sha'ar", "shama'",
    "sha'shuwa'ym", "t-naba'", "t-'anah", "ta'abah", "tsama'",
    "tse'etsa'ym", "ya'ats", "yow'ets",
    "'Ammi", "'Arar", "'Asherah", "'achazath", "'anah", "'anaph",
    "'aqab", "'asah", "'ashaq", "'orach", "'osheq", "'owlam",
    # Old patterns to double-check
    "'Abraham", "'Adam", "'Abram", "'Abshalowm", "'Amnown",
]

docs_dir = r'C:\users\joe\work\dev\yada\docs'
all_files = glob.glob(os.path.join(docs_dir, 'YY*.docx'))
docx_files = [f for f in all_files
              if not any(x in os.path.basename(f).lower()
                         for x in ['_updated', '_final', '_fmt', '_formatted',
                                   '_fixed', '_test', '_reverted', '_tagline',
                                   '_debug', '_backup'])]

print(f"Checking {len(docx_files)} documents for remaining unconverted patterns...\n")

total_remaining = 0

for doc_path in sorted(docx_files):
    doc = Document(doc_path)
    doc_name = os.path.basename(doc_path)
    found_in_doc = []

    for i, para in enumerate(doc.paragraphs):
        text = para.text
        for base_pattern in CHECK_PATTERNS:
            for apos in APOSTROPHE_VARIANTS:
                search = base_pattern.replace("'", apos)
                if search in text:
                    pos = text.index(search)
                    ctx = text[max(0, pos-15):min(len(text), pos+len(search)+15)]
                    found_in_doc.append((i, base_pattern, ctx))

    if found_in_doc:
        print(f"{doc_name}: {len(found_in_doc)} remaining")
        for para_idx, pattern, context in found_in_doc[:5]:  # Show first 5
            print(f"  Para {para_idx}: {pattern} -> ...{context}...")
        if len(found_in_doc) > 5:
            print(f"  ... and {len(found_in_doc) - 5} more")
        total_remaining += len(found_in_doc)

if total_remaining == 0:
    print("ALL PATTERNS CONVERTED SUCCESSFULLY!")
else:
    print(f"\nTotal remaining unconverted: {total_remaining}")
