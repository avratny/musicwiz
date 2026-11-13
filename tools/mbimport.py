#!/usr/bin/env python3
"""MusicWiz MusicBrainz-Import: die JSON-Dumps (artist, release-group) direkt aus dem Netz lesen – als Strom, ohne sie
auf der Platte abzulegen – und nur behalten, was MusicWiz braucht.

Aufruf:  mbimport.py <release-group.tar.xz> <artist.tar.xz>     (Adressen: https://… oder file://…)

Ausgabe je Zeile ein JSON-Objekt:
  {"type": "progress", "stage": "release-group"|"artist", "done": n}
  {"type": "timestamp", "value": "2026-11-11 00:10:01.12345+00"}         Stand des Dumps
  {"type": "artist", "id": mbid, "name": …, "sort": …, "kind": "Group", "country": "US", "disambiguation": …,
   "begin": "1997", "end": null, "genres": ["indie folk", …], "aliases": [[name, type], …],
   "links": {"spotify": [...], "discogs": [...], "wikidata": [...]}, "releases": 12,
   "members": [[gruppe, mitglied, beendet], …]}                          aus „member of band“ (beide Richtungen)
  {"type": "done", "artists": n}

Veröffentlichungen = Release-Groups (Album, Single … je einmal), gezählt über die Artist-Credits.
"""
import json
import re
import sys
import tarfile
import urllib.request

USER_AGENT = "MusicWiz/0.4 ( https://github.com/avratny/musicwiz )"
PROGRESS_EVERY = 20000

SPOTIFY = re.compile(r"open\.spotify\.com/artist/([A-Za-z0-9]+)")
DISCOGS = re.compile(r"discogs\.com/artist/(\d+)")
WIKIDATA = re.compile(r"wikidata\.org/wiki/(Q\d+)")


def emit(obj):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False) + "\n")


def entries(url, suffix):
    """Zeilen der Datei mbdump/<suffix> im Archiv – und den Zeitstempel des Dumps."""
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=60) as resp:
        with tarfile.open(fileobj=resp, mode="r|xz") as tf:
            for m in tf:
                if m.name.endswith("TIMESTAMP"):
                    yield "timestamp", tf.extractfile(m).read().decode("utf-8", "replace").strip()
                elif m.name.endswith("mbdump/" + suffix):
                    f = tf.extractfile(m)
                    for line in f:
                        yield "line", line
                    return


def count_release_groups(url):
    """Zahl der Release-Groups je Interpret (16-Byte-Schlüssel spart Speicher)."""
    counts = {}
    decoder = json.JSONDecoder()
    n = 0
    for kind, value in entries(url, "release-group"):
        if kind != "line":
            continue
        line = value.decode("utf-8", "replace")
        # nur den Artist-Credit lesen, nicht die ganze Zeile
        i = line.find('"artist-credit":')
        if i < 0:
            continue
        try:
            credit, _ = decoder.raw_decode(line, i + len('"artist-credit":'))
        except ValueError:
            continue
        seen = set()
        for c in credit or []:
            aid = ((c or {}).get("artist") or {}).get("id")
            if aid and aid not in seen:
                seen.add(aid)
                key = bytes.fromhex(aid.replace("-", ""))
                counts[key] = counts.get(key, 0) + 1
        n += 1
        if n % PROGRESS_EVERY == 0:
            emit({"type": "progress", "stage": "release-group", "done": n})
            sys.stdout.flush()
    emit({"type": "progress", "stage": "release-group", "done": n})
    return counts


def artists(url, releases):
    n = 0
    for kind, value in entries(url, "artist"):
        if kind == "timestamp":
            emit({"type": "timestamp", "value": value})
            continue
        try:
            a = json.loads(value)
        except ValueError:
            continue
        links = {"spotify": [], "discogs": [], "wikidata": []}
        bands = []
        for r in a.get("relations") or []:
            if r.get("target-type") == "artist" and r.get("type") == "member of band":
                other = (r.get("artist") or {}).get("id")
                if other:
                    # Gruppe: „backward“, Ziel ist das Mitglied – Person: „forward“, Ziel ist die Gruppe
                    pair = [a["id"], other] if r.get("direction") == "backward" else [other, a["id"]]
                    bands.append(pair + [bool(r.get("ended"))])
                continue
            if r.get("target-type") != "url":
                continue
            res = ((r.get("url") or {}).get("resource")) or ""
            for name, pattern in (("spotify", SPOTIFY), ("discogs", DISCOGS), ("wikidata", WIKIDATA)):
                m = pattern.search(res)
                if m and m.group(1) not in links[name]:
                    links[name].append(m.group(1))
        genres = sorted(a.get("genres") or [], key=lambda g: -(g.get("count") or 0))
        span = a.get("life-span") or {}
        emit({
            "type": "artist",
            "id": a["id"],
            "name": a.get("name") or "",
            "sort": a.get("sort-name") or None,
            "kind": a.get("type") or None,
            "country": a.get("country") or None,
            "disambiguation": a.get("disambiguation") or None,
            "begin": span.get("begin"),
            "end": span.get("end"),
            "genres": [g["name"] for g in genres[:5] if g.get("name")],
            "aliases": [[al.get("name"), al.get("type")] for al in (a.get("aliases") or []) if al.get("name")],
            "links": {k: v for k, v in links.items() if v},
            "releases": releases.get(bytes.fromhex(a["id"].replace("-", "")), 0),
            "members": bands,
        })
        n += 1
        if n % PROGRESS_EVERY == 0:
            emit({"type": "progress", "stage": "artist", "done": n})
            sys.stdout.flush()
    emit({"type": "progress", "stage": "artist", "done": n})
    return n


def main():
    if len(sys.argv) != 3:
        print(__doc__)
        sys.exit(2)
    releases = count_release_groups(sys.argv[1])
    n = artists(sys.argv[2], releases)
    emit({"type": "done", "artists": n})
    sys.stdout.flush()


if __name__ == "__main__":
    main()
