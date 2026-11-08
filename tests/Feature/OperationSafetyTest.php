<?php

namespace Tests\Feature;

use App\Models\HistoryEntry;
use App\Models\Operation;
use App\Support\OperationRunner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Verlauf als Absichts-Protokoll und wiederholbares Rückgängig. */
class OperationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/ops-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base.'/inbox');
        app(Settings::class)->set([
            'base' => $this->base,
            'inbox_paths' => [$this->base.'/inbox'],
            'trash' => $this->base.'/_papierkorb',
            'scan_throttle_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function file(string $rel, string $content = 'x'): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);

        return $path;
    }

    public function test_move_is_logged_and_confirmed(): void
    {
        $from = $this->file('inbox/a.txt');
        $to = $this->base.'/library/a.txt';
        $op = OperationRunner::dispatch('test', 'Test', [['type' => 'move', 'path' => $from, 'target' => $to]]);

        $this->assertSame('done', $op->fresh()->status);
        $entry = $op->entries()->sole();
        $this->assertSame($to, $entry->new_path);
        $this->assertFalse($entry->pending);
    }

    public function test_failed_move_leaves_no_entry_behind(): void
    {
        $from = $this->file('inbox/a.txt', 'neu');
        $to = $this->file('library/a.txt', 'alt');
        $op = OperationRunner::dispatch('test', 'Test', [['type' => 'move', 'path' => $from, 'target' => $to]], ['on_conflict' => 'skip']);

        $this->assertSame('failed', $op->fresh()->status);
        $this->assertSame(['error'], $op->entries()->pluck('kind')->all());
        $this->assertFileExists($from);
    }

    public function test_numbered_target_is_known_before_the_move(): void
    {
        $from = $this->file('inbox/a.txt', 'neu');
        $this->file('library/a.txt', 'alt');
        $op = OperationRunner::dispatch('test', 'Test', [['type' => 'move', 'path' => $from, 'target' => $this->base.'/library/a.txt']], ['on_conflict' => 'number']);

        $this->assertSame($this->base.'/library/a (2).txt', $op->entries()->sole()->new_path);
        $this->assertSame('neu', file_get_contents($this->base.'/library/a (2).txt'));
    }

    public function test_pending_entry_of_aborted_action_is_skipped_on_undo(): void
    {
        // Worker ist gestorben, nachdem der Eintrag stand, aber bevor die Datei bewegt wurde
        $from = $this->file('inbox/a.txt');
        $op = Operation::create(['type' => 'test', 'description' => 'Abgebrochen', 'status' => 'failed', 'payload' => []]);
        $op->entries()->create(['kind' => 'move', 'old_path' => $from, 'new_path' => $this->base.'/library/a.txt', 'pending' => true]);

        $undo = OperationRunner::dispatchUndo($op);

        $this->assertSame('done', $undo->fresh()->status);
        $this->assertSame('undone', $op->fresh()->status);
        $this->assertFileExists($from);
    }

    public function test_pending_entry_of_completed_move_is_undone(): void
    {
        // Datei ist schon bewegt, die Bestätigung im Verlauf fehlt
        $from = $this->base.'/inbox/a.txt';
        $to = $this->file('library/a.txt');
        $op = Operation::create(['type' => 'test', 'description' => 'Abgebrochen', 'status' => 'failed', 'payload' => []]);
        $op->entries()->create(['kind' => 'move', 'old_path' => $from, 'new_path' => $to, 'pending' => true]);

        OperationRunner::dispatchUndo($op);

        $this->assertFileExists($from);
        $this->assertFileDoesNotExist($to);
    }

    public function test_partial_undo_can_be_retried(): void
    {
        $a = $this->file('inbox/a.txt');
        $b = $this->file('inbox/b.txt');
        $op = OperationRunner::dispatch('test', 'Test', [
            ['type' => 'move', 'path' => $a, 'target' => $this->base.'/library/a.txt'],
            ['type' => 'move', 'path' => $b, 'target' => $this->base.'/library/b.txt'],
        ]);

        // a.txt blockiert: neue Datei am alten Ort
        $this->file('inbox/a.txt', 'blockiert');
        OperationRunner::dispatchUndo($op);
        $op->refresh();
        $this->assertSame('partly_undone', $op->status);
        $this->assertTrue($op->canUndo());
        $this->assertFileExists($b);
        $this->assertSame(1, $op->entries()->whereNull('undone_at')->count());

        // Blockade weg: zweiter Versuch nimmt nur noch den Rest zurück
        unlink($a);
        $retry = OperationRunner::dispatchUndo($op);
        $this->assertSame(1, $retry->total);
        $this->assertSame('done', $retry->fresh()->status);
        $this->assertSame('undone', $op->fresh()->status);
        $this->assertFalse($op->fresh()->canUndo());
        $this->assertSame('x', file_get_contents($a));
    }

    public function test_no_second_undo_while_one_is_waiting(): void
    {
        $op = Operation::create(['type' => 'test', 'description' => 'Test', 'status' => 'done', 'payload' => []]);
        $op->entries()->create(['kind' => 'move', 'old_path' => '/x', 'new_path' => '/y']);
        $waiting = Operation::create(['type' => 'undo', 'description' => 'Rückgängig: Test', 'status' => 'pending', 'payload' => ['undo' => $op->id], 'undo_of' => $op->id]);

        $this->assertFalse($op->canUndo());
        $this->assertSame($waiting->id, OperationRunner::dispatchUndo($op)->id);
        $this->assertSame(2, Operation::count());
    }

    public function test_missing_cover_backup_fails_undo_instead_of_reporting_success(): void
    {
        $target = $this->file('library/Album/cover.jpg', 'neu');
        $op = Operation::create(['type' => 'test', 'description' => 'Cover', 'status' => 'done', 'payload' => []]);
        $op->entries()->create(['kind' => 'file', 'new_path' => $target, 'backup' => 'history/gibt-es-nicht.jpg']);

        $undo = OperationRunner::dispatchUndo($op);

        $this->assertSame('failed', $undo->fresh()->status);
        $this->assertSame('partly_undone', $op->fresh()->status);
        $this->assertSame(1, HistoryEntry::where('operation_id', $undo->id)->where('kind', 'error')->count());
    }
}
