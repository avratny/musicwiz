#!/usr/bin/env python3
"""MusicWiz Tag-Schreiber: schreibt Tags und Cover in MP3 und FLAC (mutagen) und liest die Datei danach neu.

Aufruf:  tagwriter.py write   (JSON auf stdin)

write erwartet {"files": [{"path": ..., "tags": {feld: wert}, "cover": "/pfad.jpg"|null}]} und gibt je Datei eine Zeile
JSON aus: {"path": ..., "ok": true, "mode": "inplace"|"copy", "read": {...}} bzw. {"path": ..., "ok": false, "error": ...}. "read" hat dasselbe
Format wie ein Lied beim Einlesen (tagreader.py scan).
Nur Felder, die in "tags" vorkommen, werden geändert; leere Werte (null / "") löschen das Feld. Feldnamen wie in der
Datenbank: title artist artists album albumartist albumartists artistsort albumartistsort track track_total disc
disc_total year releasedate mb_albumid genre comment lyrics compilation rating (1–5, leer = keine) category
rg_track_gain rg_track_peak rg_album_gain rg_album_peak. Listen (artists, albumartists, genre) mit „; “ getrennt.

Zwei Wege – entschieden, bevor irgendetwas geschrieben wird (mutagen fragt vor dem Schreiben nach dem Padding):
- Die neuen Tags passen in den Reserveplatz am Dateianfang (Normalfall): direkt in die Datei. Überschrieben werden nur
  die ersten Kilobyte, die Größe bleibt gleich – Besitzer, Inode, ZFS-Snapshots und Netzwerk bleiben unberührt.
- Die Datei muss wachsen: mutagen würde sie ohnehin komplett neu schreiben – dann sicher über eine Kopie im selben
  Ordner („.<name>.musicwiz-write“); die wird geprüft (Audiodaten Byte für Byte gleich, Länge gleich) und ersetzt das
  Original in einem Schritt (os.replace). Bricht etwas ab, bleibt das Original unverändert.
Liegengebliebene Kopien eines abgebrochenen Laufs werden vor dem Schreiben im selben Ordner entfernt.
"""
import hashlib
import json
import os
import shutil
import sys

from mutagen.flac import FLAC, Picture
from mutagen.id3 import (ID3, ID3NoHeaderError, APIC, COMM, POPM, TALB, TCMP, TCON, TDRC, TDRL, TIT2, TPE1, TPE2,
                         TPOS, TRCK, TSO2, TSOP, TXXX, USLT)
from mutagen.mp3 import MP3

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from tagreader import read  # noqa: E402 – Nachlesen genau wie beim Einlesen

TMP_SUFFIX = ".musicwiz-write"
REPLAYGAIN = {"rg_track_gain": "REPLAYGAIN_TRACK_GAIN", "rg_track_peak": "REPLAYGAIN_TRACK_PEAK",
              "rg_album_gain": "REPLAYGAIN_ALBUM_GAIN", "rg_album_peak": "REPLAYGAIN_ALBUM_PEAK"}
# MP3: POPM wie MediaMonkey/Windows (Ratings.php), FLAC: RATING 0–100
POPM_EMAIL = "no@email"
POPM_VALUES = {1: 1, 2: 64, 3: 128, 4: 196, 5: 255}


def as_str(v):
    if v is None or v is False:
        return ""
    return str(v).strip()


def as_list(v):
    return [p.strip() for p in as_str(v).split(";") if p.strip()]


def stars(v):
    try:
        return max(0, min(5, int(round(float(v)))))
    except (TypeError, ValueError):
        return 0


def image_mime(data):
    return "image/png" if data[:8] == b"\x89PNG\r\n\x1a\n" else "image/jpeg"


def image_size(data):
    """Breite, Höhe, Farbtiefe (FLAC-Bild-Block); (0, 0, 0), wenn nicht lesbar"""
    try:
        from PIL import Image
        import io
        img = Image.open(io.BytesIO(data))
        depth = {"1": 1, "L": 8, "P": 8, "RGB": 24, "RGBA": 32, "CMYK": 32}.get(img.mode, 24)
        return img.size[0], img.size[1], depth
    except Exception:
        return 0, 0, 0


# ---------------------------------------------------------------- FLAC

FLAC_SIMPLE = {
    "title": ["TITLE"], "artist": ["ARTIST"], "album": ["ALBUM"], "albumartist": ["ALBUMARTIST", "ALBUM ARTIST"],
    "artistsort": ["ARTISTSORT"], "albumartistsort": ["ALBUMARTISTSORT"],
    "track": ["TRACKNUMBER"], "track_total": ["TRACKTOTAL", "TOTALTRACKS"],
    "disc": ["DISCNUMBER"], "disc_total": ["DISCTOTAL", "TOTALDISCS"],
    "year": ["DATE"], "releasedate": ["RELEASEDATE"], "mb_albumid": ["MUSICBRAINZ_ALBUMID"],
    "comment": ["COMMENT"], "lyrics": ["LYRICS", "UNSYNCEDLYRICS"], "category": ["MUSICWIZ_CATEGORY"], "fingerprint": ["ACOUSTID_FINGERPRINT"],
    **{k: [v] for k, v in REPLAYGAIN.items()},
}
FLAC_LIST = {"artists": "ARTISTS", "albumartists": "ALBUMARTISTS", "genre": "GENRE"}


def flac_write(path, tags, cover, padding):
    f = FLAC(path)
    if f.tags is None:
        f.add_tags()
    t = f.tags
    for key, fields in FLAC_SIMPLE.items():
        if key not in tags:
            continue
        val = as_str(tags[key])
        for i, field in enumerate(fields):
            # Zweitschreibweisen („ALBUM ARTIST“, „TOTALTRACKS“) nur pflegen, wenn es sie schon gibt
            if i > 0 and field not in t:
                continue
            if val:
                t[field] = [val]
            elif field in t:
                del t[field]
    for key, field in FLAC_LIST.items():
        if key in tags:
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
    if "rating" in tags:
        n = stars(tags["rating"])
        if n:
            t["RATING"] = [str(n * 20)]
        elif "RATING" in t:
            del t["RATING"]
    if cover:
        # nur das Frontcover ersetzen – Rückseite, Booklet usw. bleiben
        keep = [p for p in f.pictures if p.type != 3]
        f.clear_pictures()
        for p in keep:
            f.add_picture(p)
        pic = Picture()
        pic.type = 3
        pic.desc = ""
        with open(cover, "rb") as fh:
            pic.data = fh.read()
        pic.mime = image_mime(pic.data)
        pic.width, pic.height, pic.depth = image_size(pic.data)
        f.add_picture(pic)
    f.save(padding=padding)


# ---------------------------------------------------------------- MP3

def num_pair(n, total):
    n, total = as_str(n), as_str(total)
    if not n:
        return ""
    return f"{n}/{total}" if total else n


def split_pair(s):
    s = as_str(s)
    if "/" in s:
        a, b = s.split("/", 1)
        return a.strip(), b.strip()
    return s, ""


def mp3_write(path, tags, cover, padding):
    try:
        t = ID3(path)
    except ID3NoHeaderError:
        t = ID3()

    def set_text(frame_id, cls, val):
        t.delall(frame_id)
        if val:
            t.add(cls(encoding=3, text=val if isinstance(val, list) else [val]))

    def set_txxx(desc, vals):
        # TXXX-Beschreibungen schreiben Programme mal groß, mal klein – alle Schreibweisen ersetzen
        for k in [k for k in t.keys() if k.startswith("TXXX:") and t[k].desc.upper() == desc.upper()]:
            del t[k]
        if vals:
            t.add(TXXX(encoding=3, desc=desc, text=vals))

    simple = {"title": ("TIT2", TIT2), "artist": ("TPE1", TPE1), "album": ("TALB", TALB), "albumartist": ("TPE2", TPE2),
              "artistsort": ("TSOP", TSOP), "albumartistsort": ("TSO2", TSO2), "releasedate": ("TDRL", TDRL)}
    for key, (fid, cls) in simple.items():
        if key in tags:
            set_text(fid, cls, as_str(tags[key]))
    if "track" in tags or "track_total" in tags:
        old = split_pair(t["TRCK"].text[0] if "TRCK" in t and t["TRCK"].text else "")
        set_text("TRCK", TRCK, num_pair(tags.get("track", old[0]), tags.get("track_total", old[1])))
    if "disc" in tags or "disc_total" in tags:
        old = split_pair(t["TPOS"].text[0] if "TPOS" in t and t["TPOS"].text else "")
        set_text("TPOS", TPOS, num_pair(tags.get("disc", old[0]), tags.get("disc_total", old[1])))
    if "year" in tags:
        t.delall("TYER")
        set_text("TDRC", TDRC, as_str(tags["year"]))
    if "genre" in tags:
        set_text("TCON", TCON, as_list(tags["genre"]))
    if "compilation" in tags:
        set_text("TCMP", TCMP, "1" if tags["compilation"] else "")
    if "comment" in tags:
        lang = "eng"
        for k in [k for k in t.keys() if k.startswith("COMM")]:
            if t[k].desc == "":
                lang = t[k].lang or lang
                del t[k]
        val = as_str(tags["comment"])
        if val:
            t.add(COMM(encoding=3, lang=lang, desc="", text=[val]))
    if "lyrics" in tags:
        # Songtext: USLT ohne Beschreibung ersetzen (synchronisiert im LRC-Format oder rein) – Navidrome liest ihn
        lang = "eng"
        for k in [k for k in t.keys() if k.startswith("USLT")]:
            if t[k].desc == "":
                lang = t[k].lang or lang
                del t[k]
        val = as_str(tags["lyrics"])
        if val:
            t.add(USLT(encoding=3, lang=lang, desc="", text=val))
    for key, desc in (("artists", "ARTISTS"), ("albumartists", "ALBUMARTISTS")):
        if key in tags:
            set_txxx(desc, as_list(tags[key]))
    if "category" in tags:
        set_txxx("MUSICWIZ_CATEGORY", [as_str(tags["category"])] if as_str(tags["category"]) else [])
    if "mb_albumid" in tags:
        set_txxx("MusicBrainz Album Id", [as_str(tags["mb_albumid"])] if as_str(tags["mb_albumid"]) else [])
    if "fingerprint" in tags:
        set_txxx("Acoustid Fingerprint", [as_str(tags["fingerprint"])] if as_str(tags["fingerprint"]) else [])
    for key, desc in REPLAYGAIN.items():
        if key in tags:
            set_txxx(desc, [as_str(tags[key])] if as_str(tags[key]) else [])
    if "rating" in tags:
        # alle Bewertungen ersetzen (sonst zeigt ein anderes Programm noch die alte); Abspielzähler bleibt
        count = max([getattr(f, "count", 0) or 0 for f in t.getall("POPM")] or [0])
        t.delall("POPM")
        n = stars(tags["rating"])
        if n or count:
            t.add(POPM(email=POPM_EMAIL, rating=POPM_VALUES.get(n, 0), count=count))
    if cover:
        keep = [p for p in t.getall("APIC") if p.type != 3]
        t.delall("APIC")
        for p in keep:
            t.add(p)
        with open(cover, "rb") as fh:
            data = fh.read()
        # die Beschreibung muss je Bild eindeutig sein, sonst ersetzt das neue Bild ein vorhandenes
        taken = {p.desc for p in keep}
        desc, n = "", 1
        while desc in taken:
            desc = "Cover" if n == 1 else f"Cover {n}"
            n += 1
        t.add(APIC(encoding=3, mime=image_mime(data), type=3, desc=desc, data=data))
    t.save(path, v2_version=4, padding=padding)


# ---------------------------------------------------------------- sicher schreiben

class NeedsCopy(Exception):
    """Die Tags passen nicht in den Reserveplatz – die Datei müsste wachsen."""


def keep_padding(info):
    """Padding für mutagen: Größe genau behalten; reicht der Platz nicht, abbrechen, bevor geschrieben wird."""
    if info.padding < 0:
        raise NeedsCopy()
    return info.padding


def kind(path):
    p = path.lower()
    return "flac" if p.endswith(".flac") else "mp3" if p.endswith(".mp3") else None


def audio_length(path, k):
    # Typ ausdrücklich: die Kopie hat keine Audio-Endung
    f = FLAC(path) if k == "flac" else MP3(path)
    return float(f.info.length or 0)


def audio_range(path, k):
    """Byte-Bereich der Audiodaten (ohne Tags): FLAC nach den Metadaten-Blöcken, MP3 zwischen ID3v2 und ID3v1."""
    size = os.path.getsize(path)
    with open(path, "rb") as fh:
        head = fh.read(10)
        start = 0
        if head[:3] == b"ID3":  # ID3v2 vorneweg (bei MP3 üblich, bei FLAC selten)
            start = 10 + ((head[6] << 21) | (head[7] << 14) | (head[8] << 7) | head[9]) + (10 if head[5] & 0x10 else 0)
        if k == "flac":
            fh.seek(start + 4)  # „fLaC“
            start += 4
            while True:
                block = fh.read(4)
                if len(block) < 4:
                    raise ValueError("FLAC-Metadaten unvollständig")
                start += 4 + int.from_bytes(block[1:4], "big")
                fh.seek(start)
                if block[0] & 0x80:  # letzter Metadaten-Block
                    break
            return start, size
        end = size
        if size >= 128:
            fh.seek(size - 128)
            if fh.read(3) == b"TAG":
                end = size - 128
        return start, end


def audio_hash(path, k):
    start, end = audio_range(path, k)
    h = hashlib.sha1()
    with open(path, "rb") as fh:
        fh.seek(start)
        left = end - start
        while left > 0:
            chunk = fh.read(min(1 << 20, left))
            if not chunk:
                break
            h.update(chunk)
            left -= len(chunk)
    return h.hexdigest()


def cleanup(folder):
    """Liegengebliebene Kopien eines abgebrochenen Laufs entfernen"""
    try:
        for name in os.listdir(folder):
            if name.startswith(".") and name.endswith(TMP_SUFFIX):
                os.unlink(os.path.join(folder, name))
    except OSError:
        pass


def copy_write(path, k, write):
    folder, name = os.path.split(path)
    tmp = os.path.join(folder, "." + name + TMP_SUFFIX)
    before = audio_length(path, k)
    shutil.copyfile(path, tmp)
    try:
        try:
            shutil.copymode(path, tmp)
        except OSError:
            pass  # manche Shares erlauben kein chmod – Inhalt ist wichtiger als die Rechte
        write(tmp)
        after = audio_length(tmp, k)
        if abs(after - before) > max(0.5, before * 0.01):
            raise ValueError(f"Prüfung nach dem Schreiben fehlgeschlagen (Länge {before:.1f} s → {after:.1f} s) – Original unverändert")
        if audio_hash(tmp, k) != audio_hash(path, k):
            raise ValueError("Prüfung nach dem Schreiben fehlgeschlagen (Audiodaten verändert) – Original unverändert")
        os.replace(tmp, path)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def inplace_write(path, k, write):
    """direkt in die Datei – nur, wenn die Tags in den Reserveplatz passen (sonst NeedsCopy, nichts geschrieben)"""
    size, before = os.path.getsize(path), audio_length(path, k)
    write(path, keep_padding)
    after = audio_length(path, k)
    if os.path.getsize(path) != size or abs(after - before) > max(0.5, before * 0.01):
        raise ValueError(f"Prüfung nach dem Schreiben fehlgeschlagen (Größe {size} → {os.path.getsize(path)} Byte, Länge {before:.1f} s → {after:.1f} s)")


def write_one(item, cleaned):
    path = item["path"]
    try:
        k = kind(path)
        tags, cover = item.get("tags", {}), item.get("cover")
        if k is None:
            raise ValueError("Format wird zum Schreiben nicht unterstützt")
        if cover and not os.path.isfile(cover):
            raise ValueError("Cover-Bild fehlt im Cover-Speicher")
        folder = os.path.dirname(path)
        if folder not in cleaned:
            cleanup(folder)
            cleaned.add(folder)
        write = (lambda p, pad: flac_write(p, tags, cover, pad)) if k == "flac" else (lambda p, pad: mp3_write(p, tags, cover, pad))
        try:
            inplace_write(path, k, write)
            mode = "inplace"
        except NeedsCopy:
            copy_write(path, k, lambda p: write(p, None))
            mode = "copy"
        st = os.stat(path)
        return {"path": path, "ok": True, "mode": mode, "read": read((path, int(st.st_mtime), int(st.st_size)))}
    except Exception as e:  # noqa: BLE001 – Fehler je Datei zurückmelden
        return {"path": path, "ok": False, "error": f"{type(e).__name__}: {e}"[:500]}


def main():
    if (sys.argv[1] if len(sys.argv) > 1 else "") != "write":
        print(__doc__)
        sys.exit(2)
    cleaned = set()
    for item in json.load(sys.stdin).get("files", []):
        sys.stdout.write(json.dumps(write_one(item, cleaned), ensure_ascii=False) + "\n")
        sys.stdout.flush()


if __name__ == "__main__":
    main()
