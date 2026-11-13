#!/usr/bin/env python3
"""MusicWiz Fingerabdruck: akustische Fingerabdrücke (Chromaprint, wie AcoustID/Picard) ermitteln und vergleichen –
alles lokal, ohne Dienst im Internet.

Aufruf:  fingerprint.py measure   (JSON auf stdin: {"files": [pfad, ...], "workers": 8})
           je Zeile {"path": ..., "ok": true, "fingerprint": "AQAA…", "duration": 215.3} bzw. {"path", "ok": false, "error"}
         fingerprint.py compare   (JSON auf stdin: {"pairs": [[schlüssel, fingerabdruck_a, fingerabdruck_b], ...]})
           je Zeile {"key": ..., "score": 0.97} – Anteil gleicher Bits bei bester Verschiebung (1 = gleich), null = nicht vergleichbar

Der Fingerabdruck ist das komprimierte Format von fpcalc (erste 120 s) – dasselbe, das Picard ins Tag
ACOUSTID_FINGERPRINT schreibt. Verglichen wird die entpackte Folge von 32-Bit-Werten (etwa 8 je Sekunde): die beiden
Folgen werden gegeneinander verschoben (bis ±10 s – verschiedene Stille am Anfang), gezählt werden gleiche Bits.
"""
import json
import subprocess
import sys
from multiprocessing import Pool

import chromaprint

# höchstens so weit gegeneinander verschieben (Werte; ~8 je Sekunde)
MAX_OFFSET = 80
# mindestens so viele Werte müssen sich überlappen
MIN_OVERLAP = 40


def emit(obj):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def measure_one(path):
    try:
        r = subprocess.run(["fpcalc", "-json", path], capture_output=True, text=True, timeout=300)
    except (OSError, subprocess.TimeoutExpired) as e:
        return {"path": path, "ok": False, "error": str(e)[:300]}
    # fpcalc meldet am Dateiende oft „Error decoding audio frame“ (Rückgabe 3) – der Fingerabdruck ist trotzdem gültig
    try:
        data = json.loads(r.stdout)
    except ValueError:
        data = {}
    fp = data.get("fingerprint")
    if not fp:
        return {"path": path, "ok": False, "error": (r.stderr.strip() or "kein Fingerabdruck")[:300]}
    return {"path": path, "ok": True, "fingerprint": fp, "duration": data.get("duration")}


def decode(fp):
    try:
        return chromaprint.decode_fingerprint(fp.encode("ascii"))[0]
    except Exception:  # kaputter Wert (z. B. aus einem fremden Tag)
        return None


def similarity(a, b):
    if not a or not b:
        return None
    best = None
    for offset in range(-MAX_OFFSET, MAX_OFFSET + 1):
        xs = a[max(0, offset):]
        ys = b[max(0, -offset):]
        n = min(len(xs), len(ys))
        if n < min(MIN_OVERLAP, len(a), len(b)):
            continue
        errors = sum(bin((xs[i] ^ ys[i]) & 0xFFFFFFFF).count("1") for i in range(n))
        score = 1 - errors / (32.0 * n)
        if best is None or score > best:
            best = score
    return None if best is None else round(best, 4)


def compare_one(pair):
    key, fa, fb = pair
    return {"key": key, "score": similarity(decode(fa), decode(fb))}


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    req = json.load(sys.stdin) if cmd in ("measure", "compare") else {}
    if cmd == "measure":
        files = req.get("files", [])
        if files:
            with Pool(max(1, min(int(req.get("workers", 4)), len(files)))) as pool:
                for result in pool.imap_unordered(measure_one, files):
                    emit(result)
    elif cmd == "compare":
        for pair in req.get("pairs", []):
            emit(compare_one(pair))
    else:
        print(__doc__)
        sys.exit(2)


if __name__ == "__main__":
    main()
