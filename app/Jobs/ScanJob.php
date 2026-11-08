<?php

namespace App\Jobs;

use App\Support\AutoCleanup;
use App\Support\Scanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public string $location, public bool $allowMassRemoval = false) {}

    public function handle(Scanner $scanner, AutoCleanup $auto): void
    {
        $scanner->scan($this->location, $this->allowMassRemoval);
        // Freigegebene Aufräum-Automatiken nach dem Bestand-Scan ausführen
        if ($this->location === 'library') {
            $auto->run();
        }
    }

    public function failed(\Throwable $e): void
    {
        app(Scanner::class)->status(['running' => false, 'phase' => 'Fehler', 'error' => $e->getMessage()]);
    }
}
