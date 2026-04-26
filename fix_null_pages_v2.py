#!/usr/bin/env python3
"""
Fix remaining NULL page numbers - v2 with smarter snippet selection.

Handles texts containing special font characters (#^^#!) that don't match
between python-docx and Word COM by using snippets that avoid those characters.
Also retries s01v01 which timed out in v1.
"""

import os
import re
import sys
import subprocess
import tempfile
import logging
from pathlib import Path
from collections import defaultdict

try:
    import psycopg2
except ImportError:
    print("ERROR: pip install psycopg2-binary")
    sys.exit(1)

logging.basicConfig(level=logging.INFO, format='%(message)s')

DB_CONFIG = {
    'host': 'localhost',
    'port': 5433,
    'dbname': 'yada',
    'user': 'postgres',
    'password': 'yada_password'
}

DOCS_DIR = r"C:\Users\Joe\Work\dev\yada\docs"


def strip_html(html_text):
    """Strip HTML tags to get plain text."""
    text = re.sub(r'<[^>]+>', '', html_text)
    text = text.replace('&amp;', '&').replace('&lt;', '<').replace('&gt;', '>')
    return text.strip()


def get_clean_snippet(plain_text, max_len=120):
    """
    Get a search snippet that avoids problematic characters.

    Strategy:
    1. Find the longest prefix before any '#' character (the #^^#! pattern)
    2. If that's too short (<30 chars), try taking text after the pattern
    3. Fall back to the full text truncated
    """
    # Try text before first '#'
    hash_pos = plain_text.find('#')
    if hash_pos > 30:
        return plain_text[:min(hash_pos, max_len)].strip()

    # Try text after the pattern #^^#!)
    # Find all occurrences of #^^#! and take text after the last one
    pattern_end = 0
    for m in re.finditer(r'#\^\^#!\)?\.?\s*', plain_text):
        pattern_end = m.end()

    if pattern_end > 0 and len(plain_text) - pattern_end > 30:
        after_text = plain_text[pattern_end:pattern_end + max_len].strip()
        if after_text:
            return after_text

    # If '#' is very early or not present, just use the text as-is
    if hash_pos < 0:
        return plain_text[:max_len].strip()

    # Last resort: use text before '#' even if short
    if hash_pos > 10:
        return plain_text[:hash_pos].strip()

    # Really last resort: skip the hash characters and use what follows
    clean = re.sub(r'#\^\^#!', '', plain_text)
    return clean[:max_len].strip()


def get_null_page_translations():
    """Get all translations with NULL page numbers, grouped by book."""
    conn = psycopg2.connect(**DB_CONFIG)
    cur = conn.cursor()
    cur.execute("""
        SELECT translation_id, translation_book, translation_text_word
        FROM yy_cite_translation
        WHERE translation_page IS NULL
        ORDER BY translation_book, translation_id
    """)
    rows = cur.fetchall()
    cur.close()
    conn.close()

    by_book = defaultdict(list)
    for tid, book, text in rows:
        by_book[book].append((tid, text))
    return by_book


def find_doc_path(book_name):
    for f in Path(DOCS_DIR).glob("*.docx"):
        if f.stem == book_name:
            return str(f)
    return None


def get_pages_via_com(doc_path, search_items, timeout=300):
    """Search from beginning for each item, with case-insensitive fallback."""
    if not search_items:
        return {}

    inp = tempfile.NamedTemporaryFile(mode='w', suffix='.txt', delete=False, encoding='utf-16')
    for tid, text in search_items:
        snippet = text.replace('\t', ' ').replace('\r', ' ').replace('\n', ' ').strip()
        if snippet:
            inp.write(f"{tid}\t{snippet}\n")
    inp.close()

    out = tempfile.NamedTemporaryFile(mode='w', suffix='.txt', delete=False, encoding='utf-8')
    out.close()

    vbs_content = f'''
Dim word, fso, inputFile, outputFile

Set fso = CreateObject("Scripting.FileSystemObject")
Set word = CreateObject("Word.Application")
word.Visible = False
word.DisplayAlerts = 0

Set inputFile = fso.OpenTextFile("{inp.name}", 1, False, -1)
Set outputFile = fso.CreateTextFile("{out.name}", True, False)

WScript.StdErr.WriteLine "Opening document..."
Dim doc
Set doc = word.Documents.Open("{doc_path}", , True)
WScript.StdErr.WriteLine "Opened, paragraphs: " & doc.Paragraphs.Count

Dim rng, foundCount, line, tabPos, itemId, searchText, pageNum
foundCount = 0

Do While Not inputFile.AtEndOfStream
    line = inputFile.ReadLine()
    If Len(line) > 0 Then
        tabPos = InStr(line, vbTab)
        If tabPos > 0 Then
            itemId = Left(line, tabPos - 1)
            searchText = Mid(line, tabPos + 1)

            If Len(searchText) > 0 Then
                ' Search from BEGINNING, case-sensitive first
                Set rng = doc.Content.Duplicate
                rng.Find.ClearFormatting
                rng.Find.Text = searchText
                rng.Find.Forward = True
                rng.Find.Wrap = 0
                rng.Find.MatchWildcards = False
                rng.Find.MatchCase = True

                On Error Resume Next
                rng.Find.Execute
                If Err.Number = 0 And rng.Find.Found Then
                    pageNum = rng.Information(1)
                    outputFile.WriteLine itemId & vbTab & pageNum
                    foundCount = foundCount + 1
                Else
                    ' Case-insensitive fallback
                    Err.Clear
                    Set rng = doc.Content.Duplicate
                    rng.Find.ClearFormatting
                    rng.Find.Text = searchText
                    rng.Find.Forward = True
                    rng.Find.Wrap = 0
                    rng.Find.MatchWildcards = False
                    rng.Find.MatchCase = False
                    rng.Find.Execute
                    If Err.Number = 0 And rng.Find.Found Then
                        pageNum = rng.Information(1)
                        outputFile.WriteLine itemId & vbTab & pageNum
                        foundCount = foundCount + 1
                    End If
                End If
                On Error GoTo 0
            End If
        End If
    End If
Loop

doc.Close False
inputFile.Close
outputFile.Close
word.Quit
WScript.StdErr.WriteLine "Done. Found: " & foundCount
'''

    vbs_file = tempfile.NamedTemporaryFile(mode='w', suffix='.vbs', delete=False, encoding='utf-8')
    vbs_file.write(vbs_content)
    vbs_file.close()

    try:
        result = subprocess.run(
            ["cscript", "//NoLogo", vbs_file.name],
            capture_output=True, text=True, timeout=timeout
        )
        if result.stderr:
            for line in result.stderr.strip().split('\n'):
                if line.strip():
                    logging.info(f"  VBS: {line.strip()}")

        page_map = {}
        if os.path.exists(out.name):
            with open(out.name, 'r', encoding='utf-8', errors='replace') as f:
                for line in f:
                    line = line.strip()
                    if not line:
                        continue
                    if line.startswith('\ufeff'):
                        line = line[1:]
                    parts = line.split('\t')
                    if len(parts) >= 2:
                        page_map[int(parts[0])] = int(parts[1])
        return page_map

    except subprocess.TimeoutExpired:
        logging.warning(f"  COM timed out after {timeout}s")
        try:
            subprocess.run(["powershell", "-Command",
                "Stop-Process -Name WINWORD -Force -ErrorAction SilentlyContinue"],
                capture_output=True, timeout=10)
        except:
            pass
        return {}
    except Exception as e:
        logging.warning(f"  COM failed: {e}")
        return {}
    finally:
        for fp in [inp.name, out.name, vbs_file.name]:
            try:
                os.unlink(fp)
            except:
                pass


def update_pages(page_updates):
    if not page_updates:
        return 0
    conn = psycopg2.connect(**DB_CONFIG)
    cur = conn.cursor()
    count = 0
    for tid, page in page_updates.items():
        cur.execute("UPDATE yy_cite_translation SET translation_page = %s WHERE translation_id = %s",
                    (page, tid))
        count += cur.rowcount
    conn.commit()
    cur.close()
    conn.close()
    return count


def main():
    logging.info("=== Fix NULL Page Numbers v2 (smart snippets) ===")

    by_book = get_null_page_translations()
    total = sum(len(v) for v in by_book.values())
    logging.info(f"Found {total} translations with NULL pages across {len(by_book)} books\n")

    total_fixed = 0
    total_still_null = 0

    for book_name, items in sorted(by_book.items()):
        doc_path = find_doc_path(book_name)
        if not doc_path:
            logging.warning(f"  No .docx found for {book_name}")
            total_still_null += len(items)
            continue

        file_size_mb = Path(doc_path).stat().st_size / (1024 * 1024)
        timeout = int(180 + file_size_mb * 40)  # More generous timeout

        logging.info(f"{book_name} ({len(items)} NULL pages, {file_size_mb:.1f}MB, timeout {timeout}s)")

        search_items = []
        for tid, html_text in items:
            plain = strip_html(html_text)
            if plain:
                snippet = get_clean_snippet(plain)
                if snippet:
                    search_items.append((tid, snippet))
                    # Debug: show what we're searching for
                    if len(items) <= 5:
                        logging.info(f"  [{tid}] snippet: {snippet[:60]}...")

        page_map = get_pages_via_com(doc_path, search_items, timeout=timeout)

        if page_map:
            updated = update_pages(page_map)
            total_fixed += updated
            still_null = len(items) - len(page_map)
            total_still_null += still_null
            logging.info(f"  Fixed {updated}/{len(items)}" +
                        (f", {still_null} still NULL" if still_null > 0 else ""))
        else:
            total_still_null += len(items)
            logging.info(f"  No pages found")

    logging.info(f"\n=== Summary ===")
    logging.info(f"Fixed: {total_fixed}")
    logging.info(f"Still NULL: {total_still_null}")
    logging.info(f"Total: {total}")


if __name__ == '__main__':
    main()
