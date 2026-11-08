<?php

namespace App\Livewire\Concerns;

use App\Jobs\ScanJob;
use App\Support\Scanner;

trait StartsScans
{
    /** @param  bool  $allowMassRemoval  fehlende Dateien auch dann aus dem Index entfernen, wenn es auffällig viele sind */
    public function startScan(string $location, bool $allowMassRemoval = false): void
    {
        if (! in_array($location, ['inbox', 'library', 'blackhole'], true)) {
            return;
        }
        $scanner = app(Scanner::class);
        if ($scanner->status()['running'] ?? false) {
            return;
        }
        $scanner->status(['running' => true, 'location' => $location, 'phase' => 'wartet', 'done' => 0, 'total' => 0, 'error' => null, 'held' => null, 'unreadable' => null]);
        ScanJob::dispatch($location, $allowMassRemoval);
        $this->dispatch('activity-changed');
    }

    public function scanStatus(): array
    {
        return app(Scanner::class)->status();
    }
}
