<?php

namespace Tests\Feature;

use App\Jobs\ScanLocation;
use App\Livewire\TrackTable;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class TrackTableTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        $this->mp3('library/ABBA/Gold/01 Waterloo.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '1', 'TDRC' => '1992']);
        $this->mp3('library/ABBA/Gold/02 SOS.mp3', ['TIT2' => 'SOS', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '2', 'TDRC' => '1992']);
        $this->mp3('library/Cher/Believe/01 Believe.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher', 'TALB' => 'Believe', 'TRCK' => '1', 'TDRC' => '1998']);
        $this->mp3('inbox/Neu/01 Neu.mp3', ['TIT2' => 'Neu in der Inbox']);
        app(Scanner::class)->scan('library');
        app(Scanner::class)->scan('inbox');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_four_pages_with_navigation_badges(): void
    {
        $this->get('/')->assertRedirect('/inbox');
        $this->get('/lib')->assertOk()->assertSee('Waterloo')->assertSee('Believe')->assertDontSee('Neu in der Inbox')
            ->assertSeeInOrder(['Inbox', '1', 'Lib', '3', 'Blackhole', '0', 'Papierkorb', '0']);
        $this->get('/inbox')->assertOk()->assertSee('Neu in der Inbox');
        $this->get('/blackhole')->assertOk()->assertSee('Noch nichts eingelesen');
        $this->get('/papierkorb')->assertOk();
        $this->get('/irgendwas')->assertNotFound();
    }

    public function test_grouped_by_album_sorted_and_filtered(): void
    {
        $page = Livewire::test(TrackTable::class, ['slug' => 'lib']);
        $page->assertSeeInOrder(['Gold', 'Waterloo', 'SOS', 'Believe'])->assertSee('2 Alben');

        $page->call('sortBy', 'year')->call('sortBy', 'year'); // absteigend
        $page->assertSeeInOrder(['Believe', 'Gold', 'Waterloo']);

        $page->set('q', 'sos')->assertSee('SOS')->assertDontSee('Waterloo')->assertDontSee('Believe');
        $page->set('q', '')->set('filters.artist', 'cher')->assertSee('Believe')->assertDontSee('Waterloo');

        $page->set('filters', [])->set('grouped', false)->call('sortBy', 'title')->assertSeeInOrder(['Believe', 'SOS', 'Waterloo']);
    }

    public function test_sorting_by_a_track_field_shows_a_flat_list_and_regrouping_resets_the_sort(): void
    {
        $page = Livewire::test(TrackTable::class, ['slug' => 'lib']);

        $page->call('sortBy', 'albumartist')->assertSet('grouped', true)->call('sortBy', 'year')->assertSet('grouped', true);
        $page->call('sortBy', 'artist')->assertSet('grouped', false)->assertSet('sort', 'artist')
            ->assertSeeInOrder(['Waterloo', 'SOS', 'Believe'])->assertDontSee('2 Alben');
        $page->set('grouped', true)->assertSet('sort', 'album_dir')->assertSet('dir', 'asc')->assertSee('2 Alben');

        Livewire::withQueryParams(['sort' => 'title', 'grouped' => true])->test(TrackTable::class, ['slug' => 'lib'])->assertSet('grouped', false);
    }

    public function test_editing_in_the_table_changes_only_the_database_and_records_it(): void
    {
        $track = Track::where('title', 'SOS')->first();

        $result = Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('saveField', $track->id, 'title', '  S.O.S.  ');

        $this->assertSame('S.O.S.', $track->fresh()->title);
        $this->assertSame(['SOS'], $track->fresh()->tags['TIT2'], 'Datei unverändert');
        $change = TagChange::sole();
        $this->assertSame([$track->id, 'title', 'SOS', 'S.O.S.', TagEditor::MANUAL], [$change->track_id, $change->field, $change->old_value, $change->new_value, $change->source]);

        Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('saveField', $track->id, 'title', 'S.O.S.');
        $this->assertSame(1, TagChange::count(), 'unverändert: kein Eintrag');
    }

    public function test_changes_that_cancel_out_are_removed(): void
    {
        $track = Track::where('title', 'Waterloo')->first();
        $editor = app(TagEditor::class);

        $editor->update($track, ['disc_total' => '2']);
        $editor->update($track, ['disc_total' => '']);
        $this->assertSame(0, TagChange::count(), 'leer → 2 → leer: hebt sich auf');

        $editor->update($track, ['year' => '1974']);
        $editor->update($track, ['year' => '1975']);
        $this->assertSame(2, TagChange::where('field', 'year')->count(), '1992 → 1974 → 1975 bleibt');
        $editor->update($track, ['year' => '1992']);
        $this->assertSame(0, TagChange::count(), '… → 1992: zurück auf die Datei, alles weg');
        $this->assertSame('1992', $track->fresh()->year);

        $editor->update($track, ['genre' => 'Pop', 'title' => 'Waterloo (neu)']);
        $editor->update($track, ['genre' => null]);
        $this->assertSame(['title'], TagChange::pluck('field')->all(), 'nur das Feld, das zurückkam');
    }

    public function test_flyout_edits_all_basic_fields(): void
    {
        $track = Track::where('title', 'Waterloo')->first();
        $page = Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('open', $track->id)
            ->assertSet('form.title', 'Waterloo')->assertSee('Alle Tags der Datei');

        $page->set('form.albumartist', 'ABBA')->set('form.track_total', '19')->set('form.genre', 'Pop')->set('form.compilation', true)
            ->call('saveFlyout')->assertSee('4 Felder geändert');

        $track->refresh();
        $this->assertSame(['ABBA', 19, 'Pop', true], [$track->albumartist, $track->track_total, $track->genre, $track->compilation]);
        $this->assertSame(['albumartist', 'compilation', 'genre', 'track_total'], TagChange::orderBy('field')->pluck('field')->all());
        $page->call('close')->assertSet('selection', []);
    }

    public function test_columns_can_be_shown_and_hidden_and_stay_for_all_pages(): void
    {
        $this->mp3('library/Sampler/Hits/CD2/05 Lied.mp3', ['TIT2' => 'Lied von CD 2', 'TALB' => 'Hits', 'TRCK' => '5', 'TPOS' => '2/2', 'COMM' => 'Ein Kommentar']);
        app(Scanner::class)->scan('library');
        $page = Livewire::test(TrackTable::class, ['slug' => 'lib']);
        $page->assertSet('visible', TrackTable::defaultColumns())->assertSee('CD 2</td>', false)->assertDontSee('Ein Kommentar')->assertDontSee('Hinzugefügt</button>', false);

        $page->set('grouped', false)->assertSee('2-5');
        $page->set('grouped', true)->set('visible', ['disc', 'track', 'comment', 'created_at']);
        $page->assertSee('Ein Kommentar')->assertDontSee('2-5', false)->assertSee('Lied von CD 2', false)
            ->assertDontSee('Waterloo</td>', false)->assertDontSee('ABBA</td>', false);
        $this->assertSame(['disc', 'track', 'title', 'comment', 'created_at'], array_keys($page->instance()->visibleColumns()), 'Titel bleibt immer, Reihenfolge fest');

        // gilt auch auf den anderen Seiten (Sitzung)
        Livewire::test(TrackTable::class, ['slug' => 'inbox'])->assertSet('visible', ['disc', 'track', 'comment', 'created_at']);

        $page->call('resetColumns')->assertSet('visible', TrackTable::defaultColumns());
    }

    public function test_several_selected_tracks_are_edited_together_only_changed_fields(): void
    {
        [$waterloo, $sos, $believe] = [Track::where('title', 'Waterloo')->first(), Track::where('title', 'SOS')->first(), Track::where('title', 'Believe')->first()];
        $inbox = Track::where('location', 'inbox')->first();

        $page = Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('select', [$waterloo->id, $sos->id, $believe->id, $inbox->id]);

        $page->assertSet('selection', [$waterloo->id, $sos->id, $believe->id])->assertSee('3 Lieder ausgewählt')
            ->assertSet('form.artist', null)->assertSet('mixed.artist', true)->assertSee('(verschiedene Werte)')
            ->assertSet('form.compilation', '0')->assertNotSet('form.title', 'Waterloo');

        // Genre für alle setzen, Interpret (verschieden) leer lassen → bleibt je Lied
        $page->set('form.genre', 'Pop')->set('form.year', '2000')->call('saveFlyout')->assertSee('2 Felder geändert bei 3 Liedern');

        $this->assertSame(['Pop', 'Pop', 'Pop'], Track::whereIn('id', [$waterloo->id, $sos->id, $believe->id])->pluck('genre')->all());
        $this->assertSame(['ABBA', 'Cher'], Track::whereIn('id', [$waterloo->id, $believe->id])->orderBy('id')->pluck('artist')->all(), 'verschiedene Werte bleiben');
        $this->assertSame(6, TagChange::where('source', TagEditor::MANUAL)->count(), 'je Lied und Feld ein Eintrag');
        $this->assertNull($inbox->fresh()->genre, 'andere Bereiche bleiben unberührt');

        $page->set('form.compilation', '1')->call('saveFlyout');
        $this->assertSame([true, true, true], Track::whereIn('id', [$waterloo->id, $sos->id, $believe->id])->pluck('compilation')->all());
    }

    public function test_only_tracks_of_this_area_can_be_edited(): void
    {
        $inbox = Track::where('location', 'inbox')->first();

        Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('saveField', $inbox->id, 'title', 'x')->assertStatus(404);
        $this->assertSame('Neu in der Inbox', $inbox->fresh()->title);
    }

    public function test_reading_runs_in_the_background(): void
    {
        Queue::fake();

        Livewire::test(TrackTable::class, ['slug' => 'blackhole'])->call('scan');

        Queue::assertPushed(ScanLocation::class, fn ($job) => $job->location === 'blackhole');
        $this->getJson('/activity')->assertOk()->assertJsonPath('activities.0.key', 'scan')->assertJsonPath('activities.0.running', true)->assertJsonPath('counts.library', 3);
    }
}
