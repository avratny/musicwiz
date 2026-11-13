<?php

namespace App\Support\Lookup;

use App\Support\Settings;
use RuntimeException;

/** Fanart.tv: Bilder über die MusicBrainz-ID – Porträts (bis zu 3), ein Hintergrund, ein Logo. */
class FanartTv
{
    public function __construct(private Client $client, private Settings $settings) {}

    public function available(): bool
    {
        return (bool) $this->settings->get('fanart_key');
    }

    public function byMbid(string $mbid): ?array
    {
        $r = $this->client->get('fanart', 'https://webservice.fanart.tv/v3/music/'.$mbid, ['api_key' => $this->settings->get('fanart_key')]);
        if ($r->status() === 404) {
            return null;
        }
        if (! $r->successful()) {
            throw new RuntimeException('Fanart.tv antwortet nicht ('.$r->status().')');
        }
        $byLikes = fn (array $list) => collect($list)->sortByDesc(fn ($i) => (int) ($i['likes'] ?? 0))->values()->all();
        $image = fn (array $i, string $kind) => ['kind' => $kind, 'url' => $i['url'], 'preview_url' => str_replace('/fanart/', '/preview/', $i['url']), 'width' => null, 'height' => null];
        $images = [
            ...array_map(fn ($i) => $image($i, 'portrait'), array_slice($byLikes($r->json('artistthumb') ?? []), 0, 3)),
            ...array_map(fn ($i) => $image($i, 'background'), array_slice($byLikes($r->json('artistbackground') ?? []), 0, 1)),
            ...array_map(fn ($i) => $image($i, 'logo'), array_slice($byLikes($r->json('hdmusiclogo') ?? []), 0, 1)),
        ];
        if (! $images) {
            return null;
        }

        return ['service' => 'fanart', 'external_id' => $mbid, 'mbid' => $mbid, 'name' => (string) ($r->json('name') ?? ''), 'score' => 100,
            'data' => [], 'aliases' => [], 'images' => $images, 'links' => []];
    }
}
