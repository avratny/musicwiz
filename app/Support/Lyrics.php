<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Songtexte von LRCLIB (https://lrclib.net – frei, ohne Anmeldung). Synchronisierte Texte (LRC) haben Vorrang, sonst
 * der reine Text. „Nicht gefunden“ wird 30 Tage gemerkt, damit das Aufräumen dieselben Titel nicht immer wieder vorschlägt.
 */
class Lyrics
{
    private const URL = 'https://lrclib.net/api/get';

    /** @return string|null Text oder null (nicht gefunden) */
    public function find(string $artist, string $title, string $album, float $duration): ?string
    {
        $query = array_filter(['artist_name' => $artist, 'track_name' => $title, 'album_name' => $album, 'duration' => (int) round($duration)]);
        $response = Http::timeout(20)->withUserAgent('MusicWiz (https://github.com/avratny/musicwiz)')->get(self::URL, $query);
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();
        $data = $response->json();
        if ($data['instrumental'] ?? false) {
            return null;
        }
        $text = trim((string) ($data['syncedLyrics'] ?? '')) ?: trim((string) ($data['plainLyrics'] ?? ''));

        return $text !== '' ? $text : null;
    }

    public static function markMissing(string $path): void
    {
        Cache::put(self::missingKey($path), true, now()->addDays(30));
        Cache::increment(CleanupChecks::BUMP); // Aufräumen „Songtext fehlt“ neu rechnen
    }

    public static function knownMissing(string $path): bool
    {
        return Cache::has(self::missingKey($path));
    }

    private static function missingKey(string $path): string
    {
        return 'lyrics-missing:'.md5($path);
    }
}
