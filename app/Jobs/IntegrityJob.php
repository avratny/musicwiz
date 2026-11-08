<?php

namespace App\Jobs;

use App\Support\Integrity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Audiodaten der Lib prüfen – in Häppchen auf der Warteschlange „research“: Aktionen und Aufräumen kommen dazwischen
 * dran. Bei Abbruch einfach neu starten, schon Geprüftes wird übersprungen.
 */
class IntegrityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public bool $continued = false)
    {
        $this->onQueue('research');
    }

    public function handle(Integrity $integrity): void
    {
        if (! $integrity->runChunk(! $this->continued)) {
            self::dispatch(true);
        }
    }

    public function failed(\Throwable $e): void
    {
        app(Integrity::class)->status(['running' => false, 'error' => $e->getMessage()]);
    }
}
