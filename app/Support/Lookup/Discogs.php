<?php

namespace App\Support\Lookup;

use App\Support\Settings;
use RuntimeException;

/** Discogs: Interpret über die ID (Link aus MusicBrainz) oder per Suche – Namensvarianten, Profil, Bilder. */
class Discogs
{
    public function __construct(private Client $client, private Settings $settings) {}

    public function available(): bool
    {
        return (bool) $this->settings->get('discogs_token');
    }

    public function artist(string $id, int $score = 100): ?array
    {
        $r = $this->get('artists/'.$id);
        if ($r === null) {
            return null;
        }
        $images = $r['images'] ?? [];
        usort($images, fn ($x, $y) => ($x['type'] ?? '') === 'primary' ? -1 : (($y['type'] ?? '') === 'primary' ? 1 : 0));

        return [
            'service' => 'discogs',
            'external_id' => (string) $r['id'],
            'name' => preg_replace('/\s\(\d+\)$/', '', (string) ($r['name'] ?? '')), // „Adam (12)“ → „Adam“
            'score' => $score,
            'data' => array_filter([
                'realname' => $r['realname'] ?? null,
                'profile' => isset($r['profile']) ? mb_substr(trim(preg_replace('/\[[a-z]=?[^\]]*\]/i', '', $r['profile'])), 0, 600) : null,
                'url' => $r['uri'] ?? null,
            ]),
            'aliases' => array_map(fn ($n) => ['alias' => $n, 'type' => 'Name variation', 'locale' => null, 'primary' => false], array_slice($r['namevariations'] ?? [], 0, 30)),
            'images' => array_map(fn ($i) => ['kind' => 'portrait', 'url' => $i['uri'], 'preview_url' => $i['uri150'] ?? $i['uri'], 'width' => $i['width'] ?? null, 'height' => $i['height'] ?? null],
                array_slice(array_filter($images, fn ($i) => ! empty($i['uri'])), 0, 3)),
            'links' => [],
        ];
    }

    /** Zahl der Veröffentlichungen (laut Discogs, auch Mitwirkung) */
    public function releaseCount(string $id): ?int
    {
        $r = $this->get('artists/'.$id.'/releases', ['per_page' => 1]);

        return isset($r['pagination']['items']) ? (int) $r['pagination']['items'] : null;
    }

    /** @return list<array> Treffer der Suche (ohne Details) */
    public function search(string $name): array
    {
        $r = $this->get('database/search', ['q' => $name, 'type' => 'artist', 'per_page' => 5]);

        return array_map(fn ($h) => ['id' => (string) $h['id'], 'name' => preg_replace('/\s\(\d+\)$/', '', (string) ($h['title'] ?? ''))], $r['results'] ?? []);
    }

    private function get(string $path, array $query = []): ?array
    {
        $r = $this->client->get('discogs', 'https://api.discogs.com/'.$path, $query, ['Authorization' => 'Discogs token='.$this->settings->get('discogs_token')]);
        if ($r->status() === 404) {
            return null;
        }
        if (! $r->successful()) {
            throw new RuntimeException('Discogs antwortet nicht ('.$r->status().')');
        }

        return $r->json() ?? [];
    }
}
