<?php

namespace App\Jobs;

use App\Support\InboxWatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Ein Durchgang „Inbox beobachten“; plant sich selbst neu ein, solange die Einstellung an ist. */
class InboxWatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(InboxWatch $watch): void
    {
        if ($watch->tick()) {
            self::dispatch()->delay(now()->addSeconds($watch->interval()));
        }
    }
}
