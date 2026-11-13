<?php

namespace App\Support\Lookup;

use App\Support\Settings;
use RuntimeException;

/** TheAudioDB: über die MusicBrainz-ID oder per Suche – Porträt, Fanart, Genre, Land, Biografie. */
class AudioDb
{
    public function __construct(private Client $client, private Settings $settings) {}

    public function byMbid(string $mbid): ?array
    {
        $hit = ($this->get('artist-mb.php', ['i' => $mbid])['artists'] ?? [])[0] ?? null;

        return $hit ? $this->candidate($hit, 100) : null;
    }

    /** @return list<array> */
    public function search(string $name): array
    {
        return array_map(fn ($a) => $this->candidate($a, 0), $this->get('search.php', ['s' => $name])['artists'] ?? []);
    }

    private function candidate(array $a, int $score): array
    {
        $images = [];
        foreach (['strArtistThumb' => 'portrait', 'strArtistFanart' => 'background', 'strArtistFanart2' => 'background'] as $field => $kind) {
            if (! empty($a[$field])) {
                $images[] = ['kind' => $kind, 'url' => $a[$field], 'preview_url' => $a[$field].'/preview', 'width' => null, 'height' => null];
            }
        }
        if (! empty($a['strArtistLogo'])) {
            $images[] = ['kind' => 'logo', 'url' => $a['strArtistLogo'], 'preview_url' => $a['strArtistLogo'].'/preview', 'width' => null, 'height' => null];
        }
        $bio = trim((string) ($a['strBiographyDE'] ?? '')) ?: trim((string) ($a['strBiographyEN'] ?? ''));

        return [
            'service' => 'audiodb',
            'external_id' => (string) $a['idArtist'],
            'mbid' => ($a['strMusicBrainzID'] ?? '') ?: null,
            'name' => (string) ($a['strArtist'] ?? ''),
            'score' => $score,
            'data' => array_filter([
                'genre' => ($a['strGenre'] ?? '') ?: null,
                'style' => ($a['strStyle'] ?? '') ?: null,
                'country' => ($a['strCountry'] ?? '') ?: null,
                'formed' => ($a['intFormedYear'] ?? '') ?: (($a['intBornYear'] ?? '') ?: null),
                'biography' => $bio !== '' ? mb_substr($bio, 0, 800) : null,
            ]),
            'aliases' => array_map(fn ($n) => ['alias' => trim($n), 'type' => 'Alternate', 'locale' => null, 'primary' => false],
                array_filter(explode(',', (string) ($a['strArtistAlternate'] ?? '')), fn ($n) => trim($n) !== '')),
            'images' => $images,
            'links' => [],
        ];
    }

    private function get(string $path, array $query): array
    {
        $key = trim((string) $this->settings->get('audiodb_key')) ?: '123'; // ohne eigenen Key: freier Test-Key
        $r = $this->client->get('audiodb', 'https://www.theaudiodb.com/api/v1/json/'.$key.'/'.$path, $query);
        if (! $r->successful()) {
            throw new RuntimeException('TheAudioDB antwortet nicht ('.$r->status().')');
        }

        return $r->json() ?? [];
    }
}
