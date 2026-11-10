<?php

namespace App\Support\Chat;

use App\Http\Controllers\ApiController;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Operation;
use App\Models\PendingChange;
use App\Support\Lookup;
use App\Support\PendingChanges;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Werkzeuge des Assistenten – dieselben Funktionen wie die Schnittstelle (/api), direkt aufgerufen. Lesende Werkzeuge
 * laufen sofort; ändernde (vormerken, Künstlerverzeichnis) brauchen die Freigabe des Nutzers. In die Dateien schreiben
 * kann der Assistent nicht – dafür gibt es kein Werkzeug.
 */
class ChatTools
{
    /** Höchstlänge eines Werkzeug-Ergebnisses (Zeichen) – große Listen werden gekürzt */
    private const MAX_RESULT = 30000;

    /**
     * name => [Beschreibung, Eingabeschema (properties), Pflichtfelder, ändert?, Anzeige-Text]
     *
     * @return array<string, array{0: string, 1: array, 2: list<string>, 3: bool, 4: string}>
     */
    private static function catalog(): array
    {
        $str = fn (string $d) => ['type' => 'string', 'description' => $d];
        $int = fn (string $d) => ['type' => 'integer', 'description' => $d];
        $bool = fn (string $d) => ['type' => 'boolean', 'description' => $d];
        $list = fn (string $d, array $items = ['type' => 'string']) => ['type' => 'array', 'items' => $items, 'description' => $d];

        return [
            // ---------------------------------------------------------------- lesen
            'stats' => ['Zahlen zum Bestand: Titel, Alben, Sampler, Formate, ohne Jahr/Genre/Cover, Künstler, Vorgemerktes.', [], [], false, 'liest Zahlen zum Bestand'],
            'status' => ['Was läuft gerade (Scan, Aktionen, Warteschlange, fehlgeschlagene Jobs, Kombi-Prüfung), Speicherplatz, Ordnerrechte, PHP-Grenzen – für die Fehlersuche.', [], [], false, 'prüft den Systemstatus'],
            'logs' => ['Fehlerprotokoll, neueste Einträge zuerst.', ['level' => $str('debug|info|warning|error (Mindeststufe, Standard warning)'), 'entries' => $int('Anzahl (Standard 20, max. 100)')], [], false, 'liest das Protokoll'],
            'checks' => ['Alle Prüfungen im Aufräumen mit Anzahl Funde, Schritt und ob der Stand aktuell ist.', [], [], false, 'liest die Prüfungen'],
            'check_findings' => ['Funde einer Prüfung (Schlüssel aus „checks“) mit geplanten Aktionen. Seitenweise mit limit/offset.', ['key' => $str('Prüfung, z. B. misplaced, spelling, combined_manual'), 'limit' => $int('Standard 30'), 'offset' => $int('Standard 0'), 'fresh' => $bool('jetzt neu rechnen (langsam)')], ['key'], false, 'liest Funde'],
            'pending_overview' => ['Vorgemerkte Änderungen (noch nicht in den Dateien): Summen und je Album die ersten Titel mit Feld bisher → neu und Ziel.', ['albums' => $int('Anzahl Alben (Standard 20)')], [], false, 'liest Vorgemerktes'],
            'pending_album' => ['Alle vorgemerkten Änderungen eines Albums mit den geplanten Aktionen (Tags, Verschieben, Cover).', ['album_id' => $int('Album-ID')], ['album_id'], false, 'liest Vorgemerktes eines Albums'],
            'albums_search' => ['Alben suchen (Titel, Album-Interpret, Ordner).', ['q' => $str('Suchtext'), 'location' => $str('library|inbox (Standard library)'), 'limit' => $int('Standard 30')], [], false, 'sucht Alben'],
            'album' => ['Ein Album mit allen Titeln, Tags und Vormerkungen.', ['id' => $int('Album-ID')], ['id'], false, 'liest ein Album'],
            'tracks_search' => ['Titel suchen (Interpret, Titel, Album, Pfad).', ['q' => $str('Suchtext'), 'location' => $str('library|inbox'), 'limit' => $int('Standard 50')], ['q'], false, 'sucht Titel'],
            'artists_search' => ['Künstlerverzeichnis durchsuchen (Name und Schreibweisen).', ['q' => $str('Suchtext (leer = alle)'), 'limit' => $int('Standard 50')], [], false, 'sucht im Künstlerverzeichnis'],
            'artist' => ['Ein Eintrag im Künstlerverzeichnis: Schreibweisen, Titel im Bestand, Trennvorschlag, Mitglieder laut MusicBrainz, Kombi-Prüfung, TheAudioDB.', ['id' => $int('Künstler-ID')], ['id'], false, 'liest einen Künstler'],
            'quick_list' => ['„Schnell erfassen“: noch nicht erfasste Namen mit MusicBrainz-Treffer, Kombi-Prüfung und Bildvorschlägen.', ['kind' => $str('sure|name|unsure|pending (Standard sure)'), 'limit' => $int('Standard 30'), 'offset' => $int('Standard 0')], [], false, 'liest „Schnell erfassen“'],
            'combined_list' => ['Kombi-Namen (&, und, Komma …) aus dem Aufräumen mit Vorschlag (trennen / ein Künstler).', [], [], false, 'liest Kombi-Namen'],
            'combi_result' => ['Gespeichertes Ergebnis der Kombi-Prüfung eines Namens: Act oder Zusammenarbeit, mit den je Dienst eingetragenen Künstlern.', ['name' => $str('Kombi-Name')], ['name'], false, 'liest eine Kombi-Prüfung'],
            'combi_check' => ['Kombi-Prüfung jetzt ausführen (fragt Deezer, Spotify, MusicBrainz; dauert ein paar Sekunden je Name; max. 10).', ['names' => $list('Kombi-Namen'), 'fresh' => $bool('auch schon geprüfte neu')], ['names'], false, 'prüft Kombi-Namen bei den Diensten'],
            'wikipedia_search' => ['Wikipedia durchsuchen (frei). Für Künstler mit MusicBrainz-ID besser wikipedia_article mit artist_id – dann ist es sicher der richtige Artikel.', ['query' => $str('Suchtext'), 'lang' => $str('de|en (Standard de)')], ['query'], false, 'sucht bei Wikipedia'],
            'wikipedia_article' => ['Wikipedia-Artikel als Text (Einleitung und erste Abschnitte, gekürzt) – z. B. ob ein Duo ein fester Act ist und wer dazugehört. Entweder title (+ lang) oder artist_id (Eintrag im Verzeichnis: Artikel über die MusicBrainz-Verknüpfung).', ['title' => $str('Artikeltitel'), 'lang' => $str('de|en'), 'artist_id' => $int('Künstler-ID im Verzeichnis')], [], false, 'liest bei Wikipedia'],
            'discogs_artist' => ['Künstlerprofil bei Discogs: echter Name, Profil, Mitglieder (Gruppen), „In Groups“ (Einzelkünstler), Aliasse – sehr aussagekräftig für Act oder Zusammenarbeit, auch bei kleinen DJs. Über artist_id (Verzeichnis), discogs_id oder name.', ['name' => $str('Name'), 'discogs_id' => $str('Discogs-ID'), 'artist_id' => $int('Künstler-ID im Verzeichnis')], [], false, 'liest bei Discogs'],
            'history' => ['Letzte Aktionen im Verlauf (z. B. „Änderungen schreiben“) mit Status.', ['limit' => $int('Standard 15')], [], false, 'liest den Verlauf'],
            'history_detail' => ['Eine Aktion aus dem Verlauf mit ihren Einträgen (verschoben, beschrieben, übersprungen).', ['id' => $int('Aktions-ID'), 'errors' => $bool('nur Fehler/Hinweise'), 'limit' => $int('Standard 200')], ['id'], false, 'liest eine Aktion aus dem Verlauf'],

            // ---------------------------------------------------------------- ändern (Freigabe nötig)
            'stage_findings' => ['Behebungen einer Prüfung vormerken (nur Tags/Ablage; geschrieben wird erst mit „Änderungen schreiben“).', ['key' => $str('Prüfung'), 'ids' => $list('Fund-IDs, z. B. a12'), 'all' => $bool('alle Funde')], ['key'], true, 'Behebungen vormerken'],
            'stage_tags' => ['Eigene Tag-Änderungen vormerken. Felder siehe tag_keys der Schnittstelle (artist, artists, albumartist, album, year, genres, title, track, disc, compilation, comment …).', ['label' => $str('Begründung (erscheint bei „Änderungen schreiben“)'), 'changes' => $list('Änderungen', ['type' => 'object', 'properties' => ['track_id' => ['type' => 'integer'], 'path' => ['type' => 'string'], 'tags' => ['type' => 'object']], 'required' => ['tags']])], ['changes'], true, 'Tags vormerken'],
            'discard_pending' => ['Vormerkungen verwerfen (Dateien bleiben unverändert).', ['album_ids' => $list('Alben', ['type' => 'integer']), 'track_ids' => $list('Titel', ['type' => 'integer']), 'all' => $bool('alle')], [], true, 'Vormerkungen verwerfen'],
            'artist_create' => ['Künstler im Verzeichnis anlegen bzw. ergänzen (Tags betroffener Titel werden vorgemerkt).', ['name' => $str('Name'), 'mbid' => $str('MusicBrainz-ID'), 'genre' => $str('Standard-Genre (von der Genre-Liste)'), 'aliases' => $list('weitere Schreibweisen'), 'image_url' => $str('Bild-URL')], ['name'], true, 'Künstler anlegen'],
            'artist_update' => ['Felder eines Eintrags setzen.', ['id' => $int('Künstler-ID'), 'mbid' => $str('MusicBrainz-ID'), 'discogs_id' => $str('Discogs-ID'), 'genre' => $str('Standard-Genre')], ['id'], true, 'Künstler ändern'],
            'artist_rename' => ['Eintrag umbenennen (alter Name wird Schreibweise; gibt es den Namen schon, wird zusammengeführt).', ['id' => $int('Künstler-ID'), 'name' => $str('neuer Name')], ['id', 'name'], true, 'Künstler umbenennen'],
            'artist_merge' => ['„Ist derselbe wie …“: andere Einträge gehen in diesem auf.', ['id' => $int('Ziel-ID'), 'ids' => $list('aufzunehmende IDs', ['type' => 'integer'])], ['id', 'ids'], true, 'Künstler zusammenführen'],
            'artist_alias_add' => ['Schreibweise hinzufügen.', ['id' => $int('Künstler-ID'), 'alias' => $str('Schreibweise')], ['id', 'alias'], true, 'Schreibweise hinzufügen'],
            'artist_alias_remove' => ['Schreibweise entfernen (wird gemerkt, kommt von MusicBrainz nicht wieder).', ['id' => $int('Künstler-ID'), 'alias' => $str('Schreibweise')], ['id', 'alias'], true, 'Schreibweise entfernen'],
            'artist_split' => ['Eintrag ist eine Zusammenarbeit: in Einzelkünstler trennen (Eintrag wird herausgenommen, ARTISTS vorgemerkt).', ['id' => $int('Künstler-ID'), 'parts' => $list('Einzelkünstler (Standard: aus Kombi-Prüfung bzw. Name)')], ['id'], true, 'in Einzelkünstler trennen'],
            'quick_create' => ['Aus „Schnell erfassen“ anlegen (nur Namen der Art sure/name).', ['picks' => $list('Auswahl', ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'mb_spelling' => ['type' => 'boolean'], 'image' => ['type' => 'string']], 'required' => ['name']])], ['picks'], true, 'Künstler anlegen (Schnell erfassen)'],
            'quick_split' => ['Offenen Kombi-Namen (nicht im Verzeichnis) trennen.', ['name' => $str('Kombi-Name'), 'parts' => $list('Einzelkünstler')], ['name'], true, 'Kombi-Namen trennen'],
            'combined_apply' => ['Kombi-Vorschläge übernehmen (trennen bzw. als ein Künstler anlegen).', ['names' => $list('Namen'), 'all' => $bool('alle Vorschläge')], [], true, 'Kombi-Vorschläge übernehmen'],
            'combi_start' => ['Kombi-Prüfung aller Kombi-Namen im Hintergrund starten.', ['fresh' => $bool('auch schon geprüfte neu')], [], true, 'Kombi-Prüfung starten'],
        ];
    }

    /** Werkzeug-Definitionen für die API (stabile Reihenfolge – wichtig für den Zwischenspeicher). */
    public static function definitions(): array
    {
        $out = [];
        foreach (self::catalog() as $name => [$description, $props, $required, $mutating]) {
            $out[] = ['name' => $name, 'description' => $description.($mutating ? ' Braucht die Freigabe des Nutzers.' : ''),
                'inputSchema' => ['type' => 'object', 'properties' => $props ?: new \stdClass, 'required' => $required]];
        }

        return $out;
    }

    public static function mutating(string $name): bool
    {
        return (bool) (self::catalog()[$name][3] ?? false);
    }

    public static function label(string $name): string
    {
        return self::catalog()[$name][4] ?? $name;
    }

    /** Werkzeug ausführen; Ergebnis als (ggf. gekürzter) JSON-Text. */
    public function run(string $name, array $input): array
    {
        if (! isset(self::catalog()[$name])) {
            return ['ok' => false, 'text' => 'Unbekanntes Werkzeug: '.$name];
        }
        try {
            $data = $this->dispatch($name, $input);
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'text' => 'Fehler: '.$e->getMessage()];
        }
        $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
        if (mb_strlen($text) > self::MAX_RESULT) {
            $text = mb_substr($text, 0, self::MAX_RESULT).' … [gekürzt – mit limit/offset bzw. gezielter abfragen]';
        }

        return ['ok' => ! isset($data['error']), 'text' => $text];
    }

    private function dispatch(string $name, array $in): array
    {
        $id = fn () => (int) ($in['id'] ?? 0);

        return match ($name) {
            'stats' => $this->api('stats'),
            'status' => $this->api('status'),
            'logs' => $this->api('logs', ['level' => $in['level'] ?? 'warning', 'entries' => min(100, (int) ($in['entries'] ?? 20))]),
            'checks' => $this->api('checks'),
            'check_findings' => $this->api('check', ['limit' => (int) ($in['limit'] ?? 30), 'offset' => (int) ($in['offset'] ?? 0), 'fresh' => (bool) ($in['fresh'] ?? false)], ['key' => (string) $in['key']]),
            'pending_overview' => $this->pendingOverview((int) ($in['albums'] ?? 20)),
            'pending_album' => $this->pendingAlbum((int) $in['album_id']),
            'albums_search' => $this->api('albums', ['q' => $in['q'] ?? '', 'location' => $in['location'] ?? 'library', 'limit' => (int) ($in['limit'] ?? 30)]),
            'album' => $this->api('album', [], ['album' => Album::findOrFail($id())]),
            'tracks_search' => $this->api('tracks', ['q' => $in['q'] ?? '', 'location' => $in['location'] ?? 'library', 'limit' => (int) ($in['limit'] ?? 50)]),
            'artists_search' => $this->api('artists', ['q' => $in['q'] ?? '', 'limit' => (int) ($in['limit'] ?? 50)]),
            'artist' => $this->api('artist', [], ['artist' => Artist::findOrFail($id())]),
            'quick_list' => $this->api('quick', ['kind' => $in['kind'] ?? 'sure', 'limit' => (int) ($in['limit'] ?? 30), 'offset' => (int) ($in['offset'] ?? 0)]),
            'combined_list' => $this->api('combined'),
            'combi_result' => $this->api('combi', ['name' => (string) $in['name']]),
            'combi_check' => $this->api('combiCheck', ['names' => (array) $in['names'], 'fresh' => (bool) ($in['fresh'] ?? false)], [], 'POST'),
            'wikipedia_search' => ['results' => app(Lookup::class)->wikipediaSearch((string) $in['query'], (string) ($in['lang'] ?? 'de'))],
            'wikipedia_article' => $this->wikipedia($in),
            'discogs_artist' => $this->discogs($in),
            'history' => $this->api('history', ['limit' => (int) ($in['limit'] ?? 15)]),
            'history_detail' => $this->api('historyEntry', ['errors' => (bool) ($in['errors'] ?? false), 'limit' => (int) ($in['limit'] ?? 200)], ['operation' => Operation::findOrFail($id())]),

            'stage_findings' => $this->api('stageFindings', ['ids' => (array) ($in['ids'] ?? []), 'all' => (bool) ($in['all'] ?? false)], ['key' => (string) $in['key']], 'POST'),
            'stage_tags' => $this->api('stage', ['label' => $in['label'] ?? 'Assistent', 'changes' => (array) $in['changes']], [], 'POST'),
            'discard_pending' => $this->api('discard', array_filter(['album_ids' => $in['album_ids'] ?? null, 'track_ids' => $in['track_ids'] ?? null, 'all' => $in['all'] ?? null], fn ($v) => $v !== null), [], 'POST'),
            'artist_create' => $this->api('createArtist', array_filter(['name' => $in['name'], 'mbid' => $in['mbid'] ?? null, 'genre' => $in['genre'] ?? null, 'aliases' => $in['aliases'] ?? null, 'image_url' => $in['image_url'] ?? null], fn ($v) => $v !== null), [], 'POST'),
            'artist_update' => $this->api('updateArtist', array_filter(['mbid' => $in['mbid'] ?? null, 'discogs_id' => $in['discogs_id'] ?? null, 'genre' => $in['genre'] ?? null], fn ($v) => $v !== null), ['artist' => Artist::findOrFail($id())], 'POST'),
            'artist_rename' => $this->api('renameArtist', ['name' => $in['name']], ['artist' => Artist::findOrFail($id())], 'POST'),
            'artist_merge' => $this->api('mergeArtists', ['ids' => (array) $in['ids']], ['artist' => Artist::findOrFail($id())], 'POST'),
            'artist_alias_add' => $this->api('addArtistAlias', ['alias' => $in['alias']], ['artist' => Artist::findOrFail($id())], 'POST'),
            'artist_alias_remove' => $this->api('removeArtistAlias', ['alias' => $in['alias']], ['artist' => Artist::findOrFail($id())], 'DELETE'),
            'artist_split' => $this->api('splitArtist', array_filter(['parts' => $in['parts'] ?? null]), ['artist' => Artist::findOrFail($id())], 'POST'),
            'quick_create' => $this->api('quickSave', ['picks' => (array) $in['picks']], [], 'POST'),
            'quick_split' => $this->api('quickSplit', array_filter(['name' => $in['name'], 'parts' => $in['parts'] ?? null]), [], 'POST'),
            'combined_apply' => $this->api('combinedApply', array_filter(['names' => $in['names'] ?? null, 'all' => $in['all'] ?? null], fn ($v) => $v !== null), [], 'POST'),
            'combi_start' => $this->api('combiStart', ['fresh' => (bool) ($in['fresh'] ?? false)], [], 'POST'),
        };
    }

    /** Aufruf einer Schnittstellen-Funktion mit nachgebauter Anfrage (gleiche Logik, gleiches Protokoll). */
    private function api(string $method, array $query = [], array $params = [], string $verb = 'GET'): array
    {
        $request = Request::create('/api/chat', $verb, $query);
        $request->headers->set('Accept', 'application/json');
        app()->instance('request', $request);
        $response = app()->call([app(ApiController::class), $method], $params + ['request' => $request]);

        return $response instanceof JsonResponse ? (array) $response->getData(true) : ['result' => $response];
    }

    private function wikipedia(array $in): array
    {
        $lookup = app(Lookup::class);
        $lang = (string) ($in['lang'] ?? 'de');
        $title = trim((string) ($in['title'] ?? ''));
        if (! empty($in['artist_id'])) {
            $artist = Artist::findOrFail((int) $in['artist_id']);
            $link = $artist->mbid ? $lookup->wikipediaForMbid($artist->mbid) : null;
            if (! $link) {
                return ['error' => 'Bei MusicBrainz ist für „'.$artist->name.'“ kein Wikipedia-Artikel verknüpft'.($artist->mbid ? '' : ' (keine MusicBrainz-ID)').' – mit wikipedia_search suchen.'];
            }
            [$title, $lang] = [$link['title'], $link['lang']];
        }
        if ($title === '') {
            return ['error' => 'title oder artist_id angeben.'];
        }

        return $lookup->wikipediaArticle($title, $lang) ?? ($lang === 'de' ? ($lookup->wikipediaArticle($title, 'en') ?? ['error' => 'Kein Artikel „'.$title.'“.']) : ['error' => 'Kein Artikel „'.$title.'“.']);
    }

    private function discogs(array $in): array
    {
        $lookup = app(Lookup::class);
        if (! $lookup->hasDiscogs()) {
            return ['error' => 'Kein Discogs-Token in den Einstellungen.'];
        }
        $name = (string) ($in['name'] ?? '');
        $id = $in['discogs_id'] ?? null;
        if (! empty($in['artist_id'])) {
            $artist = Artist::findOrFail((int) $in['artist_id']);
            [$name, $id] = [$artist->name, $id ?: $artist->discogs_id];
        }
        if ($name === '' && ! $id) {
            return ['error' => 'name, discogs_id oder artist_id angeben.'];
        }

        return $lookup->discogsArtistProfile($name, $id ? (string) $id : null) ?? ['error' => 'Bei Discogs nicht gefunden.'];
    }

    /** Vorgemerktes kompakt (der volle Export ist für ein Gespräch zu groß). */
    private function pendingOverview(int $albums): array
    {
        $pending = app(PendingChanges::class);

        return ['tracks' => $pending->count(), 'albums' => $pending->albumCount(), 'base' => rtrim(app(Settings::class)->base(), '/').'/',
            'by_album' => $pending->summary(min(100, max(1, $albums)), 5)];
    }

    private function pendingAlbum(int $albumId): array
    {
        $base = rtrim(app(Settings::class)->base(), '/').'/';
        $rel = fn ($p) => is_string($p) && str_starts_with($p, $base) ? substr($p, strlen($base)) : $p;
        $plan = app(PendingChanges::class)->actions([$albumId]);
        $rows = PendingChange::with('track')->whereHas('track', fn ($q) => $q->where('album_id', $albumId))->get()->map(fn ($pc) => [
            'track_id' => $pc->track_id, 'path' => $rel($pc->track?->path), 'before' => $pc->tags_before,
            'after' => array_intersect_key($pc->track?->tags() ?? [], (array) $pc->tags_before), 'labels' => $pc->labels,
        ])->all();

        return ['album_id' => $albumId, 'tracks' => $rows, 'planned_actions' => array_map(function ($a) use ($rel) {
            foreach (['path', 'target', 'from_dir', 'to_dir', 'dir'] as $k) {
                if (isset($a[$k])) {
                    $a[$k] = $rel($a[$k]);
                }
            }

            return $a;
        }, $plan['actions'])];
    }
}
