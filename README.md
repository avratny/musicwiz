# MusicWiz

Web-App zum Einlesen, Ansehen, Bearbeiten und Einsortieren einer großen Musiksammlung (MP3 und FLAC). Oberfläche auf Deutsch.

> **1.0.0-beta – Neuaufbau** mit MariaDB. Der bisherige Stand (0.3.x, SQLite) liegt unter dem Tag `v0.3.42`; seine
> Datenbank wird nicht übernommen – nach dem Umstieg wird alles neu eingelesen.

## Stand

- **Vier Bereiche** – Inbox, Lib, Blackhole, Papierkorb – je ein Ordner, getrennt einlesbar, links in der Navigation mit Anzahl.
- **Einlesen** so schnell wie möglich: Python-Leser (mutagen) in mehreren Prozessen, nur neue oder geänderte Dateien.
  Alle Tag-Felder der Datei landen roh in der Datenbank (JSON), die Grundfelder zusätzlich als Spalten.
- **Tabelle** je Bereich: nach Album gruppiert (oder flach), Cover, sortieren, suchen, je Spalte filtern.
  Doppelklick auf eine Zelle bearbeitet direkt, Klick auf ein Lied öffnet rechts das Flyout mit allen Grunddaten und Tags.
- **Änderungen** nur in der Datenbank; jede Änderung steht in `tag_changes` (Feld, alt, neu, manuell/System).
- **Player** unten, darüber der Fortschritt laufender Vorgänge (Einlesen).

## Entwicklung

```sh
./dev.sh start   # bauen, Datenbank, Migrationen, starten → http://localhost:8000
./dev.sh test    # Tests (eigene Datenbank musicwiz_test)
./dev.sh stop
```

Musik liegt lokal in `./demofiles` (im Container `/music`): `inbox/`, `library/`, `blackhole/`, `_papierkorb/`.
Ordner per `.env`: `MUSIC_INBOX`, `MUSIC_LIBRARY`, `MUSIC_BLACKHOLE`, `MUSIC_TRASH`, parallele Leser `MUSIC_SCAN_WORKERS`.

| Teil | Ort |
| --- | --- |
| Tag-Leser (Python/mutagen) | `tools/tagreader.py` |
| Einlesen → Datenbank | `app/Support/Scanner.php`, `app/Jobs/ScanLocation.php` |
| Bearbeiten + Änderungsprotokoll | `app/Support/TagEditor.php`, `app/Models/TagChange.php` |
| Tabelle, Flyout | `app/Livewire/TrackTable.php`, `resources/views/livewire/track-table.blade.php` |
| Player, Fortschritt, Zellen bearbeiten | `resources/js/app.js` |
| Cover, Audio, Fortschritt | `app/Support/Covers.php`, `app/Http/Controllers/MediaController.php` |

## Betrieb (Docker, z. B. Dockge)

`docker-compose.prod.yml` als Stack verwenden, Werte in der `.env` des Stacks:

| Variable | Bedeutung |
| --- | --- |
| `MUSICWIZ_TAG` | Image-Version, z. B. `1.0.0-beta` (ohne: `latest` – baut jeder Push auf `main`) |
| `MUSIC_SHARE` | Musik-Share auf dem Host → `/music` (darin `library/`, `blackhole/`, `_papierkorb/`, `_artists/`) |
| `INBOX_SHARE` | Inbox-Share auf dem Host → `/inbox` |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Passwörter der MariaDB (Daten unter `./db` im Stack-Ordner) |
| `DB_BUFFER_POOL` | Zwischenspeicher der Datenbank (Vorgabe `2G`; bei großer Bibliothek nicht unter `1G`) |
| `API_TOKEN` | optional: Token für die Schnittstelle `/api` (z. B. `openssl rand -hex 24`); leer = Schnittstelle ohne Token offen |
| `PUID`, `PGID`, `PGROUPS` | Besitzer der Musikdateien bzw. zusätzliche Gruppen fürs Share |
| `MUSIC_LIBRARY`, `MUSIC_BLACKHOLE`, `MUSIC_TRASH`, `MUSIC_INBOX` | andere Ordner im Container (optional) |

Container: `db`, `app` (Oberfläche, migriert beim Start), `queue` (Einlesen, Schreiben, ReplayGain, Fingerabdrücke –
genau einer), `lookup` (Abgleich der Interpreten), `images` (Vorschaubilder). Schlüssel, Cover-Speicher und Logs
liegen unter `./data`.

## Schnittstelle (`/api`)

Zum Nachsehen und Handeln von außen (z. B. eine KI): `GET /api` listet ohne Token alle Aufrufe sowie jede Seite mit ihren
Aktionen und Parametern. Standardmäßig ohne Token; ist `API_TOKEN` gesetzt, braucht alles andere
`Authorization: Bearer <API_TOKEN>`.

- Lesen: Status (Vorgänge, Warteschlangen, fehlgeschlagene Jobs), Protokoll, Lieder, Änderungen, Interpreten, Nur-Lese-SQL.
- Tags ändern bzw. zurücksetzen wie von Hand (Grund im Änderungsprotokoll, nur in der Datenbank).
- `/api/ui/{seite}`: Seite aufbauen, Eigenschaften setzen, Aktionen aufrufen – derselbe Code wie ein Klick. Zurück
  kommen Stand, Meldungen und die Seite als Text. Zugangsdaten werden nie herausgegeben.

```sh
curl 'http://nas:8000/api/ui/track-table?mount[slug]=inbox'
curl -H 'Content-Type: application/json' http://nas:8000/api/ui/write-page \
     -d '{"call": [{"method": "write", "params": [null]}]}'
```
