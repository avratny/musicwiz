<?php

namespace App\Jobs;

use App\Models\Track;
use App\Support\Activity;
use App\Support\Lookup\Throttled;
use App\Support\Lyrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sonstiges › Songtexte › „Songtexte laden“: Lieder ohne bzw. nur mit reinem Text bei LRCLIB abfragen (Lyrics) – auf
 * der Warteschlange des Abgleichs. „Aktualisieren“ ($force) fragt auch schon abgefragte erneut. „Anhalten“ nach dem
 * laufenden Lied.
 */
class FetchLyrics implements ShouldQueue
{
    use Queueable;

    public const KEY = 'songtexte';

    private const STOP = 'songtexte:stop';

    public int $timeout = 0;

    public function __construct(public bool $force = false) {}

    public static function start(bool $force = false): bool
    {
        $total = Lyrics::pending($force)->count();
        if (Activity::running(self::KEY) || ! $total) {
            return false;
        }
        Cache::forget(self::STOP);
        Activity::start(self::KEY, 'Songtexte laden', $total, 'startet …');
        self::dispatch($force)->onQueue('lookup');

        return true;
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    public function handle(Lyrics $lyrics): void
    {
        $ids = Lyrics::pending($this->force)->orderBy('artist')->orderBy('album')->orderBy('track')->pluck('id');
        $done = $failed = 0;
        $message = null;
        foreach ($ids as $id) {
            if (Cache::get(self::STOP)) {
                $message = 'angehalten';
                break;
            }
            $track = Track::find($id);
            if (! $track) {
                continue;
            }
            Activity::progress(self::KEY, $done, $ids->count(), null, $track->artist.' – '.$track->title);
            try {
                $lyrics->fetch($track);
            } catch (Throttled $e) {
                $message = $e->getMessage();
                break;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
            $done++;
        }
        Cache::forget(self::STOP);
        $found = $lyrics->findings();
        Activity::finish(self::KEY, 'Songtexte: '.$done.' abgefragt'.($failed ? ', '.$failed.' mit Fehler' : '').' – '
            .$found['exact']->count().' eindeutig, '.$found['choice']->count().' zur Auswahl'.($message ? ' ('.$message.')' : ''));
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish(self::KEY, 'Songtexte: abgebrochen – '.mb_substr((string) $e?->getMessage(), 0, 150));
    }
}
