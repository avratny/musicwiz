<?php

namespace App\Jobs;

use App\Support\ArtistDirectory;
use App\Support\ArtistGenreSuggestions;
use App\Support\ArtistQuickMatch;
use App\Support\Lookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * „Schnell erfassen › Standard“ gesammelt übernehmen: MusicBrainz und Deezer bzw. Spotify finden eure Lieder unter genau
 * diesem Namen. Angelegt mit MusicBrainz-ID, Genre, Schreibweisen und einem Bild nur, wenn es sicher der richtige Künstler
 * ist (über die MusicBrainz-ID oder eines eurer Lieder) – sonst ohne Bild (Verzeichnis › Filter „ohne Bild“).
 * In Häppchen, die sich selbst weiterreichen; Tags werden wie immer nur vorgemerkt.
 */
class TakeStandardArtists implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 25;

    private const STATUS = 'take-standard.status';

    private const LIST = 'take-standard.list';

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('research');
    }

    /** @return int Anzahl übernommener Namen (0 = nichts zu tun oder läuft schon) */
    public static function start(): int
    {
        if (self::status()['running'] ?? false) {
            return 0;
        }
        $list = array_values(array_map(fn ($r) => array_intersect_key($r, array_flip(['name', 'key', 'hit', 'services'])),
            array_filter(app(ArtistQuickMatch::class)->rows(), fn ($r) => $r['kind'] === 'standard')));
        if (! $list) {
            return 0;
        }
        Cache::put(self::LIST, $list, now()->addDays(2));
        self::setStatus(['running' => true, 'done' => 0, 'total' => count($list), 'created' => 0, 'images' => 0, 'staged' => 0,
            'current' => null, 'started_at' => now()->toIso8601String(), 'finished_at' => null]);
        self::dispatch();

        return count($list);
    }

    public static function status(): array
    {
        $s = Cache::get(self::STATUS, []);
        if (($s['running'] ?? false) && isset($s['touched_at']) && now()->diffInMinutes($s['touched_at'], true) > 15) {
            $s['running'] = false; // hängengeblieben (Worker neu gestartet)
        }

        return $s;
    }

    private static function setStatus(array $merge): void
    {
        Cache::put(self::STATUS, array_merge(Cache::get(self::STATUS, []), $merge, ['touched_at' => now()->toIso8601String()]), now()->addDays(7));
    }

    public function handle(ArtistQuickMatch $quick, Lookup $lookup, ArtistGenreSuggestions $genres, ArtistDirectory $directory): void
    {
        $list = Cache::get(self::LIST, []);
        $chunk = array_splice($list, 0, self::CHUNK);
        $picks = [];
        foreach ($chunk as $r) {
            // inzwischen von Hand erfasst bzw. zugeordnet: auslassen
            if ($directory->check($r['name'])['status'] !== 'new') {
                continue;
            }
            $picks[] = ['name' => $r['name'], 'hit' => $r['hit'], 'mb_spelling' => $r['hit']['name'] !== $r['name'], 'image' => $quick->safeImage($r, $lookup)];
        }
        self::setStatus(['current' => $chunk ? end($chunk)['name'] : null]);
        $result = $picks ? rescue(fn () => $quick->save($picks, $lookup, $genres), ['created' => 0, 'staged' => 0]) : ['created' => 0, 'staged' => 0];
        $s = self::status();
        self::setStatus(['done' => ($s['done'] ?? 0) + count($chunk), 'created' => ($s['created'] ?? 0) + $result['created'],
            'staged' => ($s['staged'] ?? 0) + $result['staged'], 'images' => ($s['images'] ?? 0) + count(array_filter(array_column($picks, 'image')))]);
        Cache::put(self::LIST, $list, now()->addDays(2));
        if ($list) {
            self::dispatch();
        } else {
            self::setStatus(['running' => false, 'current' => null, 'finished_at' => now()->toIso8601String()]);
        }
    }
}
