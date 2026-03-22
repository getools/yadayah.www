"""
Scrape Blue Letter Bible Hebrew lexicon (H0001-H8674) into yy_word_import table.
Usage: python -X utf8 scrape_blb.py [start] [end]
"""
import requests
from bs4 import BeautifulSoup, NavigableString
import psycopg2
import time
import re
import sys

DB_HOST = 'localhost'
DB_PORT = 5433
DB_NAME = 'yada'
DB_USER = 'postgres'
DB_PASS = 'yada_password'

BASE_URL = 'https://www.blueletterbible.org/lexicon/h{}/kjv/wlc/0-1/'
DELAY = 1.0
MAX_RETRIES = 3


def get_text(el):
    if el is None:
        return None
    t = el.get_text(strip=True)
    return t if t else None


def get_direct_text(el):
    """Get only direct text nodes of an element, not from children."""
    if not el:
        return None
    texts = [t.strip() for t in el.children
             if isinstance(t, NavigableString) and t.strip()]
    return ' '.join(texts) if texts else None


def get_label_value(container, label_text):
    """Find a lexicon-label with given text, return the next sibling div's text."""
    if not container:
        return None
    labels = container.find_all('div', class_='lexicon-label')
    for label in labels:
        if label_text in label.get_text():
            sib = label.find_next_sibling('div')
            if sib:
                t = sib.get_text(separator=' ', strip=True)
                return t if t else None
    return None


def truncate(val, maxlen):
    if val and len(val) > maxlen:
        return val[:maxlen]
    return val


def parse_page(html, num):
    soup = BeautifulSoup(html, 'html.parser')

    # Hebrew word
    h6 = soup.find('h6', class_='lexTitleHb')
    hebrew = get_text(h6)

    # Transliteration
    translit = None
    trans_div = soup.find('div', id='lexTrans')
    if trans_div:
        em = trans_div.find('em')
        translit = get_text(em)

    # Pronunciation - direct text only (exclude audio child)
    pronunciation = None
    pro_div = soup.find('div', id='lexPro')
    if pro_div:
        pronunc = pro_div.find('div', class_='lexicon-pronunc')
        if pronunc:
            pronunciation = get_direct_text(pronunc)
        else:
            # Fallback: clone and remove audio
            content = pro_div.find('div', class_='small-text-right')
            if content:
                clone = BeautifulSoup(str(content), 'html.parser')
                audio = clone.find('div', id='lexPronunc')
                if audio:
                    audio.decompose()
                pronunciation = clone.get_text(strip=True) or None

    # Part of Speech
    pos = get_label_value(soup.find('div', id='lexPart'), 'Part of Speech')
    if not pos:
        part_div = soup.find('div', id='lexPart')
        if part_div:
            content = part_div.find('div', class_='small-text-right')
            pos = get_text(content)

    # Root Word (Etymology)
    root = get_label_value(soup.find('div', id='lexRoot'), 'Root Word')
    if not root:
        root_div = soup.find('div', id='lexRoot')
        if root_div:
            content = root_div.find('div', attrs={'data-font-man': 'true'})
            root = get_text(content)

    # Extract root strongs H#### from root text or links
    root_strongs = None
    if root:
        m = re.search(r'H(\d{1,5})', root)
        if m:
            root_strongs = m.group(1).zfill(4)
    if not root_strongs:
        root_div = soup.find('div', id='lexRoot')
        if root_div:
            links = root_div.find_all('a', href=re.compile(r'/lexicon/[hH]\d+', re.I))
            if links:
                m2 = re.search(r'/lexicon/[hH](\d+)', links[0]['href'])
                if m2:
                    root_strongs = m2.group(1).zfill(4)

    # TWOT
    twot = None
    twot_el = soup.find('a', rel='lexicon.twot')
    if twot_el:
        twot = get_text(twot_el)

    # Strong's definition (desktop version)
    strongs_def = None
    desktop_defs = soup.select('.show-for-medium .lexStrongsDef')
    if desktop_defs:
        strongs_def = desktop_defs[0].get_text(separator=' ', strip=True)
    else:
        all_defs = soup.find_all('div', class_='lexStrongsDef')
        if all_defs:
            strongs_def = all_defs[-1].get_text(separator=' ', strip=True)

    # Outline of Biblical Usage
    usage = None
    usage_div = soup.find('div', id='outlineBiblical')
    if usage_div:
        usage = usage_div.get_text(separator=' ', strip=True) or None

    # Brown-Driver-Briggs definition
    bdb = None
    lexy = soup.find('div', id='lexyText')
    if lexy:
        # Remove non-definition parts
        for el in lexy.select('.bdb-title, .bdb-license, .scriptureIndex, #lexical-si'):
            el.decompose()
        for h3 in lexy.find_all('h3'):
            h3.decompose()
        bdb = lexy.get_text(separator=' ', strip=True) or None

    return {
        'hebrew': truncate(hebrew, 100),
        'translit': truncate(translit, 100),
        'pronunciation': truncate(pronunciation, 250),
        'pos': truncate(pos, 250),
        'root': root,
        'root_strongs': truncate(root_strongs, 5) if root_strongs else None,
        'def_usage': usage,
        'def_strongs': strongs_def,
        'def_bdb': bdb,
        'twot': truncate(twot, 100),
    }


def main():
    start = int(sys.argv[1]) if len(sys.argv) > 1 else 1
    end = int(sys.argv[2]) if len(sys.argv) > 2 else 8674

    conn = psycopg2.connect(host=DB_HOST, port=DB_PORT, dbname=DB_NAME, user=DB_USER, password=DB_PASS)
    cur = conn.cursor()

    # Load word_strongs -> word_id mapping
    cur.execute("SELECT TRIM(word_strongs), word_key FROM yy_word WHERE word_strongs IS NOT NULL")
    strongs_map = {}
    for row in cur.fetchall():
        s = row[0]
        if s and s not in strongs_map:
            strongs_map[s] = row[1]
    print(f'Loaded {len(strongs_map)} word_strongs -> word_id mappings', flush=True)

    # Check what's already imported
    cur.execute("SELECT TRIM(word_import_strongs) FROM yy_word_import")
    existing = set(row[0] for row in cur.fetchall())
    print(f'Already imported: {len(existing)} entries', flush=True)

    session = requests.Session()
    session.headers.update({
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    })

    inserted = 0
    skipped = 0
    errors = 0

    for num in range(start, end + 1):
        strongs = str(num).zfill(4)

        if strongs in existing:
            skipped += 1
            if skipped % 500 == 0:
                print(f'[{num}/{end}] skipped {skipped} existing...', flush=True)
            continue

        url = BASE_URL.format(num)

        for attempt in range(MAX_RETRIES):
            try:
                resp = session.get(url, timeout=30)

                if resp.status_code == 404:
                    print(f'[{num}/{end}] H{strongs}: 404, skipping', flush=True)
                    skipped += 1
                    break

                if resp.status_code != 200:
                    print(f'[{num}/{end}] H{strongs}: HTTP {resp.status_code} (attempt {attempt+1})', flush=True)
                    if attempt < MAX_RETRIES - 1:
                        time.sleep(DELAY * (attempt + 2))
                        continue
                    errors += 1
                    break

                data = parse_page(resp.text, num)

                if not data['hebrew'] and not data['translit']:
                    print(f'[{num}/{end}] H{strongs}: no lexicon data, skipping', flush=True)
                    skipped += 1
                    break

                word_id = strongs_map.get(strongs)

                cur.execute("""
                    INSERT INTO yy_word_import (
                        word_key, word_import_strongs, word_import_hebrew,
                        word_import_translit, word_import_pronunciation,
                        word_import_pos, word_import_root, word_import_root_strongs,
                        word_import_definition_main, word_import_definition_strongs,
                        word_import_definition_bdb, word_import_twot
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """, (
                    word_id, strongs, data['hebrew'],
                    data['translit'], data['pronunciation'],
                    data['pos'], data['root'], data['root_strongs'],
                    data['def_usage'], data['def_strongs'],
                    data['def_bdb'], data['twot'],
                ))
                conn.commit()
                inserted += 1

                if inserted % 100 == 0:
                    print(f'[{num}/{end}] inserted {inserted} (errors={errors} skipped={skipped})', flush=True)
                elif num <= 5:
                    print(f'H{strongs}: {data["hebrew"]} ({data["translit"]}) pos={data["pos"]} twot={data["twot"]}', flush=True)

                break  # success

            except requests.RequestException as e:
                print(f'[{num}/{end}] H{strongs}: network error (attempt {attempt+1}): {e}', flush=True)
                if attempt < MAX_RETRIES - 1:
                    time.sleep(DELAY * (attempt + 2))
                else:
                    errors += 1
                    conn.rollback()
            except Exception as e:
                print(f'[{num}/{end}] H{strongs}: ERROR: {e}', flush=True)
                errors += 1
                conn.rollback()
                break

        time.sleep(DELAY)

    cur.close()
    conn.close()
    print(f'\nDone! inserted={inserted} skipped={skipped} errors={errors}', flush=True)


if __name__ == '__main__':
    main()
