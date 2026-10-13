#!/usr/bin/env python3
"""
embed_fonts.py — Embed Yada custom TrueType fonts into a DOCX so that
Microsoft's DOCX->PDF renderers (Graph media-transform + Word for the
Web) reproduce the correct glyphs instead of substituting a system font.

WHY THIS EXISTS
---------------
Microsoft renders DOCX->PDF on *their* servers, which do NOT have the
Yada custom fonts installed. A glyph only survives the conversion if the
font is physically embedded inside the .docx (the "Embed fonts in the
file" Word option). Most YY source docs reference the fonts but embed
nothing, so the paleo-Hebrew Tetragrammaton (HWHY, set in "Yada Towrah")
and related glyphs render as a fallback face in the PDF/flipbook.

This is the exact inverse of strip_obfuscated_fonts.py. It injects the
ECMA-376 obfuscated font streams (word/fonts/fontN.odttf), the
fontTable.xml <w:embed*> references, the fontTable.xml.rels
relationships, and the [Content_Types].xml odttf Default — i.e. it
reproduces what Word writes when "Embed fonts in the file" is checked.

Idempotent: a font that already carries <w:embedRegular> is left
untouched, so re-running (or running on an already-embedded doc) is a
safe no-op for that font.

Obfuscation algorithm (validated against a Word-embedded YY doc):
  key  = bytes.fromhex(fontKeyGuid without dashes)[::-1]
  for i in 0..31: data[i] ^= key[i % 16]
(XOR is symmetric, so obfuscate == deobfuscate.)

Usage:
    embed_fonts.py <input.docx> <output.docx> [--fonts-dir DIR]
"""

import re
import struct
import sys
import uuid
import zipfile
from pathlib import Path

# Custom fonts we manage, by the family name a docx uses for them.
# Microsoft's renderers lack these; everything else (Times, Calibri, Aptos,
# ...) they already have, so we never embed those (keeps the docx small and
# avoids Graph's many-font 500s). The font file for each is found in the
# fonts dir by its INTERNAL family name (a .ttf, .otf or .ttc collection),
# so a new upload with the same family needs no change here. "Yada Towrah"
# (v7.11, ASCII drawn as paleo-Hebrew glyphs, older docs) and
# "Yada Towrah-Times" (v7.12+, ASCII drawn as letters) are different fonts.
MANAGED_FONTS = ["Yada Towrah", "Yada Towrah-Times", "Jupiter-Yada", "Semitic Early"]
DEFAULT_FONTS_DIR = "/usr/local/share/fonts/yada"

REL_NS = "http://schemas.openxmlformats.org/package/2006/relationships"
FONT_REL_TYPE = "http://schemas.openxmlformats.org/officeDocument/2006/relationships/font"
OBFUSCATED_CT = "application/vnd.openxmlformats-officedocument.obfuscatedFont"

EMPTY_RELS = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\r\n'
    f'<Relationships xmlns="{REL_NS}"></Relationships>'
)


def obfuscate(ttf: bytes, guid: str) -> bytes:
    """Obfuscate a raw TTF into an .odttf stream using the fontKey GUID."""
    clean = guid.replace("{", "").replace("}", "").replace("-", "")
    key = bytes.fromhex(clean)[::-1]
    data = bytearray(ttf)
    for i in range(32):
        data[i] ^= key[i % 16]
    return bytes(data)


def new_guid() -> str:
    return "{" + str(uuid.uuid4()).upper() + "}"


def _tables(buf: bytes, off: int = 0) -> dict:
    """{tag: (offset, length)} for the sfnt whose offset table is at `off`."""
    num = struct.unpack(">H", buf[off + 4:off + 6])[0]
    out = {}
    for i in range(num):
        tag, _cs, toff, tlen = struct.unpack(">4sIII", buf[off + 12 + 16 * i:off + 28 + 16 * i])
        out[tag.decode("latin-1")] = (toff, tlen)
    return out


def _family(buf: bytes, tables: dict) -> str:
    """Typographic family (nameID 16) or family (nameID 1), Windows platform."""
    if "name" not in tables:
        return ""
    off = tables["name"][0]
    _fmt, cnt, so = struct.unpack(">HHH", buf[off:off + 6])
    found = {}
    for r in range(cnt):
        pid, _e, _l, nid, ln, o = struct.unpack(">HHHHHH", buf[off + 6 + 12 * r:off + 18 + 12 * r])
        if pid == 3 and nid in (1, 16):
            found[nid] = buf[off + so + o:off + so + o + ln].decode("utf-16-be", "replace")
    return found.get(16) or found.get(1) or ""


def _is_italic(buf: bytes, tables: dict) -> bool:
    if "OS/2" in tables:
        return bool(struct.unpack(">H", buf[tables["OS/2"][0] + 62:tables["OS/2"][0] + 64])[0] & 1)
    if "head" in tables:
        return bool(struct.unpack(">H", buf[tables["head"][0] + 44:tables["head"][0] + 46])[0] & 2)
    return False


def _standalone(buf: bytes, off: int) -> bytes:
    """One face of a .ttc rebuilt as a self-contained .ttf (tables copied,
    offsets recomputed). A plain .ttf (off 0) is returned unchanged."""
    if buf[:4] != b"ttcf":
        return buf
    version, num = struct.unpack(">IH", buf[off:off + 6])
    recs = [struct.unpack(">4sIII", buf[off + 12 + 16 * i:off + 28 + 16 * i]) for i in range(num)]
    es = max(k for k in range(16) if (1 << k) <= num)
    head = struct.pack(">IHHHH", version, num, 16 << es, es, num * 16 - (16 << es))
    pos = 12 + 16 * num
    dir_, data = b"", b""
    for tag, cs, toff, tlen in sorted(recs):
        dir_ += struct.pack(">4sIII", tag, cs, pos + len(data), tlen)
        data += buf[toff:toff + tlen] + b"\0" * (-tlen % 4)
    return head + dir_ + data


def index_fonts(fonts_dir: Path) -> dict:
    """{family.lower(): {"regular": ttf bytes, "italic": ttf bytes}} for every
    face of every .ttf/.otf/.ttc in fonts_dir."""
    idx = {}
    for p in sorted(fonts_dir.iterdir()):
        if p.suffix.lower() not in (".ttf", ".otf", ".ttc") or not p.is_file():
            continue
        buf = p.read_bytes()
        if buf[:4] == b"ttcf":
            n = struct.unpack(">I", buf[8:12])[0]
            offs = struct.unpack(">%dI" % n, buf[12:12 + 4 * n])
        else:
            offs = (0,)
        for off in offs:
            t = _tables(buf, off)
            fam = _family(buf, t).strip().lower()
            if not fam:
                continue
            slot = "italic" if _is_italic(buf, t) else "regular"
            idx.setdefault(fam, {}).setdefault(slot, _standalone(buf, off))
    return idx


def max_rid(rels_xml: str) -> int:
    """Highest numeric rIdN already present in a .rels document."""
    return max((int(m) for m in re.findall(r'Id="rId(\d+)"', rels_xml)), default=0)


def embed(input_path: Path, output_path: Path, fonts_dir: Path) -> dict:
    summary = {"embedded": [], "skipped_present": [], "skipped_already": [], "missing": [],
               "odttf_added": 0, "input_size": input_path.stat().st_size,
               "output_size": 0}

    if not zipfile.is_zipfile(input_path):
        raise ValueError(f"{input_path} is not a ZIP / DOCX")

    with zipfile.ZipFile(input_path, "r") as src:
        names = src.namelist()
        if "word/fontTable.xml" not in names:
            raise ValueError("no word/fontTable.xml — cannot embed fonts")

        font_table = src.read("word/fontTable.xml").decode("utf-8")
        rels_name = "word/_rels/fontTable.xml.rels"
        rels_xml = (src.read(rels_name).decode("utf-8")
                    if rels_name in names else EMPTY_RELS)
        ct_name = "[Content_Types].xml"
        content_types = src.read(ct_name).decode("utf-8")

        new_odttf = {}          # zip path -> bytes
        rid_counter = max_rid(rels_xml)
        font_counter = max(
            (int(m) for m in re.findall(r'fonts/font(\d+)\.odttf', rels_xml)),
            default=0,
        )
        new_rel_entries = []

        fonts = index_fonts(fonts_dir)
        for font_name in MANAGED_FONTS:
            # Locate the <w:font w:name="..."> ... </w:font> block.
            block_re = re.compile(
                r'(<w:font\b[^>]*\bw:name="' + re.escape(font_name) + r'"[^>]*>)(.*?)(</w:font>)',
                re.S,
            )
            m = block_re.search(font_table)
            if not m:
                continue  # doc doesn't reference this font
            if "<w:embed" in m.group(2):
                summary["skipped_already"].append(font_name)
                continue  # already embedded — idempotent no-op

            faces = fonts.get(font_name.lower())
            if not faces:
                summary["missing"].append(font_name)
                continue  # leave it to substitution rather than fail the whole embed
            regular = faces.get("regular") or faces["italic"]
            italic = faces.get("italic") or regular

            # Regular face in the Regular+Bold slots, the italic face (a .ttc
            # may carry one) in Italic+BoldItalic; with no italic face the
            # same face fills all four (what Word does for symbol/script
            # fonts). Each slot gets its own GUID.
            embeds = []
            for tag, ttf_bytes in (("embedRegular", regular), ("embedBold", regular),
                                   ("embedItalic", italic), ("embedBoldItalic", italic)):
                rid_counter += 1
                font_counter += 1
                rid = f"rId{rid_counter}"
                guid = new_guid()
                target = f"fonts/font{font_counter}.odttf"
                new_odttf[f"word/{target}"] = obfuscate(ttf_bytes, guid)
                new_rel_entries.append(
                    f'<Relationship Id="{rid}" Type="{FONT_REL_TYPE}" Target="{target}"/>'
                )
                embeds.append(f'<w:{tag} r:id="{rid}" w:fontKey="{guid}"/>')

            # Inject embed refs just before </w:font> (correct CT_Font order:
            # sig is the last existing child, embed* follow it).
            font_table = (
                font_table[:m.start()]
                + m.group(1) + m.group(2) + "".join(embeds) + m.group(3)
                + font_table[m.end():]
            )
            summary["embedded"].append(font_name)

        if not summary["embedded"]:
            # Nothing to do — still produce output (a faithful copy) so the
            # caller can use a single path unconditionally.
            with zipfile.ZipFile(output_path, "w", zipfile.ZIP_DEFLATED) as dst:
                for n in names:
                    dst.writestr(n, src.read(n))
            summary["output_size"] = output_path.stat().st_size
            return summary

        # Splice new relationships into the .rels document.
        rels_xml = rels_xml.replace(
            "</Relationships>", "".join(new_rel_entries) + "</Relationships>"
        )

        # Ensure the odttf Default content-type is declared exactly once.
        if 'Extension="odttf"' not in content_types:
            content_types = content_types.replace(
                "</Types>",
                f'<Default Extension="odttf" ContentType="{OBFUSCATED_CT}"/></Types>',
            )

        # Write the embedded DOCX.
        output_path.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(output_path, "w", zipfile.ZIP_DEFLATED) as dst:
            handled = {"word/fontTable.xml", ct_name, rels_name}
            for n in names:
                if n == "word/fontTable.xml":
                    dst.writestr(n, font_table)
                elif n == ct_name:
                    dst.writestr(n, content_types)
                elif n == rels_name:
                    dst.writestr(n, rels_xml)
                else:
                    dst.writestr(n, src.read(n))
            if rels_name not in names:           # create rels if absent
                dst.writestr(rels_name, rels_xml)
            for path, data in new_odttf.items():  # add obfuscated streams
                dst.writestr(path, data)
                summary["odttf_added"] += 1

    summary["output_size"] = output_path.stat().st_size
    return summary


def main(argv=None):
    argv = argv or sys.argv[1:]
    fonts_dir = Path(DEFAULT_FONTS_DIR)
    args = []
    i = 0
    while i < len(argv):
        if argv[i] == "--fonts-dir":
            fonts_dir = Path(argv[i + 1]); i += 2
        else:
            args.append(argv[i]); i += 1
    if len(args) != 2:
        sys.stderr.write("usage: embed_fonts.py <input.docx> <output.docx> [--fonts-dir DIR]\n")
        return 1
    s = embed(Path(args[0]), Path(args[1]), fonts_dir)
    print(f"input:            {args[0]} ({s['input_size']:,} bytes)")
    print(f"output:           {args[1]} ({s['output_size']:,} bytes)")
    print(f"fonts embedded:   {s['embedded']}")
    print(f"already embedded: {s['skipped_already']}")
    if s["missing"]:
        print(f"MISSING (not in fonts dir): {s['missing']}")
    print(f"odttf streams added: {s['odttf_added']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
