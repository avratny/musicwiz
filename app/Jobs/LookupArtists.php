<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\ArtistSource;
use App\Support\Activity;
use App\Support\Lookup\ArtistLookup;
use App\Support\Lookup\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Interpreten im Hintergrund abgleichen (Lib und Inbox, die mit den meisten Liedern zuerst), paketweise: zwischen zwei
 * Paketen kommen Einzelaufträge („neu abgleichen“) dran; „Anhalten“ wirkt nach dem laufenden Interpreten. War ein Dienst
 * gesperrt, geht es mit den übrigen weiter – das Gesperrte wird nach der Sperre nachgeholt.
 */
class LookupArtists implements ShouldQueue
{
    use Queueable;

    private const BATCH = 25;

    private const STOP = 'lookup:stop';

    public int $timeout = 0;

    /** Abgleich starten, falls er nicht schon läuft */
    public static function start(): bool
    {
        if (Activity::running('lookup') || ! self::pending()->exists()) {
            return false;
        }
        Cache::forget(self::STOP);
        Activity::start('lookup', 'Interpreten abgleichen', self::pending()->count(), 'startet …');
        self::dispatch()->onQueue('lookup');

        return true;
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    /**
     * Interpreten mit Liedern in Lib oder Inbox, noch nie oder vor längerer Zeit abgeglichen. Dazu: ein Dienst ist neu
     * dazugekommen (z. B. Last.fm), ein Dienst war gesperrt und antwortet wieder, oder ein Fehler liegt länger zurück
     * (lookup_retry_days) – die übrigen Dienste kommen dann aus dem Zwischenspeicher.
     */
    public static function pending(): Builder
    {
        $linked = DB::table('track_artist')->join('tracks', 'tracks.id', '=', 'track_artist.track_id')
            ->whereIn('tracks.location', ['library', 'inbox'])->select('track_artist.artist_id');
        $services = array_keys(ArtistSource::SERVICES);
        $open = array_values(array_filter($services, fn ($s) => ! Client::blockedUntil($s)));

        return Artist::query()->whereIn('id', $linked)
            ->where(fn ($q) => $q->whereNull('looked_up_at')->orWhere('looked_up_at', '<', now()->subDays((int) config('musicwiz.lookup_refresh_days')))
                ->orWhereRaw('(SELECT COUNT(*) FROM artist_lookups WHERE artist_lookups.artist_id = artists.id AND artist_lookups.service IN ('.implode(',', array_fill(0, count($services), '?')).')) < ?', [...$services, count($services)])
                ->orWhereExists(fn ($e) => $e->from('artist_lookups')->whereColumn('artist_lookups.artist_id', 'artists.id')
                    ->where(fn ($w) => $w->where(fn ($g) => $g->where('status', 'gesperrt')->whereIn('service', $open ?: ['-']))
                        ->orWhere(fn ($f) => $f->where('status', 'fehler')->where('fetched_at', '<', now()->subDays((int) config('musicwiz.lookup_retry_days')))))));
    }

    /** frühestes Ende einer Sperre, auf die gesperrte Abgleiche warten – oder null */
    public static function waitingUntil(): ?int
    {
        $blocked = DB::table('artist_lookups')->where('status', 'gesperrt')->distinct()->pluck('service')
            ->map(fn ($s) => Client::blockedUntil($s))->filter();

        return $blocked->isEmpty() ? null : $blocked->min();
    }

    public function handle(ArtistLookup $lookup): void
    {
        $state = Activity::current('lookup');
        $done = $state['running'] ? $state['done'] : 0;
        $remaining = self::pending()->count();
        $total = $done + $remaining;
        if (! $state['running']) {
            Activity::start('lookup', 'Interpreten abgleichen', $total);
        }
        $started = microtime(true);
        $batch = self::pending()
            ->orderByRaw('(SELECT COUNT(*) FROM track_artist WHERE track_artist.artist_id = artists.id) DESC')
            ->limit(self::BATCH)->get();
        foreach ($batch as $i => $artist) {
            if (Cache::get(self::STOP)) {
                break;
            }
            $perArtist = $i ? (microtime(true) - $started) / $i : null;
            $eta = $perArtist ? ' · noch ca. '.self::duration((int) ($perArtist * ($total - $done))) : '';
            Activity::progress('lookup', $done, $total, null, $artist->name.$eta);
            try {
                $lookup->run($artist);
            } catch (Throwable $e) {
                report($e);
            }
            $done++;
        }
        Activity::progress('lookup', $done, $total);
        if (Cache::pull(self::STOP)) {
            Activity::finish('lookup', 'Abgleich angehalten – '.$done.' von '.$total.' Interpreten');
        } elseif (self::pending()->exists()) {
            self::dispatch()->onQueue('lookup'); // nächstes Paket
        } elseif ($until = self::waitingUntil()) {
            // nur noch Gesperrtes offen: nach der Sperre nachholen
            Activity::progress('lookup', $done, $total, null, 'warte auf gesperrten Dienst – weiter ab '.date('H:i', $until).' Uhr');
            self::dispatch()->onQueue('lookup')->delay(now()->setTimestamp($until + 5));
        } else {
            Client::prune();
            Activity::finish('lookup', 'Abgleich fertig – '.$done.' Interpreten');
        }
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish('lookup', 'Abgleich abgebrochen – '.mb_substr((string) $e?->getMessage(), 0, 150));
    }

    private static function duration(int $seconds): string
    {
        return $seconds >= 3600 ? round($seconds / 3600, 1).' h' : max(1, (int) round($seconds / 60)).' min';
    }
}
