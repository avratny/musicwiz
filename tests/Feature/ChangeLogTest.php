<?php

namespace Tests\Feature;

use App\Livewire\ChangeLog;
use App\Models\Artist;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\ArtistRegistry;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class ChangeLogTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold']);
        $this->mp3('inbox/Cher/Believe/01.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher', 'TALB' => 'Believe']);
        app(Scanner::class)->scan('library');
        app(Scanner::class)->scan('inbox');
        app(TagEditor::class)->update(Track::where('title', 'Waterloo')->first(), ['genre' => 'Pop', 'year' => '1974']);
        app(ArtistRegistry::class)->rename(Artist::where('name', 'Cher')->first(), 'CHER');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_all_changes_with_source_and_reason_filterable_and_sortable(): void
    {
        $this->get('/aenderungen')->assertOk()->assertSee('3 Änderungen')->assertSee('Interpret umbenannt: Cher → CHER');

        $page = Livewire::test(ChangeLog::class)->assertSeeInOrder(['Believe', 'Waterloo']); // neueste zuerst
        $page->set('source', 'system')->assertSee('Believe')->assertDontSee('Waterloo');
        $page->set('source', '')->set('field', 'genre')->assertSee('Waterloo')->assertSee('Pop')->assertDontSee('1974');
        $page->set('field', '')->set('location', 'inbox')->assertSee('Believe')->assertDontSee('Waterloo');
        $page->call('resetFilters')->set('q', '1974')->assertSee('Waterloo')->assertDontSee('Believe');
        $page->set('q', '')->call('sortBy', 'title')->assertSet('dir', 'asc')->assertSeeInOrder(['Believe', 'Waterloo', 'Waterloo']);
        $page->call('sortBy', 'title')->assertSeeInOrder(['Waterloo', 'Believe']);
    }

    public function test_grouped_by_album_then_track_newest_album_first(): void
    {
        $this->mp3('library/Single/Kein Album/Hit.mp3', ['TIT2' => 'Einzelner Hit', 'TPE1' => 'Niemand']);
        app(Scanner::class)->scan('library');
        app(TagEditor::class)->update(Track::where('title', 'Einzelner Hit')->first(), ['year' => '1999']);

        $page = Livewire::test(ChangeLog::class)->assertSet('grouped', true);
        // neuestes Album zuerst: „Ohne Album“ (eben geändert), dann Believe (umbenannt), dann Gold mit Waterloo und seinen zwei Änderungen
        $page->assertSeeInOrder(['Ohne Album', 'Kein Album', 'Einzelner Hit', '1999', 'Believe', 'CHER', 'Gold', 'Waterloo', 'Jahr', 'Genre'])
            ->assertSee('2 Änderungen');

        $page->call('sortBy', 'field')->assertSet('grouped', false);
        $page->set('grouped', true)->assertSet('sort', 'created_at')->assertSet('dir', 'desc');
    }

    public function test_navigation_shows_the_number_of_changes(): void
    {
        $this->get('/lib')->assertSeeInOrder(['Protokoll', 'Änderungen', '3']);
        $this->getJson('/activity')->assertJsonPath('counts.changes', 3)->assertJsonPath('counts.artists', 2);
    }

    public function test_revert_one_field_back_to_the_file(): void
    {
        $waterloo = Track::where('title', 'Waterloo')->first();
        app(TagEditor::class)->update($waterloo, ['year' => '1975', 'track' => '3', 'compilation' => true]);

        Livewire::test(ChangeLog::class)->call('revert', $waterloo->id, 'year')
            ->assertSet('message', '„Waterloo“: Jahr zurückgesetzt.')->assertDontSee('1975');

        $waterloo->refresh();
        $this->assertNull($waterloo->year);
        $this->assertSame('Pop', $waterloo->genre);
        $this->assertSame(['compilation', 'genre', 'track'], TagChange::where('track_id', $waterloo->id)->orderBy('field')->pluck('field')->all());

        app(TagEditor::class)->revert($waterloo, ['track', 'compilation']);
        $waterloo->refresh();
        $this->assertSame([null, false], [$waterloo->track, $waterloo->compilation]);
    }

    public function test_revert_all_or_only_the_filtered_changes(): void
    {
        $page = Livewire::test(ChangeLog::class)->set('source', 'manual')->call('revertAll')
            ->assertSet('message', '2 Felder bei 1 Lied auf den Stand der Datei zurückgesetzt.');
        $this->assertSame(['artist'], TagChange::pluck('field')->all());
        $this->assertSame([null, null], [Track::where('title', 'Waterloo')->value('genre'), Track::where('title', 'Waterloo')->value('year')]);

        $page->set('source', '')->call('revertAll')->assertSee('Noch keine Änderungen.');
        $this->assertSame(0, TagChange::count());
        $this->assertSame('Cher', Track::where('title', 'Believe')->value('artist'));
    }
}
