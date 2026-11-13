<?php

namespace Tests\Feature;

use App\Livewire\TrackTable;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        config(['musicwiz.category_fill' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function track(string $title): Track
    {
        return Track::where('title', $title)->firstOrFail();
    }

    public function test_reads_own_tag_and_fills_missing_by_location_and_compilation(): void
    {
        $this->mp3('inbox/Album/01.mp3', ['TIT2' => 'Normal', 'TPE1' => 'ABBA', 'COMM' => 'eigener Kommentar']);
        $this->mp3('inbox/Sampler/01.mp3', ['TIT2' => 'Flag', 'TPE1' => 'ABBA', 'TCMP' => '1']);
        $this->mp3('inbox/VA/01.mp3', ['TIT2' => 'VA', 'TPE1' => 'ABBA', 'TPE2' => 'Various Artists']);
        $this->mp3('inbox/Getaggt/01.mp3', ['TIT2' => 'Getaggt', 'TPE1' => 'ABBA', 'TXXX:MUSICWIZ_CATEGORY' => 'BLACKHOLE']);
        $this->flac('blackhole/Alt/01.flac', ['TITLE' => 'Blackhole', 'ARTIST' => 'Cher']);
        $this->flac('library/Alt/01.flac', ['TITLE' => 'Lib alt', 'ARTIST' => 'Cher', 'MUSICWIZ_CATEGORY' => 'lib compilation']);
        $this->mp3('trash/Weg/01.mp3', ['TIT2' => 'Weg']);
        foreach (['inbox', 'library', 'blackhole', 'trash'] as $location) {
            app(Scanner::class)->scan($location);
        }

        $this->assertSame('LIB', $this->track('Normal')->category);
        $this->assertSame('eigener Kommentar', $this->track('Normal')->comment);
        $this->assertSame('LIB COMPILATION', $this->track('Flag')->category);
        $this->assertSame('LIB COMPILATION', $this->track('VA')->category);
        $this->assertSame('BLACKHOLE', $this->track('Getaggt')->category);
        $this->assertNull($this->track('Blackhole')->category, 'Blackhole wird ignoriert');
        $this->assertSame('LIB COMPILATION', $this->track('Lib alt')->category);
        $this->assertNull($this->track('Weg')->category);

        // vorbelegt = System-Änderung; aus der Datei gelesen = keine Änderung
        $change = TagChange::where('track_id', $this->track('Normal')->id)->where('field', 'category')->sole();
        $this->assertSame(['system', null, 'LIB', 'Kategorie vorbelegt'], [$change->source, $change->old_value, $change->new_value, $change->reason]);
        $this->assertFalse(TagChange::where('track_id', $this->track('Getaggt')->id)->exists());

        // erneut einlesen: bleibt, keine doppelten Änderungen
        app(Scanner::class)->scan('inbox');
        $this->assertSame('LIB', $this->track('Normal')->category);
        $this->assertSame(1, TagChange::where('track_id', $this->track('Normal')->id)->where('field', 'category')->count());
    }

    public function test_follows_compilation_within_same_location(): void
    {
        $this->mp3('inbox/Album/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'ABBA']);
        app(Scanner::class)->scan('inbox');
        $editor = app(TagEditor::class);

        $editor->update($this->track('Eins'), ['compilation' => true]);
        $this->assertSame('LIB COMPILATION', $this->track('Eins')->category);

        $editor->update($this->track('Eins'), ['category' => 'BLACKHOLE COMPILATION']);
        $editor->update($this->track('Eins'), ['compilation' => false]);
        $this->assertSame('BLACKHOLE', $this->track('Eins')->category);

        $editor->update($this->track('Eins'), ['albumartist' => 'Various Artists']);
        $this->assertSame('BLACKHOLE COMPILATION', $this->track('Eins')->category);

        // ungültige Werte werden nicht übernommen
        $editor->update($this->track('Eins'), ['category' => 'irgendwas']);
        $this->assertNull($this->track('Eins')->category);
    }

    public function test_choose_for_whole_album_and_in_flyout(): void
    {
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold']);
        $this->mp3('library/ABBA/Gold/02.mp3', ['TIT2' => 'SOS', 'TPE1' => 'ABBA', 'TALB' => 'Gold']);
        app(Scanner::class)->scan('library');
        $hash = $this->track('Waterloo')->album_hash;

        $page = Livewire::test(TrackTable::class, ['slug' => 'lib'])->assertSee('Lib Compilation')->assertDontSee('wird beim Schreiben');
        $page->call('setAlbumCategory', $hash, 'BLACKHOLE')->assertSee('wird beim Schreiben nach Blackhole verschoben');
        $this->assertSame(['BLACKHOLE'], Track::pluck('category')->unique()->values()->all());
        $this->assertSame('manual', TagChange::where('field', 'category')->latest('id')->first()->source);
        // nur in der Datenbank: die Datei liegt noch in der Lib
        $this->assertSame('library', $this->track('Waterloo')->location);

        $page->call('setAlbumCategory', $hash, 'quatsch')->assertStatus(422);

        // Flyout mit mehreren Liedern
        $page = Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('select', Track::pluck('id')->all())
            ->assertSet('form.category', 'BLACKHOLE')->set('form.category', 'LIB')->call('saveFlyout');
        $this->assertSame(['LIB'], Track::pluck('category')->unique()->values()->all());

        // in Blackhole und Papierkorb keine Kategorie-Wahl
        Livewire::test(TrackTable::class, ['slug' => 'papierkorb'])->call('setAlbumCategory', $hash, 'LIB')->assertStatus(422);
        Livewire::test(TrackTable::class, ['slug' => 'blackhole'])->call('setAlbumCategory', $hash, 'LIB')->assertStatus(422);
    }

    public function test_per_track_for_singles(): void
    {
        $this->mp3('inbox/Singles/a.mp3', ['TIT2' => 'Single A', 'TPE1' => 'ABBA']);
        $this->mp3('inbox/Singles/b.mp3', ['TIT2' => 'Single B', 'TPE1' => 'Cher', 'TALB' => 'Believe']);
        $this->mp3('inbox/Album/01.mp3', ['TIT2' => 'Im Album', 'TPE1' => 'ABBA', 'TALB' => 'Gold']);
        app(Scanner::class)->scan('inbox');

        // Einzeltitel: Auswahl je Titel; ganzes Album: nur im Album-Kopf
        Livewire::test(TrackTable::class, ['slug' => 'inbox'])->assertSee('Einzeltitel')
            ->assertSeeHtml('setCategory(['.$this->track('Single A')->id.']')
            ->assertDontSeeHtml('setCategory(['.$this->track('Im Album')->id.']')
            ->call('setCategory', [$this->track('Single A')->id], 'BLACKHOLE')
            ->call('setCategory', [$this->track('Single A')->id], 'kaputt')->assertStatus(422);

        $this->assertSame('BLACKHOLE', $this->track('Single A')->category);
        $this->assertSame('LIB', $this->track('Single B')->category);
    }
}
