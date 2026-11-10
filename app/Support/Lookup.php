<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Nachschlagen per Knopf: MusicBrainz (ohne Anmeldung, ca. 1 Abfrage/s), Discogs (Token), Deezer, Fanart.tv.
 */
class Lookup
{
    /** true: Zwischenspeicher umgehen und mit frischen Daten überschreiben („Aktuelle Daten laden“) */
    public bool $fresh = false;

    public function __construct(private Settings $settings) {}

    /** Antworten der Dienste zwischenspeichern (Standard 10 Tage – Künstlerdaten ändern sich selten). */
    private function cached(string $key, callable $fetch): mixed
    {
        $ttl = now()->addDays(max(1, (int) config('musicwiz.lookup_cache_days', 10)));
        if ($this->fresh) {
            $value = $fetch();
            Cache::put($key, $value, $ttl);

            return $value;
        }

        return Cache::remember($key, $ttl, $fetch);
    }

    public static function userAgent(): string
    {
        return 'MusicWiz/1.0 ( '.config('app.url').' )';
    }

    // ------------------------------------------------------------ MusicBrainz

    /** @return list<array{name:string, mbid:string, disambiguation:?string, country:?string, score:int}> */
    public function musicbrainzArtists(string $name, int $limit = 8): array
    {
        // "Beatles, The" → "The Beatles" (MusicBrainz kennt nur die normale Reihenfolge)
        $name = preg_replace('/^(.*),\s*(The|Die|Les|Los)$/iu', '$2 $1', trim($name));
        $key = ArtistNames::compareKey($name);
        $map = fn ($data) => array_map(fn ($a) => [
            'name' => ArtistNames::plainPunctuation((string) $a['name']), // „Lexy & K‐Paul“ → „Lexy & K-Paul“
            'mbid' => $a['id'],
            'disambiguation' => $a['disambiguation'] ?? null,
            'country' => $a['country'] ?? null,
            'type' => $a['type'] ?? null,
            'area' => $a['area']['name'] ?? null,
            'score' => (int) ($a['score'] ?? 0),
        ], $data['artists'] ?? []);

        // 1) exakter Ausdruck
        $hits = $map($this->mb('artist', ['query' => 'artist:"'.addcslashes($name, '"\\').'"', 'limit' => $limit]));
        // 2) freie Suche, wenn kein Name passt – z. B. "C.C. Catch" steht bei MusicBrainz als "C.C.Catch"
        if (! array_filter($hits, fn ($h) => ArtistNames::compareKey($h['name']) === $key)) {
            $free = addcslashes($name, '+-&|!(){}[]^"~*?:\\/');
            $more = $map($this->mb('artist', ['query' => $free, 'limit' => $limit]));
            $hits = array_values(array_merge($hits, array_filter($more, fn ($m) => ! in_array($m['mbid'], array_column($hits, 'mbid'), true))));
        }
        // passende Namen zuerst, dann nach Score
        usort($hits, fn ($a, $b) => [ArtistNames::compareKey($b['name']) === $key, $b['score']] <=> [ArtistNames::compareKey($a['name']) === $key, $a['score']]);

        return array_slice($hits, 0, $limit);
    }

    /**
     * Welche dieser Künstler (MBIDs) haben eines dieser Lieder? Eine einzige Abfrage statt einer je Künstler und Titel
     * (MusicBrainz erlaubt nur etwa eine Abfrage pro Sekunde).
     *
     * @param  list<string>  $mbids
     * @param  list<string>  $titles
     * @return array<string, string> MBID => gefundener Titel
     */
    public function musicbrainzArtistsWithSongs(array $mbids, array $titles): array
    {
        $titles = array_values(array_unique(array_filter(array_map(fn ($t) => trim(preg_replace('/[\(\[].*?[\)\]]/u', '', $t)) ?: $t, $titles))));
        if (! $mbids || ! $titles) {
            return [];
        }
        $artists = implode(' OR ', array_map(fn ($m) => 'arid:'.$m, $mbids));
        $songs = implode(' OR ', array_map(fn ($t) => 'recording:"'.addcslashes($t, '"\\').'"', $titles));
        $data = $this->mb('recording', ['query' => '('.$artists.') AND ('.$songs.')', 'limit' => 50]);
        $keys = array_map(fn ($t) => ArtistNames::compareKey($t), $titles);
        $out = [];
        foreach ($data['recordings'] ?? [] as $rec) {
            foreach ($rec['artist-credit'] ?? [] as $credit) {
                $id = $credit['artist']['id'] ?? null;
                if (! $id || isset($out[$id]) || ! in_array($id, $mbids, true)) {
                    continue;
                }
                // Titel aus unserer Liste, der in der Aufnahme steckt (Suche ist unscharf: "Runaway" findet auch "Runaway (Live)")
                $recKey = ArtistNames::compareKey((string) ($rec['title'] ?? ''));
                foreach ($keys as $i => $k) {
                    if ($k !== '' && str_contains($recKey, $k)) {
                        $out[$id] = $titles[$i];
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Künstler-Treffer bei MusicBrainz samt Liedprüfung (für den Assistenten „Neue Künstler“ und dessen Vorladen –
     * gleiche Abfragen, damit das Vorladen den Cache füllt).
     *
     * @param  list<string>  $titles  eigene Liedtitel des Künstlers
     * @return list<array> Treffer mit exact (Name passt) und songs (gefundene eigene Titel)
     */
    public function artistCandidates(string $name, array $titles): array
    {
        $key = ArtistNames::compareKey($name);
        $hits = array_slice($this->musicbrainzArtists($name, 6), 0, 6);
        foreach ($hits as &$h) {
            $h['exact'] = ArtistNames::compareKey($h['name']) === $key;
            $h['songs'] = [];
        }
        unset($h);
        // Liedprüfung für die drei aussichtsreichsten Treffer: welcher hat eure Lieder? (Gleichnamige auseinanderhalten)
        $check = array_column(array_slice(array_filter($hits, fn ($h) => $h['exact'] || $h['score'] >= 90), 0, 3), 'mbid');
        $found = $this->musicbrainzArtistsWithSongs($check, array_slice($titles, 0, 3));
        foreach ($hits as &$h) {
            if (isset($found[$h['mbid']])) {
                $h['songs'] = [$found[$h['mbid']]];
            }
        }

        return $hits;
    }

    /** Gibt es bei diesem Künstler (MBID) eine Aufnahme mit diesem Titel? */
    public function musicbrainzRecordingByArtist(string $mbid, string $title): bool
    {
        $clean = trim(preg_replace('/[\(\[].*?[\)\]]/u', '', $title)) ?: $title;
        $data = $this->mb('recording', ['query' => 'arid:'.$mbid.' AND recording:"'.addcslashes($clean, '"\\').'"', 'limit' => 1]);

        return ($data['count'] ?? 0) > 0;
    }

    /**
     * Album nachschlagen (Jahr + Genre) in MusicBrainz, Deezer und – mit Token – Discogs.
     * Sampler werden nach Albumname und "Various Artists" gesucht, nicht nach einem einzelnen Interpreten.
     *
     * @return array{candidates: list<array{source:string, title:string, artist:string, year:?string, tracks:?int, genres:list<string>, url:?string}>, errors: list<string>}
     */
    public function albumCandidates(?string $artist, string $album, bool $compilation): array
    {
        $album = trim(Scanner::stripDisc($album));
        $wanted = Scanner::albumKey($album);
        $fits = function (string $title) use ($wanted) {
            $k = Scanner::albumKey($title);

            return $k !== '' && ($k === $wanted || str_contains($k, $wanted) || str_contains($wanted, $k) || Scanner::similarAlbumKeys($k, $wanted));
        };
        $out = [];
        $errors = [];

        // MusicBrainz: Release-Groups (Jahr = Erstveröffentlichung)
        try {
            $query = 'releasegroup:"'.addcslashes($album, '"\\').'"'.($compilation ? '' : ' AND artist:"'.addcslashes((string) $artist, '"\\').'"');
            foreach (array_slice($this->mb('release-group', ['query' => $query, 'limit' => 6])['release-groups'] ?? [], 0, 6) as $rg) {
                $credit = $rg['artist-credit'][0]['name'] ?? '';
                if (! $fits($rg['title']) || ($compilation && ! in_array('Compilation', $rg['secondary-types'] ?? [], true) && $credit !== 'Various Artists')) {
                    continue;
                }
                $detail = count($out) < 3 ? $this->mb('release-group/'.$rg['id'], ['inc' => 'genres+tags']) : [];
                $genres = array_merge(array_column($detail['genres'] ?? [], 'name'), array_column(array_filter($detail['tags'] ?? [], fn ($t) => ($t['count'] ?? 0) > 0), 'name'));
                $out[] = ['source' => 'MusicBrainz', 'title' => $rg['title'], 'artist' => $credit, 'year' => substr((string) ($rg['first-release-date'] ?? ''), 0, 4) ?: null,
                    'tracks' => null, 'genres' => array_values(array_unique(array_map('ucwords', $genres))), 'url' => 'https://musicbrainz.org/release-group/'.$rg['id'], 'mbid' => $rg['id']];
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        // Deezer (ohne Schlüssel)
        try {
            $r = Http::timeout(15)->get('https://api.deezer.com/search/album', ['q' => ($compilation ? '' : $artist.' ').$album, 'limit' => 8]);
            foreach (array_slice($r->json('data') ?? [], 0, 8) as $a) {
                if (! $fits($a['title']) || count(array_filter($out, fn ($c) => $c['source'] === 'Deezer')) >= 3) {
                    continue;
                }
                $d = Http::timeout(15)->get('https://api.deezer.com/album/'.$a['id'])->json() ?? [];
                $out[] = ['source' => 'Deezer', 'title' => $a['title'], 'artist' => $a['artist']['name'] ?? '', 'year' => substr((string) ($d['release_date'] ?? ''), 0, 4) ?: null,
                    'tracks' => $a['nb_tracks'] ?? null, 'genres' => array_column($d['genres']['data'] ?? [], 'name'), 'url' => $a['link'] ?? null];
            }
        } catch (\Throwable $e) {
            $errors[] = 'Deezer: '.$e->getMessage();
        }

        // Discogs (nur mit Token)
        if ($this->hasDiscogs()) {
            try {
                $params = ['release_title' => $album, 'type' => 'release', 'per_page' => 6] + ($compilation ? ['artist' => 'Various'] : ['artist' => (string) $artist]);
                foreach ($this->discogs('database/search', $params)['results'] ?? [] as $h) {
                    if (! $fits(preg_replace('/^.*? - /', '', $h['title'] ?? '')) || count(array_filter($out, fn ($c) => $c['source'] === 'Discogs')) >= 3) {
                        continue;
                    }
                    $out[] = ['source' => 'Discogs', 'title' => $h['title'], 'artist' => '', 'year' => isset($h['year']) ? (string) $h['year'] : null,
                        'tracks' => null, 'genres' => array_values(array_unique(array_merge($h['style'] ?? [], $h['genre'] ?? []))), 'url' => isset($h['uri']) ? 'https://www.discogs.com'.$h['uri'] : null];
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return ['candidates' => $out, 'errors' => $errors];
    }

    /**
     * Titelliste einer Release-Group: die Veröffentlichung, deren Titelzahl am besten passt (offizielle bevorzugt).
     *
     * @return array{release:string, url:string, tracks:list<array{disc:int, track:int, title:string, artist:string, seconds:?float}>}|null
     */
    public function musicbrainzTracklist(string $releaseGroupId, int $wantedTracks): ?array
    {
        $releases = $this->mb('release', ['release-group' => $releaseGroupId, 'inc' => 'recordings+artist-credits+media', 'limit' => 25])['releases'] ?? [];
        $count = fn ($r) => array_sum(array_map(fn ($m) => count($m['tracks'] ?? []), $r['media'] ?? []));
        usort($releases, fn ($a, $b) => [abs($count($a) - $wantedTracks), ($a['status'] ?? '') === 'Official' ? 0 : 1, (string) ($a['date'] ?? '9999')]
            <=> [abs($count($b) - $wantedTracks), ($b['status'] ?? '') === 'Official' ? 0 : 1, (string) ($b['date'] ?? '9999')]);
        $release = $releases[0] ?? null;
        if (! $release || ! $count($release)) {
            return null;
        }
        $credit = fn (array $credits) => implode('', array_map(fn ($c) => ($c['name'] ?? '').($c['joinphrase'] ?? ''), $credits));
        $tracks = [];
        foreach ($release['media'] ?? [] as $m) {
            foreach ($m['tracks'] ?? [] as $t) {
                $tracks[] = ['disc' => (int) ($m['position'] ?? 1), 'track' => (int) ($t['position'] ?? 0), 'title' => (string) ($t['title'] ?? ''),
                    'artist' => $credit($t['artist-credit'] ?? $t['recording']['artist-credit'] ?? []), 'seconds' => isset($t['length']) ? $t['length'] / 1000 : null];
            }
        }
        $label = trim(($release['title'] ?? '').' · '.implode(' · ', array_filter([$release['country'] ?? null, $release['date'] ?? null, count($release['media'] ?? []) > 1 ? count($release['media']).' CDs' : null])), ' ·');

        return ['release' => $label, 'url' => 'https://musicbrainz.org/release/'.$release['id'], 'tracks' => $tracks];
    }

    // ------------------------------------------------------------ AcoustID

    /**
     * Titel am Fingerabdruck erkennen (mehrere Dateien in einer Abfrage).
     *
     * @param  array<int|string, array{fingerprint:string, duration:float}>  $items
     * @return array<int|string, array{title:string, artist:string, album:?string, score:float}|null>
     */
    public function acoustid(array $items): array
    {
        $key = trim((string) $this->settings->get('acoustid_key'));
        if ($key === '' || ! $items) {
            return [];
        }
        $keys = array_keys($items);
        $form = ['client' => $key, 'meta' => 'recordings releasegroups compress', 'format' => 'json'];
        foreach (array_values($items) as $i => $item) {
            $form['duration.'.$i] = (int) round($item['duration']);
            $form['fingerprint.'.$i] = $item['fingerprint'];
        }
        $r = Http::timeout(30)->asForm()->withHeaders(['User-Agent' => self::userAgent()])->post('https://api.acoustid.org/v2/lookup', $form);
        if (! $r->successful() || ($r->json('status') ?? '') !== 'ok') {
            throw new RuntimeException('AcoustID: '.($r->json('error.message') ?? 'antwortet nicht ('.$r->status().')'));
        }
        // Einzelabfrage liefert "results", Sammelabfrage "fingerprints" mit Index
        $groups = $r->json('fingerprints') ?? [['index' => 0, 'results' => $r->json('results') ?? []]];
        $out = array_fill_keys($keys, null);
        foreach ($groups as $g) {
            $best = null;
            foreach ($g['results'] ?? [] as $res) {
                foreach ($res['recordings'] ?? [] as $rec) {
                    if (empty($rec['title']) || ($best && $best['score'] >= (float) $res['score'])) {
                        continue;
                    }
                    $artist = implode('', array_map(fn ($a) => ($a['name'] ?? '').($a['joinphrase'] ?? ''), $rec['artists'] ?? []));
                    $album = collect($rec['releasegroups'] ?? [])->sortBy(fn ($rg) => ($rg['type'] ?? '') === 'Album' ? 0 : 1)->first()['title'] ?? null;
                    $best = ['title' => (string) $rec['title'], 'artist' => $artist, 'album' => $album, 'score' => (float) $res['score']];
                }
            }
            $out[$keys[(int) ($g['index'] ?? 0)]] = $best;
        }

        return $out;
    }

    /**
     * Genres eines Künstlers bei MusicBrainz (Genres und Tags mit Stimmen).
     *
     * @return array<string, int> Wert => Stimmen
     */
    public function musicbrainzArtistGenres(string $mbid): array
    {
        $data = $this->musicbrainzArtist($mbid);
        $out = [];
        foreach ([...($data['genres'] ?? []), ...($data['tags'] ?? [])] as $g) {
            if (($g['count'] ?? 0) > 0) {
                $out[$g['name']] = max($out[$g['name']] ?? 0, (int) $g['count']);
            }
        }
        arsort($out);

        return $out;
    }

    /** Künstler-Datensatz bei MusicBrainz mit Aliasen, Genres und Tags (eine Abfrage, einen Tag gecacht). */
    /**
     * Mitglieder bzw. Beteiligte eines Künstlers bei MusicBrainz („Stone & Van Linden“ → CJ Stone, Marc van Linden) –
     * Vorschläge beim Trennen einer Zusammenarbeit.
     *
     * @return list<string>
     */
    public function musicbrainzMembers(string $mbid): array
    {
        $data = $this->mb('artist/'.$mbid, ['inc' => 'artist-rels']);
        $out = [];
        foreach ($data['relations'] ?? [] as $r) {
            if (in_array($r['type'] ?? '', ['member of band', 'collaboration'], true) && ($r['direction'] ?? '') === 'backward' && ! empty($r['artist']['name'])) {
                $out[] = ArtistNames::plainPunctuation($r['artist']['name']);
            }
        }

        return array_values(array_unique($out));
    }

    private function musicbrainzArtist(string $mbid): array
    {
        return $this->mb('artist/'.$mbid, ['inc' => 'aliases+genres+tags']);
    }

    /**
     * Schreibweisen eines Künstlers bei MusicBrainz: der Name selbst und seine Aliase. Ausgelassen werden
     *  - bürgerliche Namen ("Legal name" – z. B. Ralph Armand Beck für Taucher): eine andere Angabe, keine Schreibweise;
     *  - Zusammenarbeiten ("X feat. Y", "X & Y", "X pres. Y"): im Verzeichnis würden sie geschützt und nie mehr getrennt,
     *    der zweite Künstler ginge verloren – außer der Künstlername selbst ist so gebaut ("Simon & Garfunkel").
     *
     * @return list<string>
     */
    public function musicbrainzArtistAliases(string $mbid): array
    {
        $data = $this->musicbrainzArtist($mbid);
        $main = (string) ($data['name'] ?? '');
        $collab = ArtistNames::looksCollaboration($main);
        $names = [$main];
        foreach ($data['aliases'] ?? [] as $a) {
            if (($a['type'] ?? null) !== 'Legal name' && ! empty($a['name']) && ($collab || ! ArtistNames::looksCollaboration($a['name']))) {
                $names[] = $a['name'];
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * Genres/Stile eines Künstlers bei Discogs: gezählt über seine Releases (Master).
     *
     * @return array<string, int> Wert => Anzahl Releases
     */
    public function discogsArtistGenres(string $name): array
    {
        $data = $this->discogs('database/search', ['artist' => $name, 'type' => 'master', 'per_page' => 30]);
        $key = ArtistNames::compareKey($name);
        $out = [];
        foreach ($data['results'] ?? [] as $r) {
            // Titel ist "Künstler - Album": nur Releases dieses Künstlers zählen
            $artist = trim(explode(' - ', (string) ($r['title'] ?? ''), 2)[0]);
            if (ArtistNames::compareKey(ArtistNames::stripDiscogsSuffix($artist)) !== $key) {
                continue;
            }
            // Stile sind genauer als Genres (z. B. "Trance" statt "Electronic") – doppelt gewichten
            foreach ($r['style'] ?? [] as $s) {
                $out[$s] = ($out[$s] ?? 0) + 2;
            }
            foreach ($r['genre'] ?? [] as $g) {
                $out[$g] = ($out[$g] ?? 0) + 1;
            }
        }
        arsort($out);

        return $out;
    }

    /** Genres/Tags einer Release-Group bei MusicBrainz. */
    public function musicbrainzAlbumGenres(string $artist, string $album): array
    {
        $data = $this->mb('release-group', ['query' => 'releasegroup:"'.addcslashes($album, '"').'" AND artist:"'.addcslashes($artist, '"').'"', 'limit' => 3]);
        $rg = $data['release-groups'][0] ?? null;
        if (! $rg) {
            return [];
        }
        $detail = $this->mb('release-group/'.$rg['id'], ['inc' => 'genres+tags']);
        $values = array_merge(
            array_map(fn ($g) => $g['name'], $detail['genres'] ?? []),
            array_map(fn ($g) => $g['name'], array_filter($detail['tags'] ?? [], fn ($t) => ($t['count'] ?? 0) > 0)),
        );

        return ['source' => 'MusicBrainz', 'title' => $rg['title'] ?? $album, 'values' => array_values(array_unique(array_map('ucwords', $values)))];
    }

    private function mb(string $path, array $query): array
    {
        $key = 'mb:'.$path.':'.md5(json_encode($query));

        return $this->cached($key, function () use ($path, $query) {
            // Höflich bleiben: höchstens etwa eine Abfrage pro Sekunde – auch wenn Oberfläche und Vorladen (Worker)
            // gleichzeitig fragen (Sperre über den gemeinsamen Cache)
            Cache::lock('mb:pace', 10)->block(20, function () {
                $last = (float) Cache::get('mb:last', 0);
                $wait = (float) config('musicwiz.mb_pause', 1.1) - (microtime(true) - $last);
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }
                Cache::put('mb:last', microtime(true));
            });
            // MusicBrainz antwortet bei Last mit 503 – dann kurz warten und erneut fragen
            $r = Http::timeout(15)->retry(4, 1500, fn ($e) => $e instanceof RequestException && $e->response->status() === 503, throw: false)
                ->withHeaders(['User-Agent' => self::userAgent(), 'Accept' => 'application/json'])
                ->get('https://musicbrainz.org/ws/2/'.$path, $query + ['fmt' => 'json']);
            if (! $r->successful()) {
                throw new RuntimeException('MusicBrainz antwortet nicht ('.$r->status().')');
            }

            return $r->json() ?? [];
        });
    }

    // ------------------------------------------------------------ Discogs

    public function hasDiscogs(): bool
    {
        return trim((string) $this->settings->get('discogs_token')) !== '';
    }

    public function discogsArtists(string $name): array
    {
        $data = $this->discogs('database/search', ['q' => $name, 'type' => 'artist', 'per_page' => 8]);

        return array_map(fn ($a) => [
            'name' => ArtistNames::stripDiscogsSuffix($a['title']),
            'raw' => $a['title'],
            'discogs_id' => (string) $a['id'],
            'image' => $a['cover_image'] ?? null,
        ], $data['results'] ?? []);
    }

    /**
     * Künstlerprofil bei Discogs: echter Name, Profiltext, Mitglieder (bei Gruppen), Gruppen (bei Einzelkünstlern),
     * Aliasse. Über die Discogs-ID, sonst nur, wenn genau ein Künstler so heißt.
     */
    public function discogsArtistProfile(string $name, ?string $discogsId = null): ?array
    {
        if (! $discogsId) {
            $key = ArtistNames::compareKey($name);
            $same = array_values(array_filter($this->discogsArtists($name), fn ($a) => ArtistNames::compareKey($a['name']) === $key));
            if (count($same) !== 1) {
                return $same ? ['ambiguous' => array_map(fn ($a) => ['name' => $a['raw'], 'discogs_id' => $a['discogs_id']], $same)] : null;
            }
            $discogsId = $same[0]['discogs_id'];
        }
        $a = $this->discogs('artists/'.$discogsId, []);
        $names = fn ($list) => array_values(array_map(fn ($m) => ArtistNames::stripDiscogsSuffix((string) $m['name']).(isset($m['active']) && ! $m['active'] ? ' (früher)' : ''), (array) $list));

        return array_filter([
            'discogs_id' => (string) ($a['id'] ?? $discogsId), 'name' => ArtistNames::stripDiscogsSuffix((string) ($a['name'] ?? $name)),
            'real_name' => $a['realname'] ?? null,
            // Discogs-Auszeichnung ([a=…], [l=…]) lesbar machen, Länge begrenzen
            'profile' => mb_substr(trim(preg_replace('/\[[alrm]=?([^\]]*)\]/u', '$1', (string) ($a['profile'] ?? ''))), 0, 2500) ?: null,
            'members' => $names($a['members'] ?? []), 'groups' => $names($a['groups'] ?? []),
            'aliases' => $names($a['aliases'] ?? []), 'name_variations' => array_slice((array) ($a['namevariations'] ?? []), 0, 15),
            'url' => $a['uri'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    // ------------------------------------------------------------ Wikipedia (frei, ohne Key)

    /** Artikel suchen (Titel + Ausschnitt), zuerst in der angegebenen Sprache. */
    public function wikipediaSearch(string $query, string $lang = 'de'): array
    {
        $lang = in_array($lang, ['de', 'en'], true) ? $lang : 'de';
        $data = $this->wiki($lang, ['action' => 'query', 'list' => 'search', 'srsearch' => $query, 'srlimit' => 6]);

        return array_map(fn ($r) => ['title' => $r['title'], 'lang' => $lang, 'snippet' => html_entity_decode(strip_tags((string) ($r['snippet'] ?? '')))], $data['query']['search'] ?? []);
    }

    /**
     * Artikeltext (reiner Text, gekürzt): Einleitung und erste Abschnitte – bei Bands steht dort meist, wer dazugehört.
     *
     * @return array{title: string, lang: string, url: string, text: string}|null
     */
    public function wikipediaArticle(string $title, string $lang = 'de', int $maxChars = 6000): ?array
    {
        $lang = in_array($lang, ['de', 'en'], true) ? $lang : 'de';
        $data = $this->wiki($lang, ['action' => 'query', 'prop' => 'extracts|info', 'explaintext' => 1, 'redirects' => 1, 'inprop' => 'url', 'titles' => $title]);
        $page = collect($data['query']['pages'] ?? [])->first();
        if (! $page || isset($page['missing']) || trim((string) ($page['extract'] ?? '')) === '') {
            return null;
        }
        $text = trim((string) $page['extract']);

        return ['title' => $page['title'], 'lang' => $lang, 'url' => $page['fullurl'] ?? 'https://'.$lang.'.wikipedia.org/wiki/'.rawurlencode($page['title']),
            'text' => mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars).' …' : $text];
    }

    /**
     * Wikipedia-Artikel zur MusicBrainz-ID – über die bei MusicBrainz hinterlegte Verknüpfung (Wikidata), also sicher der
     * richtige Künstler. Deutsch bevorzugt, sonst Englisch.
     *
     * @return array{title: string, lang: string}|null
     */
    public function wikipediaForMbid(string $mbid): ?array
    {
        $rels = $this->mb('artist/'.$mbid, ['inc' => 'url-rels'])['relations'] ?? [];
        foreach ($rels as $r) {
            if (($r['type'] ?? '') === 'wikipedia' && preg_match('#https?://(de|en)\.wikipedia\.org/wiki/(.+)$#', (string) ($r['url']['resource'] ?? ''), $m)) {
                return ['title' => rawurldecode(str_replace('_', ' ', $m[2])), 'lang' => $m[1]];
            }
        }
        foreach ($rels as $r) {
            if (($r['type'] ?? '') === 'wikidata' && preg_match('#(Q\d+)$#', (string) ($r['url']['resource'] ?? ''), $m)) {
                $entity = $this->cached('wikidata:'.$m[1], fn () => Http::timeout(15)->withHeaders(['User-Agent' => self::userAgent()])
                    ->get('https://www.wikidata.org/wiki/Special:EntityData/'.$m[1].'.json')->json() ?? []);
                $links = $entity['entities'][$m[1]]['sitelinks'] ?? [];
                foreach (['de', 'en'] as $lang) {
                    if (! empty($links[$lang.'wiki']['title'])) {
                        return ['title' => $links[$lang.'wiki']['title'], 'lang' => $lang];
                    }
                }
            }
        }

        return null;
    }

    private function wiki(string $lang, array $query): array
    {
        return $this->cached('wiki:'.$lang.':'.md5(json_encode($query)), function () use ($lang, $query) {
            $r = Http::timeout(15)->withHeaders(['User-Agent' => self::userAgent()])
                ->get('https://'.$lang.'.wikipedia.org/w/api.php', $query + ['format' => 'json', 'formatversion' => 2]);
            if (! $r->successful()) {
                throw new RuntimeException('Wikipedia antwortet nicht ('.$r->status().')');
            }

            return $r->json() ?? [];
        });
    }

    public function discogsAlbumGenres(string $artist, string $album): array
    {
        $data = $this->discogs('database/search', ['artist' => $artist, 'release_title' => $album, 'type' => 'master', 'per_page' => 3]);
        $hit = $data['results'][0] ?? null;
        if (! $hit) {
            $data = $this->discogs('database/search', ['artist' => $artist, 'release_title' => $album, 'type' => 'release', 'per_page' => 3]);
            $hit = $data['results'][0] ?? null;
        }
        if (! $hit) {
            return [];
        }

        return ['source' => 'Discogs', 'title' => $hit['title'] ?? $album, 'values' => array_values(array_unique(array_merge($hit['style'] ?? [], $hit['genre'] ?? [])))];
    }

    public function discogsArtistImages(string $discogsId): array
    {
        $data = $this->discogs('artists/'.$discogsId, []);

        return array_values(array_map(fn ($i) => ['url' => $i['uri'], 'thumb' => $i['uri150'] ?? $i['uri'], 'source' => 'Discogs', 'size' => ($i['width'] ?? '?').'×'.($i['height'] ?? '?')], $data['images'] ?? []));
    }

    private function discogs(string $path, array $query): array
    {
        if (! $this->hasDiscogs()) {
            throw new RuntimeException('Kein Discogs-Token in den Einstellungen.');
        }
        $key = 'discogs:'.$path.':'.md5(json_encode($query));

        return $this->cached($key, function () use ($path, $query) {
            $r = Http::timeout(15)->withHeaders([
                'User-Agent' => self::userAgent(),
                'Authorization' => 'Discogs token='.trim($this->settings->get('discogs_token')),
            ])->get('https://api.discogs.com/'.$path, $query);
            if (! $r->successful()) {
                throw new RuntimeException('Discogs antwortet nicht ('.$r->status().')');
            }

            return $r->json() ?? [];
        });
    }

    // ------------------------------------------------------------ Bilder

    public function deezerArtistImages(string $name): array
    {
        return $this->cached('deezer:artist:'.md5($name), function () use ($name) {
            $r = Http::timeout(15)->get('https://api.deezer.com/search/artist', ['q' => $name, 'limit' => 6]);
            if (! $r->successful()) {
                throw new RuntimeException('Deezer antwortet nicht ('.$r->status().')');
            }

            return array_values(array_filter(array_map(fn ($a) => empty($a['picture_xl']) || str_contains($a['picture_xl'], '/artist//') ? null : [
                'url' => $a['picture_xl'], 'thumb' => $a['picture_medium'] ?? $a['picture_xl'], 'source' => 'Deezer: '.$a['name'], 'size' => '1000×1000',
            ], $r->json('data') ?? [])));
        });
    }

    /**
     * Bildvorschläge nur aus dem Zwischenspeicher (vom Vorladen) – für Listen, die nicht auf Dienste warten dürfen:
     * zuerst TheAudioDB und Fanart.tv (hängen an der MusicBrainz-ID), dann Spotify (über ein eigenes Lied abgesichert), dann Deezer-Treffer
     * mit genau diesem Namen.
     *
     * @param  list<string>  $names  Bestandsname und ggf. MusicBrainz-Schreibweise
     * @return list<array{url:string, thumb:string, source:string, size:string}>
     */
    public function cachedArtistImages(array $names, ?string $mbid, int $limit = 3): array
    {
        $keys = array_map(fn ($n) => ArtistNames::compareKey($n), $names);
        $fanart = $mbid ? (array) Cache::get('fanart:'.$mbid, []) : [];
        // Spotify: über ein eigenes Lied abgesicherter Treffer aus dem Zwischenspeicher (vom Vorladen)
        $spotify = (array) (Cache::get('spotify:artist:'.md5($names[0] ?? ''))['images'] ?? []);
        // TheAudioDB über die MusicBrainz-ID: Foto zuerst (Fanart/Breitbild sind Querformat)
        $audiodb = array_slice((array) ($this->audiodbArtistCached($mbid, $names[0] ?? '')['images'] ?? []), 0, 2);
        $deezer = array_filter((array) Cache::get('deezer:artist:'.md5($names[0] ?? ''), []),
            fn ($i) => in_array(ArtistNames::compareKey(substr((string) ($i['source'] ?? ''), strlen('Deezer: '))), $keys, true));

        return array_slice(array_values([...$audiodb, ...$fanart, ...$spotify, ...$deezer]), 0, $limit);
    }

    // ------------------------------------------------------------ eingetragene Künstler eines Lieds (Kombi-Prüfung)

    /**
     * Welche Künstler sind bei MusicBrainz für dieses Lied eingetragen? „Dr. Motte & WestBam – Sunshine“ → [Dr. Motte, WestBam]
     * (zwei Künstler = Zusammenarbeit), „Simon & Garfunkel – The Boxer“ → [Simon & Garfunkel] (ein Act).
     *
     * @return list<string>|null null = Lied nicht gefunden
     */
    public function musicbrainzCredit(string $artist, string $title): ?array
    {
        $clean = trim(preg_replace('/[\(\[].*?[\)\]]/u', '', $title)) ?: $title;
        $data = $this->mb('recording', ['query' => 'recording:"'.addcslashes($clean, '"\\').'" AND artist:"'.addcslashes($artist, '"\\').'"', 'limit' => 5]);
        $key = ArtistNames::compareKey($clean);
        foreach ($data['recordings'] ?? [] as $rec) {
            if (str_contains(ArtistNames::compareKey((string) ($rec['title'] ?? '')), $key)) {
                $names = array_values(array_filter(array_map(fn ($c) => ArtistNames::plainPunctuation(trim((string) ($c['name'] ?? $c['artist']['name'] ?? ''))), $rec['artist-credit'] ?? [])));

                return $names ?: null;
            }
        }

        return null;
    }

    /**
     * Beteiligte eines Lieds bei Deezer (nur Hauptkünstler, keine Gäste): ein Eintrag „Lexy & K-Paul“ = Act,
     * mehrere („Mike Candys“, „Jack Holiday“) = Zusammenarbeit.
     *
     * @return list<string>|null null = Lied nicht gefunden
     */
    public function deezerCredit(string $artist, string $title): ?array
    {
        return $this->cached('deezer:credit:'.md5($artist.'|'.$title), function () use ($artist, $title) {
            $clean = trim(preg_replace('/[\(\[].*?[\)\]]/u', '', $title)) ?: $title;
            $r = Http::timeout(15)->get('https://api.deezer.com/search/track', ['q' => $artist.' '.$clean, 'limit' => 10]);
            if (! $r->successful()) {
                throw new RuntimeException('Deezer antwortet nicht ('.$r->status().')');
            }
            $key = ArtistNames::compareKey($clean);
            $words = array_filter(array_map(fn ($n) => ArtistNames::compareKey($n), ArtistNames::suggestSplit($artist) ?: [$artist]));
            foreach ($r->json('data') ?? [] as $t) {
                // Titel passt und mindestens ein Teil des Namens kommt beim Künstler vor
                if (! str_contains(ArtistNames::compareKey((string) $t['title']), $key)) {
                    continue;
                }
                $track = Http::timeout(15)->get('https://api.deezer.com/track/'.$t['id'])->json() ?? [];
                $main = array_values(array_map(fn ($c) => ArtistNames::plainPunctuation((string) $c['name']),
                    array_filter($track['contributors'] ?? [], fn ($c) => ($c['role'] ?? 'Main') === 'Main')));
                $all = ArtistNames::compareKey(implode(' ', $main));
                if ($main && array_filter($words, fn ($w) => str_contains($all, $w))) {
                    return $main;
                }
            }

            return null;
        });
    }

    // ------------------------------------------------------------ Spotify (Client-Zugang: Bilder, Genres, eingetragene Künstler)

    public function hasSpotify(): bool
    {
        return trim((string) $this->settings->get('spotify_client_id')) !== '' && trim((string) $this->settings->get('spotify_client_secret')) !== '';
    }

    private function spotify(string $path, array $query): array
    {
        if (! $this->hasSpotify()) {
            throw new RuntimeException('Kein Spotify-Zugang in den Einstellungen.');
        }

        return $this->cached('spotify:'.$path.':'.md5(json_encode($query)), function () use ($path, $query) {
            $id = trim((string) $this->settings->get('spotify_client_id'));
            $token = Cache::remember('spotify:token:'.md5($id), now()->addMinutes(50), function () use ($id) {
                $r = Http::asForm()->timeout(15)->withBasicAuth($id, trim((string) $this->settings->get('spotify_client_secret')))
                    ->post('https://accounts.spotify.com/api/token', ['grant_type' => 'client_credentials']);
                if (! $r->successful()) {
                    throw new RuntimeException('Spotify-Anmeldung fehlgeschlagen ('.$r->status().') – Client-ID/Secret prüfen');
                }

                return (string) $r->json('access_token');
            });
            $r = Http::timeout(15)->withToken($token)->retry(2, 2000, fn ($e) => $e instanceof RequestException && $e->response->status() === 429, throw: false)
                ->get('https://api.spotify.com/v1/'.$path, $query);
            if ($r->status() === 401) {
                Cache::forget('spotify:token:'.md5($id));
            }
            if (! $r->successful()) {
                throw new RuntimeException('Spotify antwortet nicht ('.$r->status().')');
            }

            return $r->json() ?? [];
        });
    }

    /**
     * Künstler bei Spotify für das Lied: welche Künstler sind eingetragen (Liste der Künstler des Titels)?
     *
     * @return list<string>|null null = Lied nicht gefunden
     */
    public function spotifyCredit(string $artist, string $title): ?array
    {
        $clean = trim(preg_replace('/[\(\[].*?[\)\]]/u', '', $title)) ?: $title;
        $data = $this->spotify('search', ['q' => $artist.' '.$clean, 'type' => 'track', 'limit' => 10, 'market' => 'DE']);
        $key = ArtistNames::compareKey($clean);
        $words = array_filter(array_map(fn ($n) => ArtistNames::compareKey($n), ArtistNames::suggestSplit($artist) ?: [$artist]));
        foreach ($data['tracks']['items'] ?? [] as $t) {
            $names = array_map(fn ($a) => ArtistNames::plainPunctuation((string) $a['name']), $t['artists'] ?? []);
            $all = ArtistNames::compareKey(implode(' ', $names));
            if (str_contains(ArtistNames::compareKey((string) $t['name']), $key) && $names && array_filter($words, fn ($w) => str_contains($all, $w))) {
                return $names;
            }
        }

        return null;
    }

    /**
     * Künstler bei Spotify: über eines eurer Lieder gefunden (gleichnamige Künstler werden so auseinandergehalten –
     * „Beam“ der Trance-DJ statt des gleichnamigen Sängers); ohne Lied nur, wenn es genau einen mit diesem Namen gibt.
     *
     * @param  list<string>  $titles  eigene Liedtitel des Namens
     * @return array{id: string, name: string, genres: list<string>, popularity: int, images: list<array>, via: string}|null
     */
    public function spotifyArtist(string $name, array $titles = []): ?array
    {
        $cacheKey = 'spotify:artist:'.md5($name);
        if (! $this->fresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        $result = (function () use ($name, $titles) {
            $key = ArtistNames::compareKey($name);
            $id = null;
            $via = null;
            foreach (array_slice(array_values(array_filter($titles)), 0, 3) as $title) {
                $clean = trim(preg_replace('/[\(\[].*?[\)\]]/u', '', $title)) ?: $title;
                foreach ($this->spotify('search', ['q' => $name.' '.$clean, 'type' => 'track', 'limit' => 10, 'market' => 'DE'])['tracks']['items'] ?? [] as $t) {
                    if (! str_contains(ArtistNames::compareKey((string) $t['name']), ArtistNames::compareKey($clean))) {
                        continue;
                    }
                    foreach ($t['artists'] ?? [] as $a) {
                        if (ArtistNames::compareKey((string) $a['name']) === $key) {
                            [$id, $via] = [$a['id'], 'Lied „'.$t['name'].'“'];
                            break 3;
                        }
                    }
                }
            }
            if (! $id) {
                $same = array_values(array_filter($this->spotify('search', ['q' => $name, 'type' => 'artist', 'limit' => 10])['artists']['items'] ?? [],
                    fn ($a) => ArtistNames::compareKey((string) $a['name']) === $key));
                if (count($same) !== 1) {
                    return null; // keiner oder mehrere gleichnamige: lieber kein Bild als das falsche
                }
                [$id, $via] = [$same[0]['id'], 'Name (einziger Treffer)'];
            }
            $a = $this->spotify('artists/'.$id, []);
            $img = $a['images'] ?? [];

            return ['id' => $id, 'name' => ArtistNames::plainPunctuation((string) ($a['name'] ?? $name)), 'genres' => $a['genres'] ?? [], 'popularity' => (int) ($a['popularity'] ?? 0), 'via' => $via,
                'images' => $img ? [['url' => $img[0]['url'], 'thumb' => end($img)['url'] ?? $img[0]['url'], 'source' => 'Spotify: '.($a['name'] ?? $name).' ('.$via.')', 'size' => ($img[0]['width'] ?? '?').'×'.($img[0]['height'] ?? '?')]] : []];
        })();
        // „nicht gefunden“ nur merken, wenn mit eigenen Liedern gesucht wurde – sonst darf ein späterer Versuch mit Liedern noch
        if ($result !== null || $titles) {
            Cache::put($cacheKey, $result, now()->addDays(max(1, (int) config('musicwiz.lookup_cache_days', 10))));
        }

        return $result;
    }

    // ------------------------------------------------------------ TheAudioDB (Bilder, Genre/Stil, Biografie)

    /**
     * Künstler bei TheAudioDB – über die MusicBrainz-ID (sicher der richtige); ohne ID nur, wenn genau einer so heißt.
     *
     * @return array{id: string, name: string, mbid: ?string, genre: ?string, style: ?string, mood: ?string, members: ?int,
     *     country: ?string, biography: ?string, biography_lang: ?string, images: list<array>, logo: ?string}|null
     */
    public function audiodbArtist(?string $mbid, string $name = ''): ?array
    {
        $key = trim((string) $this->settings->get('audiodb_key')) ?: '123'; // freier Test-Key
        $get = function (string $path, array $query) use ($key) {
            return $this->cached('audiodb:'.$path.':'.md5(json_encode($query)), function () use ($key, $path, $query) {
                $r = Http::timeout(15)->retry(2, 3000, fn ($e) => $e instanceof RequestException && $e->response->status() === 429, throw: false)
                    ->get('https://www.theaudiodb.com/api/v1/json/'.$key.'/'.$path, $query);
                if ($r->status() === 404) {
                    throw new RuntimeException('TheAudioDB: Key unbekannt oder abgelaufen');
                }
                if (! $r->successful()) {
                    throw new RuntimeException('TheAudioDB antwortet nicht ('.$r->status().')');
                }

                return $r->json() ?? [];
            });
        };
        $hit = null;
        if ($mbid) {
            $hit = ($get('artist-mb.php', ['i' => $mbid])['artists'] ?? [])[0] ?? null;
        }
        if (! $hit && $name !== '') {
            $nameKey = ArtistNames::compareKey($name);
            $same = array_values(array_filter($get('search.php', ['s' => $name])['artists'] ?? [], fn ($a) => ArtistNames::compareKey((string) $a['strArtist']) === $nameKey
                && (! $mbid || empty($a['strMusicBrainzID']) || $a['strMusicBrainzID'] === $mbid)));
            $hit = count($same) === 1 ? $same[0] : null; // mehrere Gleichnamige: lieber nichts als das Falsche
        }
        if (! $hit) {
            return null;
        }
        $images = [];
        foreach (['strArtistThumb' => 'Foto', 'strArtistWideThumb' => 'Breitbild', 'strArtistFanart' => 'Fanart', 'strArtistFanart2' => 'Fanart', 'strArtistFanart3' => 'Fanart'] as $field => $kind) {
            if (! empty($hit[$field])) {
                $images[] = ['url' => $hit[$field], 'thumb' => $hit[$field].'/preview', 'source' => 'TheAudioDB: '.$kind, 'size' => ''];
            }
        }
        $bio = trim((string) ($hit['strBiographyDE'] ?? '')) ?: null;

        return ['id' => (string) $hit['idArtist'], 'name' => ArtistNames::plainPunctuation((string) $hit['strArtist']), 'mbid' => $hit['strMusicBrainzID'] ?? null,
            'genre' => ($hit['strGenre'] ?? '') ?: null, 'style' => ($hit['strStyle'] ?? '') ?: null, 'mood' => ($hit['strMood'] ?? '') ?: null,
            'members' => isset($hit['intMembers']) && $hit['intMembers'] !== '' ? (int) $hit['intMembers'] : null, 'country' => ($hit['strCountry'] ?? '') ?: null,
            'biography' => $bio ?? (trim((string) ($hit['strBiographyEN'] ?? '')) ?: null), 'biography_lang' => $bio ? 'de' : (! empty($hit['strBiographyEN']) ? 'en' : null),
            'images' => $images, 'logo' => ($hit['strArtistLogo'] ?? '') ?: null];
    }

    /** TheAudioDB-Ergebnis nur aus dem Zwischenspeicher (für Seiten, die nicht warten dürfen). */
    public function audiodbArtistCached(?string $mbid, string $name = ''): ?array
    {
        $cache = fn (string $path, array $q) => Cache::get('audiodb:'.$path.':'.md5(json_encode($q)));
        if (! ($mbid && ($cache('artist-mb.php', ['i' => $mbid])['artists'] ?? null)) && ! ($name !== '' && $cache('search.php', ['s' => $name]) !== null)) {
            return null;
        }

        return rescue(fn () => $this->audiodbArtist($mbid, $name), null, report: false); // liest dann nur aus dem Speicher
    }

    public function fanartArtistImages(string $mbid): array
    {
        $key = trim((string) $this->settings->get('fanart_key'));
        if ($key === '') {
            throw new RuntimeException('Kein Fanart.tv-API-Key in den Einstellungen.');
        }

        return $this->cached('fanart:'.$mbid, function () use ($mbid, $key) {
            $r = Http::timeout(15)->get('https://webservice.fanart.tv/v3/music/'.$mbid, ['api_key' => $key]);
            if ($r->status() === 404) {
                return [];
            }
            if (! $r->successful()) {
                throw new RuntimeException('Fanart.tv antwortet nicht ('.$r->status().')');
            }
            $out = [];
            foreach ($r->json('artistthumb') ?? [] as $img) {
                $out[] = ['url' => $img['url'], 'thumb' => str_replace('/fanart/', '/preview/', $img['url']), 'source' => 'Fanart.tv', 'size' => ''];
            }

            return $out;
        });
    }
}
