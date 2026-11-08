<?php

namespace App\Jobs;

use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistNames;
use App\Support\Lookup;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Alle Künstler, die noch nicht im Verzeichnis stehen, im Hintergrund nachschlagen (Inbox zuerst, dann Lib nach
 * Häufigkeit) – danach öffnet der Assistent bzw. der Dialog im Editor sofort. MusicBrainz erlaubt nur etwa eine Abfrage
 * pro Sekunde: deshalb in kleinen Häppchen, die sich selbst weiterreichen – Dateiaktionen kommen dazwischen dran.
 */
class PrefetchUnknownArtists implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 5;

    private const STATUS = 'artist-prefetch.status';

    private const LIST = 'artist-prefetch.list';

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('research');
    }

    /** Vorladen starten (nicht doppelt, nicht bei sync-Warteschlange). @return bool gestartet? */
    public static function start(): bool
    {
        if (config('queue.default') === 'sync' || (self::status()['running'] ?? false)) {
            return false;
        }
        $list = array_values(array_filter(self::unknownNames(), fn ($e) => ! PrefetchArtistLookup::isFresh($e['name'])));
        if (! $list) {
            return false;
        }
        Cache::put(self::LIST, $list, now()->addDay());
        $inbox = count(array_filter($list, fn ($e) => $e['location'] === 'inbox'));
        self::setStatus(['running' => true, 'done' => 0, 'total' => count($list), 'inbox' => $inbox, 'library' => count($list) - $inbox,
            'current' => null, 'started_at' => now()->toIso8601String(), 'finished_at' => null]);
        self::dispatch();

        return true;
    }

    public static function status(): array
    {
        $s = Cache::get(self::STATUS, []);
        // hängengebliebener Lauf (Worker neu gestartet): nach 10 Minuten ohne Fortschritt als beendet ansehen
        if (($s['running'] ?? false) && isset($s['touched_at']) && now()->diffInMinutes($s['touched_at'], true) > 10) {
            $s['running'] = false;
        }

        return $s;
    }

    private static function setStatus(array $merge): void
    {
        Cache::put(self::STATUS, array_merge(Cache::get(self::STATUS, []), $merge, ['touched_at' => now()->toIso8601String()]), now()->addDays(2));
    }

    public function handle(Lookup $lookup): void
    {
        $list = Cache::get(self::LIST, []);
        $chunk = array_splice($list, 0, self::CHUNK);
        foreach ($chunk as $entry) {
            self::setStatus(['current' => $entry['name']]);
            PrefetchArtistLookup::prefetch($lookup, $entry['name'], $entry['titles']);
            self::setStatus(['done' => (self::status()['done'] ?? 0) + 1]);
        }
        Cache::put(self::LIST, $list, now()->addDay());
        if ($list) {
            self::dispatch(); // nächstes Häppchen – Dateiaktionen (Warteschlange default) kommen vorher dran
        } else {
            self::setStatus(['running' => false, 'current' => null, 'finished_at' => now()->toIso8601String()]);
        }
    }

    /**
     * Noch nicht erfasste Namen mit bis zu drei Liedtiteln (für die Liedprüfung): Inbox zuerst, dann Lib, je nach Häufigkeit.
     *
     * @return list<array{name:string, titles:list<string>, location:string}> location: wo der Name zuerst gefunden wurde
     */
    public static function unknownNames(): array
    {
        $directory = app(ArtistDirectory::class);
        $skip = mb_strtolower((string) app(Settings::class)->get('compilation_artist'));
        $found = ['inbox' => [], 'library' => []];
        foreach (Track::whereIn('location', ['inbox', 'library'])->select(['location', 'title', 'artist', 'artists', 'albumartist', 'albumartists'])->cursor() as $t) {
            $names = array_unique(array_merge($t->artists ?: ArtistNames::split($t->artist), ArtistDirectory::albumArtistNames($t)));
            foreach ($names as $n) {
                if ($n === '' || mb_strtolower($n) === $skip) {
                    continue;
                }
                $e = &$found[$t->location][$n];
                $e['count'] = ($e['count'] ?? 0) + 1;
                if ($t->title && count($e['titles'] ?? []) < 3 && ! in_array($t->title, $e['titles'] ?? [], true)) {
                    $e['titles'][] = $t->title;
                }
                unset($e);
            }
        }
        $out = [];
        foreach (['inbox', 'library'] as $location) {
            uasort($found[$location], fn ($a, $b) => $b['count'] <=> $a['count']);
            foreach ($found[$location] as $name => $e) {
                if (! isset($out[$name]) && in_array($directory->check($name)['status'], ['new', 'similar'], true)) {
                    $out[$name] = ['name' => (string) $name, 'titles' => $e['titles'] ?? [], 'location' => $location];
                }
            }
        }

        return array_values($out);
    }
}
