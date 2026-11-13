<?php

namespace App\Support\Lookup;

use App\Support\Settings;
use RuntimeException;

/**
 * Last.fm: Hörer und Wiedergaben, die von Last.fm korrigierte Schreibweise („Beatles“ → „The Beatles“), Tags (als
 * Genres) und eine kurze Bio. Keine Bilder (liefert Last.fm seit Jahren nicht mehr). Achtung: Last.fm kennt jeden Namen,
 * der irgendwo abgespielt wurde – „gefunden“ allein sagt wenig, aussagekräftig sind die Zahlen.
 */
class LastFm
{
    private const API = 'https://ws.audioscrobbler.com/2.0/';

    public function __construct(private Client $client, private Settings $settings) {}

    public function available(): bool
    {
        return (bool) $this->settings->get('lastfm_api_key');
    }

    /** Nach Namen (mit Korrektur der Schreibweise) – null, wenn Last.fm ihn nicht kennt */
    public function byName(string $name): ?array
    {
        return $this->info(['artist' => $name, 'autocorrect' => 1]);
    }

    /** Über die MusicBrainz-ID – genau derselbe Interpret */
    public function byMbid(string $mbid): ?array
    {
        return $this->info(['mbid' => $mbid]);
    }

    private function info(array $query): ?array
    {
        $r = $this->client->get('lastfm', self::API, $query + ['method' => 'artist.getinfo', 'api_key' => $this->settings->get('lastfm_api_key'), 'format' => 'json']);
        $json = $r->json() ?? [];
        // Last.fm meldet „gibt es nicht“ als Fehler 6 (je nach Fall mit HTTP 200 oder 400)
        if (($json['error'] ?? null) === 6) {
            return null;
        }
        if (! $r->successful() || isset($json['error'])) {
            throw new RuntimeException('Last.fm antwortet nicht ('.($json['message'] ?? $r->status()).')');
        }
        $a = $json['artist'] ?? null;
        if (! $a || ! isset($a['name'])) {
            return null;
        }
        $bio = trim(html_entity_decode(strip_tags(preg_replace('~<a href="https://www\.last\.fm[^>]*>.*?</a>\.?~s', '', (string) ($a['bio']['summary'] ?? '')))));

        return [
            'service' => 'lastfm',
            // Last.fm hat keine eigene ID: MusicBrainz-ID, sonst der Name (gekürzt)
            'external_id' => ($a['mbid'] ?? '') ?: mb_substr((string) $a['name'], 0, 64),
            'mbid' => ($a['mbid'] ?? '') ?: null,
            'name' => (string) $a['name'],
            'score' => 0,
            'data' => array_filter([
                'listeners' => isset($a['stats']['listeners']) ? (int) $a['stats']['listeners'] : null,
                'playcount' => isset($a['stats']['playcount']) ? (int) $a['stats']['playcount'] : null,
                'genres' => array_slice(array_map(fn ($t) => $t['name'], $a['tags']['tag'] ?? []), 0, 5),
                'biography' => $bio !== '' ? mb_substr($bio, 0, 600) : null,
                'url' => $a['url'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
            'aliases' => [],
            'images' => [],
            'links' => [],
        ];
    }
}
