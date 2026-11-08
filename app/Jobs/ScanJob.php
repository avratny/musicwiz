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
        $result = $scanner->scan($this->location, $this->allowMassRemoval);
        // neue, noch unbekannte Künstler im Hintergrund nachschlagen (der Assistent ist dann sofort gefüllt)
        if (in_array($this->location, ['inbox', 'library'], true)) {
            PrefetchUnknownArtists::start();
        }
        if ($this->location !== 'library') {
            return;
        }
        // Freigegebene Aufräum-Automatiken nach dem Bestand-Scan ausführen – aber nicht auf einem unvollständigen Index
        // (nicht lesbare Ordner, zurückgehaltenes Entfernen): sonst planen sie mit Dateien, die es nicht mehr gibt
        if ($result['held'] || $result['unreadable']) {
            if ($auto->config()) {
                $scanner->status(['auto_skipped' => 'Aufräum-Automatiken nicht ausgeführt, weil der Scan unvollständig war. Nach einem vollständigen Scan laufen sie wieder.']);
            }

            return;
        }
        $auto->run();
    }

    public function failed(\Throwable $e): void
    {
        app(Scanner::class)->status(['running' => false, 'phase' => 'Fehler', 'error' => $e->getMessage()]);
    }
}
