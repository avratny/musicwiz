<?php

namespace App\Jobs;

use App\Support\AutoCleanup;
use App\Support\OperationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Aufräum-Automatiken: Schritte nacheinander, damit jeder auf dem Ergebnis des vorigen aufbaut. */
class RunAutoCleanup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function handle(AutoCleanup $auto, OperationRunner $runner): void
    {
        $auto->runNow($runner);
    }
}
