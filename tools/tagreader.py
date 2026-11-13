#!/usr/bin/env python3
"""MusicWiz Tag-Leser: Ordner durchlaufen und Tags lesen – so schnell wie möglich.

Aufruf:  tagreader.py scan    (JSON auf stdin)
         tagreader.py cover   <datei> <ziel>   (eingebettetes Cover in eine Datei schreiben)
         tagreader.py image   <bild>           (Maße und Fingerabdruck eines Bilds)
         tagreader.py read    <datei>          (eine Datei lesen – Ausgabe wie ein Lied beim Einlesen)

scan erwartet {"roots": [...], "known": {pfad: [mtime, größe, datei-id]}, "known_images": {pfad: [mtime, größe]},
"extensions": [...], "workers": 8} und gibt je Zeile ein JSON-Objekt aus:
  {"type": "walk", "files": n, "read": m, "missing_roots": [...]}   nach dem Durchlaufen
  {"type": "moved", "from": ..., "to": ..., "mtime": ..., "size": ..., "fid": ...}   umbenannt bzw. verschoben (gleiche Datei-ID)
  {"type": "ids", "items": [[pfad, datei-id], ...]}                 Datei-ID bekannter Dateien (neu bzw. geändert)
  {"type": "track", "path": ..., "mtime": ..., "size": ..., "ok": true, "info": {...}, "core": {...}, "tags": {...}}
  {"type": "image", "path": ..., "mtime": ..., "size": ..., "cover": {...}}   Bild im Ordner (cover/folder/front)
  {"type": "deleted", "paths": [...], "images": [...]}              bekannte Dateien bzw. Bilder, die es nicht mehr gibt
  {"type": "done"}

Gelesen wird nur, was neu ist oder sich geändert hat (Änderungszeit bzw. Größe) – und das parallel in mehreren
Prozessen (mutagen ist reines Python, übers Netzwerk wartet jede Datei auf das Share). Datei-ID = „Gerät:Inode“: bleibt
beim Umbenennen und Verschieben innerhalb eines Shares gleich – so wird ein außerhalb umbenannter Ordner als derselbe
erkannt („moved“) statt gelöscht und neu angelegt.
Cover (eingebettet: info.cover, Ordner-Bild: cover) als {"width", "height", "phash"} – phash ist ein Bild-Fingerabdruck
(dHash, 64 Bit hex), der dasselbe Motiv auch in anderer Größe oder Kompression wiedererkennt; null, wenn nicht lesbar.
"tags" enthält alle Felder der Datei roh (ID3-Frames, Vorbis-Kommentare, MP4-Atome, ASF-Attribute), Bilder und
Binärdaten nur als Beschreibung; "core" die Grundfelder einheitlich für alle Formate.
"""
import io
import json
import os
import re
import sys
from multiprocessing import Pool

import mutagen
from mutagen.asf import ASF
from mutagen.flac import FLAC
from mutagen.id3 import ID3FileType
from mutagen.mp4 import MP4

PICTURE_TYPES = {0: "Sonstiges", 3: "Front", 4: "Rückseite", 6: "Medium", 8: "Künstler"}

# Bilder im Ordner, die Navidrome als Album-Cover nimmt (in dieser Reihenfolge)
FOLDER_IMAGE = re.compile(r"^(cover|folder|front)\.(jpe?g|png)$", re.I)

try:
    from PIL import Image
except ImportError:  # ohne Pillow: keine Cover-Daten
    Image = None


def text(v):
    if isinstance(v, bytes):
        try:
            return v.decode("utf-8")
        except UnicodeDecodeError:
            return "[binär, %d Byte]" % len(v)
    return str(v)


def picture(mime, data, kind=None):
    label = PICTURE_TYPES.get(kind, "Bild") if kind is not None else "Bild"
    return "[%s: %s, %d KB]" % (label, mime or "?", len(data or b"") // 1024)


def split_pair(v):
    s = str(v or "").strip()
    if "/" in s:
        a, b = s.split("/", 1)
        return a.strip(), b.strip()
    return s, ""


# ---------- rohe Tags je Format ----------

def raw_id3(tags):
    out = {}
    for key, frame in tags.items():
        fid = frame.FrameID
        if fid in ("APIC", "PIC"):
            val = [picture(frame.mime, frame.data, frame.type)]
        elif fid in ("PRIV", "GEOB", "MCDI", "SYLT", "RVA2", "AENC", "ENCR", "GRID", "SIGN"):
            val = ["[binär]"]
        elif fid == "UFID":
            val = [text(frame.data)]
        elif fid == "POPM":
            val = ["%s: %d (gespielt %d)" % (frame.email, frame.rating, getattr(frame, "count", 0) or 0)]
        elif fid in ("COMM", "USLT"):
            val = [text(frame.text)] if fid == "USLT" else [text(t) for t in frame.text]
        elif hasattr(frame, "text"):
            val = [text(t) for t in frame.text]
        elif hasattr(frame, "url"):
            val = [frame.url]
        else:
            val = [text(frame)]
        out.setdefault(key, []).extend(val)
    return out


def raw_vorbis(f):
    out = {}
    tags = f.tags
    for key in (tags.keys() if tags is not None else []):
        if key.upper() == "METADATA_BLOCK_PICTURE":
            out.setdefault("METADATA_BLOCK_PICTURE", []).append("[Bild]")
            continue
        out.setdefault(key.upper(), []).extend(text(v) for v in tags[key])
    for p in getattr(f, "pictures", []) or []:
        out.setdefault("PICTURE", []).append(picture(p.mime, p.data, p.type))
    return out


def raw_mp4(tags):
    out = {}
    for key, values in tags.items():
        vals = []
        for v in values if isinstance(values, list) else [values]:
            if key == "covr":
                vals.append(picture("image/png" if getattr(v, "imageformat", 13) == 14 else "image/jpeg", bytes(v)))
            elif isinstance(v, tuple):
                vals.append("/".join(str(x) for x in v))
            else:
                vals.append(text(bytes(v) if key.startswith("----") else v))
        out[key] = vals
    return out


def raw_asf(tags):
    out = {}
    for key, values in tags.items():
        vals = []
        for v in values:
            val = getattr(v, "value", v)
            vals.append("[Bild]" if key == "WM/Picture" else text(val))
        out[key] = vals
    return out


# ---------- Grundfelder ----------

def first(raw, *keys):
    for k in keys:
        for rk, vals in raw.items():
            if rk.upper() == k.upper() and vals and str(vals[0]).strip():
                return str(vals[0]).strip()
    return ""


def all_values(raw, *keys):
    for k in keys:
        for rk, vals in raw.items():
            if rk.upper() == k.upper() and vals:
                return [str(v).strip() for v in vals if str(v).strip()]
    return []


def comment_id3(tags):
    for key, frame in tags.items():
        if frame.FrameID == "COMM" and frame.desc == "" and frame.text:
            return text(frame.text[0])
    return ""


# Mehrfach-Interpreten (ARTISTS/ALBUMARTISTS, wie Picard sie schreibt) – je Format ein anderer Schlüssel
CREDIT_KEYS = {
    "id3": ("TXXX:ARTISTS", "TXXX:ALBUMARTISTS"),
    "mp4": ("----:com.apple.iTunes:ARTISTS", "----:com.apple.iTunes:ALBUMARTISTS"),
    "asf": ("WM/ARTISTS", "WM/ALBUMARTISTS"),
    "vorbis": ("ARTISTS", "ALBUMARTISTS"),
}


def credits(raw, kind):
    artists, albumartists = CREDIT_KEYS[kind]
    return {"artists": "; ".join(all_values(raw, artists)), "albumartists": "; ".join(all_values(raw, albumartists))}


# nachgestellter Artikel in Sortierform („Gang, The“)
TRAILING_ARTICLE = re.compile(r"^(.+),\s*(the|die|der|das|les|la|le|los|las|el|il)$", re.I)


def people(raw, keys):
    """Interpret bzw. Album-Interpret – mehrere Werte im Tag („Al Bano“, „Romina Power“) werden wieder ein Name wie
    „Al Bano & Romina Power“; Teile in Sortierform kommen dabei richtig herum („Kool“, „Gang, The“ → „Kool & The Gang“).
    Getrennt wird nicht hier: Tagger teilen auch „Earth, Wind & Fire“ so auf – ob es eine Kombi ist, entscheidet
    ArtistCredits."""
    names = list(dict.fromkeys(all_values(raw, *keys)))
    if len(names) < 2:
        return names[0] if names else ""
    names = [TRAILING_ARTICLE.sub(r"\2 \1", n) for n in names]
    return ", ".join(names[:-1]) + " & " + names[-1]


def core_from(f, raw):
    if isinstance(f, ID3FileType) or (f.tags is not None and f.tags.__class__.__name__ == "ID3"):
        tags = f.tags
        track, track_total = split_pair(first(raw, "TRCK"))
        disc, disc_total = split_pair(first(raw, "TPOS"))
        return {
            "title": first(raw, "TIT2"), "album": first(raw, "TALB"), "track": track, "track_total": track_total,
            "disc": disc, "disc_total": disc_total, "year": first(raw, "TDRC", "TYER"),
            "genre": "; ".join(all_values(raw, "TCON")), "comment": comment_id3(tags) if tags else "",
            "compilation": first(raw, "TCMP") == "1", "artist": people(raw, ["TPE1"]), "albumartist": people(raw, ["TPE2"]), **credits(raw, "id3"),
        }
    if isinstance(f, MP4):
        track, track_total = split_pair(first(raw, "trkn"))
        disc, disc_total = split_pair(first(raw, "disk"))
        return {
            "title": first(raw, "\xa9nam"), "album": first(raw, "\xa9alb"), "track": "" if track == "0" else track, "track_total": "" if track_total == "0" else track_total,
            "disc": "" if disc == "0" else disc, "disc_total": "" if disc_total == "0" else disc_total,
            "year": first(raw, "\xa9day"), "genre": "; ".join(all_values(raw, "\xa9gen")), "comment": first(raw, "\xa9cmt"),
            "compilation": first(raw, "cpil") in ("True", "1"), "artist": people(raw, ["\xa9ART"]), "albumartist": people(raw, ["aART"]), **credits(raw, "mp4"),
        }
    if isinstance(f, ASF):
        track, track_total = split_pair(first(raw, "WM/TrackNumber", "WM/Track"))
        disc, disc_total = split_pair(first(raw, "WM/PartOfSet"))
        return {
            "title": first(raw, "Title"), "album": first(raw, "WM/AlbumTitle"), "track": track, "track_total": track_total,
            "disc": disc, "disc_total": disc_total, "year": first(raw, "WM/Year"),
            "genre": "; ".join(all_values(raw, "WM/Genre")), "comment": first(raw, "Description"),
            "compilation": first(raw, "WM/IsCompilation") in ("True", "1"), "artist": people(raw, ["Author"]), "albumartist": people(raw, ["WM/AlbumArtist"]), **credits(raw, "asf"),
        }
    # Vorbis-Kommentare (FLAC, OGG, Opus)
    track, track_total = split_pair(first(raw, "TRACKNUMBER"))
    disc, disc_total = split_pair(first(raw, "DISCNUMBER"))
    return {
        "title": first(raw, "TITLE"), "album": first(raw, "ALBUM"),
        "track": track, "track_total": track_total or first(raw, "TRACKTOTAL", "TOTALTRACKS"),
        "disc": disc, "disc_total": disc_total or first(raw, "DISCTOTAL", "TOTALDISCS"),
        "year": first(raw, "DATE", "YEAR"), "genre": "; ".join(all_values(raw, "GENRE")),
        "comment": first(raw, "COMMENT", "DESCRIPTION"), "compilation": first(raw, "COMPILATION") == "1",
        "artist": people(raw, ["ARTIST"]), "albumartist": people(raw, ["ALBUMARTIST", "ALBUM ARTIST"]), **credits(raw, "vorbis"),
    }


def front_picture(f):
    """Bilddaten des eingebetteten Covers (Front bevorzugt) oder None"""
    if getattr(f, "pictures", None):
        return sorted(f.pictures, key=lambda p: p.type != 3)[0].data
    if isinstance(f, MP4) and f.tags and f.tags.get("covr"):
        return bytes(f.tags["covr"][0])
    if f.tags is not None and not isinstance(f, (MP4, ASF)):
        apics = [fr for k, fr in f.tags.items() if k.startswith("APIC")]
        apics.sort(key=lambda p: p.type != 3)
        return apics[0].data if apics else None
    return None


def cover_info(data):
    """Maße und Fingerabdruck (dHash) eines Bilds – None, wenn es sich nicht lesen lässt"""
    if not data or Image is None:
        return None
    try:
        img = Image.open(io.BytesIO(data))
        width, height = img.size
        small = img.convert("L").resize((9, 8), Image.LANCZOS)
        px = list(small.getdata())
        bits = 0
        for row in range(8):
            for col in range(8):
                bits = (bits << 1) | (px[row * 9 + col] > px[row * 9 + col + 1])
        return {"width": width, "height": height, "phash": "%016x" % bits}
    except Exception:
        return None


def read_image(item):
    path, mtime, size = item
    out = {"type": "image", "path": safe(path), "mtime": mtime, "size": size}
    try:
        with open(path, "rb") as fh:
            return {**out, "cover": cover_info(fh.read())}
    except OSError:
        return {**out, "cover": None}


def has_cover(f, raw):
    if getattr(f, "pictures", None):
        return True
    return any(k.startswith("APIC") or k in ("covr", "WM/Picture", "METADATA_BLOCK_PICTURE") for k in raw)


def safe(path):
    """Pfad als gültiges UTF-8 (Dateinamen in Latin-1 o. Ä. werden lesbar ersetzt – sonst ließe sich nichts ausgeben)."""
    return path.encode("utf-8", "surrogateescape").decode("utf-8", "replace")


def read(item):
    path, mtime, size = item[:3]
    out = {"type": "track", "path": safe(path), "mtime": mtime, "size": size, "fid": item[3] if len(item) > 3 else None}
    if out["path"] != path:
        return {**out, "ok": False, "error": "Dateiname ist kein gültiges UTF-8"}
    try:
        f = mutagen.File(path)
        if f is None:
            return {**out, "ok": False, "error": "kein bekanntes Audioformat"}
        tags = f.tags
        if tags is None:
            raw = {}
        elif isinstance(f, MP4):
            raw = raw_mp4(tags)
        elif isinstance(f, ASF):
            raw = raw_asf(tags)
        elif tags.__class__.__name__ == "ID3":
            raw = raw_id3(tags)
        else:
            raw = raw_vorbis(f)
        i = f.info
        info = {
            "format": type(f).__name__.replace("EasyMP3", "MP3").replace("OggVorbis", "OGG").replace("OggOpus", "OPUS"),
            "duration": round(float(getattr(i, "length", 0) or 0), 3),
            "bitrate": int(getattr(i, "bitrate", 0) or 0),
            "sample_rate": int(getattr(i, "sample_rate", 0) or 0),
            "channels": int(getattr(i, "channels", 0) or 0),
            "bits": int(getattr(i, "bits_per_sample", 0) or 0) or None,
            "has_cover": has_cover(f, raw),
            "cover": cover_info(front_picture(f)),
        }
        return {**out, "ok": True, "info": info, "core": core_from(f, raw), "tags": raw}
    except Exception as e:  # kaputte Datei: trotzdem erfassen, mit Fehler
        return {**out, "ok": False, "error": str(e)[:300]}


def walk(roots, extensions):
    exts = {"." + e.lower() for e in extensions}
    found, images, missing = [], [], []
    for root in roots:
        if not os.path.isdir(root):
            missing.append(root)
            continue
        stack = [root]
        while stack:
            d = stack.pop()
            try:
                with os.scandir(d) as it:
                    for e in it:
                        if e.name.startswith("."):
                            continue
                        if e.is_dir(follow_symlinks=False):
                            stack.append(e.path)
                        elif os.path.splitext(e.name)[1].lower() in exts:
                            st = e.stat(follow_symlinks=False)
                            found.append((e.path, int(st.st_mtime), int(st.st_size), "%d:%d" % (st.st_dev, st.st_ino)))
                        elif FOLDER_IMAGE.match(e.name):
                            st = e.stat(follow_symlinks=False)
                            images.append((e.path, int(st.st_mtime), int(st.st_size)))
            except OSError:
                missing.append(d)
    return found, images, missing


def emit(obj):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False) + "\n")


def cmd_scan(req):
    known = req.get("known", {})
    known_images = req.get("known_images", {})
    found, images, missing = walk(req["roots"], req.get("extensions", []))
    seen = {safe(f[0]) for f in found}
    # umbenannt/verschoben: neuer Pfad mit der Datei-ID eines bekannten, verschwundenen Pfads (nur eindeutige)
    gone = {}
    if not missing:
        for p, k in known.items():
            if p not in seen and len(k) > 2 and k[2]:
                gone[k[2]] = None if k[2] in gone else p
    moved = {}
    for f in found:
        p = safe(f[0])
        if p not in known and gone.get(f[3]):
            moved[p] = gone.pop(f[3])
    for to, frm in moved.items():
        known[to] = known.pop(frm)
    todo = [f for f in found if (known.get(safe(f[0])) or [None, None])[:2] != [f[1], f[2]]]
    emit({"type": "walk", "files": len(found), "read": len(todo), "missing_roots": missing})
    for f in found:
        p = safe(f[0])
        if p in moved:
            emit({"type": "moved", "from": moved[p], "to": p, "mtime": f[1], "size": f[2], "fid": f[3]})
    # Datei-ID nachtragen, wo sie fehlt oder sich geändert hat (gelesene Dateien bringen sie selbst mit)
    ids = [[safe(f[0]), f[3]] for f in found if safe(f[0]) in known and (known[safe(f[0])] + [None, None, None])[2] != f[3]]
    for i in range(0, len(ids), 2000):
        emit({"type": "ids", "items": ids[i:i + 2000]})
    sys.stdout.flush()
    if todo:
        workers = max(1, int(req.get("workers", 8)))
        with Pool(min(workers, len(todo))) as pool:
            for n, result in enumerate(pool.imap_unordered(read, todo, chunksize=8)):
                emit(result)
                if n % 50 == 0:
                    sys.stdout.flush()
    image_todo = [i for i in images if known_images.get(safe(i[0])) != [i[1], i[2]]]
    if image_todo:
        workers = max(1, int(req.get("workers", 8)))
        with Pool(min(workers, len(image_todo))) as pool:
            for result in pool.imap_unordered(read_image, image_todo, chunksize=4):
                emit(result)
    seen_images = {safe(i[0]) for i in images}
    # Wurzel nicht lesbar: nichts als gelöscht melden (Share nicht eingehängt?)
    deleted = [] if missing else [p for p in known if p not in seen]   # „known“ ist um die Umbenannten bereinigt
    deleted_images = [] if missing else [p for p in known_images if p not in seen_images]
    emit({"type": "deleted", "paths": deleted, "images": deleted_images})
    emit({"type": "done"})
    sys.stdout.flush()


def cmd_cover(path, target):
    f = mutagen.File(path)
    data = front_picture(f) if f is not None else None
    if not data:
        emit({"ok": False})
        return
    with open(target, "wb") as fh:
        fh.write(data)
    emit({"ok": True})


def cmd_image(path):
    try:
        with open(path, "rb") as fh:
            emit({"ok": True, "cover": cover_info(fh.read())})
    except OSError:
        emit({"ok": False})


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "scan":
        cmd_scan(json.load(sys.stdin))
    elif cmd == "cover":
        cmd_cover(sys.argv[2], sys.argv[3])
    elif cmd == "image":
        cmd_image(sys.argv[2])
    elif cmd == "read":
        st = os.stat(sys.argv[2])
        emit(read((sys.argv[2], int(st.st_mtime), int(st.st_size), "%d:%d" % (st.st_dev, st.st_ino))))
    else:
        print(__doc__)
        sys.exit(2)


if __name__ == "__main__":
    main()
