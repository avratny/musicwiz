#!/usr/bin/env python3
"""MusicWiz Tag-Tool: schreibt Tags und Cover mit mutagen.

Aufruf:  tagtool.py write   (JSON auf stdin)
         tagtool.py read    (JSON auf stdin: {"files": [...]})
         tagtool.py cover   <datei> <ziel> [--front]   (eingebettetes Cover extrahieren; --front: nur das Frontcover)

write erwartet {"files": [{"path": ..., "tags": {...}, "cover": "/pfad.jpg"|null,
                           "remove_cover": false}], "id3_version": 4}
Nur Schlüssel, die in "tags" vorkommen, werden geändert. Leere Werte ("" / [] / null) löschen das Feld.
Schlüssel: title artist artists album albumartist albumartists track tracktotal
           disc disctotal year genres comment compilation rating (0–5 Sterne, 0 = keine)
           rg_track_gain rg_track_peak rg_album_gain rg_album_peak (ReplayGain, z. B. "-6.62 dB" / "0.988")
           lyrics (Songtext, auch synchronisiert im LRC-Format "[00:12.34] …"; FLAC: LYRICS, MP3: USLT)
Gelesen werden zusätzlich: source (Herkunft, z. B. YouTube) und rating.
read liefert außerdem "info": {duration, bitrate, cover_embedded, cover_size} – so muss der Scan jede Datei nur einmal öffnen.
Ausgabe: JSON {"results": [{"path": ..., "ok": true|false, "error": ...}]}

Geschrieben wird nie direkt ins Original: erst in eine Kopie im selben Ordner, die wird geprüft und ersetzt das Original
dann in einem Schritt (os.replace). Bricht der Vorgang ab, bleibt das Original unverändert.
"""
import json
import os
import shutil
import sys

from mutagen.mp3 import MP3, HeaderNotFoundError

from mutagen.flac import FLAC, Picture
from mutagen.id3 import (ID3, ID3NoHeaderError, APIC, COMM, POPM, TALB, TCMP, TCON,
                         TDRC, TIT2, TPE1, TPE2, TPOS, TRCK, TXXX, USLT)

LIST_KEYS = {"artists", "albumartists", "genres"}

# ReplayGain: Schlüssel im Tag-Tool → Feldname (FLAC: Vorbis-Kommentar, MP3: TXXX-Beschreibung)
REPLAYGAIN = {"rg_track_gain": "REPLAYGAIN_TRACK_GAIN", "rg_track_peak": "REPLAYGAIN_TRACK_PEAK",
              "rg_album_gain": "REPLAYGAIN_ALBUM_GAIN", "rg_album_peak": "REPLAYGAIN_ALBUM_PEAK"}


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


# ---------------------------------------------------------------- Bewertung (0–5 Sterne)
# MP3: POPM wie MediaMonkey/Windows (1★=1, 2★=64, 3★=128, 4★=196, 5★=255), FLAC: RATING 0–100 (20 pro Stern)
POPM_EMAIL = "no@email"
POPM_VALUES = {1: 1, 2: 64, 3: 128, 4: 196, 5: 255}


def stars(n):
    try:
        return max(0, min(5, int(round(float(n)))))
    except (TypeError, ValueError):
        return 0


def popm_to_stars(v):
    v = int(v or 0)
    if v <= 0:
        return 0
    return 1 if v < 32 else 2 if v < 96 else 3 if v < 160 else 4 if v < 224 else 5


def flac_rating_to_stars(s):
    try:
        v = float(s)
    except (TypeError, ValueError):
        return 0
    # manche Programme schreiben 1–5, MediaMonkey 0–100
    return stars(v) if v <= 5 else stars(v / 20)


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


def png_info(data):
    """Breite, Höhe und Farbtiefe aus dem IHDR-Block eines PNG; (0, 0, 0), wenn nicht lesbar."""
    if data[:8] != b"\x89PNG\r\n\x1a\n" or len(data) < 26:
        return 0, 0, 0
    width = int.from_bytes(data[16:20], "big")
    height = int.from_bytes(data[20:24], "big")
    bits, color = data[24], data[25]
    channels = {0: 1, 2: 3, 3: 1, 4: 2, 6: 4}.get(color, 0)
    return width, height, bits * channels


def image_mime(data):
    return "image/png" if data[:8] == b"\x89PNG\r\n\x1a\n" else "image/jpeg"


def image_info(data):
    return png_info(data) if image_mime(data) == "image/png" else jpeg_info(data)


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
    "lyrics": ["LYRICS", "UNSYNCEDLYRICS"],
    **{k: [v] for k, v in REPLAYGAIN.items()},
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
        "source": g("SOURCE"), "rating": flac_rating_to_stars(g("RATING")),
        "lyrics": g("LYRICS") or g("UNSYNCEDLYRICS"),
        **{k: g(v) for k, v in REPLAYGAIN.items()},
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
    if "rating" in tags:
        n = stars(tags["rating"])
        if n:
            t["RATING"] = [str(n * 20)]
        elif "RATING" in t:
            del t["RATING"]
    if cover or remove_cover:
        keep = [p for p in f.pictures if p.type != 3]
        f.clear_pictures()
        for p in keep:
            f.add_picture(p)
        if cover:
            pic = Picture()
            pic.type = 3
            pic.desc = ""
            with open(cover, "rb") as fh:
                pic.data = fh.read()
            pic.mime = image_mime(pic.data)
            # Breite/Höhe/Farbtiefe gehören laut FLAC-Standard in den Bild-Block (strenge Player lesen sie)
            pic.width, pic.height, pic.depth = image_info(pic.data)
            f.add_picture(pic)
    f.save()


def flac_cover(path, front_only=False):
    f = FLAC(path)
    pics = [p for p in f.pictures if p.type == 3 or not front_only]
    pics.sort(key=lambda p: 0 if p.type == 3 else 1)
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


def txxx_any(t, desc):
    # TXXX-Beschreibungen schreiben Programme mal groß, mal klein ("replaygain_track_gain")
    for k, fr in t.items():
        if k.startswith("TXXX:") and fr.desc.upper() == desc and fr.text:
            return str(fr.text[0])
    return ""


def mp3_lyrics(t):
    frames = t.getall("USLT")
    return str(frames[0].text) if frames else ""


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
        "source": (txxx("SOURCE") or [""])[0],
        "rating": mp3_rating(t),
        "lyrics": mp3_lyrics(t),
        **{k: txxx_any(t, v) for k, v in REPLAYGAIN.items()},
    }


def mp3_rating(t):
    frames = t.getall("POPM")
    own = [f for f in frames if f.email == POPM_EMAIL]
    return popm_to_stars((own or frames or [None])[0].rating) if frames else 0


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
    for key, desc in REPLAYGAIN.items():
        if key in tags:
            for k in [k for k in t.keys() if k.startswith("TXXX:") and t[k].desc.upper() == desc]:
                del t[k]
            val = as_str(tags[key])
            if val:
                t.add(TXXX(encoding=3, desc=desc, text=[val]))
    if "lyrics" in tags:
        lang = (t.getall("USLT") or [None])[0]
        lang = getattr(lang, "lang", None) or "XXX"
        t.delall("USLT")
        val = as_str(tags["lyrics"])
        if val:
            t.add(USLT(encoding=3, lang=lang, desc="", text=val))
    if "rating" in tags:
        # alle Bewertungen ersetzen (sonst zeigt ein anderes Programm noch die alte); Abspielzähler bleibt
        count = max([getattr(f, "count", 0) or 0 for f in t.getall("POPM")] or [0])
        t.delall("POPM")
        n = stars(tags["rating"])
        if n or count:
            t.add(POPM(email=POPM_EMAIL, rating=POPM_VALUES.get(n, 0), count=count))
    if cover or remove_cover:
        # nur das Frontcover ersetzen – Rückseite, Booklet usw. bleiben (wie bei FLAC)
        keep = [p for p in t.getall("APIC") if p.type != 3]
        t.delall("APIC")
        for p in keep:
            t.add(p)
        if cover:
            with open(cover, "rb") as fh:
                data = fh.read()
            # die Beschreibung muss je Bild eindeutig sein, sonst ersetzt das neue Bild ein vorhandenes
            taken = {p.desc for p in keep}
            desc, n = "", 1
            while desc in taken:
                desc = "Cover" if n == 1 else f"Cover {n}"
                n += 1
            t.add(APIC(encoding=3, mime=image_mime(data), type=3, desc=desc, data=data))
    t.save(path, v2_version=3 if version == 3 else 4)


def mp3_cover(path, front_only=False):
    try:
        t = ID3(path)
    except ID3NoHeaderError:
        return None
    pics = [p for p in t.getall("APIC") if p.type == 3 or not front_only]
    pics.sort(key=lambda p: 0 if p.type == 3 else 1)
    return pics[0].data if pics else None


# ---------------------------------------------------------------- Dispatcher

def kind(path):
    p = path.lower()
    if p.endswith(".flac"):
        return "flac"
    if p.endswith(".mp3"):
        return "mp3"
    return None


TMP_SUFFIX = ".musicwiz-write"


def audio_length(path, k):
    # Typ ausdrücklich angeben: die Kopie hat keine Audio-Endung, mutagen könnte das Format sonst nicht erkennen
    f = FLAC(path) if k == "flac" else MP3(path)
    return float(f.info.length or 0)


def safe_write(path, k, write):
    """In eine Kopie schreiben, prüfen und das Original erst dann in einem Schritt ersetzen."""
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
        os.replace(tmp, path)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


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
                safe_write(path, k, lambda p: flac_write(p, tags, cover, remove))
            elif k == "mp3":
                safe_write(path, k, lambda p: mp3_write(p, tags, cover, remove, version))
            else:
                raise ValueError("Format wird zum Schreiben nicht unterstützt")
            results.append({"path": path, "ok": True})
        except Exception as e:  # noqa: BLE001 – Fehler pro Datei zurückmelden
            results.append({"path": path, "ok": False, "error": f"{type(e).__name__}: {e}"})
    return {"results": results}


def cover_info(pics):
    """Eingebettetes Cover: vorhanden? Kleinere Seite in Pixeln (Frontcover bevorzugt)."""
    if not pics:
        return False, None
    pics = sorted(pics, key=lambda p: 0 if p.type == 3 else 1)
    w, h, _ = image_info(pics[0].data)
    return True, (min(w, h) if w and h else None)


def tech_info(f, pics):
    embedded, size = cover_info(pics)
    bitrate = getattr(f.info, "bitrate", 0) or 0
    return {"duration": float(f.info.length or 0), "bitrate": int(bitrate) or None,
            "cover_embedded": embedded, "cover_size": size}


def cmd_read(req):
    results = []
    for path in req.get("files", []):
        try:
            k = kind(path)
            # Datei nur einmal öffnen: Tags und Technik (Länge, Bitrate, Cover) aus demselben Objekt
            if k == "flac":
                f = FLAC(path)
                tags = flac_read(f)
                info = tech_info(f, f.pictures)
            elif k == "mp3":
                try:
                    f = MP3(path)
                except HeaderNotFoundError:
                    # Tags lesbar, aber keine Audiodaten (z. B. abgebrochener Rip): Länge unbekannt = "defekt"
                    try:
                        t = ID3(path)
                    except ID3NoHeaderError:
                        t = ID3()
                    tags = mp3_read(t)
                    embedded, size = cover_info(t.getall("APIC"))
                    info = {"duration": None, "bitrate": None, "cover_embedded": embedded, "cover_size": size}
                else:
                    t = f.tags if f.tags is not None else ID3()
                    tags = mp3_read(t)
                    info = tech_info(f, t.getall("APIC"))
            else:
                raise ValueError("Format nicht unterstützt")
            results.append({"path": path, "ok": True, "tags": tags, "info": info})
        except Exception as e:  # noqa: BLE001
            results.append({"path": path, "ok": False, "error": f"{type(e).__name__}: {e}"})
    return {"results": results}


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "cover":
        src, dst = sys.argv[2], sys.argv[3]
        front = "--front" in sys.argv[4:]
        k = kind(src)
        data = flac_cover(src, front) if k == "flac" else mp3_cover(src, front) if k == "mp3" else None
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
