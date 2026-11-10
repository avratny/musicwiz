<?php

namespace App\Jobs;

use App\Support\CombiCheck;
use App\Support\Lookup;
use App\Support\Settings;
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

    /**
     * Ergebnis des Vorladens (für „Schnell erfassen“): MusicBrainz-Treffer samt Liedprüfung.
     *
     * @param  list<string>  $names
     * @return array<string, array{hits: list<array>}|null> Name => Ergebnis (null = noch nicht nachgeschlagen)
     */
    public static function results(array $names): array
    {
        if (! $names) {
            return [];
        }
        $keys = array_combine($names, array_map(fn ($n) => self::marker($n), $names));
        $found = Cache::many(array_values($keys));

        return array_map(fn ($k) => is_array($found[$k] ?? null) ? $found[$k] : null, $keys);
    }

    public static function prefetch(Lookup $lookup, string $name, array $titles): void
    {
        if (self::isFresh($name)) {
            return;
        }
        $result = null;
        $steps = [
            function () use ($lookup, $name, $titles, &$result) {
                $hits = $lookup->artistCandidates($name, $titles);
                $result = ['hits' => array_map(fn ($h) => array_intersect_key($h, array_flip(['name', 'mbid', 'disambiguation', 'country', 'type', 'score', 'exact', 'songs'])), $hits)];
                $withSongs = array_values(array_filter($hits, fn ($h) => $h['songs']));
                $exact = array_values(array_filter($hits, fn ($h) => $h['exact']));
                $pick = $withSongs[0] ?? (count($exact) === 1 ? $exact[0] : null);
                if ($pick) {
                    $lookup->musicbrainzArtistAliases($pick['mbid']); // Künstler-Details inkl. Genres
                    // Bilder zur MusicBrainz-ID (für „Schnell erfassen“ und den Assistenten)
                    if (trim((string) app(Settings::class)->get('fanart_key')) !== '') {
                        rescue(fn () => $lookup->fanartArtistImages($pick['mbid']), report: false);
                    }
                    rescue(fn () => $lookup->audiodbArtist($pick['mbid'], $name), report: false);
                }
            },
            fn () => $lookup->hasDiscogs() ? $lookup->discogsArtists($name) : null,
            fn () => $lookup->deezerArtistImages($name),
            fn () => $lookup->hasSpotify() ? $lookup->spotifyArtist($name, $titles) : null,
            // Kombi-Namen: Act oder Zusammenarbeit? (eingetragene Künstler bei Deezer, Spotify, MusicBrainz)
            fn () => CombiCheck::applies($name) ? app(CombiCheck::class)->check($name) : null,
        ];
        foreach ($steps as $step) {
            rescue($step, report: false);
        }
        // MusicBrainz nicht erreichbar: nicht als erledigt merken, beim nächsten Vorladen erneut
        if ($result !== null) {
            Cache::put(self::marker($name), $result, now()->addDays(max(1, (int) config('musicwiz.lookup_cache_days', 10))));
        }
    }

    /** v2: mit Ergebnis (vorher nur ein Merker) – ältere Einträge werden einmal neu (meist aus dem Zwischenspeicher) geholt */
    private static function marker(string $name): string
    {
        return 'artist-prefetched:v2:'.md5($name);
    }
}
