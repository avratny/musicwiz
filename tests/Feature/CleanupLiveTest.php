<?php

namespace Tests\Feature;

use App\Livewire\Cleanup;
use App\Livewire\PendingPage;
use App\Models\Operation;
use App\Models\PendingChange;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class CleanupLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_and_badges_refresh_after_fix(): void
    {
        $base = storage_path('framework/testing/live-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        File::ensureDirectoryExists($base.'/library/artists/A/ABBA/Kein Album');
        foreach (['Waterloo', 'SOS'] as $t) {
            file_put_contents($base."/library/artists/A/ABBA/Kein Album/$t.MP3", str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        }
        app(Scanner::class)->scan('library');

        $page = Livewire::test(Cleanup::class)->set('check', 'extension')
            ->assertViewHas('counts', fn ($c) => $c['extension'] === 2)
            ->assertViewHas('total', 2)
            ->call('selectAll')->assertSet('selected', fn ($s) => count($s) === 2)
            ->call('previewFix', true)->assertSee('wird vorgemerkt')
            ->call('confirm');

        // vorgemerkt: Funde sofort weg, Dateien noch unverändert, kein Worker
        $this->assertNull(Operation::latest('id')->first(), 'nichts ausgeführt');
        $this->assertFileExists($base.'/library/artists/A/ABBA/Kein Album/SOS.MP3');
        $page->call('$refresh')
            ->assertViewHas('counts', fn ($c) => $c['extension'] === 0)
            ->assertViewHas('total', 0)
            ->assertSet('selected', [])
            ->assertSee('2 Titel vorgemerkt')
            ->assertSee('Änderungen schreiben (2)');

        // schreiben auf der eigenen Seite (Queue im Test: sync – sofort fertig)
        Livewire::test(PendingPage::class)->assertSee('Alle schreiben (2)')->assertSee('Dateiname')->call('write');
        $this->assertSame('done', Operation::latest('id')->first()->status, (string) Operation::latest('id')->first()->error);
        $this->assertFileExists($base.'/library/artists/A/ABBA/Kein Album/SOS.mp3');
        $this->assertSame(0, PendingChange::count());

        File::deleteDirectory($base);
    }

    public function test_fix_buttons_disabled_while_operation_runs(): void
    {
        Operation::create(['type' => 'cleanup', 'description' => 'läuft noch', 'status' => 'running', 'total' => 10, 'done' => 3]);
        Livewire::test(Cleanup::class)->assertSee('läuft noch')->assertSee('3 / 10')
            ->assertSeeHtml('wire:poll.2s');
    }

    public function test_downloads_and_planned_operations_do_not_block(): void
    {
        Operation::create(['type' => 'download', 'description' => 'Download läuft', 'status' => 'running', 'total' => 1]);
        Operation::create(['type' => 'cleanup', 'description' => 'Für morgen', 'status' => 'pending', 'total' => 1,
            'payload' => ['actions' => [], 'options' => ['not_before' => now()->addDay()->toIso8601String()]]]);

        Livewire::test(Cleanup::class)
            ->assertViewHas('active', fn ($a) => $a->isEmpty())
            ->assertSet('watching', [])
            ->assertDontSeeHtml('wire:poll.2s');
    }

    public function test_apply_single_finding_from_row(): void
    {
        $base = storage_path('framework/testing/row-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        foreach (['Apollo vs. Crystal Lake' => 'Free', 'Cerla vs. Manian' => 'Jump'] as $artist => $title) {
            $path = $base."/library/sampler/Hits $title/01 - $artist - $title.mp3";
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => $artist, 'title' => $title, 'album' => 'Hits '.$title, 'comment' => 'LIB COMPILATION']]]);
        }
        app(Scanner::class)->scan('library');

        $page = Livewire::test(Cleanup::class)->set('check', 'combined')->assertSee('Übernehmen')->assertViewHas('total', 2);
        $apollo = collect($page->viewData('findings'))->first(fn ($f) => str_contains($f['detail'], 'Apollo'));
        $page->call('applyOne', $apollo['id'])->call('$refresh')->assertViewHas('total', 1);
        Livewire::test(PendingPage::class)->call('write'); // vorgemerkt → schreiben

        // beim Schreiben landet das Album am Regelort (Sampler mit Buchstabenordner)
        $path = collect(glob($base.'/library/sampler/*/*/*Apollo vs. Crystal Lake - Free.mp3'))->first();
        $this->assertNotNull($path, 'Datei am Regelort');
        $tags = app(TagTool::class)->read([$path])[$path]['tags'];
        $this->assertSame(['Apollo', 'Crystal Lake'], $tags['artists']);
        File::deleteDirectory($base);
    }
}
