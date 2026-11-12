# MusicWiz – Regeln und Abläufe

Was MusicWiz prüft, entscheidet und schreibt – und was nicht. Stand: Version 0.3.42 (November 2026).
Technischer Aufbau und Installation: [`README.md`](README.md), Anforderungen: [`MusicWiz – Planung.md`](MusicWiz%20–%20Planung.md).

---

## 1. Grundsätze

1. **Nichts passiert ungefragt.** Gescannt wird per Knopf (oder Inbox-Beobachtung, nur lesend). Geschrieben, verschoben,
   umbenannt wird erst nach bestätigter Vorschau.
2. **Alles läuft als Aktion über den `OperationRunner`.** Jede Dateiänderung steht im Verlauf (alter/neuer Pfad, alte/neue
   Tags, Cover-Sicherung) und lässt sich rückgängig machen.
3. **Nie löschen, nie überschreiben.** Aussortiertes wandert in den Papierkorb (`_papierkorb`, wiederherstellbar).
   Ist ein Ziel belegt, wird übersprungen – oder bei „beste Version“ verglichen (siehe 6.3).
4. **Digitaler Zwilling.** Aufräumen, Album-Editor (Lib), Künstlerverzeichnis und API merken Tag-Änderungen nur in der
   Datenbank vor. In die Dateien kommt es gesammelt mit **„Änderungen schreiben“** – je Datei einmal Tags, höchstens
   einmal verschieben. Ausnahme: Einordnen aus der Inbox schreibt sofort.
5. **Die Tags bestimmen den Ort.** Ordner- und Dateinamen werden immer aus den (fertigen) Tags und dem Namensschema der
   Kategorie berechnet – nie umgekehrt.

---

## 2. Bereiche und Kategorien

| Bereich | Zweck |
|---|---|
| **Inbox** (`/inbox`) | Neue Musik. Einlesen, prüfen, korrigieren, einordnen. |
| **Lib** (`/music/library/…`) | Eingeordneter Bestand. Hier laufen alle Aufräum-Prüfungen. |
| **Blackhole** (`/music/blackhole/…`) | Musik, die nicht gehört wird. Eigener Scan, nur Suche, kein Aufräumen. |
| **Papierkorb** (`/music/_papierkorb`) | Aussortiertes mit Originalpfad, wiederherstellbar. |

Kategorien (Einstellungen) legen Ordner und Namensschema fest, z. B.:

| Kategorie | Ordner | Schema |
|---|---|---|
| LIB | `library/artists` | `{buchstabe}/{ordner_interpret}/{album} ({jahr})/{nr} - {titel}.{ext}` |
| LIB COMPILATION | `library/sampler` | `{album_buchstabe}/{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}` |
| BLACKHOLE / BLACKHOLE COMPILATION | `blackhole/…` | wie oben |

Die Kategorie steht zusätzlich im Kommentar-Tag und im Feld `MUSICWIZ_CATEGORY` (MP3: `TXXX`).

---

## 3. Namensschema (PathBuilder)

| Platzhalter | Regel |
|---|---|
| `{ordner_interpret}` | Erster Eintrag aus ALBUMARTISTS, sonst ALBUMARTIST (getrennt), sonst ARTISTS, sonst ARTIST. Schreibweise laut Künstlerverzeichnis bzw. häufigste Schreibweise im Bestand (das NAS unterscheidet Groß/klein nicht – „ATB“ und „Atb“ können nicht zwei Ordner sein). |
| `{buchstabe}` / `{album_buchstabe}` | Erster Buchstabe (ohne Akzent) des Ordner-Interpreten bzw. Albums; sonst `0-9`. |
| `{album}` | Albumname; ohne Album → `Kein Album` (Einzellieder). |
| `{jahr}` | Ordner-Jahr fürs ganze Album: Jahr der Mehrheit der Titel, sonst das neueste (Sampler mit Original-Single-Jahren bleiben in einem Ordner; Tags bleiben). Ohne Album kein Jahr. |
| `{nr}` | Tracknummer zweistellig; bei mehreren CDs `CD-Nr` (`1-05`). „Kein Album“: keine Nummern (werden auch im Tag geleert). |
| `{interpret}`, `{titel}` | Aus den Tags; ohne Titel der bisherige Dateiname. |
| `{ext}` | Endung immer klein. |

Bereinigung: `:` und `/` → Ersatzzeichen aus den Einstellungen (`-`), `? * < > |` entfernt, `"` → `'`, Unicode NFC,
führende/abschließende Punkte weg, leere Platzhalter samt Klammern/Trennern entfernt, Namen auf 200 Byte gekürzt
(Endung bleibt).

---

## 4. Einlesen (Scan)

- **Inkrementell:** Nur Dateien mit geänderter Größe/Änderungszeit werden neu gelesen (Migrationen setzen bei neuen
  Feldern einmal alles neu).
- **Tag-Tool** (`tools/tagtool.py`, mutagen) liest die Tag-Felder, **getID3** Länge, Bitrate, Cover.
- Gelesene Felder: Titel, Interpret, ARTISTS, Album, Album-Interpret, ALBUMARTISTS, Track/Gesamt, CD/Gesamt, Jahr,
  Genres, Kommentar, Kategorie, Compilation, Sortiernamen, Bewertung, Herkunft (`SOURCE`), ReplayGain, Songtext
  (nur ob vorhanden), **Original-Datum, Veröffentlichungsdatum, MusicBrainz-Album-ID** (nur für die Prüfung, siehe 7).
- **Alben** = Ordner (Unterordner `CD1`/`Disc 2` gehören zum Album darüber).
- **Schutz:** Fehlen auffällig viele Dateien (Share nicht eingehängt?), wird nichts entfernt. Nicht lesbare Ordner werden
  gemeldet statt als leer behandelt.
- **Reste** beim Scan: leere Ordner, Ordner ohne Musik, `.DS_Store`/`Thumbs.db`, abgebrochene Tag-Schreib-Kopien
  (`.musicwiz-write`). **Hängengebliebene Umbenennungen** (`Lied.MP3.musicwiz-tmp`) sind echte Musik – sie werden nie als
  Rest weggeräumt, sondern zurückbenannt (siehe 9).

---

## 5. Inbox → Einordnen

1. Inbox scannen → Alben (Ordner) mit Tags, Cover, Qualität.
2. **Abgleich mit dem Bestand** (`InboxMatcher`): gibt es das Album/die Lieder schon? Bessere Fassung (FLAC statt MP3,
   höhere Bitrate)?
3. **Album-Editor:** Felder (Album, Album-Interpret, Jahr, Genres, Kategorie) und Titel prüfen, Nachschlagen bei
   MusicBrainz/Discogs, Cover suchen, Titelliste abgleichen, Fingerabdruck (AcoustID).
4. **Vorschau** (`AlbumPlanner`): je Titel neue Tags + Zielpfad; belegte Ziele blockiert bzw. „beste Version“.
5. **Einordnen** schreibt sofort (Tags, Cover, verschieben) – als Aktion im Verlauf.

Mehrfach-CDs werden zusammengefasst, Einzellieder ohne Album landen in `{Interpret}/Kein Album/`.

---

## 6. „Änderungen schreiben“ (Vorgemerktes in die Dateien)

### 6.1 Ablauf

1. **Plan** (`PendingChanges::actions`): Für alle Alben mit Vormerkungen wird aus den **fertigen Tags** der Regelort
   berechnet (dieselbe Logik wie „Nicht am Regelort“).
2. **Je Titel eine Aktion:** Tags schreiben (nur die vorgemerkten Felder), danach höchstens einmal verschieben.
3. **Je Album:** Cover & Extras (Bilder, Booklets) ziehen mit, wenn alle Titel in denselben neuen Ordner wandern.
4. **Reihenfolge:** Cover in den Papierkorb → Titel → Doppel aus anderen Alben → Cover & Extras → neues `cover.jpg`.
5. **Ausführen** im Worker (`queue`), Fortschritt und Fehler im Verlauf; danach Neu-Einlesen der berührten Pfade,
   leere Ordner werden entfernt (nur leer bzw. nur `.DS_Store` & Co.; nie Kategorie-, Inbox- oder Papierkorb-Wurzel).
6. Vormerkungen, die sich während des Schreibens geändert haben, werden nicht geschrieben (bleiben vorgemerkt).

### 6.2 Schutzregeln beim Verschieben

| Regel | Was passiert |
|---|---|
| **Gleiches Ziel im Album** (z. B. `Lied.mp3` und `Lied (2).mp3`, auch nur Groß/klein verschieden) | Keiner der Titel wird verschoben → erst unter „Duplikate“ entscheiden. |
| **Album würde auf mehrere Ordner verteilt** | Nichts verschieben → erst Album angleichen. („Kein Album“ ist ausgenommen.) |
| **`Lied (2).mp3` neben `Lied.mp3` im selben Ordner** | Bleibt als gewollte Variante liegen. |
| **Ziel belegt** | Überspringen (Fehler „Ziel existiert bereits“) – nie überschreiben. |

### 6.3 Mehrere Alben mit demselben Zielordner (`AlbumMerge`)

Ordner werden dabei **ohne Groß/klein** verglichen (das NAS ist case-insensitive).

| Fall | Ergebnis |
|---|---|
| **Teile ergänzen sich** (andere Tracknummern, z. B. ein Album über mehrere Interpreten-Ordner verteilt) | Wird ein Album; Cover & Extras ziehen mit. |
| **Dasselbe Album doppelt** (gleiche Nummer, Titel beginnt gleich, Länge ±3 s) | Jedes Lied bekommt das Ziel seines Gegenstücks. Beim Schreiben vergleicht `Quality::compare` → bessere Fassung bleibt, die andere in den Papierkorb. Lieder nur in einer Kopie ergänzen das Album. Cover & Extras der aufgelösten Kopie bleiben im alten Ordner (→ „Reste“). |
| **Anderes Album mit gleichem Ordnernamen** | Bleibt, wo es ist; Tags werden geschrieben; Hinweis im Verlauf. |
| **Beim Schreiben doch nicht nachweislich dasselbe Lied** | Bleibt am alten Ort (kein „(2)“ im fremden Album). |
| **„Kein Album“-Ordner** | Je Datei: gleiches Ziel → beste Fassung, sonst bleibt sie liegen. |

Führend ist das Album, das schon im Zielordner liegt, sonst das vollständigere – danach richten sich Dateinamen.

### 6.4 Qualitätsvergleich („beste Version“)

`Quality::compare` in dieser Reihenfolge:

1. Identische Datei (MD5) → Kopie aussortieren.
2. Datei nicht lesbar → beide bleiben.
3. Eine defekt → die heile gewinnt.
4. Länge weicht > 3 s ab → beide bleiben (andere Fassung).
5. **Dasselbe Lied?** Tags (Interpret + Titel normalisiert) und Klang-Fingerabdruck (Chromaprint) müssen passen, sonst
   bleiben beide.
6. Verlustfrei schlägt verlustbehaftet; FLAC: höhere Bittiefe/Abtastrate; MP3 & Co.: Bitrate > 10 % höher.
7. Sonst gleichwertig → vorhandene bleibt, neue wird aussortiert.

---

## 7. Aufräumen – Prüfungen (5 Schritte)

Jede Prüfung zeigt Funde mit Vorschau. „Übernehmen“ merkt Tags/Ablage vor (→ „Änderungen schreiben“) bzw. führt
Datei-Aktionen (Papierkorb, ReplayGain, Songtexte …) direkt als Aktion aus. Funde lassen sich als „gewollt“ ausnehmen.

**Schritt 1 – Dateien**

| Prüfung | Regel | Behebung |
|---|---|---|
| Defekte Dateien | Länge 0 oder nicht lesbar | Papierkorb |
| Beschädigte Audiodaten | Beim kompletten Dekodieren (ffmpeg) CRC-/Frame-Fehler; per Knopf „Audiodaten prüfen“ | Papierkorb |
| Mögliche Duplikate | Gleicher Interpret+Titel+Länge, `(2)`-Dateien, gleiche Aufnahme als MP3 und FLAC. Dasselbe Lied auf verschiedenen Alben (Single/Best-of) ist gewollt. | Beste Version behalten, Rest Papierkorb |
| Uneinheitliche Endungen | `.Mp3`/`.MP3` statt `.mp3` | Endung klein (Umbenennen über Zwischennamen) |

**Schritt 2 – Künstler & Tags**

| Prüfung | Regel | Behebung |
|---|---|---|
| Kaputte Zeichen | Mojibake („KÃ¶nig“), unsichtbare Zeichen, NFD sicher behebbar; falsche Codepage / verlorene Zeichen („�“) im Zeichen-Assistenten | sichere Korrekturen |
| Gleichnamige Künstler | Name mehrfach im Verzeichnis („Adam [DE]“/„Adam [GB]“), Lied noch keiner Person zugeordnet | manuell |
| Kombi mit &, und, Komma | Wird nie ungefragt getrennt; Vorschlag, wenn alle Teile bekannt bzw. MusicBrainz den Namen kennt | manuell / Vorschlag |
| Umgedrehte Namen | „Crystals, The“ → „The Crystals“ (je nach Einstellung auch Personen) | richtig herum |
| Uneinheitliche Schreibweisen | Name laut Künstlerverzeichnis anders geschrieben (Schreibweisen/Aliase) | richtige Schreibweise |
| Kombi ohne ARTISTS | „X feat. Y“ ohne einzelne ARTISTS | ARTISTS erzeugen |
| **Album uneinheitlich** | Verschiedene Album-Namen/-Interpreten im Ordner (häufigster Wert gewinnt). **Neu:** MusicBrainz-Album-ID, Veröffentlichungs- oder Original-Datum, nach denen Navidrome Alben trennt: bei einem Teil gesetzt, beim Rest leer → einzigen Wert ergänzen; verschiedene IDs/Veröffentlichungsdaten → melden, Nutzer entscheidet. Verschiedene Original-Jahre je Titel (Sampler) sind ok. | häufigsten bzw. einzigen Wert setzen |
| Sampler-Flags | Compilation-Kategorie: Album-Interpret „Various Artists“ + Flag | Tags setzen |
| Sampler im Künstler-Ordner | Flag bzw. „V.A.“ als Album-Interpret, liegt aber bei Künstlern | Flag entfernen bzw. Editor |
| Jahr als Datum | „2003-05-12“ im Jahr-Tag | nur Jahr |
| Kategorie fehlt/passt nicht | Kommentar bzw. `MUSICWIZ_CATEGORY` ≠ Ordner-Kategorie | Kategorie setzen |
| Sortiernamen | ARTISTSORT/ALBUMARTISTSORT („Beatles, The“) nach Artikeln bzw. Verzeichnis | schreiben |
| Fehlende Tags | Interpret, Album (wo eins sein sollte), Tracknummer fehlt; „Unknown Artist“ | manuell |

**Schritt 3 – Genres**: nicht auf der Liste (→ Zuordnung), gemischt im Album (häufigstes), fehlend (Vorschlag aus
Standard-Genre des Künstlers), Electronic ohne Unterstil.

**Schritt 4 – Ablage**: *Nicht am Regelort* – Pfad weicht vom Schema ab → verschieben (mit den Schutzregeln aus 6.2;
vorgemerkte Titel kommen ohnehin mit „Änderungen schreiben“ an den Regelort).

**Schritt 5 – Abschluss**

| Prüfung | Regel |
|---|---|
| Leere Ordner und Reste | Leere Ordner, Ordner ohne Musik, Systemdateien, kaputte Ordnernamen. Bilder eines umgezogenen Albums → ins Album. **Hängengebliebene Umbenennungen → zurückbenennen.** |
| Album unvollständig | Lücken in Tracknummern oder weniger Titel als TRACKTOTAL |
| Cover fehlt/zu klein | unter eingestellter Größe (300 px) |
| Cover nicht in allen Dateien | einbetten aus anderem Titel bzw. `cover.jpg` |
| Künstler ohne Bild | Verzeichnis |
| ReplayGain fehlt | Messen (EBU R128, −18 LUFS) und schreiben |
| Songtext fehlt | LRCLIB (synchronisiert, wenn vorhanden); nicht gefunden → 30 Tage Ruhe |

**Automatik** (Einstellungen): einzelne Prüfungen je Kategorie freigeben (standardmäßig aus). Riskante (Duplikate,
Ablage, Reste, defekte Dateien) sind extra gekennzeichnet.

---

## 8. Künstlerverzeichnis

- Einträge mit Name, **Schreibweisen (Aliase)**, MusicBrainz-/Discogs-ID, Bild, bis zu zwei Standard-Genres, Sortiername,
  „Name passt“-Bestätigung. Abgelehnte Schreibweisen werden gemerkt.
- **Status eines Namens:** `ok` (genau so im Verzeichnis), `variant` (bekannte Schreibweise → Korrektur), `similar`
  (fast gleich → Vorschlag), `new` (unbekannt).
- **Trennen:** nur bei `feat.`, `ft.`, `featuring`, `vs.`, `versus` und `;` automatisch; `&`, `und`, Komma nie ungefragt.
  Gemerkte Trennungen („Beam & Yanou“ → Beam · Yanou) gelten künftig. Namen mit Trenner, die im Verzeichnis stehen
  („Spy vs Spy“), bleiben ganz. Klammern am Namensende bleiben erhalten („Was (Not Was)“).
- **Schnell erfassen:** eindeutige MusicBrainz-Treffer (Name passt, eure Lieder gefunden) gesammelt anlegen.
- Änderungen im Verzeichnis wirken sofort; betroffene Tags werden **vorgemerkt**.
- ⚠ MusicBrainz-Aliase können zu weit greifen (Beispiel: „Alex“ als Alias von Arash) – unter „Uneinheitliche
  Schreibweisen“ vor dem Schreiben prüfen.

---

## 9. Verlauf, Rückgängig, Papierkorb, Sicherheit

- Jede Aktion: Einträge `tags` (alt/neu), `move`, `trash`, Cover-Sicherung. **Rückgängig** spielt sie rückwärts ab
  und prüft, was tatsächlich passiert ist.
- **Vor dem Ausführen:** Ist ein betroffener Ordner nicht erreichbar oder leer (Share nicht eingehängt), wird nichts getan.
- **Tag schreiben:** immer in eine Kopie (`.…musicwiz-write`), prüfen, dann in einem Schritt ersetzen.
- **Nur Groß/klein umbenennen** (Endung, Ordner-Schreibweise): über den Zwischennamen `….musicwiz-tmp`. Seit 0.3.41
  wird geprüft, ob die Datei wirklich unter dem neuen Namen liegt; hängengebliebene Dateien erkennt der Scan und
  „Leere Ordner und Reste“ benennt sie zurück.
- Ordner mit korrigierter Schreibweise („Kiss“ → „KISS“) werden im Index mitgezogen.

---

## 10. Weitere Werkzeuge

| Werkzeug | Was es tut |
|---|---|
| Nachschlagen | MusicBrainz, Discogs, Deezer, Spotify, TheAudioDB, Fanart.tv (Album-/Künstlerdaten, Cover, Bilder, Genres) |
| Fingerabdruck | Chromaprint/AcoustID: Titel erkennen, „dasselbe Lied?“ beim Qualitätsvergleich |
| YouTube-Download | yt-dlp → MP3 (beste VBR) mit Tags/Cover in die Inbox; Pausen, Bandbreite, Tagesgrenze; `SOURCE=YouTube` |
| FLAC-Kandidaten | Lib-Alben nur als MP3 nach Bewertung/Bitrate, Qobuz-Suchlinks, Merkliste |
| Inbox beobachten | erkennt Änderungen und scannt (nur lesen) |
| Statistik | Zahlen zum Bestand |
| Assistent (Chat) | Claude mit den API-Werkzeugen: liest sofort, Änderungen nur als Karte zum Freigeben, schreibt nie in Dateien |
| API (`/api`) | Lesen und Vormerken von außen; schreibt nie in Dateien, kann nichts in den Papierkorb legen |
| Sicherung | Einstellungen, Genres, Künstlerverzeichnis, Ausnahmen als Datei exportieren/importieren |

Hintergrund-Worker: `queue` (Dateiaktionen, genau einer), `research` (Nachschlagen, Künstler, Audiodaten prüfen),
`downloads` (YouTube).

---

## 11. Was MusicWiz **nicht** kann (bzw. bewusst nicht tut)

- **Tags nur in MP3 und FLAC schreiben.** M4A, WMA, OGG, Opus, WAV, AIFF werden gelesen, eingeordnet und verschoben,
  aber nicht getaggt (z. B. Kategorie fehlt dauerhaft).
- **Keine Audio-Umwandlung** (kein MP3→FLAC, kein Neu-Kodieren).
- **Nichts endgültig löschen** – nur Papierkorb.
- **Navidrome-Felder** (Original-/Veröffentlichungsdatum, MusicBrainz-Album-ID) werden gelesen und auf Einheitlichkeit
  geprüft, im Album-Editor aber nicht bearbeitet; geschrieben werden sie nur über die Prüfung „Album uneinheitlich“.
  Weitere Felder, nach denen Player gruppieren (Albumversion, Katalognummer, Disc-Untertitel), werden nicht gelesen.
- **Doppelte Alben nur beim Schreiben auflösen**, wenn sie im selben Zielordner landen; sonst über „Duplikate“.
- **Keine Entscheidung über verschiedene Fassungen**: Live/Remix/Duett mit gleichem Titelanfang, aber anderem Klang
  oder anderer Länge bleiben beide (bzw. am alten Ort).
- **Keine Bearbeitung ohne Vorschau**, keine automatischen Änderungen außer freigegebener Automatik.
- **API/Assistent schreiben nie in Dateien** und legen nichts in den Papierkorb.
- **Grenzen im Betrieb:** Sehr große Pläne (~40.000 Titel) brauchen ~700 MB PHP-Speicher (`PHP_MEMORY_LIMIT` ≥ 1G);
  SQLite meldet bei gleichzeitigen Hintergrund-Jobs gelegentlich „database is locked“ (wird wiederholt, kann einzelne
  Jobs scheitern lassen); der Verlauf einer Aktion ist über die API auf 20.000 Einträge begrenzt.
- **Groß/klein:** Das NAS unterscheidet Groß/klein nicht. MusicWiz vereinheitlicht Ordner-Schreibweisen und vergleicht
  Ziele ohne Groß/klein; Dateien, die sich nur darin unterscheiden, gelten als dasselbe Ziel.
