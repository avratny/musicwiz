<?php

namespace App\Jobs;

use App\Support\Integrity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Audiodaten der Lib prüfen (dauert lange; bei Abbruch einfach neu starten – schon Geprüftes wird übersprungen). */
class IntegrityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function handle(Integrity $integrity): void
    {
        $integrity->run();
    }

    public function failed(\Throwable $e): void
    {
        app(Integrity::class)->status(['running' => false, 'error' => $e->getMessage()]);
    }
}
