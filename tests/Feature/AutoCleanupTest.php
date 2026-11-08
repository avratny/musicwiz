<?php

namespace Tests\Feature;

use App\Jobs\ScanJob;
use App\Livewire\SettingsPage;
use App\Models\Operation;
use App\Support\ArtistDirectory;
use App\Support\AutoCleanup;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class AutoCleanupTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/auto-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox_paths' => [$this->base.'/inbox'], 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $mp3 = str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 20);
        foreach (['library/artists/A/ABBA/Gold/01 - Waterloo.MP3', 'library/sampler/Hits/01 - ABBA - SOS.MP3'] as $rel) {
            File::ensureDirectoryExists(dirname($this->base.'/'.$rel));
            file_put_contents($this->base.'/'.$rel, $mp3);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_nothing_happens_without_permission(): void
    {
        (new ScanJob('library'))->handle(app(Scanner::class), app(AutoCleanup::class));
        $this->assertSame(0, Operation::count());
        $this->assertFileExists($this->base.'/library/artists/A/ABBA/Gold/01 - Waterloo.MP3');
    }

    public function test_enabled_check_runs_only_for_chosen_category(): void
    {
        Livewire::test(SettingsPage::class)->set('tab', 'auto')
            ->assertSee('Uneinheitliche Endungen')
            ->call('toggleAuto', 'extension', 'LIB');
        $this->assertSame(['extension' => ['LIB' => true]], app(Settings::class)->get('auto_cleanup'));

        (new ScanJob('library'))->handle(app(Scanner::class), app(AutoCleanup::class));

        $op = Operation::where('type', 'auto')->firstOrFail();
        $this->assertSame('done', $op->status, (string) $op->error);
        $this->assertStringContainsString('Automatisch: Uneinheitliche Endungen', $op->description);
        $this->assertFileExists($this->base.'/library/artists/A/ABBA/Gold/01 - Waterloo.mp3');
        $this->assertFileExists($this->base.'/library/sampler/Hits/01 - ABBA - SOS.MP3'); // LIB COMPILATION nicht freigegeben
        $this->assertTrue($op->canUndo());
    }

    public function test_saving_general_settings_keeps_automations(): void
    {
        $page = Livewire::test(SettingsPage::class);
        $page->set('tab', 'auto')->call('toggleAuto', 'leftovers', '_other');
        $page->set('tab', 'general')->call('save');
        $this->assertSame(['leftovers' => ['_other' => true]], app(Settings::class)->get('auto_cleanup'));
    }

    public function test_automations_run_in_step_order_with_fresh_findings(): void
    {
        app(Settings::class)->set(['categories' => [['name' => 'LIB', 'folder' => 'library/artists', 'pattern' => '{buchstabe}/{ordner_interpret}/{album}/{nr} - {titel}.{ext}', 'compilation' => false]]]);
        $path = $this->base.'/library/artists/A/A. Teens/Hits/01 - Mamma Mia.mp3';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'A. Teens', 'albumartist' => 'A. Teens', 'album' => 'Hits', 'title' => 'Mamma Mia', 'track' => '1', 'comment' => 'LIB']]]);
        app(ArtistDirectory::class)->resolve('A-Teens', ['A. Teens']);
        app(Settings::class)->set(['auto_cleanup' => ['misplaced' => ['LIB' => true], 'spelling' => ['LIB' => true]]]); // absichtlich "falsch" herum eingetragen

        (new ScanJob('library'))->handle(app(Scanner::class), app(AutoCleanup::class));

        $ops = Operation::where('type', 'auto')->orderBy('id')->pluck('description')->all();
        $this->assertCount(2, $ops);
        $this->assertStringContainsString('Schreibweisen', $ops[0], 'erst Tags (Schritt 2) …');
        $this->assertStringContainsString('Regelort', $ops[1], '… dann Ablage (Schritt 4)');
        $target = $this->base.'/library/artists/A/A-Teens/Hits/01 - Mamma Mia.mp3';
        $this->assertFileExists($target, 'in einem Durchlauf im richtigen Ordner');
        $this->assertSame('A-Teens', app(TagTool::class)->read([$target])[$target]['tags']['albumartist']);
    }
}
