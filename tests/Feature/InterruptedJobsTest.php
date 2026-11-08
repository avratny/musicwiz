<?php

namespace Tests\Feature;

use App\Jobs\RunOperation;
use App\Jobs\ScanJob;
use App\Models\Operation;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Worker mitten in der Arbeit beendet (Neustart/Update): die Warteschlange meldet den Job als "zu oft versucht". */
class InterruptedJobsTest extends TestCase
{
    use RefreshDatabase;

    private function interrupted(): MaxAttemptsExceededException
    {
        return new MaxAttemptsExceededException('App\Jobs\ScanJob has been attempted too many times.');
    }

    public function test_interrupted_scan_is_resumed_once_then_reported_clearly(): void
    {
        Queue::fake();

        (new ScanJob('library'))->failed($this->interrupted());
        Queue::assertPushed(ScanJob::class, fn ($job) => $job->location === 'library' && $job->resumed);
        $this->assertTrue(app(Scanner::class)->status()['running']);

        (new ScanJob('library', false, true))->failed($this->interrupted());
        Queue::assertPushed(ScanJob::class, 1); // kein dritter Anlauf
        $status = app(Scanner::class)->status();
        $this->assertFalse($status['running']);
        $this->assertStringContainsString('zweimal unterbrochen', $status['error']);
        $this->assertStringNotContainsString('attempted too many times', $status['error']);
    }

    public function test_interrupted_operation_is_requeued_if_not_started_and_explained_otherwise(): void
    {
        Queue::fake();
        $waiting = Operation::create(['type' => 'album', 'description' => 'wartet', 'status' => 'pending', 'total' => 1, 'payload' => ['actions' => []]]);
        (new RunOperation($waiting->id))->failed($this->interrupted());
        Queue::assertPushed(RunOperation::class, fn ($job) => $job->operationId === $waiting->id);
        $this->assertSame('pending', $waiting->fresh()->status);

        $running = Operation::create(['type' => 'album', 'description' => 'läuft', 'status' => 'running', 'total' => 3, 'done' => 1, 'payload' => ['actions' => []]]);
        (new RunOperation($running->id))->failed($this->interrupted());
        $this->assertSame('failed', $running->fresh()->status);
        $this->assertStringContainsString('unterbrochen', $running->fresh()->error);
        Queue::assertPushed(RunOperation::class, 1); // nicht blind wiederholt
    }
}
