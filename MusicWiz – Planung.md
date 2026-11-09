# MusicWiz – Planung

Nov 8, 2026 · @Andreas Vratny

## Ziel und Umfang

MusicWiz ist eine eigene Web-App, die MediaMonkey ablöst: Sie ordnet neue Musik aus der Inbox ein, bearbeitet Tags und Cover und räumt den Bestand nach einheitlichen Regeln auf.

- **Inbox:** neue Musik albumweise anzeigen, Tags und Cover prüfen, Kategorie wählen, richtig einordnen.
- **Bestand:** eingeordnete Musik durchsuchen und bearbeiten.
- **Aufräumen:** einheitliche Künstler-Schreibweisen, eine feste Genre-Liste, richtige Ablage, Duplikate und Lücken finden.
- **Interpreten sauber trennen:** Jedes Lied erscheint bei jedem beteiligten Künstler einzeln, nie unter einem Kombi-Interpreten wie „Yanou feat. Liz“.
- **Ausdrücklich kein Player:** Wiedergabe mit CarPlay und Playlisten übernimmt später ein Server wie Navidrome.

**Grundsatz: Vorerst passiert nichts automatisch.** Die App schreibt, verschiebt oder benennt nur um, wenn Andreas eine Aktion auslöst und die Vorschau bestätigt.

- Kein Hintergrund-Scan und keine Ordnerüberwachung: gescannt wird per Knopf.
- Gespeicherte Schreibweisen und erkannte Künstler sind nur Vorschläge, die Andreas übernimmt oder verwirft.
- Aufräum-Prüfungen zeigen nur Funde an; behoben wird erst nach Klick.
- Automatiken können später einzeln in den Einstellungen dazukommen, standardmäßig aus.

## Oberfläche

Eine Seitenleiste führt zu den Bereichen; die Hauptpunkte sind Inbox, Lib und Blackhole – ein Kreislauf: Inbox → Lib oder Blackhole, Lib → Blackhole, Blackhole → zurück in die Inbox.

- **Inbox:** neue Alben mit Anzahl als Badge; Album öffnen, Tags und Cover bearbeiten, Kategorie wählen, einordnen.
- **Lib:** die eingeordnete Sammlung durchsuchen und bearbeiten (im Album-Editor auch direkt ins Blackhole verschieben).
  - Filter nach Kategorie (LIB, LIB COMPILATION).
  - Navigation Buchstabe → Interpret → Album, Sampler direkt nach Album.
  - Suche über Interpret, Titel und Album.
  - Album öffnet denselben Editor wie in der Inbox; ein Album lässt sich auch in eine andere Kategorie umhängen.
- **Weitere Punkte darunter:** Künstler, Aufräumen, Verlauf, Einstellungen.

**Layout:** Die App füllt immer genau 100 % des Viewports (Tailwind `h-dvh`), die Seite selbst scrollt nie. Seitenleiste und Kopfzeile bleiben stehen; gescrollt wird nur innerhalb der Inhaltsbereiche, etwa in der Album- oder Trackliste.

**Gestaltung:** modern mit Tailwind CSS, Farben Blau, Grau, Schwarz und Violett.

| Rolle | Farbe (Tailwind) |
| --- | --- |
| Hintergrund | Schwarz bis Grau (`zinc-950`, `zinc-900`) |
| Flächen, Karten, Seitenleiste | Grau (`zinc-800`, `zinc-700`), feine Ränder |
| Text | `zinc-100`, Nebentext `zinc-400` |
| Primäre Aktionen, Auswahl, Links | Blau (`blue-500` / `blue-600`) |
| Akzente, Badges, Hervorhebungen | Violett (`violet-500`), Verlauf Blau → Violett für Highlights |

Dunkles Design als Standard, große Cover, abgerundete Ecken, dezente Übergänge.

## Technik und Betrieb

Laravel, SQLite, Livewire und Tailwind CSS reichen dafür aus; die einzige Schwachstelle, das Schreiben von Tags, lösen wir mit einem externen Tool.

- **Tags lesen:** getID3 in PHP (MP3, FLAC, eingebettete Cover).
- **Tags schreiben:** nicht in PHP, sondern per `kid3-cli` oder einem kleinen Python-Skript mit `mutagen`, aufgerufen über Laravels `Process`.
- **Betrieb:** Docker-Container hinter Traefik, verwaltet mit Dockge; das Musik-Share wird direkt eingebunden, nicht über den Mac.
- **Rechte:** Container läuft mit der UID/GID des Shares, damit neue Dateien nicht root gehören.
- **Lange Vorgänge:** Verschieben und Tag-Schreiben als Queue-Jobs (Queue auf SQLite), damit die Oberfläche nicht hängt.
- **Verlauf:** jede Aktion wird in SQLite protokolliert (alter Pfad, neuer Pfad, alte und neue Tags), damit sie rückgängig gemacht werden kann.
- **Schonend scannen:** gedrosselt und inkrementell, weil das Share am 08.11. unter Last ausgestiegen ist.
- **Extras:** Cover-Anzeige und Reinhören per HTML5-Audio.

**Entwickeln und Testen mit Demodateien:** Im Projektordner liegt der Ordner `demofiles` mit den Unterordnern `inbox`, `library` und `blackhole`. Damit wird gearbeitet und getestet; die Basis-Pfade in den Einstellungen zeigen während der Entwicklung darauf. Das echte Musik-Share wird erst eingebunden, wenn alles mit den Demodateien funktioniert.

## Bestand auf dem NAS

Das Share `music` enthält rund 15.500 Musikdateien (ca. 10.300 FLAC, 5.200 MP3) in `library` und `blackhole`, dazu `roon` mit Roon-Backups; Stand der Lese-Analyse am 08.11.2026, einige Ordner waren dabei nicht lesbar.

Bisheriges Schema, wie MediaMonkey es tatsächlich umgesetzt hat (wird durch das neue Namensschema unter Kategorien abgelöst):

| Regel | Beispiel im Bestand |
| --- | --- |
| Buchstabenordner A–Z, alles andere in `0-9` | `0-9/'D' Train`, `0-9/2 Unlimited` |
| „The“ und „Die“ werden nicht umsortiert | `T/The Beatles`, `D/Die Apostel` |
| Tracknummer wie im Tag, ohne Auffüllen | `1-5  Arrivederci`, `09  Waterloo` |
| Interpreten-Titel: 2 Leerzeichen nach der Nummer | `1-05  Titel.flac` |
| Sampler: 3 Leerzeichen nach der Nummer | `2-04   Kim Sozzi - Break Up.mp3` |
| Ohne Tracknummer nur der Titel (ca. 1.500 Dateien) | `Kein Album/Miles And Miles.mp3` |
| `:` und `/` werden zu `-` | `AC-DC`, `La Mer- Vom Tagesanbruch …` |
| Doppelte Ziele bekommen `  (2) ` | `… Megastylerz Edit (2).mp3` |
| `cover.jpg` im Albumordner | `ABBA/Blondes Have More Fun …/cover.jpg` |

Auffälligkeiten: ein leerer Ordner `blackhole/artists/-Interpret@1-/-Interpret-` (Datei ohne Interpret-Tag) und doppelte Schreibweisen wie `A-teens` / `A. Teens` oder `7T; Mike D; Damize` / `7t; Mike D.; Damize`.

## Kategorien und Zielpfade

Vier Kategorien bestimmen Zielordner und Kommentar-Tag; entschieden ist ein neues Namensschema (zweistellige Tracknummern, CD-Nummer nur bei Mehrfach-CDs, Trenner `-`, Jahr am Albumordner). Die Tabelle zeigt die Standardwerte, alle Pfade und Muster sind in der App einstellbar (siehe Einstellungen).

| Kategorie (Kommentar) | Zielpfad |
| --- | --- |
| LIB | `library/artists/{Buchstabe}/{Ordner-Interpret}/{Album} ({Jahr})/{Nr} - {Titel}.ext` |
| BLACKHOLE | `blackhole/artists/{Buchstabe}/{Ordner-Interpret}/{Album} ({Jahr})/{Nr} - {Titel}.ext` |
| LIB COMPILATION | `library/sampler/{Album-Buchstabe}/{Album} ({Jahr})/{Nr} - {Interpret} - {Titel}.ext` |
| BLACKHOLE COMPILATION | `blackhole/sampler/{Album-Buchstabe}/{Album} ({Jahr})/{Nr} - {Interpret} - {Titel}.ext` |

- **`{Nr}`:** Tracknummer immer zweistellig (`05`). Hat das Album mehrere CDs, steht die CD-Nummer davor (`2-05`), bei einer CD nicht. Mehrere CDs erkennt die App an der Gesamtzahl im Tag (z. B. `1/2`) oder daran, dass im Album verschiedene CD-Nummern vorkommen. Ohne Tracknummer entfällt ` {Nr} -  `.
- **`({Jahr})`:** fällt weg, wenn kein Jahr im Tag steht; fehlt das Album, heißt der Ordner „Kein Album“.
- **Compilation-Flag folgt immer der Kategorie:** Bei LIB COMPILATION und BLACKHOLE COMPILATION setzt die App das Flag (`TCMP=1` bzw. `COMPILATION=1`) und Album-Interpret = „Various Artists“. Bei LIB und BLACKHOLE entfernt sie das Flag; steht dann noch „Various Artists“ als Album-Interpret, weist die App darauf hin.
- **Sampler erkennen:** Compilation-Flag bei mehr als der Hälfte der Titel oder eine Schreibweise von „Various Artists“ im Album-Interpreten („V.A.“, „V/A“, „Diverse“, „Verschiedene Interpreten“, „Artistes divers“, „AA.VV.“ … – verglichen nach Normalisierung: klein, ohne Akzente, ohne Klammer-Zusatz am Ende, ohne Punkte/Bindestriche/Leerzeichen; nur bei vollständiger Gleichheit). Geprüft wird nur der Album-Interpret, der Interpret der Titel bleibt immer unverändert. Im Sampler-Ordner wird die Schreibweise auf genau „Various Artists“ gebracht (Aufräumen „Sampler ohne Various Artists oder Flag“); liegt ein so erkannter Sampler im Künstler-Ordner („Sampler im Künstler-Ordner“), ist ohne „Various Artists“-Schreibweise im Album-Interpreten (echter Name wie bei Best-of/DJ-Mix, leer, Einzellieder in „Kein Album“) nur das Flag falsch – Behebung: Flag entfernen; bei „Various Artists“-Schreibweise wird nur gemeldet und im Album-Editor entschieden. In der Inbox wird dann die Sampler-Kategorie vorausgewählt.
- **Platzhalter** wie „Unknown Artist“, „[unknown]“, „Unbekannter Interpret“ sind ein fehlendes Tag, kein Sampler: nur als „prüfen“ unter „Fehlende Tags“.
- BLACKHOLE ist der Ordner für Musik, die Andreas nicht hört. Dorthin wird nur verschoben – sonst interessiert nicht, was dort liegt: eigener Bereich mit eigenem Scan, nicht in der Lib, kein Aufräumen (auch keine Duplikate) und keine Automatiken, kein Abgleich beim Einordnen („schon in der Lib?“), zählt nicht für Künstler, Genres und Schreibweisen. Die Seite **Blackhole** bietet nur eine Suche; ein Album oder einzelne Titel lassen sich von dort zurück in die Inbox holen (Tags bleiben, als Aktion im Verlauf, rückgängig machbar). Bearbeitet wird im Blackhole nichts.
- Ohne Interpret wird nichts eingeordnet; die App fragt nach. Existiert eine Zieldatei schon, zeigt die App das vor dem Verschieben an; eine vorhandene cover.jpg wandert mit.
- **Bestand umstellen:** Die rund 15.500 vorhandenen Dateien folgen noch dem alten MediaMonkey-Schema. Die Umstellung läuft über die Aufräum-Prüfung „Datei liegt nicht am Regelort“, einmal mit Vorschau; vorher Roon-Backup, danach liest Roon neu ein.

## Einstellungen

Kein Pfad ist fest im Code: Alles wird auf einer Einstellungsseite gepflegt und in SQLite gespeichert, Startwerte kommen aus der `.env`.

- **Basis:** Wurzel des Musik-Shares im Container (z. B. `/music`).
- **Inbox:** genau ein Inbox-Ordner.
- **Feste Kategorien:** Lib und Blackhole mit je einem Ordner für Künstler und einem für Sampler; einstellbar sind Name (= Wert im Kommentar-Tag), Zielordner und Dateinamen-Muster.
- **Muster mit Platzhaltern:** `{buchstabe}` (Ordner-Interpret), `{album_buchstabe}` (Album, für Sampler – deren Interpret „Various Artists“ würde sonst alles unter `V` legen), `{ordner_interpret}`, `{album}`, `{nr}`, `{interpret}`, `{jahr}, {titel}`, `{ext}`, z. B. `{buchstabe}/{ordner_interpret}/{album} ({jahr})/{nr} - {titel}.{ext}`.
- **Weitere Werte:** Text für fehlendes Album („Kein Album“), Ordner für Nicht-Buchstaben (`0-9`), Album-Interpret für Sampler („Various Artists“), Ersatzzeichen für `:` und `/`, Papierkorb-Ordner, Ordner für Künstlerbilder, Zugangsdaten für Discogs und Fanart.tv, Genre-Liste.
- **Live-Vorschau:** Beim Ändern eines Musters zeigt die App an Beispieldateien, wie die Pfade aussehen würden.
- Ein geändertes Muster gilt für neue Einordnungen; den Bestand verschiebt die App nur, wenn Andreas das ausdrücklich mit Vorschau startet.

## Interpreten-Regeln

Entschieden: `ARTIST` bleibt der Anzeigetext, ein neues Feld `ARTISTS` listet jeden Künstler einzeln, und der Ordner wird nach dem ersten Künstler gebildet.

```
ARTIST       = Yanou feat. Liz    (Anzeige, bleibt)
ARTISTS      = Yanou
ARTISTS      = Liz
ALBUMARTIST  = Yanou
ALBUMARTISTS = Yanou
```

- **Ordner-Interpret:** erster Eintrag aus `ALBUMARTISTS`, ersatzweise `ARTISTS`. So bleibt ein Album immer in einem Ordner, und Kombi-Ordner wie `2Pac; Dr. Dre; Roger Troutman` verschwinden.
- **Navidrome:** baut die Interpretenliste aus `ARTISTS` – das Lied erscheint bei Yanou und bei Liz, „Yanou feat. Liz“ nur noch in der Titelzeile. [Navidrome Tagging Guidelines](https://www.navidrome.org/docs/usage/library/tagging)
- **Roon:** liest `ARTISTS` nicht, trennt `ARTIST` aber bei `;`. In Roon einmalig `feat.` und `ft.` als File Tag Delimiter eintragen; bei Bedarf „Prefer File“ für Interpreten setzen.
- **Automatisch trennen:** nur bei ` ;  `, `feat.` / `ft.` / `featuring` und `vs.` / `vs` / `versus` (z. B. „Apollo vs. Crystal Lake“). Namen, die so im Künstlerverzeichnis stehen, werden nie getrennt.
- **Nie automatisch trennen:** `&`, `,`, `und`, `and` (Simon & Garfunkel, Earth, Wind & Fire). Solche Fälle schlägt das Aufräumen zur Entscheidung vor.
- **MP3:** `ARTISTS` liegt in `TXXX:ARTISTS`; ob ID3v2.3 oder v2.4 genutzt wird, klärt die Tag-Prüfung.
- MediaMonkey und die Musik-App am Mac ignorieren `ARTISTS` und zeigen weiter den Anzeigetext.

**Künstlerverzeichnis für einheitliche Schreibweisen:** In SQLite steht jeder Künstler genau einmal, mit richtigem Namen, allen bekannten Varianten und optional einer MusicBrainz- oder Discogs-ID; gepflegt wird es im Seitenleisten-Punkt „Künstler“.

- **Richtige Schreibweise:** entscheidet Andreas; Vorschlag ist die häufigste Variante im Bestand. Kandidaten für dieselbe Person findet die App über einen Vergleichsschlüssel (klein geschrieben, ohne Punkte, Bindestriche, Leerzeichen und Akzente, &/and/und vereinheitlicht) und eine Ähnlichkeitssuche gegen Tippfehler.
- **Nachschlagen per Knopf:** zuerst [MusicBrainz](https://musicbrainz.org) (offen, ohne Anmeldung, ca. 1 Abfrage/s), bei keinem Treffer [Discogs](https://www.discogs.com/developers) (Token nötig, 60 Abfragen/min; Stärke bei Dance, Trance, Samplern). Discogs-Zusätze wie `Adam (2)` werden entfernt.
- **Gilt überall:** Inbox gleicht jeden Namen ab (bekannte Variante → Korrekturvorschlag, unbekannt → „neu“); `ARTISTS`, `ALBUMARTISTS`, der Anzeigetext in `ARTIST` und damit der Ordner nutzen den richtigen Namen.
- Alles bleibt Vorschlag, bis Andreas klickt. Der Container braucht für das Nachschlagen Internetzugang.

## Genres

Entschieden: eine feste, selbst gepflegte Genre-Liste; flach mit einer Ebene, nur Electronic bekommt Unterstile. In `GENRE` steht nur, was auf der Liste steht.

- **Schreiben:** Electronic-Alben bekommen zwei Einträge (`GENRE = Electronic`, `GENRE = Trance`), alles andere einen (`GENRE = Rock`). So zeigt der Server unter Electronic alles Elektronische und unter Trance nur Trance.
- **Genre pro Album**, bei Samplern pro Track erlaubt; höchstens zwei Unterstile bzw. zwei Genres in Ausnahmefällen.
- **Zuordnungstabelle:** Jeder vorhandene Genre-Wert im Bestand (z. B. `Euro House`, `Euro-Dance`, `Dance/Electronic`) wird einmal einem Listen-Genre zugeordnet und gespeichert; daraus entstehen Vorschläge für die Inbox.
- **Standard-Genre pro Künstler** im Künstlerverzeichnis als Vorschlag für neue Alben.
- **Nachschlagen per Knopf:** Genre bzw. Stil von Discogs oder MusicBrainz, über die Zuordnungstabelle in das eigene Genre übersetzt. Entscheidung immer durch Andreas.
- Unterstile lassen sich später auch für andere Genres anlegen, ohne Programmänderung.

**Vorlage für die Genre-Liste** (abgeglichen mit den Hauptgenres von Discogs/MusicBrainz und dem Bestand; eigene Genres lassen sich jederzeit ergänzen):

- Electronic
  - Trance · House · Techno · Eurodance · Dance / Pop-Dance · Euro Disco / Hi-NRG · Italo Disco · EDM / Big Room
  - Hard Dance / Hardstyle · Hardcore · Drum & Bass · Breakbeat / Big Beat · Electro / Synthpop · EBM / Dark Electro
  - Chill / Ambient / Lounge · Downtempo / Trip-Hop
- Pop · Rock · Rock 'n' Roll / Rockabilly · Hard Rock · Alternative / Indie · Metal · Punk
- Deutschrock · NDW / Deutschpop · Austropop · Schlager · Liedermacher · Chanson
- Volksmusik · Blasmusik / Marsch
- Hip-Hop · R&B / Soul · Funk / Disco · Gospel · Reggae · Latin · Weltmusik
- Jazz · Swing / Big Band · Blues · Country / Folk
- Klassik · Klassik-Crossover · Easy Listening · New Age / Instrumental
- Soundtrack · Musical · Weihnachten · Kinder · Comedy · Hörbuch / Hörspiel

Dazu kommen Start-Zuordnungen für häufige Werte aus Tags, MusicBrainz und Discogs (z. B. „Rock & Roll“, „Rockabilly“ → Rock 'n' Roll / Rockabilly; „Italo-Disco“ → Italo Disco; „Brass & Military“ → Blasmusik / Marsch).

## Künstlerbilder

Entschieden: Künstlerbilder liegen in einem zentralen, einstellbaren Ordner auf dem Share (z. B. `music/_artists/A-Teens.jpg`), benannt nach dem richtigen Namen aus dem Künstlerverzeichnis.

- **Warum zentral:** funktioniert auch für Künstler, die nur auf Samplern vorkommen und keinen eigenen Ordner haben, und für Künstler in `library` und `blackhole` zugleich.
- **Navidrome:** liest den Ordner über `ArtistImageFolder` und den Eintrag `image-folder` in `ArtistArtPriority` ([Navidrome Artwork](https://navidrome.org/docs/usage/library/artwork/)). Roon nutzt meist eigene Bilder.
- **Anzeige:** im Künstlerverzeichnis und im Bestand beim Interpreten.
- **Bild holen per Knopf:** mehrere Kandidaten zur Auswahl aus Deezer (öffentliche Schnittstelle ohne Key), Fanart.tv (kostenloser API-Key, Suche über MusicBrainz-ID) und Discogs; alternativ eigenes Bild hochladen. Andreas wählt jedes Bild selbst.
- **Umbenennen:** Ändert sich ein Name im Künstlerverzeichnis, benennt die App das Bild mit um.

## Tags und Cover bearbeiten

Entschieden: Die App ist auch Tag-Editor; Interpret, Titel, Album, Album-Interpret, Disc# und Track#, dazu Jahr und Genre (aus der Genre-Liste), sowie das Cover lassen sich anzeigen und ändern – in der Inbox und für Dateien im Bestand.

- **Album-Ansicht:** alle Tracks als bearbeitbare Tabelle; Album, Album-Interpret und Cover einmal fürs ganze Album ändern, Interpret, Titel, Disc# und Track# pro Track.
- **Mehrere Interpreten:** beim Speichern wird `ARTISTS` aus dem Interpreten-Feld erzeugt (Regeln siehe Interpreten-Regeln); gespeicherte Schreibweisen werden vorgeschlagen.
- **Hilfen:** Tracknummern fortlaufend durchnummerieren, Disc# für alle setzen.
- **Cover anzeigen:** eingebettetes Cover, sonst `cover.jpg` im Ordner.
- **Cover ändern:** Bild hochladen oder per Drag & Drop; die App bettet es in alle Tracks des Albums ein und legt `cover.jpg` im Albumordner ab.
- **Folgen für den Pfad:** Ändert eine Bearbeitung den Zielpfad (z. B. neuer Titel oder Album-Interpret), zeigt die App vor dem Speichern, wohin die Datei wandert.
- Jede Änderung läuft über das externe Tag-Tool und wird im Verlauf protokolliert, also rückgängig machbar.

## Bestand aufräumen

Der Bereich Aufräumen prüft den ganzen Bestand und zeigt je Prüfung, wie viele Funde es gibt; jeder Fund lässt sich einzeln oder gesammelt beheben, immer mit Vorschau und rückgängig machbar.

| Prüfung | Behebung |
| --- | --- |
| Datei liegt nicht dort, wo die Regeln sie hinlegen würden | an den richtigen Ort verschieben |
| Umgedrehte Namen („Crystals, The“; einstellbar: aus / nur Artikel / auch Personen) | richtig herum schreiben („The Crystals“) |
| Uneinheitliche Interpreten-Schreibweisen | richtige Schreibweise wählen, als Alias speichern |
| Kombi-Interpreten ohne `ARTISTS` | `ARTISTS` erzeugen, Ordner nach erstem Künstler |
| Kombi mit &, und, and, Komma (nicht im Verzeichnis) | nie ungefragt trennen; Vorschlag „Trennen“, wenn alle Teile im Verzeichnis bzw. einzeln eindeutig bei MusicBrainz sind, „Ein Künstler“, wenn MusicBrainz den Namen mit euren Liedern kennt (aus dem Vorladen) – einzeln oder alle auf einmal übernehmen |
| Album mit uneinheitlichem Album-Interpret oder Album-Namen | Werte fürs ganze Album vereinheitlichen (verschiedene Jahre sind kein Fund: der Ordner bekommt das Album-Jahr – das der Mehrheit, sonst das neueste; die Tags bleiben) |
| Jahr als Datum („2003-05-12“) | nur die Jahreszahl schreiben (der Album-Editor speichert ohnehin nur das Jahr) |
| Album würde beim Ablegen auf mehrere Ordner verteilt bzw. mehrere Dateien hätten dasselbe Ziel | nicht verschieben (nur Tags), Hinweis unter „Nicht am Regelort“ |
| Sampler ohne „Various Artists“ oder Compilation-Flag | Tags setzen |
| Sampler im Künstler-Ordner (Flag oder „Various Artists“-Schreibweise) | ohne „Various Artists“-Schreibweise (echter Name, leer, „Kein Album“): Compilation-Flag entfernen; „Various Artists“-Schreibweise: nur melden, im Editor entscheiden |
| Fehlende Tags: Interpret, Album (nur wo eins sein sollte: nicht in „Kein Album“, sondern wenn andere Titel im Ordner eins haben oder eine Tracknummer da ist), Tracknummer, Kategorie-Kommentar; Platzhalter wie „Unknown Artist“ | im Editor nachtragen |
| Fehlendes oder sehr kleines Cover | Cover hochladen |
| Mögliche Duplikate: gleicher Interpret, Titel und Länge, Dateien mit `  (2) `, gleiche Aufnahme als MP3 und FLAC | eine Version behalten, die andere aussortieren |
| Leere Ordner und Reste wie `-Interpret@1-`, `.DS_Store` | entfernen |
| Uneinheitliche Endungen wie `.Mp3` | umbenennen in `.mp3` |

Weitere Prüfungen: **Beschädigte Audiodaten** (per Knopf „Audiodaten prüfen“ wird jede Lib-Datei einmal komplett mit ffmpeg dekodiert, danach nur Neues und Geändertes; Behebung: Papierkorb), **Album unvollständig** (Lücken in den Tracknummern bzw. weniger Titel als `TRACKTOTAL`, nur melden), **ReplayGain fehlt** (EBU R128 über ffmpeg, Bezug −18 LUFS; gemessen wird erst beim Ausführen, Album-Wert nach Länge gewichtet) und **Songtext fehlt** (LRCLIB, synchronisiert bevorzugt, in FLAC `LYRICS` bzw. MP3 `USLT`; nicht gefundene Titel 30 Tage nicht erneut).

Dazu kommen Genre-Prüfungen: Genres, die nicht auf der Liste stehen (Behebung über die Zuordnungstabelle), Alben mit gemischten Genres, Alben ohne Genre und Electronic-Alben ohne Unterstil; außerdem Künstler ohne Bild.

- **Vormerken statt sofort schreiben (digitaler Zwilling):** Behebungen im Aufräumen, Speichern im Album-Editor (Lib-Alben: Tags, Ablage, neues/entferntes Cover) und Entscheidungen im Künstlerverzeichnis (umbenennen, zusammenführen, Schreibweise, Trennen, umgedrehte Namen) ändern nur die Datenbank – die Liste reagiert sofort. Der Seitenleisten-Punkt „Änderungen schreiben“ zeigt alles Vorgemerkte je Album und schreibt je Datei einmal die Tags und verschiebt höchstens einmal an den Ort, den die fertigen Tags ergeben (Cover und Extras mit); der neue Pfad kommt danach in die Datenbank. Verwerfen je Album oder alles. Einordnen aus der Inbox, ins Blackhole verschieben, Papierkorb, ReplayGain, Songtexte und Cover-Behebungen im Aufräumen laufen weiter sofort. Roon/Navidrome sehen Änderungen erst nach dem Schreiben.
- **Prüfen nur bei Bedarf:** nach Änderungen an einzelnen Alben werden nur diese neu geprüft; lib-weite Prüfungen (Duplikate, Genres …) erst beim Öffnen, veraltete Zahlen grau.
- **Nichts wird endgültig gelöscht:** Aussortiertes wandert in einen einstellbaren Papierkorb-Ordner auf dem Share, den Andreas selbst leert.
- Die Prüfungen laufen auf den Scan-Daten in SQLite, nicht direkt auf dem Share.

## Weitere Funktionen

- **Inbox beobachten** (Einstellung, standardmäßig aus): prüft jede Minute, ob sich Musikdateien in der Inbox geändert haben, und startet dann einen Inbox-Scan. Nur lesen.
- **Titelliste abgleichen** im Album-Editor: Titelliste eines MusicBrainz-Treffers mit dem Album vergleichen – fehlende Titel, abweichende Titelnamen, Nummern für Titel ohne Nummer; alles nur als Vorschlag.
- **Am Fingerabdruck erkennen** im Album-Editor (mit AcoustID-Schlüssel): Titel ohne Tags über AcoustID erkennen und Titel, Interpret und ggf. Album vorschlagen.
- **FLAC-Kandidaten:** Lib-Alben ohne verlustfreie Datei, sortiert nach Bewertung bzw. Bitrate, mit Links zur Qobuz-Albensuche („Interpret“: alle Alben des Interpreten, „Komplett“: Interpret + Album) (Shop einstellbar, Standard Deutschland); merken oder ausblenden (gemerkt über Interpret + Album). Reiter „Unvollständig“: Alben aus „Album unvollständig“ in jedem Format, um sie komplett nachzukaufen (Link auch im Aufräumen). Gekauftes läuft über die Inbox („bessere Version behalten“ ersetzt die MP3s). Verfügbarkeit und Preis werden nicht abgefragt – Qobuz vergibt Schnittstellen-Zugänge nur an Partner.
- **Statistik:** Kennzahlen, Vollständigkeit (Cover, Genre, ReplayGain, Songtext, Bewertung), Formate, MP3-Bitraten, Genres, Jahrzehnte, neu je Monat, Kandidaten für FLAC.

## Umsetzung in Schritten

Gebaut wird in sechs Schritten, jeweils erst mit den Demodateien; jeder Schritt ist für sich nutzbar.

1. **Grundgerüst:** Laravel-Projekt, Docker-Setup mit `dev.sh`, Seitenleiste und Layout, Einstellungsseite, Tag-Tool im Container, Verlauf.
2. **Scan:** Bestand und Inbox per Knopf in SQLite einlesen (Pfad, alle Tags, Cover), gedrosselt und inkrementell.
3. **Inbox und Editor:** Alben anzeigen, Tags und Cover bearbeiten, Kategorie wählen, Vorschau, einordnen nach dem neuen Namensschema.
4. **Bestand:** Navigation, Suche, derselbe Editor, Kategorie wechseln.
5. **Künstler und Genres:** Künstlerverzeichnis, `ARTISTS` für den Bestand, Genre-Liste und Zuordnungstabelle, Nachschlagen bei MusicBrainz und Discogs, Künstlerbilder.
6. **Aufräumen und Umstellung:** alle Prüfungen, Papierkorb; danach Roon-Backup und Umstellung der rund 15.500 Dateien auf das neue Schema.

## Offene Punkte

- [ ] Wo liegt die Inbox auf dem NAS? (nicht im Share `music`)
- [ ] Share neu verbinden und Kommentar-Tags an einzelnen MP3- und FLAC-Dateien prüfen: welches Feld nutzt MediaMonkey, wie speichert es `;`-Interpreten, ID3v2.3 oder v2.4?
- [ ] Wird Roon weiter aktiv genutzt?
- [ ] Auf welchem Server läuft der Container, und wie wird das Share dort eingebunden?

* [x] Zu tun: Im Projekt-Root ein Bash-Skript `dev.sh` anlegen, um den Container während der Entwicklung zu starten und zu stoppen.

- [ ] Discogs-Token, Fanart.tv- und AcoustID-Schlüssel anlegen.
- [ ] Genre-Liste nach dem ersten Scan mit den echten Genre-Werten abgleichen und festlegen.
- [ ] Navidrome einrichten und `ArtistImageFolder` auf den Künstlerbilder-Ordner setzen (sobald der Musikserver kommt).
