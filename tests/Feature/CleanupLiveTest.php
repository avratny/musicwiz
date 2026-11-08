<?php

namespace Tests\Feature;

use App\Livewire\Cleanup;
use App\Models\Operation;
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
            ->call('previewFix', true)
            ->call('confirm'); // Queue im Test: sync – Aktion ist sofort fertig

        $this->assertSame('done', Operation::latest('id')->first()->status);
        $page->call('$refresh')
            ->assertViewHas('counts', fn ($c) => $c['extension'] === 0)
            ->assertViewHas('total', 0)
            ->assertSet('selected', [])
            ->assertSee('Liste aktualisiert');

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

        $path = collect(glob($base.'/library/sampler/*/01 - Apollo vs. Crystal Lake - Free.mp3'))->first() ?? collect(glob($base.'/library/sampler/*/*Apollo*'))->first();
        $tags = app(TagTool::class)->read([$path])[$path]['tags'];
        $this->assertSame(['Apollo', 'Crystal Lake'], $tags['artists']);
        File::deleteDirectory($base);
    }
}
