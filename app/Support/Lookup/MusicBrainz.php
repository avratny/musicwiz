<?php

namespace App\Support\Lookup;

use App\Support\ArtistMatcher;
use App\Support\MusicBrainzData;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * MusicBrainz: Suche nach Namen, dann für die besten Treffer die Details – Schreibweisen (Aliase), Typ, Land, Zusatz,
 * Genres und Links zu Spotify/Discogs (damit finden wir dort genau denselben Interpreten).
 * Zuerst in den lokalen Daten (Einstellungen › MusicBrainz); die Schnittstelle nur, wenn lokal nichts passt.
 */
class MusicBrainz
{
    private const API = 'https://musicbrainz.org/ws/2/';

    public function __construct(private Client $client, private MusicBrainzData $local) {}

    /** @return list<array> Treffer */
    public function search(string $name, int $details = 2): array
    {
        if ($hits = $this->local->search($name, $details)) {
            return $hits;
        }
        $r = $this->searchRequest($name);
        // Treffer, deren Name oder Schreibweise zum gesuchten Namen passt, zuerst – auch unter Score 80: bei „Bill Haley“
        // steht die Gruppe „Bill Haley and His Comets“ (100) vor der Person „Bill Haley“ (79)
        $key = ArtistMatcher::key($name);
        $fits = fn ($a) => in_array($key, array_map([ArtistMatcher::class, 'key'], [$a['name'] ?? '', ...array_column($a['aliases'] ?? [], 'name')]), true);
        $hits = array_values(array_filter($r->json('artists') ?? [], fn ($a) => $fits($a) || ($a['score'] ?? 0) >= 80));
        usort($hits, fn ($a, $b) => [! $fits($a), -($a['score'] ?? 0)] <=> [! $fits($b), -($b['score'] ?? 0)]);
        $out = [];
        foreach (array_slice($hits, 0, $details) as $hit) {
            $out[] = $this->artist($hit['id'], (int) $hit['score']);
        }

        return array_values(array_filter($out));
    }

    /**
     * Wie viele Interpreten heißen genau so (gleicher Vergleichsschlüssel, nur Namen, keine Schreibweisen)? Ab 2 sind
     * Treffer und Zahlen anderer Dienste womöglich von jemand anderem. Ohne lokale Daten aus der Suche (höchstens 8).
     */
    public function namesakes(string $name): int
    {
        if ($this->local->available()) {
            return $this->local->namesakes($name);
        }
        $key = ArtistMatcher::key($name);

        return count(array_filter($this->searchRequest($name)->json('artists') ?? [], fn ($a) => ArtistMatcher::key($a['name'] ?? '') === $key));
    }

    /** Suche bei der Schnittstelle (dieselbe Abfrage wie search() – kommt also meist aus dem Zwischenspeicher) */
    private function searchRequest(string $name): Response
    {
        $r = $this->client->get('musicbrainz', self::API.'artist', ['query' => 'artist:"'.addcslashes($name, '"\\').'" OR alias:"'.addcslashes($name, '"\\').'"', 'limit' => 8, 'fmt' => 'json']);
        if (! $r->successful()) {
            throw new RuntimeException('MusicBrainz antwortet nicht ('.$r->status().')');
        }

        return $r;
    }

    /**
     * Mitglieder einer Gruppe – lokal, sonst aus den Details der Schnittstelle (zwischengespeichert).
     *
     * @return list<array{mbid: string, name: string, type: ?string, ended: bool}>
     */
    public function members(string $groupMbid): array
    {
        return $this->local->available() ? $this->local->members($groupMbid) : ($this->artist($groupMbid)['members'] ?? []);
    }

    /** Zahl der Veröffentlichungen (Release-Groups: Album, Single … je einmal, ohne die einzelnen Ausgaben) */
    public function releaseCount(string $mbid): ?int
    {
        if (($n = $this->local->releases($mbid)) !== null) {
            return $n;
        }
        $r = $this->client->get('musicbrainz', self::API.'release-group', ['artist' => $mbid, 'limit' => 1, 'fmt' => 'json']);
        if (! $r->successful()) {
            return null;
        }

        return $r->json('release-group-count');
    }

    public function artist(string $mbid, int $score = 100): ?array
    {
        if ($local = $this->local->artist($mbid, $score)) {
            return $local;
        }
        $r = $this->client->get('musicbrainz', self::API.'artist/'.$mbid, ['inc' => 'aliases+url-rels+genres+artist-rels', 'fmt' => 'json']);
        if ($r->status() === 404) {
            return null;
        }
        if (! $r->successful()) {
            throw new RuntimeException('MusicBrainz antwortet nicht ('.$r->status().')');
        }
        $a = $r->json();
        $links = ['spotify' => [], 'discogs' => []];
        $members = [];
        foreach ($a['relations'] ?? [] as $rel) {
            // Gruppe: „member of band“ rückwärts → Mitglied
            if (($rel['type'] ?? '') === 'member of band' && ($rel['direction'] ?? '') === 'backward' && isset($rel['artist']['id'])) {
                $members[] = ['mbid' => $rel['artist']['id'], 'name' => (string) ($rel['artist']['name'] ?? ''), 'type' => $rel['artist']['type'] ?? null, 'ended' => (bool) ($rel['ended'] ?? false)];

                continue;
            }
            $url = (string) ($rel['url']['resource'] ?? '');
            if (preg_match('~open\.spotify\.com/artist/([A-Za-z0-9]+)~', $url, $m)) {
                $links['spotify'][] = $m[1];
            } elseif (preg_match('~discogs\.com/artist/(\d+)~', $url, $m)) {
                $links['discogs'][] = $m[1];
            }
        }
        $genres = $a['genres'] ?? [];
        usort($genres, fn ($x, $y) => ($y['count'] ?? 0) <=> ($x['count'] ?? 0));

        return [
            'service' => 'musicbrainz',
            'external_id' => $a['id'],
            'mbid' => $a['id'],
            'name' => $a['name'] ?? '',
            'score' => $score,
            'data' => array_filter([
                'type' => $a['type'] ?? null,
                'country' => $a['country'] ?? null,
                'disambiguation' => ($a['disambiguation'] ?? '') ?: null,
                'sort_name' => $a['sort-name'] ?? null,
                'begin' => $a['life-span']['begin'] ?? null,
                'end' => $a['life-span']['end'] ?? null,
                'genres' => array_slice(array_map(fn ($g) => $g['name'], $genres), 0, 5),
                'url' => 'https://musicbrainz.org/artist/'.$a['id'],
            ]),
            'aliases' => array_map(fn ($al) => ['alias' => $al['name'], 'type' => $al['type'] ?? null, 'locale' => $al['locale'] ?? null, 'primary' => (bool) ($al['primary'] ?? false)], $a['aliases'] ?? []),
            'images' => [],
            'links' => array_map('array_unique', $links),
            'members' => $members,
        ];
    }
}
