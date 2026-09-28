#!/usr/bin/env python3
"""
Re-key TTS page-sync markers after a volume re-parse (no re-synthesis).

    python3 tts_marker_remap.py [--audio N ...] [--volume N ...] [--apply] [-v]

Run automatically by book-pipeline-worker.sh after every successful parse
(--volume <vk> --apply). Exit 2 = some audio could not be remapped (corpus
mismatch) — those keep their old markers; see the SKIP lines.

A re-parse DELETEs and re-INSERTs every yy_paragraph row of a volume. The
marker FK is ON DELETE SET NULL, so already-built chapter audio keeps its
OLD paragraph_number / paragraph_page — and a re-render that repaginates the
book (or re-splits page-spanning paragraphs) makes the flipbook turn to the
wrong page and highlight the wrong paragraph. The narration itself is still
right (same text), only the marker keys are stale.

For each affected audio this tool:
  1. rebuilds the paragraph corpus as it stood when the markers were written
     (from yy_paragraph_rev: NEW rows on insert/update, OLD row on delete),
     and verifies the markers' (number, page) match it;
  2. lays old and current paragraphs of the chapter on one whitespace-free
     character stream (aligned with difflib if the text changed);
  3. uses the old markers as (char offset -> ms) anchors and emits one marker
     per CURRENT paragraph that starts inside narrated text: exact anchor if
     a paragraph still starts there, otherwise interpolated (byte NULL, like
     the worker's own page-break continuation markers).
Old markers are copied to yy_tts_audio_marker_bak_remap before rewrite.
Dry-run by default.
"""
import argparse, bisect, difflib, os, re, subprocess, sys
import psycopg2, psycopg2.extras

WS = re.compile(r'\s+')


def env():
    e = {}
    for line in open('/opt/yada-www/.env', encoding='utf-8'):
        if '=' in line and not line.lstrip().startswith('#'):
            k, v = line.strip().split('=', 1)
            e[k] = v
    return e


def db_connect(e):
    """Mirrors parse_volume_from_bundle.db_connect: .env host, else the
    container IP (the host-side 5433 mapping is not always published)."""
    kw = dict(user=e['POSTGRES_USER'], password=e['POSTGRES_PASSWORD'],
              dbname=e.get('POSTGRES_DB', 'yada'), connect_timeout=3,
              application_name='tts-marker-remap')
    try:
        return psycopg2.connect(host=os.environ.get('PG_HOST') or e.get('POSTGRES_HOST', 'localhost'),
                                port=os.environ.get('PG_PORT') or e.get('POSTGRES_PORT', '5433'), **kw)
    except psycopg2.OperationalError:
        ip = subprocess.check_output(
            ['docker', 'inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}',
             'yada-postgres-prod'], text=True).strip()
        return psycopg2.connect(host=ip, port='5432', **kw)


def norm(s):
    return WS.sub('', s or '')


_hist = {}


def corpus_at(cur, vk, T):
    """{paragraph_key: row} of volume vk as it existed at time T."""
    if vk not in _hist:
        _hist[vk] = _load_hist(cur, vk)
    by, live = _hist[vk]
    return _snapshot(by, live, T)


def _load_hist(cur, vk):
    cur.execute("""
        SELECT paragraph_key, chapter_key, paragraph_number, paragraph_page,
               paragraph_is_continuation, paragraph_text_plain, paragraph_dtime,
               paragraph_revision_dtime, paragraph_revision_delete_dtime AS del
          FROM yy_paragraph_rev WHERE volume_key = %s
         ORDER BY paragraph_key, paragraph_revision_id""", (vk,))
    by = {}
    for r in cur.fetchall():
        by.setdefault(r['paragraph_key'], []).append(r)
    cur.execute("""SELECT paragraph_key, chapter_key, paragraph_number, paragraph_page,
                          paragraph_is_continuation, paragraph_text_plain, paragraph_dtime,
                          paragraph_revision_dtime, NULL::timestamptz AS del
                     FROM yy_paragraph WHERE volume_key = %s""", (vk,))
    live = {r['paragraph_key']: r for r in cur.fetchall()}
    return by, live


def _snapshot(by, live, T):
    out = {}
    for pk in set(by) | set(live):
        rows = by.get(pk, [])
        born = (rows[0] if rows else live[pk])['paragraph_dtime']
        if born is None or born > T:
            continue
        dels = [r['del'] for r in rows if r['del'] is not None]
        if dels and min(dels) <= T:
            continue
        before = [r for r in rows if r['del'] is None and r['paragraph_revision_dtime'] <= T]
        if before:
            out[pk] = before[-1]
        elif dels:
            out[pk] = next(r for r in rows if r['del'] is not None)
        elif pk in live:
            out[pk] = live[pk]
    return out


def stream(paras):
    """paras sorted by number -> (text, {pn: (start, end)})."""
    parts, spans, pos = [], {}, 0
    for p in paras:
        t = norm(p['paragraph_text_plain'])
        spans[p['paragraph_number']] = (pos, pos + len(t))
        parts.append(t)
        pos += len(t)
    return ''.join(parts), spans


def align(old_ch, new_ch):
    """Word-level alignment of two paragraph lists on the whitespace-free
    stream. Returns (blocks [(old_char, new_char, size)], matched ratio)."""
    def toks(paras):
        out, pos = [], 0
        for p in paras:
            for t in (p['paragraph_text_plain'] or '').split():
                out.append((t, pos))
                pos += len(t)
        return out
    ot, nt = toks(old_ch), toks(new_ch)
    sm = difflib.SequenceMatcher(None, [t for t, _ in ot], [t for t, _ in nt], autojunk=False)
    blocks = []
    for bl in sm.get_matching_blocks():
        if bl.size:
            last_o = ot[bl.a + bl.size - 1]
            blocks.append((ot[bl.a][1], nt[bl.b][1], last_o[1] + len(last_o[0]) - ot[bl.a][1]))
    total = sum(len(t) for t, _ in ot)
    return blocks, sum(b[2] for b in blocks) / max(1, total)


def remap_audio(cur, a, verbose):
    ak, vk, ck = a['tts_audio_key'], a['volume_key'], a['chapter_key']
    cur.execute("""SELECT * FROM yy_tts_audio_marker WHERE tts_audio_key = %s
                   ORDER BY tts_audio_marker_offset_ms, paragraph_number""", (ak,))
    markers = cur.fetchall()
    if not markers:
        return None
    T = min(m['tts_audio_marker_dtime'] for m in markers)
    old = corpus_at(cur, vk, T)
    old_ch = sorted([p for p in old.values() if p['chapter_key'] == ck],
                    key=lambda p: p['paragraph_number'])
    old_by_pn = {p['paragraph_number']: p for p in old_ch}

    # Validate: every marker's paragraph existed; head markers on its page.
    miss = [m for m in markers if m['paragraph_number'] not in old_by_pn]
    heads = [m for m in markers if m['tts_audio_marker_byte_offset'] is not None]
    badpg = [m for m in heads if m['paragraph_number'] in old_by_pn
             and old_by_pn[m['paragraph_number']]['paragraph_page'] != m['paragraph_page']]
    if miss or len(badpg) > max(2, 0.02 * len(heads)):
        return {'ak': ak, 'status': 'SKIP-corpus-mismatch', 'T': T,
                'missing': len(miss), 'bad_head_page': len(badpg), 'markers': len(markers)}

    cur.execute("""SELECT paragraph_key, paragraph_number, paragraph_page, paragraph_is_continuation,
                          paragraph_text_plain
                     FROM yy_paragraph WHERE chapter_key = %s ORDER BY paragraph_number""", (ck,))
    new_ch = cur.fetchall()
    ostr, ospan = stream(old_ch)
    nstr, nspan = stream(new_ch)
    if ostr == nstr:
        blocks, ratio = [(0, 0, len(ostr))], 1.0
    else:
        blocks, ratio = align(old_ch, new_ch)

    # Anchors: markers at a paragraph start (head markers, and continuation
    # markers keyed to a tail row on its own page). Intra-paragraph page-break
    # markers (page != row page) have no exact char position: interpolate them
    # from their paragraph's head/next anchors afterwards.
    anchors, intra = [], []
    for m in markers:
        op = old_by_pn[m['paragraph_number']]
        if m['paragraph_page'] == op['paragraph_page']:
            anchors.append((ospan[m['paragraph_number']][0], m))
        else:
            intra.append(m)
    anchors.sort(key=lambda x: (x[0], x[1]['tts_audio_marker_offset_ms']))
    # collapse duplicate offsets (empty paragraphs) — keep the earliest time
    ded = []
    for off, m in anchors:
        if ded and ded[-1][0] == off:
            continue
        ded.append((off, m))
    anchors = ded
    aoff = [o for o, _ in anchors]
    ams = [m['tts_audio_marker_offset_ms'] for _, m in anchors]
    nonmono = sum(1 for i in range(1, len(ams)) if ams[i] < ams[i - 1])

    # Narrated coverage in OLD offsets = spans of paragraphs that had a marker.
    covered = sorted({ospan[m['paragraph_number']] for m in markers})
    cstarts = [c[0] for c in covered]

    def is_covered(ooff):
        i = bisect.bisect_right(cstarts, ooff) - 1
        return i >= 0 and ooff < covered[i][1]

    # new offset -> old offset (None inside changed text)
    bst = [b[1] for b in blocks]

    def inv(y):
        i = bisect.bisect_right(bst, y) - 1
        if i >= 0 and y < blocks[i][1] + blocks[i][2]:
            return blocks[i][0] + (y - blocks[i][1])
        return None

    def ms_at(ooff):
        i = bisect.bisect_left(aoff, ooff)
        if i < len(aoff) and aoff[i] == ooff:
            return ams[i], anchors[i][1]
        lo, hi = i - 1, i
        if lo < 0:
            return ams[0], None
        if hi >= len(aoff):
            # past last anchor: interpolate inside the last narrated paragraph
            m = anchors[lo][1]
            return ams[lo], None
        span = aoff[hi] - aoff[lo]
        frac = (ooff - aoff[lo]) / span if span else 0
        return int(round(ams[lo] + frac * (ams[hi] - ams[lo]))), None

    new_markers, unaligned = [], 0
    for p in new_ch:
        s, e = nspan[p['paragraph_number']]
        if e == s:
            continue
        ooff = inv(s)
        if ooff is None:
            # start sits in changed text: use first aligned char inside para
            for y in range(s, e):
                ooff = inv(y)
                if ooff is not None:
                    break
            if ooff is None:
                unaligned += 1
                continue
        if not is_covered(ooff):
            continue
        ms, anchor = ms_at(ooff)
        byte = None
        if anchor is not None and anchor['tts_audio_marker_byte_offset'] is not None \
                and not p['paragraph_is_continuation']:
            byte = anchor['tts_audio_marker_byte_offset']
        new_markers.append({'paragraph_key': p['paragraph_key'],
                            'paragraph_number': p['paragraph_number'],
                            'paragraph_page': p['paragraph_page'],
                            'ms': ms, 'byte': byte})

    # Intra-paragraph page-break markers: place at the same time, keyed to the
    # CURRENT paragraph being read at that moment, on the page offset they
    # carried relative to their old row.
    have = {(x['paragraph_number'], x['paragraph_page']) for x in new_markers}
    by_ms = sorted(new_markers, key=lambda x: x['ms'])
    bms = [x['ms'] for x in by_ms]
    intra_out = 0
    for m in intra:
        i = bisect.bisect_right(bms, m['tts_audio_marker_offset_ms']) - 1
        if i < 0:
            continue
        host = by_ms[i]
        op = old_by_pn[m['paragraph_number']]
        pg = host['paragraph_page'] + (m['paragraph_page'] - op['paragraph_page'])
        # only if the host row is still the one spanning onto that page
        if pg <= host['paragraph_page'] or (host['paragraph_number'], pg) in have:
            continue
        nxt = [x for x in new_ch if x['paragraph_number'] > host['paragraph_number']]
        if nxt and nxt[0]['paragraph_page'] <= pg and nxt[0]['paragraph_is_continuation']:
            continue  # tail row now carries this page itself
        new_markers.append({'paragraph_key': host['paragraph_key'],
                            'paragraph_number': host['paragraph_number'],
                            'paragraph_page': pg,
                            'ms': m['tts_audio_marker_offset_ms'], 'byte': None})
        have.add((host['paragraph_number'], pg))
        intra_out += 1

    new_markers.sort(key=lambda x: (x['ms'], x['paragraph_number']))
    # sanity: what the old markers claimed vs what the new ones say
    changed = 0
    oldset = {(m['paragraph_number'], m['paragraph_page'], m['tts_audio_marker_offset_ms']) for m in markers}
    for x in new_markers:
        if (x['paragraph_number'], x['paragraph_page'], x['ms']) not in oldset:
            changed += 1
    if verbose:
        print(f"  audio {ak}: first new markers:")
        for x in new_markers[:8]:
            txt = next(norm(p['paragraph_text_plain'])[:40] for p in new_ch
                       if p['paragraph_number'] == x['paragraph_number'])
            print(f"    pn{x['paragraph_number']:>5} pg{x['paragraph_page']:>4} {x['ms']:>9}ms "
                  f"byte={x['byte']} {txt}")
    return {'ak': ak, 'status': 'OK' if changed or len(new_markers) != len(markers)
                                or any(m['paragraph_key'] is None for m in markers) else 'UNCHANGED',
            'T': T, 'markers': len(markers), 'new': len(new_markers), 'changed': changed,
            'intra': f"{intra_out}/{len(intra)}", 'text_ratio': round(ratio, 5),
            'nonmono': nonmono, 'unaligned': unaligned, 'rows': new_markers,
            'first_old': (markers[0]['paragraph_number'], markers[0]['paragraph_page']),
            'first_new': (new_markers[0]['paragraph_number'], new_markers[0]['paragraph_page'])
            if new_markers else None}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--audio', type=int, nargs='*', default=[])
    ap.add_argument('--volume', type=int, nargs='*', default=[])
    ap.add_argument('--apply', action='store_true')
    ap.add_argument('-v', '--verbose', action='store_true')
    args = ap.parse_args()
    db = db_connect(env())
    db.autocommit = True  # analysis can be slow; don't sit idle in a transaction
    cur = db.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
    # Default scope: every audio holding a marker orphaned by a paragraph delete.
    where, params = ["EXISTS (SELECT 1 FROM yy_tts_audio_marker m WHERE m.tts_audio_key = a.tts_audio_key "
                     "AND m.paragraph_key IS NULL)"], []
    if args.audio:
        where, params = ["a.tts_audio_key = ANY(%s)"], [args.audio]
    elif args.volume:
        where.append("a.volume_key = ANY(%s)")
        params.append(args.volume)
    cur.execute(f"""SELECT a.tts_audio_key, a.volume_key, a.chapter_key, v.volume_code, c.chapter_number
                      FROM yy_tts_audio a JOIN yy_volume v USING (volume_key)
                      JOIN yy_chapter c ON c.chapter_key = a.chapter_key
                     WHERE {' AND '.join(where)} ORDER BY v.volume_code, c.chapter_sort""", params)
    audios = cur.fetchall()
    results = []
    for a in audios:
        r = remap_audio(cur, a, args.verbose)
        if r is None:
            continue
        r['vol'], r['cn'] = a['volume_code'], a['chapter_number']
        results.append(r)
        print(f"{r['vol'][:34]:34} ch{r['cn']:>3} ak{r['ak']:>5} {r['status']:22} "
              + ' '.join(f"{k}={r[k]}" for k in ('markers', 'new', 'changed', 'intra', 'text_ratio',
                                                    'nonmono', 'unaligned', 'first_old', 'first_new',
                                                    'missing', 'bad_head_page') if k in r))
    todo = [r for r in results if r['status'] == 'OK']
    skipped = sum(1 for r in results if r['status'].startswith('SKIP'))
    print(f"\n{len(results)} audios checked, {len(todo)} to rewrite, "
          f"{sum(1 for r in results if r['status'].startswith('SKIP'))} skipped")
    if not args.apply or not todo:
        print('(dry run)' if not args.apply else '')
        sys.exit(2 if skipped else 0)
    db.autocommit = False
    cur.execute("""CREATE TABLE IF NOT EXISTS yy_tts_audio_marker_bak_remap
                   (LIKE yy_tts_audio_marker INCLUDING DEFAULTS)""")
    cur.execute("ALTER TABLE yy_tts_audio_marker_bak_remap ADD COLUMN IF NOT EXISTS bak_dtime timestamptz DEFAULT now()")
    for r in todo:
        cur.execute("""INSERT INTO yy_tts_audio_marker_bak_remap
                       SELECT m.*, now() FROM yy_tts_audio_marker m WHERE tts_audio_key = %s""", (r['ak'],))
        cur.execute("DELETE FROM yy_tts_audio_marker WHERE tts_audio_key = %s", (r['ak'],))
        psycopg2.extras.execute_values(cur, """
            INSERT INTO yy_tts_audio_marker (tts_audio_key, paragraph_key, paragraph_page, paragraph_number,
                                             tts_audio_marker_offset_ms, tts_audio_marker_byte_offset)
            VALUES %s""", [(r['ak'], x['paragraph_key'], x['paragraph_page'], x['paragraph_number'],
                            x['ms'], x['byte']) for x in r['rows']])
    db.commit()
    print(f"APPLIED: rewrote markers for {len(todo)} audios (backup: yy_tts_audio_marker_bak_remap)")
    sys.exit(2 if skipped else 0)


if __name__ == '__main__':
    main()
