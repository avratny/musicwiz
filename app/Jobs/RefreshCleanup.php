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

    public function handle(): void
    {
        try {
            // Hat sich der Datenstand während des Rechnens geändert (weitere Aktion), gleich noch einmal
            for ($round = 0; $round < 3; $round++) {
                $checks = app()->make(CleanupChecks::class); // neue Instanz: frische Version und Daten
                if ($checks->isFresh()) {
                    break;
                }
                $checks->refreshAll();
            }
        } finally {
            Cache::forget(self::RUNNING);
        }
    }

    public function failed(\Throwable $e): void
    {
        Cache::forget(self::RUNNING);
    }
}
