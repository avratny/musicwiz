<?php

namespace App\Jobs;

use App\Support\AutoCleanup;
use App\Support\Scanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;

class ScanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    /** @param  bool  $resumed  automatischer zweiter Anlauf nach einer Unterbrechung (nur einmal, keine Endlosschleife) */
    public function __construct(public string $location, public bool $allowMassRemoval = false, public bool $resumed = false) {}

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
        $scanner = app(Scanner::class);
        // Worker wurde mitten im Scan beendet (z. B. Neustart/Update des Containers): die Warteschlange gibt den Job nach
        // einer Weile erneut aus und Laravel bricht ihn als "zu oft versucht" ab. Ein Scan liest nur und überspringt schon
        // gelesene Dateien – also einmal von selbst neu ansetzen.
        if ($e instanceof MaxAttemptsExceededException) {
            if (! $this->resumed) {
                $scanner->status(['running' => true, 'phase' => 'wartet', 'error' => null]);
                self::dispatch($this->location, $this->allowMassRemoval, true);

                return;
            }
            $scanner->status(['running' => false, 'phase' => 'Fehler', 'error' => 'Scan wurde zweimal unterbrochen (Container neu gestartet?) – bitte erneut scannen; schon gelesene Dateien werden übersprungen.']);

            return;
        }
        $scanner->status(['running' => false, 'phase' => 'Fehler', 'error' => $e->getMessage()]);
    }
}
