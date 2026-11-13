<?php

namespace App\Support\Lookup;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Spotify: Interpreten über die ID (Link aus MusicBrainz) oder per Suche – Bild, Genres, Popularität, Follower. */
class Spotify
{
    public function __construct(private Client $client, private Settings $settings) {}

    public function available(): bool
    {
        return $this->settings->get('spotify_client_id') && $this->settings->get('spotify_client_secret');
    }

    /** @return list<array> */
    public function byIds(array $ids): array
    {
        // „mehrere auf einmal“ (artists?ids=) ist bei Spotify gesperrt (403) – einzeln abfragen
        $out = [];
        foreach (array_slice($ids, 0, 3) as $id) {
            $a = $this->get('artists/'.$id, []);
            if (! empty($a['id'])) {
                $out[] = $this->candidate($a, 100);
            }
        }

        return $out;
    }

    /** @return list<array> */
    public function search(string $name): array
    {
        $r = $this->get('search', ['q' => 'artist:'.$name, 'type' => 'artist', 'limit' => 5]);

        return array_map(fn ($a) => $this->candidate($a, 0), $r['artists']['items'] ?? []);
    }

    private function candidate(array $a, int $score): array
    {
        $images = $a['images'] ?? [];
        usort($images, fn ($x, $y) => ($y['width'] ?? 0) <=> ($x['width'] ?? 0));
        $preview = collect($images)->first(fn ($i) => ($i['width'] ?? 0) <= 400) ?? end($images) ?: null;

        return [
            'service' => 'spotify',
            'external_id' => $a['id'],
            'name' => $a['name'] ?? '',
            'score' => $score,
            'data' => array_filter([
                'genres' => array_slice($a['genres'] ?? [], 0, 5),
                'popularity' => $a['popularity'] ?? null,
                'followers' => $a['followers']['total'] ?? null,
                'url' => $a['external_urls']['spotify'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
            'aliases' => [],
            // Spotify hat je Interpret ein Bild in mehreren Größen – davon eins
            'images' => $images ? [['kind' => 'portrait', 'url' => $images[0]['url'], 'preview_url' => $preview['url'] ?? $images[0]['url'],
                'width' => $images[0]['width'] ?? null, 'height' => $images[0]['height'] ?? null]] : [],
            'links' => [],
        ];
    }

    private function get(string $path, array $query): array
    {
        $r = $this->client->get('spotify', 'https://api.spotify.com/v1/'.$path, $query, [], $this->token());
        if ($r->status() === 401) {
            Cache::forget($this->tokenKey());
            $r = $this->client->get('spotify', 'https://api.spotify.com/v1/'.$path, $query, [], $this->token());
        }
        if (! $r->successful()) {
            throw new RuntimeException('Spotify antwortet nicht ('.$r->status().')');
        }

        return $r->json() ?? [];
    }

    private function tokenKey(): string
    {
        return 'spotify:token:'.md5((string) $this->settings->get('spotify_client_id'));
    }

    private function token(): string
    {
        return Cache::remember($this->tokenKey(), now()->addMinutes(50), function () {
            $r = Http::asForm()->timeout(15)->withBasicAuth((string) $this->settings->get('spotify_client_id'), (string) $this->settings->get('spotify_client_secret'))
                ->post('https://accounts.spotify.com/api/token', ['grant_type' => 'client_credentials']);
            if (! $r->successful()) {
                throw new RuntimeException('Spotify-Anmeldung fehlgeschlagen ('.$r->status().') – Client-ID/Secret prüfen');
            }

            return (string) $r->json('access_token');
        });
    }
}
