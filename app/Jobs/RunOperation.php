<?php

namespace App\Jobs;

use App\Models\Operation;
use App\Support\OperationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public int $operationId) {}

    public function handle(OperationRunner $runner): void
    {
        $op = Operation::find($this->operationId);
        // eingeplante Aktion vorzeitig geholt (z. B. synchrone Warteschlange): liegen lassen, der verzögerte Job kommt noch
        if ($op && $op->status === 'pending' && ! $op->isPlanned()) {
            $runner->run($op);
        }
    }

    public function failed(\Throwable $e): void
    {
        Operation::whereKey($this->operationId)->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
    }
}
