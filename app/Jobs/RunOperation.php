<?php

namespace App\Jobs;

use App\Models\Operation;
use App\Support\ArtistGroupAnalyzer;
use App\Support\OperationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;

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
            if ($op->type !== 'download') {
                ArtistGroupAnalyzer::warm(); // z. B. nach „Schreibweisen vereinheitlichen“ – nicht beim nächsten Klick
                // Aufräumen wird nicht mehr auf Vorrat neu berechnet: erst, wenn die Seite offen ist (sie fordert es an)
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        $op = Operation::find($this->operationId);
        if ($e instanceof MaxAttemptsExceededException && $op) {
            // Worker wurde beendet (Neustart/Update des Containers), bevor die Aktion lief: einfach neu einplanen
            if ($op->status === 'pending') {
                $job = self::dispatch($op->id)->onQueue($op->type === 'download' ? 'downloads' : 'default');
                if ($op->isPlanned()) {
                    $job->delay(Carbon::parse($op->payload['options']['not_before'])); // für später eingeplant: Termin behalten
                }

                return;
            }
            // mittendrin unterbrochen: nicht blind wiederholen – jeder erledigte Schritt steht im Verlauf (rückgängig machbar)
            $op->update(['status' => 'failed', 'finished_at' => now(),
                'error' => 'Aktion wurde unterbrochen (Container neu gestartet?). Erledigte Schritte stehen im Verlauf und lassen sich rückgängig machen; den Rest bitte erneut starten.']);

            return;
        }
        Operation::whereKey($this->operationId)->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
    }
}
