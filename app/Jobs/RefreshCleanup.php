<?php

namespace App\Jobs;

use App\Support\CleanupChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Alle Aufräum-Prüfungen im Hintergrund neu berechnen (nach Scan bzw. Aktion oder wenn die Seite einen veralteten Stand
 * sieht). Die Seite zeigt solange den letzten Stand – so dauert kein Seitenaufruf mehr Sekunden.
 */
class RefreshCleanup implements ShouldQueue
{
    use Queueable;

    private const RUNNING = 'cleanup.refreshing';

    public int $tries = 1;

    public int $timeout = 0;

    /** Neu berechnen lassen, wenn nötig und nicht schon unterwegs. */
    public static function request(): void
    {
        if (! app(CleanupChecks::class)->isFresh() && Cache::add(self::RUNNING, true, 1800)) {
            self::dispatch();
        }
    }

    public static function running(): bool
    {
        return Cache::has(self::RUNNING);
    }

    /** Sekunden je Häppchen – dazwischen erledigt der Worker wartende Aktionen (z. B. das nächste „Übernehmen“) */
    public const SLICE_SECONDS = 10;

    public function handle(): void
    {
        $checks = app()->make(CleanupChecks::class); // neue Instanz: frische Version und Daten
        $result = $checks->isFresh() ? 'done' : $checks->refreshAll(self::SLICE_SECONDS);
        if ($result === 'done') {
            Cache::forget(self::RUNNING);
            Cache::forget(CleanupChecks::PROGRESS);

            return;
        }
        // Zeit um bzw. Datenstand geändert: weiter im nächsten Häppchen (hinter bereits wartenden Aktionen)
        Cache::put(self::RUNNING, true, 1800);
        self::dispatch();
    }

    public function failed(\Throwable $e): void
    {
        Cache::forget(self::RUNNING);
        Cache::forget(CleanupChecks::PROGRESS);
    }
}
