<?php

namespace App\Support\Lookup;

use RuntimeException;
use Throwable;

/**
 * Titellisten zu einem Album aus dem Netz (ohne Schlüssel): MusicBrainz, Deezer, iTunes – je bis zu drei
 * Veröffentlichungen. Je Titel: CD, Nummer, Titel, Interpret, Länge (Sekunden). Fällt ein Dienst aus, kommen die übrigen
 * trotzdem – mit Hinweis.
 */
class Tracklists
{
    private const PER_SERVICE = 3;

    public function __construct(private Client $client) {}

    /** @return array{releases: list<array{service: string, title: string, artist: string, year: ?string, tracks: list<array>}>, errors: list<string>} */
    public function search(string $album, string $artist): array
    {
        $releases = [];
        $errors = [];
        foreach (['MusicBrainz' => fn () => $this->musicBrainz($album, $artist), 'Deezer' => fn () => $this->deezer($album, $artist),
            'iTunes' => fn () => $this->itunes($album, $artist)] as $service => $fn) {
            try {
                array_push($releases, ...array_filter($fn(), fn ($r) => $r['tracks']));
            } catch (Throwable $e) {
                $errors[] = $service.': '.$e->getMessage();
            }
        }

        return ['releases' => $releases, 'errors' => $errors];
    }

    private function musicBrainz(string $album, string $artist): array
    {
        $query = 'release:"'.addcslashes($album, '"\\').'"'.($artist !== '' ? ' AND artist:"'.addcslashes($artist, '"\\').'"' : '');
        $r = $this->ok($this->client->get('musicbrainz', 'https://musicbrainz.org/ws/2/release', ['query' => $query, 'limit' => self::PER_SERVICE, 'fmt' => 'json']));
        $out = [];
        foreach (array_slice($r->json('releases') ?? [], 0, self::PER_SERVICE) as $rel) {
            $d = $this->client->get('musicbrainz', 'https://musicbrainz.org/ws/2/release/'.$rel['id'], ['inc' => 'recordings artist-credits', 'fmt' => 'json']);
            if (! $d->successful()) {
                continue;
            }
            $tracks = [];
            foreach ($d->json('media') ?? [] as $medium) {
                foreach ($medium['tracks'] ?? [] as $t) {
                    $tracks[] = ['disc' => (int) ($medium['position'] ?? 1), 'n' => (int) ($t['position'] ?? 0), 'title' => (string) ($t['title'] ?? ''),
                        'artist' => self::credit($t['artist-credit'] ?? $d->json('artist-credit') ?? []), 'duration' => isset($t['length']) ? (int) round($t['length'] / 1000) : null];
                }
            }
            $out[] = ['service' => 'MusicBrainz', 'title' => (string) ($rel['title'] ?? $album), 'artist' => self::credit($rel['artist-credit'] ?? []),
                'year' => isset($rel['date']) ? substr($rel['date'], 0, 4) : null, 'tracks' => $tracks];
        }

        return $out;
    }

    private function deezer(string $album, string $artist): array
    {
        $q = ($artist !== '' ? 'artist:"'.$artist.'" ' : '').'album:"'.$album.'"';
        $r = $this->ok($this->client->get('deezer', 'https://api.deezer.com/search/album', ['q' => $q, 'limit' => self::PER_SERVICE]));
        $out = [];
        foreach (array_slice($r->json('data') ?? [], 0, self::PER_SERVICE) as $a) {
            $d = $this->client->get('deezer', 'https://api.deezer.com/album/'.$a['id'].'/tracks', ['limit' => 300]);
            if (! $d->successful()) {
                continue;
            }
            $tracks = array_map(fn ($t) => ['disc' => (int) ($t['disk_number'] ?? 1), 'n' => (int) ($t['track_position'] ?? 0), 'title' => (string) ($t['title'] ?? ''),
                'artist' => (string) ($t['artist']['name'] ?? ''), 'duration' => isset($t['duration']) ? (int) $t['duration'] : null], $d->json('data') ?? []);
            $out[] = ['service' => 'Deezer', 'title' => (string) ($a['title'] ?? ''), 'artist' => (string) ($a['artist']['name'] ?? ''), 'year' => null, 'tracks' => $tracks];
        }

        return $out;
    }

    private function itunes(string $album, string $artist): array
    {
        $r = $this->ok($this->client->get('itunes', 'https://itunes.apple.com/search', ['term' => trim($artist.' '.$album), 'entity' => 'album', 'limit' => self::PER_SERVICE, 'country' => 'DE']));
        $out = [];
        foreach (array_slice($r->json('results') ?? [], 0, self::PER_SERVICE) as $a) {
            if (empty($a['collectionId'])) {
                continue;
            }
            $d = $this->client->get('itunes', 'https://itunes.apple.com/lookup', ['id' => $a['collectionId'], 'entity' => 'song', 'country' => 'DE']);
            if (! $d->successful()) {
                continue;
            }
            $tracks = [];
            foreach ($d->json('results') ?? [] as $t) {
                if (($t['wrapperType'] ?? '') === 'track') {
                    $tracks[] = ['disc' => (int) ($t['discNumber'] ?? 1), 'n' => (int) ($t['trackNumber'] ?? 0), 'title' => (string) ($t['trackName'] ?? ''),
                        'artist' => (string) ($t['artistName'] ?? ''), 'duration' => isset($t['trackTimeMillis']) ? (int) round($t['trackTimeMillis'] / 1000) : null];
                }
            }
            $out[] = ['service' => 'iTunes', 'title' => (string) ($a['collectionName'] ?? ''), 'artist' => (string) ($a['artistName'] ?? ''),
                'year' => isset($a['releaseDate']) ? substr($a['releaseDate'], 0, 4) : null, 'tracks' => $tracks];
        }

        return $out;
    }

    private function ok($response)
    {
        if (! $response->successful()) {
            throw new RuntimeException('antwortet nicht ('.$response->status().')');
        }

        return $response;
    }

    /** MusicBrainz: „artist-credit“ als Text („A feat. B“) */
    private static function credit(array $credit): string
    {
        return trim(implode('', array_map(fn ($c) => ($c['name'] ?? $c['artist']['name'] ?? '').($c['joinphrase'] ?? ''), $credit)));
    }
}
