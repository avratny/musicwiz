<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Support\Activity;
use App\Support\ClearArtists;
use App\Support\Lookup\RecordingEvidence;
use App\Support\Lookup\Throttled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Interpreten › Wahrscheinlich › „Lieder prüfen“: für unsichere Interpreten mit Gleichnamigen bei MusicBrainz nach
 * unseren Titeln suchen (RecordingEvidence) – auf der Warteschlange des Abgleichs (MusicBrainz-Tempo gemeinsam).
 * „Anhalten“ nach dem laufenden Interpreten.
 */
class CheckRecordings implements ShouldQueue
{
    use Queueable;

    public const KEY = 'lieder';

    private const STOP = 'lieder:stop';

    public int $timeout = 0;

    public static function start(): bool
    {
        $ids = app(ClearArtists::class)->evidenceOpen();
        if (Activity::running(self::KEY) || ! $ids) {
            return false;
        }
        Cache::forget(self::STOP);
        Activity::start(self::KEY, 'Lieder bei MusicBrainz prüfen', count($ids), 'startet …');
        self::dispatch()->onQueue('lookup');

        return true;
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    public function handle(ClearArtists $clear, RecordingEvidence $evidence): void
    {
        $ids = $clear->evidenceOpen();
        $done = $found = 0;
        $message = null;
        foreach (Artist::whereIn('id', $ids)->orderBy('name')->get() as $artist) {
            if (Cache::get(self::STOP)) {
                break;
            }
            Activity::progress(self::KEY, $done, count($ids), null, $artist->name);
            try {
                $found += count(array_filter($evidence->check($artist))) === 1 ? 1 : 0;
            } catch (Throttled $e) {
                $message = 'MusicBrainz sperrt gerade – später weiter';
                break;
            } catch (Throwable $e) {
                report($e);
            }
            $done++;
        }
        $stopped = Cache::pull(self::STOP);
        ClearArtists::forget();   // Belege ändern die Listen
        Activity::finish(self::KEY, ($stopped ? 'Angehalten – ' : '').($message ? $message.' – ' : '').$done.' geprüft, bei '.$found.' eindeutig belegt');
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish(self::KEY, 'Prüfen abgebrochen – '.mb_substr((string) $e?->getMessage(), 0, 150));
    }
}
