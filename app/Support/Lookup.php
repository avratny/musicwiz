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
            'name' => $a['name'],
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
                    'tracks' => null, 'genres' => array_values(array_unique(array_map('ucwords', $genres))), 'url' => 'https://musicbrainz.org/release-group/'.$rg['id']];
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
