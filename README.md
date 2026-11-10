# MusicWiz

Web-App zum Einordnen, Taggen und Aufräumen einer großen Musiksammlung (MP3, FLAC, …) – gedacht als Ablöse für MediaMonkey.
Läuft als Docker-Stack, z. B. in [Dockge](https://github.com/louislam/dockge). Oberfläche auf Deutsch.

> **Beta.** Vor dem ersten Einsatz auf dem echten Bestand: Sicherung der Musik (bzw. Roon-Backup) anlegen.

**Grundsatz: Nichts passiert automatisch.** Gescannt wird per Knopf; geschrieben, verschoben oder umbenannt wird erst nach
bestätigter Vorschau. Jede Aktion steht im Verlauf und lässt sich rückgängig machen. Aussortiertes landet im Papierkorb, nie direkt gelöscht.

## Funktionen

- **Inbox** – neue Alben einlesen, Tags prüfen und korrigieren (MusicBrainz, Discogs, Cover), nach festem Namensschema in den Bestand einordnen.
  Mehrfach-CDs werden zusammengefasst, vorhandene Alben und bessere Fassungen erkannt.
- **Lib** – Übersicht über die eingeordnete Sammlung, Album-Editor mit Vorschau; Alben lassen sich von hier direkt ins Blackhole verschieben.
- **Blackhole** – Musik, die nicht gehört wird: eigener Scan, nur Suche; Album oder Titel lassen sich zurück in die Inbox holen.
- **Künstler** – Künstlerverzeichnis mit Schreibweisen, MusicBrainz-/Discogs-ID, Bild und Standard-Genre; gleichnamige Künstler
  auseinanderhalten; neue Namen aus dem Bestand Schritt für Schritt erfassen. „Schnell erfassen“ legt eindeutige
  MusicBrainz-Treffer (Name passt, eure Lieder gefunden) gesammelt an.
  Kombi-Prüfung (Act oder Zusammenarbeit?) nach den bei Deezer, Spotify und MusicBrainz eingetragenen Künstlern;
  Bilder, Genres und Biografie auch von Spotify und TheAudioDB (Zugangsdaten in den Einstellungen).
- **Aufräumen** – Prüfungen in fünf Schritten (Defekte Dateien, Duplikate, Schreibweisen, Genres, Cover, Ablageort, Reste …),
  jeweils mit Vorschau und „Übernehmen“. Tags und Ablage werden erst vorgemerkt (Datenbank) und mit „Änderungen schreiben“
  gesammelt in die Dateien geschrieben – je Datei einmal Tags, höchstens einmal verschieben. Das gilt auch fürs Speichern
  im Album-Editor (Lib); Einordnen aus der Inbox schreibt sofort. Einzelne Prüfungen lassen sich als Automatik freigeben (standardmäßig alles aus).
- **YouTube-Download** – Ton als MP3 (beste VBR-Stufe, mit Tags und Cover) direkt in die Inbox; einstellbare Pausen,
  Bandbreite und Tagesgrenze, damit YouTube nicht sperrt. Herkunft steht im Tag `SOURCE`.
- **FLAC-Kandidaten** – Lib-Alben nur als MP3, nach Bewertung bzw. Bitrate; Suchlinks zu Qobuz (Interpret / Komplett), Merkliste.
- **Extras** – ReplayGain messen und schreiben, Songtexte (LRCLIB), beschädigte Audiodaten finden, unvollständige Alben,
  Titelliste mit MusicBrainz abgleichen, Titel per Fingerabdruck erkennen (AcoustID), Inbox beobachten, Statistik.
- **Verlauf & Papierkorb** – jede Dateiänderung mit alten/neuen Pfaden und Tags, rückgängig machbar; Papierkorb mit Wiederherstellen.
- **Einstellungen** – Pfade, Kategorien und Namensschema, Genre-Liste und -Zuordnung, Sicherung/Wiederherstellung als Datei.

## Installation mit Dockge

Das Image wird bei jedem Push von GitHub Actions gebaut: `ghcr.io/avratny/musicwiz` (x86-64/amd64).

1. In Dockge **„+ Compose“**, Stack-Name z. B. `musicwiz`.
2. Als `compose.yaml` den Inhalt von [`docker-compose.prod.yml`](docker-compose.prod.yml) einfügen.
3. Im **.env**-Feld des Stacks die eigenen Werte eintragen:

   ```env
   # Musik-Share auf dem Server (→ /music im Container)
   MUSIC_SHARE=/mnt/music
   # Inbox, falls sie nicht im Musik-Share liegt (→ /inbox); sonst weglassen und in den Einstellungen /music/… eintragen
   INBOX_SHARE=/mnt/inbox
   # Besitzer des Shares (ls -ln /mnt/music) – neue und verschobene Dateien gehören dann diesem Benutzer
   PUID=1000
   PGID=1000
   # optional: weitere Gruppen, über die der Benutzer aufs Share darf (z. B. 100,1001)
   # PGROUPS=
   # Port im LAN
   MUSICWIZ_PORT=8000
   # Adresse, unter der MusicWiz erreichbar ist
   APP_URL=http://nas.local:8000
   # optional: Speichergrenze je PHP-Prozess (Standard 512M; bei viel RAM z. B. 2G)
   # PHP_MEMORY_LIMIT=512M
   # optional: Zeitgrenze je Seitenaufruf in Sekunden (Standard 120; Scans und Aktionen laufen im Worker ohne Grenze)
   # PHP_MAX_EXECUTION_TIME=120
   # optional: gleichzeitige Leseprozesse beim Scan (Standard 4)
   # MUSIC_SCAN_WORKERS=4
   ```

4. **Deploy.** Danach läuft MusicWiz unter `http://<server>:8000`.
5. In der App unter **Einstellungen** Basisordner, Inbox, Papierkorb, Kategorien und Namensschema prüfen, dann **Inbox → Scannen**.

Der Stack besteht aus drei Containern aus demselben Image: `app` (Oberfläche), `queue` (Verschieben, Tags schreiben, Scans)
und `downloads` (YouTube). Der Anwendungsschlüssel (`APP_KEY`) wird beim ersten Start erzeugt und in `./data` abgelegt.

### Daten und Sicherung

Alles, was MusicWiz selbst speichert, liegt im Stack-Ordner unter `./data`: SQLite-Datenbank, Schlüssel, Sicherungen fürs
Rückgängigmachen (alte Cover und Tags), Cache. Diesen Ordner mitsichern. Einstellungen, Künstlerverzeichnis, Genre-Liste und
Ausnahmen lassen sich zusätzlich unter **Einstellungen → Sicherung** als Datei exportieren.

### Update

In Dockge beim Stack **„Update“** (zieht das neueste Image und startet neu). Die Datenbank wird beim Start automatisch
aktualisiert. Für eine feste Version statt `latest` in der `.env` z. B. `MUSICWIZ_TAG=0.3` (immer die neueste 0.3.x)
bzw. `MUSICWIZ_TAG=0.3.10` setzen.

Update auf 0.3: Der nächste Scan liest einmal alle Dateien neu ein (neue Felder ReplayGain und Songtext) – dank paralleler
Leseprozesse schneller als bisher (`MUSIC_SCAN_WORKERS`, Standard 4). Sampler bekommen Buchstabenordner nach dem Album
(`sampler/B/Bravo Hits …`); gespeicherte Standard-Muster werden umgestellt, verschoben wird erst über „Aufräumen“.

Update auf 0.2: Der nächste Scan liest einmal alle Dateien neu ein (neue Felder Bewertung und Herkunft) – das dauert je nach
Sammlung eine Weile; Aufräum-Automatiken laufen erst nach einem vollständigen Scan.

yt-dlp lässt sich zwischendurch auf der Seite **YouTube-Download** aktualisieren (YouTube ändert häufig etwas);
mit dem nächsten Image-Update kommt ohnehin die aktuelle Version.

### Hinter Traefik

Statt `ports` die üblichen Labels beim Dienst `app` ergänzen (interner Port **8080**) und das Traefik-Netz einbinden, z. B.:

```yaml
    labels:
      - traefik.enable=true
      - traefik.http.routers.musicwiz.rule=Host(`musicwiz.example.lan`)
      - traefik.http.services.musicwiz.loadbalancer.server.port=8080
    networks: [traefik, default]
```

### Befehle im Container

Artisan-Befehle als Share-Benutzer ausführen (sonst entstehen Dateien, die root gehören):

```sh
docker compose exec -u 1000:1000 app php artisan musicwiz:scan inbox
```

## Assistent (Chat)

Runder Knopf unten rechts auf jeder Seite: Chat mit Claude über die Werkzeuge der Schnittstelle (lesen, vormerken,
Künstlerverzeichnis). Lesen läuft sofort, **Änderungen erscheinen als Karte mit „Ausführen“ / „Ablehnen“**; in die
Dateien schreibt der Assistent nie. Unter jeder Antwort stehen die geschätzten Kosten, oben die Summe der Unterhaltung.
Einrichten: Einstellungen › Zugangsdaten › Anthropic-API-Key (console.anthropic.com, Ausgabenlimit dort beim Key) und
Modell (Standard Claude Opus 5.5). Gesendet werden Namen, Titel, Pfade und Tags – keine Musikdateien.

## Schnittstelle (API)

Unter `/api` (z. B. `http://10.1.1.5:8000/api`) lässt sich der Bestand als JSON abfragen und Änderungen vormerken – etwa
für eine Analyse von außen. **In die Dateien geschrieben wird darüber nie**, nur mit „Änderungen schreiben“ in der App.
Ohne Anmeldung: nur im eigenen Netz erreichbar machen. `GET /api` listet alle Aufrufe. Jede Änderung über die
Schnittstelle steht im Protokoll („Schnittstelle: …“).

| Aufruf | Zweck |
|---|---|
| `GET /api/checks`, `GET /api/checks/{key}` | Prüfungen und ihre Funde (`?fresh=1` rechnet neu) |
| `POST /api/checks/{key}/stage` | Behebungen vormerken (`{"ids": [...]}` bzw. `{"all": true}`; nur Tags/Ablage) |
| `GET /api/pending` | alles Vorgemerkte inkl. geplanter Aktionen (wie der JSON-Export) |
| `POST /api/pending/stage`, `POST /api/pending/discard` | eigene Tag-Änderungen vormerken bzw. Vormerkungen verwerfen |
| `GET /api/albums`, `/api/albums/{id}`, `/api/tracks`, `/api/artists`, `/api/history`, `/api/stats` | lesen |
| `POST /api/artists`, `/api/artists/{id}` (`/rename`, `/merge`, `/aliases`, `/split`) | Künstlerverzeichnis pflegen wie in der App – wirkt sofort, Tags werden nur vorgemerkt |
| `GET/POST /api/quick`, `POST /api/quick/split`, `GET/POST /api/combined` | Schnell erfassen und Kombi-Vorschläge |
| `GET /api/status` | laufende Vorgänge, Warteschlange, fehlgeschlagene Jobs, Speicherplatz, Ordnerrechte, PHP-Grenzen |
| `GET /api/logs?entries=50&level=warning` | Fehlerprotokoll (neueste zuerst, Zugangsdaten geschwärzt) |
| `GET /api/history/{id}?errors=1` | eine Aktion mit allen Einträgen bzw. nur Fehlern/Hinweisen |

Das Protokoll liegt im Betrieb zusätzlich zu den Docker-Logs in `data/logs/musicwiz-JJJJ-MM-TT.log` (7 Tage).

## Entwicklung

Voraussetzung: Docker. PHP, Composer und Node laufen in Containern (lokal ist kein PHP nötig).

```sh
./dev.sh start      # bauen, Datenbank anlegen, starten → http://localhost:8000
./dev.sh stop
./dev.sh logs       # Logs verfolgen
./dev.sh test       # Tests (inkl. Ende-zu-Ende: Scan → Einordnen → Rückgängig)
./dev.sh artisan musicwiz:scan inbox|library|blackhole   # Scan wie der Knopf in der App
./dev.sh shell
docker compose exec app vendor/bin/pint       # Code formatieren
```

In der Entwicklung ist `./demofiles` im Container als `/music` eingebunden (`inbox`, `library`, `blackhole`); der Ordner ist
nicht im Repository. Container: `app` (FrankenPHP), `queue` und `downloads` (Jobs, Queue auf SQLite), `assets` (Vite/Tailwind im Watch-Modus).

Stack: Laravel 13, Livewire 4, Tailwind 4, SQLite; Tags über mutagen (`tools/tagtool.py`) und getID3; yt-dlp + ffmpeg für YouTube.

### Aufbau

| Teil | Ort |
| --- | --- |
| Tag-Tool (Schreiben und Lesen der Tag-Felder, mutagen) | `tools/tagtool.py` |
| Technik & Cover lesen (getID3), Scan gedrosselt/inkrementell | `app/Support/TagReader.php`, `Scanner.php` |
| Namensschema / Zielpfade | `app/Support/PathBuilder.php` |
| Interpreten-Regeln, Künstlerverzeichnis | `app/Support/ArtistNames.php`, `ArtistDirectory.php` |
| Editor-Vorschau → Aktionen | `app/Support/AlbumPlanner.php` |
| Ausführen + Verlauf + Rückgängig | `app/Support/OperationRunner.php`, `FileOps.php` |
| Aufräum-Prüfungen | `app/Support/CleanupChecks.php` |
| Nachschlagen (MusicBrainz, Discogs, Deezer, Fanart.tv) | `app/Support/Lookup.php` |
| YouTube-Download (yt-dlp) | `app/Support/YouTube.php` |
| Oberfläche (Livewire) | `app/Livewire`, `resources/views/livewire` |
| Image und Start im Betrieb | `docker/Dockerfile` (Ziel `prod`), `docker/entrypoint.sh` |

Tag-Felder lesen und schreiben beide über das Tag-Tool, damit `TXXX:ARTISTS` und der Kommentar ohne Beschreibung
(`COMM::XXX`, wie MediaMonkey) zuverlässig gleich behandelt werden; getID3 liefert Länge, Bitrate und Cover.
MP3 wird als ID3v2.4 geschrieben (einstellbar). Planung und Anforderungen: [`MusicWiz – Planung.md`](MusicWiz%20–%20Planung.md).

### Release

Push auf `main` baut `ghcr.io/avratny/musicwiz:latest`. Für eine Version ein Tag pushen:

```sh
git tag v0.3.10 && git push origin v0.3.10   # → Image :0.3.10 und :0.3
```
