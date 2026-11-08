<?php

namespace App\Jobs;

use App\Support\Lookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Assistent „Neue Künstler“: Treffer, Liedprüfung, Details, Discogs und Bilder für einen Namen vorab nachschlagen –
 * dieselben Abfragen wie im Assistenten, sie landen im Cache. Öffnet man den Namen danach, ist alles sofort da.
 * Eigene Warteschlange (research), hält keine Dateiaktionen auf; Fehler sind egal (dann fragt der Assistent selbst).
 */
class PrefetchArtistLookup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $name, public array $titles)
    {
        $this->onQueue('research');
    }

    public function handle(Lookup $lookup): void
    {
        self::prefetch($lookup, $this->name, $this->titles);
    }

    /** Wurde der Name in den letzten Tagen schon vorgeladen? (dann liegt alles im Zwischenspeicher) */
    public static function isFresh(string $name): bool
    {
        return Cache::has(self::marker($name));
    }

    public static function prefetch(Lookup $lookup, string $name, array $titles): void
    {
        if (self::isFresh($name)) {
            return;
        }
        $steps = [
            function () use ($lookup, $name, $titles) {
                $hits = $lookup->artistCandidates($name, $titles);
                $withSongs = array_values(array_filter($hits, fn ($h) => $h['songs']));
                $exact = array_values(array_filter($hits, fn ($h) => $h['exact']));
                $pick = $withSongs[0] ?? (count($exact) === 1 ? $exact[0] : null);
                if ($pick) {
                    $lookup->musicbrainzArtistAliases($pick['mbid']); // Künstler-Details inkl. Genres
                }
            },
            fn () => $lookup->hasDiscogs() ? $lookup->discogsArtists($name) : null,
            fn () => $lookup->deezerArtistImages($name),
        ];
        foreach ($steps as $step) {
            rescue($step, report: false);
        }
        Cache::put(self::marker($name), true, now()->addDays(max(1, (int) config('musicwiz.lookup_cache_days', 10))));
    }

    private static function marker(string $name): string
    {
        return 'artist-prefetched:'.md5($name);
    }
}
