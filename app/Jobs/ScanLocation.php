<?php

namespace App\Jobs;

use App\Support\Activity;
use App\Support\Locations;
use App\Support\Scanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Einen Bereich im Hintergrund einlesen (Fortschritt über Activity). */
class ScanLocation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public function __construct(public string $location) {}

    public function handle(Scanner $scanner): void
    {
        $scanner->scan($this->location);
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish('scan', Locations::LABELS[$this->location].' einlesen: Fehler – '.mb_substr((string) $e?->getMessage(), 0, 200));
    }
}
