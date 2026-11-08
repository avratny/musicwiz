#!/usr/bin/env python3
"""MusicWiz Tag-Tool: schreibt Tags und Cover mit mutagen.

Aufruf:  tagtool.py write   (JSON auf stdin)
         tagtool.py read    (JSON auf stdin: {"files": [...]})
         tagtool.py cover   <datei> <ziel.jpg>   (eingebettetes Cover extrahieren)

write erwartet {"files": [{"path": ..., "tags": {...}, "cover": "/pfad.jpg"|null,
                           "remove_cover": false}], "id3_version": 4}
Nur Schlüssel, die in "tags" vorkommen, werden geändert. Leere Werte ("" / [] / null) löschen das Feld.
Schlüssel: title artist artists album albumartist albumartists track tracktotal
           disc disctotal year genres comment compilation
Ausgabe: JSON {"results": [{"path": ..., "ok": true|false, "error": ...}]}
"""
import json
import sys

from mutagen.flac import FLAC, Picture
from mutagen.id3 import (ID3, ID3NoHeaderError, APIC, COMM, TALB, TCMP, TCON,
                         TDRC, TIT2, TPE1, TPE2, TPOS, TRCK, TXXX)

LIST_KEYS = {"artists", "albumartists", "genres"}


def as_list(v):
    if v is None:
        return []
    if isinstance(v, list):
        return [str(x) for x in v if str(x).strip() != ""]
    return [str(v)] if str(v).strip() != "" else []


def as_str(v):
    if v is None:
        return ""
    return str(v).strip()


def jpeg_info(data):
    """Breite, Höhe und Farbtiefe (Bit pro Pixel) aus dem SOF-Segment eines JPEG; (0, 0, 0), wenn nicht lesbar."""
    if data[:2] != b"\xff\xd8":
        return 0, 0, 0
    i = 2
    while i + 9 < len(data):
        if data[i] != 0xFF:
            return 0, 0, 0
        marker = data[i + 1]
        if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
            i += 2
            continue
        length = int.from_bytes(data[i + 2:i + 4], "big")
        if marker in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
            height = int.from_bytes(data[i + 5:i + 7], "big")
            width = int.from_bytes(data[i + 7:i + 9], "big")
            components = data[i + 9]
            return width, height, data[i + 4] * components
        i += 2 + length
    return 0, 0, 0


def num_pair(n, total):
    n, total = as_str(n), as_str(total)
    if not n:
        return ""
    return f"{n}/{total}" if total else n


# ---------------------------------------------------------------- FLAC

FLAC_SIMPLE = {
    "title": ["TITLE"],
    "artist": ["ARTIST"],
    "album": ["ALBUM"],
    "albumartist": ["ALBUMARTIST", "ALBUM ARTIST"],
    "track": ["TRACKNUMBER"],
    "tracktotal": ["TRACKTOTAL", "TOTALTRACKS"],
    "disc": ["DISCNUMBER"],
    "disctotal": ["DISCTOTAL", "TOTALDISCS"],
    "year": ["DATE"],
    "comment": ["COMMENT"],
}
FLAC_LIST = {"artists": "ARTISTS", "albumartists": "ALBUMARTISTS", "genres": "GENRE"}


def flac_read(f):
    t = f.tags or {}
    g = lambda k: (t.get(k) or [""])[0]
    out = {
        "title": g("TITLE"), "artist": g("ARTIST"), "album": g("ALBUM"),
        "albumartist": g("ALBUMARTIST") or g("ALBUM ARTIST"),
        "track": g("TRACKNUMBER"), "tracktotal": g("TRACKTOTAL") or g("TOTALTRACKS"),
        "disc": g("DISCNUMBER"), "disctotal": g("DISCTOTAL") or g("TOTALDISCS"),
        "year": g("DATE"), "comment": g("COMMENT") or g("DESCRIPTION"),
        "artists": list(t.get("ARTISTS", [])), "albumartists": list(t.get("ALBUMARTISTS", [])),
        "genres": list(t.get("GENRE", [])), "compilation": g("COMPILATION") == "1",
    }
    return out


def flac_write(path, tags, cover, remove_cover):
    f = FLAC(path)
    if f.tags is None:
        f.add_tags()
    t = f.tags
    for key, fields in FLAC_SIMPLE.items():
        if key not in tags:
            continue
        val = as_str(tags[key])
        for i, field in enumerate(fields):
            # Zweitschreibweisen (z. B. "ALBUM ARTIST") nur pflegen, wenn sie schon existieren
            if i > 0 and field not in t:
                continue
            if val:
                t[field] = [val]
            elif field in t:
                del t[field]
    for key, field in FLAC_LIST.items():
        if key not in tags:
            continue
        vals = as_list(tags[key])
        if vals:
            t[field] = vals
        elif field in t:
            del t[field]
    if "compilation" in tags:
        if tags["compilation"]:
            t["COMPILATION"] = ["1"]
        elif "COMPILATION" in t:
            del t["COMPILATION"]
    if cover or remove_cover:
        keep = [p for p in f.pictures if p.type != 3]
        f.clear_pictures()
        for p in keep:
            f.add_picture(p)
        if cover:
            pic = Picture()
            pic.type = 3
            pic.mime = "image/jpeg"
            pic.desc = ""
            with open(cover, "rb") as fh:
                pic.data = fh.read()
            # Breite/Höhe/Farbtiefe gehören laut FLAC-Standard in den Bild-Block (strenge Player lesen sie)
            pic.width, pic.height, pic.depth = jpeg_info(pic.data)
            f.add_picture(pic)
    f.save()


def flac_cover(path):
    f = FLAC(path)
    pics = sorted(f.pictures, key=lambda p: 0 if p.type == 3 else 1)
    return pics[0].data if pics else None


# ---------------------------------------------------------------- MP3

def id3_text(t, key):
    fr = t.get(key)
    return str(fr.text[0]) if fr and fr.text else ""


def id3_comment(t):
    for k, fr in t.items():
        if k.startswith("COMM") and fr.desc == "" and fr.text:
            return str(fr.text[0])
    return ""


def split_pair(s):
    s = as_str(s)
    if "/" in s:
        a, b = s.split("/", 1)
        return a.strip(), b.strip()
    return s, ""


def mp3_read(t):
    track, tracktotal = split_pair(id3_text(t, "TRCK"))
    disc, disctotal = split_pair(id3_text(t, "TPOS"))
    year = id3_text(t, "TDRC") or id3_text(t, "TYER")
    txxx = lambda d: [str(x) for x in (t.get("TXXX:" + d).text if t.get("TXXX:" + d) else [])]
    return {
        "title": id3_text(t, "TIT2"), "artist": id3_text(t, "TPE1"), "album": id3_text(t, "TALB"),
        "albumartist": id3_text(t, "TPE2"), "track": track, "tracktotal": tracktotal,
        "disc": disc, "disctotal": disctotal, "year": year, "comment": id3_comment(t),
        "artists": txxx("ARTISTS"), "albumartists": txxx("ALBUMARTISTS"),
        "genres": [str(x) for x in (t.get("TCON").text if t.get("TCON") else [])],
        "compilation": id3_text(t, "TCMP") == "1",
    }


def mp3_write(path, tags, cover, remove_cover, version):
    try:
        t = ID3(path)
    except ID3NoHeaderError:
        t = ID3()
    multi = (lambda vals: vals) if version == 4 else (lambda vals: ["; ".join(vals)] if vals else [])

    def set_text(frame_id, cls, val):
        t.delall(frame_id)
        if val:
            t.add(cls(encoding=3, text=[val] if isinstance(val, str) else val))

    simple = {"title": ("TIT2", TIT2), "artist": ("TPE1", TPE1),
              "album": ("TALB", TALB), "albumartist": ("TPE2", TPE2)}
    for key, (fid, cls) in simple.items():
        if key in tags:
            set_text(fid, cls, as_str(tags[key]))

    old = mp3_read(t)
    if "track" in tags or "tracktotal" in tags:
        set_text("TRCK", TRCK, num_pair(tags.get("track", old["track"]), tags.get("tracktotal", old["tracktotal"])))
    if "disc" in tags or "disctotal" in tags:
        set_text("TPOS", TPOS, num_pair(tags.get("disc", old["disc"]), tags.get("disctotal", old["disctotal"])))
    if "year" in tags:
        t.delall("TYER")
        set_text("TDRC", TDRC, as_str(tags["year"]))
    if "genres" in tags:
        set_text("TCON", TCON, multi(as_list(tags["genres"])))
    for key, desc in (("artists", "ARTISTS"), ("albumartists", "ALBUMARTISTS")):
        if key in tags:
            t.delall("TXXX:" + desc)
            vals = multi(as_list(tags[key]))
            if vals:
                t.add(TXXX(encoding=3, desc=desc, text=vals))
    if "comment" in tags:
        lang = "eng"
        for k in [k for k in t.keys() if k.startswith("COMM")]:
            if t[k].desc == "":
                lang = t[k].lang or lang
                del t[k]
        val = as_str(tags["comment"])
        if val:
            t.add(COMM(encoding=3, lang=lang, desc="", text=[val]))
    if "compilation" in tags:
        set_text("TCMP", TCMP, "1" if tags["compilation"] else "")
    if cover or remove_cover:
        t.delall("APIC")
        if cover:
            with open(cover, "rb") as fh:
                t.add(APIC(encoding=3, mime="image/jpeg", type=3, desc="", data=fh.read()))
    t.save(path, v2_version=3 if version == 3 else 4)


def mp3_cover(path):
    try:
        t = ID3(path)
    except ID3NoHeaderError:
        return None
    pics = sorted(t.getall("APIC"), key=lambda p: 0 if p.type == 3 else 1)
    return pics[0].data if pics else None


# ---------------------------------------------------------------- Dispatcher

def kind(path):
    p = path.lower()
    if p.endswith(".flac"):
        return "flac"
    if p.endswith(".mp3"):
        return "mp3"
    return None


def cmd_write(req):
    version = int(req.get("id3_version", 4))
    results = []
    for item in req.get("files", []):
        path = item["path"]
        try:
            k = kind(path)
            tags = item.get("tags", {})
            cover, remove = item.get("cover"), bool(item.get("remove_cover"))
            if k == "flac":
                flac_write(path, tags, cover, remove)
            elif k == "mp3":
                mp3_write(path, tags, cover, remove, version)
            else:
                raise ValueError("Format wird zum Schreiben nicht unterstützt")
            results.append({"path": path, "ok": True})
        except Exception as e:  # noqa: BLE001 – Fehler pro Datei zurückmelden
            results.append({"path": path, "ok": False, "error": f"{type(e).__name__}: {e}"})
    return {"results": results}


def cmd_read(req):
    results = []
    for path in req.get("files", []):
        try:
            k = kind(path)
            if k == "flac":
                tags = flac_read(FLAC(path))
            elif k == "mp3":
                try:
                    tags = mp3_read(ID3(path))
                except ID3NoHeaderError:
                    tags = mp3_read(ID3())
            else:
                raise ValueError("Format nicht unterstützt")
            results.append({"path": path, "ok": True, "tags": tags})
        except Exception as e:  # noqa: BLE001
            results.append({"path": path, "ok": False, "error": f"{type(e).__name__}: {e}"})
    return {"results": results}


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "cover":
        src, dst = sys.argv[2], sys.argv[3]
        k = kind(src)
        data = flac_cover(src) if k == "flac" else mp3_cover(src) if k == "mp3" else None
        if not data:
            print(json.dumps({"ok": False}))
            return
        with open(dst, "wb") as fh:
            fh.write(data)
        print(json.dumps({"ok": True}))
        return
    req = json.load(sys.stdin)
    out = cmd_write(req) if cmd == "write" else cmd_read(req) if cmd == "read" else {"error": "unbekannter Befehl"}
    json.dump(out, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
