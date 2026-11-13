<?php

namespace App\Jobs;

use App\Support\Activity;
use App\Support\Fingerprints;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sonstiges › Akustischer Fingerabdruck: Lieder ohne Fingerabdruck in den gewählten Bereichen paketweise ermitteln
 * (parallel wie beim Einlesen); „Anhalten“ wirkt nach dem laufenden Paket. Nicht ermittelbare werden vermerkt und nicht
 * wiederholt, bis sich die Datei ändert.
 */
class MeasureFingerprints implements ShouldQueue
{
    use Queueable;

    public const KEY = 'fingerprint';

    private const BATCH = 100;

    private const STOP = 'fingerprint:stop';

    public int $timeout = 0;

    public int $tries = 3;

    /** @param  list<string>  $locations */
    public function __construct(public array $locations, public int $done = 0, public int $failed = 0, public int $total = 0) {}

    /** @param  list<string>  $locations */
    public static function start(array $locations): bool
    {
        $pending = Fingerprints::pending($locations)->count();
        if (Activity::running(self::KEY) || ! $pending) {
            return false;
        }
        Cache::forget(self::STOP);
        Activity::start(self::KEY, 'Fingerabdrücke ermitteln', $pending, 'startet …');
        self::dispatch(array_values($locations), 0, 0, $pending);

        return true;
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish(self::KEY, 'Fingerabdrücke: Fehler – '.mb_substr((string) $e?->getMessage(), 0, 150).' (Ermitteltes bleibt, erneut starten setzt fort)');
    }

    public function handle(Fingerprints $fingerprints): void
    {
        $batch = Fingerprints::pending($this->locations)->orderBy('id')->limit(self::BATCH)->get(['id', 'location', 'path']);
        if (! Cache::get(self::STOP) && $batch->isNotEmpty()) {
            $result = $fingerprints->measure($batch, max(1, (int) config('musicwiz.scan_workers')));
            $this->done += $result['done'];
            $this->failed += $result['failed'];
        }
        Activity::progress(self::KEY, $this->done + $this->failed, max($this->total, $this->done + $this->failed));
        $summary = $this->done.' '.($this->done === 1 ? 'Fingerabdruck' : 'Fingerabdrücke').' ermittelt'.($this->failed ? ', '.$this->failed.' nicht möglich' : '');
        if (Cache::pull(self::STOP)) {
            Activity::finish(self::KEY, 'Fingerabdrücke angehalten – '.$summary);
        } elseif ($batch->isNotEmpty() && Fingerprints::pending($this->locations)->exists()) {
            self::dispatch($this->locations, $this->done, $this->failed, $this->total);
        } else {
            Activity::finish(self::KEY, 'Fingerabdrücke fertig: '.$summary);
        }
    }
}
