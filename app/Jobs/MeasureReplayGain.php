<?php

namespace App\Jobs;

use App\Support\Activity;
use App\Support\ReplayGain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sonstiges › ReplayGain fehlt: Alben paketweise messen – die Lieder eines Pakets parallel über die Alben hinweg (so viele
 * wie beim Einlesen gleichzeitig); „Anhalten“ wirkt nach dem laufenden Paket. Alben, die in diesem Durchlauf schon dran
 * waren, werden nicht wiederholt (nicht messbare Lieder).
 */
class MeasureReplayGain implements ShouldQueue
{
    use Queueable;

    public const KEY = 'replaygain';

    private const BATCH = 10;

    private const STOP = 'replaygain:stop';

    public int $timeout = 0;

    /** abgebrochen (z. B. Worker neu gestartet): darf neu ansetzen – schon Gemessenes bleibt am Lied */
    public int $tries = 3;

    /**
     * @param  list<string>|null  $only  nur diese Alben (null = alle, denen etwas fehlt)
     * @param  list<string>  $done  in diesem Durchlauf schon gemessen
     */
    public function __construct(public ?array $only = null, public array $done = [], public array $failed = [], public int $changed = 0) {}

    public static function start(?array $only = null): bool
    {
        $pending = self::pending($only, []);
        if (Activity::running(self::KEY) || $pending->isEmpty()) {
            return false;
        }
        Cache::forget(self::STOP);
        Activity::start(self::KEY, 'ReplayGain messen', $pending->count(), 'startet …');
        self::dispatch($only);

        return true;
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish(self::KEY, 'ReplayGain: Fehler – '.mb_substr((string) $e?->getMessage(), 0, 150).' (Gemessenes bleibt, „Alle messen“ setzt fort)');
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    private static function pending(?array $only, array $done)
    {
        return ReplayGain::pendingAlbums()->when($only !== null, fn ($c) => $c->intersect($only))->diff($done)->values();
    }

    public function handle(ReplayGain $replayGain): void
    {
        $pending = self::pending($this->only, $this->done);
        $total = count($this->done) + $pending->count();
        // ein Paket Alben auf einmal: die Messungen laufen über die Alben hinweg parallel
        if (! Cache::get(self::STOP) && $pending->isNotEmpty()) {
            $batch = $pending->take(self::BATCH)->all();
            Activity::progress(self::KEY, count($this->done), $total);
            try {
                $result = $replayGain->measureAlbums($batch, max(1, (int) config('musicwiz.scan_workers')));
                $this->changed += $result['changed'];
                $this->failed = [...$this->failed, ...$result['failed']];
            } catch (Throwable $e) {
                report($e);
                $this->failed[] = $e->getMessage();
            }
            $this->done = [...$this->done, ...$batch];
        }
        Activity::progress(self::KEY, count($this->done), $total);
        $summary = count($this->done).' '.(count($this->done) === 1 ? 'Album' : 'Alben').', '.$this->changed.' '.($this->changed === 1 ? 'Lied' : 'Lieder').' mit ReplayGain'
            .($this->failed ? ' – nicht messbar: '.implode(', ', array_slice($this->failed, 0, 3)).(count($this->failed) > 3 ? ' …' : '') : '');
        if (Cache::pull(self::STOP)) {
            Activity::finish(self::KEY, 'ReplayGain angehalten – '.$summary);
        } elseif (self::pending($this->only, $this->done)->isNotEmpty()) {
            self::dispatch($this->only, $this->done, $this->failed, $this->changed);
        } else {
            Activity::finish(self::KEY, 'ReplayGain gemessen: '.$summary);
        }
    }
}
