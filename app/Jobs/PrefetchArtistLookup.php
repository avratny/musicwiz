<?php

namespace App\Jobs;

use App\Support\ArtistQuickMatch;
use App\Support\CombiCheck;
use App\Support\Lookup;
use App\Support\ServiceThrottled;
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

    /** Wurde der Name schon vorgeladen – samt Prüfung bei Deezer und Spotify? (dann liegt alles im Zwischenspeicher) */
    public static function isFresh(string $name): bool
    {
        return isset(Cache::get(self::marker($name))['services']);
    }

    /**
     * Ergebnis des Vorladens (für „Schnell erfassen“): MusicBrainz-Treffer samt Liedprüfung, dazu der Künstler, unter dem
     * Deezer und Spotify eure Lieder führen (services; fehlt bei älteren Ergebnissen).
     *
     * @param  list<string>  $names
     * @return array<string, array{hits: list<array>, services?: array{deezer: ?array, spotify: ?array}}|null> Name => Ergebnis (null = noch nicht nachgeschlagen)
     */
    public static function results(array $names): array
    {
        if (! $names) {
            return [];
        }
        $keys = array_combine($names, array_map(fn ($n) => self::marker($n), $names));
        $found = Cache::many(array_values($keys));
        $out = array_map(fn ($k) => is_array($found[$k] ?? null) ? $found[$k] : null, $keys);
        // noch nicht neu geholt: Ergebnis von v2 anzeigen, aber als veraltet (stale) – „nachschlagen“ holt es neu
        $missing = array_keys(array_filter($out, fn ($r) => $r === null));
        if ($missing) {
            $old = Cache::many(array_map(fn ($n) => self::marker((string) $n, 2), $missing));
            foreach ($missing as $n) {
                if (is_array($r = $old[self::marker((string) $n, 2)] ?? null)) {
                    $out[$n] = $r + ['stale' => true];
                }
            }
        }

        return $out;
    }

    public static function prefetch(Lookup $lookup, string $name, array $titles): void
    {
        if (self::isFresh($name)) {
            return;
        }
        $result = null;
        // Spotify bremst (429): kein „nicht gefunden“ merken, sondern den Namen beim nächsten Vorladen erneut fragen
        $throttled = false;
        $spotify = function (callable $fn) use (&$throttled) {
            return rescue($fn, function ($e) use (&$throttled) {
                $throttled = $throttled || $e instanceof ServiceThrottled;

                return null;
            }, report: false);
        };
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
            // Unter welchem Künstler führen Deezer und Spotify eure Lieder? (Bestätigung, richtiges Bild, Tippfehler)
            function () use ($lookup, $name, $titles, $spotify, &$result) {
                if ($result !== null) {
                    $result['services'] = [
                        'deezer' => rescue(fn () => $lookup->deezerArtistBySongs($name, $titles), null, report: false),
                        'spotify' => $lookup->hasSpotify() ? $spotify(fn () => $lookup->spotifyArtistBySongs($name, $titles)) : null,
                    ];
                }
            },
            fn () => $lookup->hasDiscogs() ? $lookup->discogsArtists($name) : null,
            fn () => $lookup->deezerArtistImages($name),
            fn () => $lookup->hasSpotify() ? $spotify(fn () => $lookup->spotifyArtist($name, $titles)) : null,
            // Kombi-Namen: Act oder Zusammenarbeit? (eingetragene Künstler bei Deezer, Spotify, MusicBrainz)
            fn () => CombiCheck::applies($name) ? app(CombiCheck::class)->check($name) : null,
        ];
        foreach ($steps as $step) {
            rescue($step, report: false);
        }
        // MusicBrainz nicht erreichbar bzw. Spotify bremst: nicht als erledigt merken, beim nächsten Vorladen erneut
        // (was schon da ist, kommt dann aus dem Zwischenspeicher)
        if ($result !== null && ! $throttled) {
            Cache::put(self::marker($name), $result, now()->addDays(max(1, (int) config('musicwiz.lookup_cache_days', 10))));
            Cache::increment(ArtistQuickMatch::RESULTS_BUMP);
        }
    }

    /**
     * v2: mit Ergebnis (vorher nur ein Merker). v3: Spotify-429 wird nicht mehr als „nicht gefunden“ gespeichert – v2-Ergebnisse
     * können so falsch sein und werden einmal neu geholt (was geklappt hatte, kommt aus dem Zwischenspeicher).
     */
    private static function marker(string $name, int $version = 3): string
    {
        return 'artist-prefetched:v'.$version.':'.md5($name);
    }
}
