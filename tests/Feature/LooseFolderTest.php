<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Models\Album;
use App\Models\Operation;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** "Kein Album" = Einzellieder: keine Album-Regeln für Genre und Cover. */
class LooseFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_loose_folder_rules(): void
    {
        $base = storage_path('framework/testing/loose-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $songs = ['Waterloo' => ['Pop'], 'Dancing Queen' => ['Electronic'], 'SOS' => []];
        foreach ($songs as $title => $genres) {
            $path = $base."/library/artists/A/ABBA/Kein Album/$title.mp3";
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'ABBA', 'title' => $title, 'genres' => $genres, 'comment' => 'LIB']]]);
        }
        app(Scanner::class)->scan('library');
        $checks = app(CleanupChecks::class);

        $this->assertSame([], $checks->findings('genre_mixed'), 'verschiedene Genres sind bei Einzelliedern normal');
        $this->assertSame([], $checks->findings('cover'), 'kein gemeinsames Cover');
        $this->assertStringContainsString('1 von 3 Einzelliedern ohne Genre', $checks->findings('genre_missing')[0]['detail']);
        $this->assertStringContainsString('1 Einzellied', $checks->findings('electronic_sub')[0]['detail']);

        // Editor: Genre pro Lied, kein gemeinsames Genre
        $album = Album::firstOrFail();
        $editor = Livewire::test(AlbumEditor::class, ['album' => $album])->assertSee('Einzellieder: Genre pro Lied');
        $rows = $editor->get('rows');
        $sos = array_search('SOS', array_column($rows, 'title'), true);
        $editor->call('toggleGenre', 'Pop', $sos)->call('preview', false)->call('confirm');
        $this->assertSame('done', Operation::latest('id')->first()->status);

        $read = fn ($t) => app(TagTool::class)->read([$base."/library/artists/A/ABBA/Kein Album/$t.mp3"])[$base."/library/artists/A/ABBA/Kein Album/$t.mp3"]['tags']['genres'];
        $this->assertSame(['Pop'], $read('SOS'));
        $this->assertSame(['Electronic'], $read('Dancing Queen'), 'andere Lieder bleiben unverändert');
        File::deleteDirectory($base);
    }

    public function test_track_and_disc_numbers_are_cleared_without_album(): void
    {
        $base = storage_path('framework/testing/loose2-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $make = function (string $file, string $title) use ($base) {
            $path = $base."/library/artists/A/ABBA/Kein Album/$file";
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'ABBA', 'title' => $title, 'track' => '3', 'tracktotal' => '12', 'disc' => '1', 'disctotal' => '2', 'comment' => 'LIB']]]);

            return $path;
        };
        $make('1-03 - Waterloo.mp3', 'Waterloo');
        $sos = $make('SOS.mp3', 'SOS');
        app(Scanner::class)->scan('library');

        // 1) Aufräumen › Regelort: Nummern leeren, Dateiname ohne Nummer
        $f = collect(app(CleanupChecks::class)->findings('misplaced'))->first();
        $this->assertStringContainsString('Track- und CD-Nummer leeren', $f['detail']);
        OperationRunner::dispatch('cleanup', 'Regelort', $f['actions']);
        $waterloo = $base.'/library/artists/A/ABBA/Kein Album/Waterloo.mp3';
        $this->assertFileExists($waterloo);
        $tags = app(TagTool::class)->read([$waterloo])[$waterloo]['tags'];
        $this->assertSame(['', '', '', ''], [$tags['track'], $tags['tracktotal'], $tags['disc'], $tags['disctotal']]);
        $this->assertSame([], app(CleanupChecks::class)->findings('misplaced'), 'danach stabil');

        // 2) Editor zeigt keine Nummernfelder und leert beim Speichern
        Livewire::test(AlbumEditor::class, ['album' => Album::firstOrFail()])
            ->assertSee('Track- und CD-Nummern werden geleert')
            ->call('preview', false)->call('confirm');
        $this->assertSame('', app(TagTool::class)->read([$sos])[$sos]['tags']['track']);
        File::deleteDirectory($base);
    }
}
