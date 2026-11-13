<?php

namespace Tests\Feature;

use App\Livewire\ArtistList;
use App\Livewire\GenreBrowser;
use App\Models\Artist;
use App\Models\ArtistSource;
use App\Models\Genre;
use App\Models\GenreIgnore;
use App\Models\GenreSynonym;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\ArtistGenres;
use App\Support\GenreTree;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class GenresTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function genre(string $path): Genre
    {
        $tree = app(GenreTree::class);

        return Genre::get()->first(fn ($g) => $tree->path($g->id) === $path) ?? $this->fail('Genre fehlt: '.$path);
    }

    public function test_tree_is_imported_from_the_csv_and_misplaced_rows_are_fixed(): void
    {
        $this->assertSame([19, 152, 225], [Genre::where('level', 1)->count(), Genre::where('level', 2)->count(), Genre::where('level', 3)->count()]);
        $this->assertSame(3, $this->genre('Electronic › Ambient › Dark Ambient')->level, 'Style der doppelten Familie richtig eingehängt');
        $this->assertSame(0, Genre::whereNull('parent_id')->where('name', 'Ambient')->count(), 'kein Hauptgenre „Ambient“');
        $this->assertSame(2, $this->genre('Kinder & Wort › Kinderlieder')->level, 'Style ohne Familie = Ebene 2');
        $this->assertTrue(GenreSynonym::where('synonym', 'Electronica')->exists(), '„(als Sammelbegriff)“ entfernt');
        $this->assertSame('Deep House', $this->genre('Electronic › House › Deep House')->beatport);
    }

    public function test_values_are_matched_by_name_or_synonym_case_and_signs_ignored(): void
    {
        $tree = app(GenreTree::class);
        $cases = ['hip hop' => 'Hip-Hop', 'SYNTH-POP' => 'Pop › Pop (international) › Synthpop', 'Trance' => 'Electronic › Trance',
            'EDM' => 'Electronic › Mainstage', 'Elektronisch' => 'Electronic', 'Oberkrainer' => 'Schlager & Volksmusik › Volksmusik › Oberkrainer'];
        foreach ($cases as $value => $path) {
            $this->assertSame($path, $tree->path((int) $tree->match($value)), $value);
        }
        $this->assertNull($tree->match('Rock/Pop'));
        $this->assertSame(['Pop', 'Folk, World, & Country'], GenreTree::split('Pop; Folk, World, & Country'), 'nur ; trennt');
    }

    public function test_artist_genres_come_from_tags_and_lookup_and_manual_ones_stay(): void
    {
        $this->mp3('library/ATB/A/01.mp3', ['TIT2' => 'Ecstasy', 'TPE1' => 'ATB', 'TCON' => 'Trance']);
        $this->mp3('library/ATB/A/02.mp3', ['TIT2' => 'Ecstasy 2', 'TPE1' => 'ATB', 'TCON' => 'Trance; Rock/Pop']);
        app(Scanner::class)->scan('library');
        $atb = Artist::where('name', 'ATB')->first();
        $trance = $this->genre('Electronic › Trance');

        $this->assertSame(2, (int) DB::table('artist_genre')->where(['artist_id' => $atb->id, 'genre_id' => $trance->id, 'source' => 'tags'])->value('weight'), 'nach dem Einlesen verknüpft');

        ArtistSource::create(['artist_id' => $atb->id, 'service' => 'musicbrainz', 'external_id' => 'mb1', 'name' => 'ATB', 'mbid' => 'mb1', 'chosen' => true,
            'data' => ['genres' => ['trance', 'progressive trance']]]);
        DB::table('artist_genre')->insert(['artist_id' => $atb->id, 'genre_id' => $this->genre('Pop')->id, 'source' => 'manual', 'weight' => 1]);
        app(ArtistGenres::class)->sync($atb);

        $sources = DB::table('artist_genre')->where('artist_id', $atb->id)->get()->map(fn ($r) => app(GenreTree::class)->path($r->genre_id).' · '.$r->source)->sort()->values()->all();
        $this->assertSame(['Electronic › Trance · musicbrainz', 'Electronic › Trance · tags', 'Electronic › Trance › Progressive Trance · musicbrainz', 'Pop · manual'], $sources);

        // Genre im Lied geändert: gleich neu verknüpft
        app(TagEditor::class)->update(Track::where('title', 'Ecstasy')->first(), ['genre' => 'Eurodance']);
        $this->assertSame(1, (int) DB::table('artist_genre')->where(['artist_id' => $atb->id, 'genre_id' => $trance->id, 'source' => 'tags'])->value('weight'));
        $this->assertTrue(DB::table('artist_genre')->where(['artist_id' => $atb->id, 'genre_id' => $this->genre('Eurodance')->id])->exists());
    }

    public function test_genre_page_edits_the_tree_and_relinks(): void
    {
        $page = Livewire::test(GenreBrowser::class)->assertSee('Electronic')->assertSee('Eurodance');
        $trance = $this->genre('Electronic › Trance');

        $page->call('open', $trance->id)->assertSee('Familie · Ebene 2')
            ->set('newSynonym', 'Trance Music')->call('addSynonym')->assertSee('Synonym hinzugefügt');
        $this->assertSame($trance->id, app(GenreTree::class)->match('trance music'));

        $page->set('newChild', 'Dream Trance')->call('addChild')->assertSee('„Dream Trance“ gibt es hier schon');
        $page->set('newChild', 'Neo Trance')->call('addChild')->assertSee('„Neo Trance“ angelegt');
        $dream = $this->genre('Electronic › Trance › Neo Trance');
        $this->assertSame(3, $dream->level);
        $page->call('open', $dream->id)->set('newChild', 'Zu tief')->call('addChild')->assertSee('keine weitere Ebene');

        // Familie verschieben: Ebenen wandern mit; Familie mit Styles unter einen Style geht nicht
        $page->call('open', $trance->id)->set('form.parent_id', $this->genre('Eurodance')->id)->call('save')->assertSee('Gespeichert');
        $this->assertSame('Eurodance › Trance › Neo Trance', app(GenreTree::class)->path($dream->id));
        $page->set('form.parent_id', $this->genre('Pop › Pop (international)')->id)->call('save')->assertSee('höchstens drei Ebenen');

        $page->call('delete')->assertSee('erst die Untergenres');
        $page->call('open', $dream->id)->call('delete')->assertSee('gelöscht');
        $this->assertNull(Genre::find($dream->id));
    }

    public function test_genres_are_added_and_moved_right_in_the_groups(): void
    {
        $page = Livewire::test(GenreBrowser::class);
        $house = $this->genre('Electronic › House');
        $deep = $this->genre('Electronic › House › Deep House');

        $page->call('addChildTo', $house->id, 'Lofi House')->assertSee('„Lofi House“ in Electronic › House angelegt');
        $this->assertSame(3, $this->genre('Electronic › House › Lofi House')->level);
        $page->call('addChildTo', $deep->id, 'zu tief')->assertSee('keine weitere Ebene');

        // Style in eine andere Familie, Style zur Familie, Familie zum Hauptgenre – die Ebene ergibt sich aus der Gruppe
        $page->call('move', $deep->id, $this->genre('Electronic › Techno')->id)->assertSee('„Deep House“ → Electronic › Techno');
        $this->assertSame([3, 'Electronic › Techno › Deep House'], [$deep->fresh()->level, app(GenreTree::class)->path($deep->id)]);
        $page->call('move', $deep->id, $this->genre('Electronic')->id);
        $this->assertSame(2, $deep->fresh()->level);
        $page->call('move', $house->id, null)->assertSee('→ Hauptgenre');
        $this->assertSame([1, 2], [$house->fresh()->level, $this->genre('House › Acid House')->level], 'Styles wandern mit');

        // ungültig: unter sich selbst, zu tief
        $page->call('move', $house->id, $this->genre('House › Acid House')->id)->assertSee('nicht unter sich selbst');
        $page->call('move', $house->id, $this->genre('Pop › Pop (international)')->id)->assertSee('höchstens drei Ebenen');

        $this->get('/genres')->assertOk()->assertSee('+ Familie')->assertSee('Verschieben');
    }

    public function test_unmapped_values_can_be_assigned_globally(): void
    {
        $this->mp3('library/X/A/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'X', 'TCON' => 'Rock/Pop']);
        app(Scanner::class)->scan('library');

        $page = Livewire::test(GenreBrowser::class)->set('tab', 'unmapped')->assertSee('Rock/Pop');
        $page->call('startMap', 'Rock/Pop')->set('mapQuery', 'rock')->assertSee('Rock')
            ->call('mapTo', $this->genre('Rock')->id)->assertSee('„Rock/Pop“ → Rock')->assertDontSee('zuordnen …')
            ->assertSeeHtml('um-done-'.md5('Rock/Pop'))->assertSee('zugeordnet → Rock');

        $this->assertTrue(DB::table('artist_genre')->where(['artist_id' => Artist::where('name', 'X')->value('id'), 'genre_id' => $this->genre('Rock')->id])->exists(), 'neu verknüpft');
    }

    public function test_too_broad_values_are_removed_or_replaced_by_the_artists_main_genre(): void
    {
        $this->mp3('library/ATB/A/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'ATB', 'TCON' => 'Trance']);
        $this->mp3('library/ATB/A/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'ATB', 'TCON' => 'Rock/Pop']);
        $this->mp3('library/ATB/A/03.mp3', ['TIT2' => 'Drei', 'TPE1' => 'ATB', 'TCON' => 'Pop; Rock/Pop']);
        $this->mp3('library/Y/A/01.mp3', ['TIT2' => 'Vier', 'TPE1' => 'Y', 'TCON' => 'Rock/Pop']);
        app(Scanner::class)->scan('library');

        $page = Livewire::test(GenreBrowser::class)->set('tab', 'unmapped')->assertSee('Rock/Pop')->assertSee('zu breit – entfernen');
        $page->call('ignore', 'Rock/Pop')->assertSee('3 Lieder angepasst')->assertSee('Wird entfernt (zu breit)')
            ->assertSeeHtml('um-done-'.md5('Rock/Pop'))->assertSee('zu breit – wird entfernt, 3 Lieder angepasst');   // Zeile bleibt grün stehen

        $this->assertSame(['Eins' => 'Trance', 'Zwei' => 'Electronic', 'Drei' => 'Pop', 'Vier' => null], Track::orderBy('path')->pluck('genre', 'title')->all());
        $this->assertSame(3, TagChange::where('source', 'system')->where('field', 'genre')->where('reason', 'like', '%zu breit%')->count());
        $this->assertSame([], array_filter(app(ArtistGenres::class)->unmapped(), fn ($u) => $u['value'] === 'Rock/Pop'), 'nicht mehr unter „Nicht zugeordnet“');

        // Neu eingelesen: wird gleich wieder entfernt
        $this->mp3('library/Y/A/02.mp3', ['TIT2' => 'Fünf', 'TPE1' => 'Y', 'TCON' => 'Rock/Pop; Schlager']);
        app(Scanner::class)->scan('library');
        $this->assertSame('Schlager', Track::where('title', 'Fünf')->value('genre'));

        $page->call('unignore', GenreIgnore::first()->id)->assertSee('wird nicht mehr entfernt');
        $this->assertSame(0, GenreIgnore::count());
    }

    public function test_unmapped_values_get_suggestions_that_can_be_taken_in_one_click(): void
    {
        $this->mp3('library/X/A/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'X', 'TCON' => 'Elektronik']);
        app(Scanner::class)->scan('library');
        $electronic = $this->genre('Electronic');

        $page = Livewire::test(GenreBrowser::class)->set('tab', 'unmapped')->assertSee('→ Electronic');
        $page->call('assign', 'Elektronik', $electronic->id)->assertSee('„Elektronik“ → Electronic');
        $this->assertSame($electronic->id, app(GenreTree::class)->match('Elektronik'));
    }

    public function test_artist_flyout_shows_genres_and_manual_ones_can_be_added(): void
    {
        $this->mp3('library/ATB/A/01.mp3', ['TIT2' => 'Ecstasy', 'TPE1' => 'ATB', 'TCON' => 'Trance']);
        app(Scanner::class)->scan('library');
        $atb = Artist::where('name', 'ATB')->first();

        $page = Livewire::test(ArtistList::class)->call('open', $atb->id)->assertSee('Electronic')->assertSee('Trance');
        $page->set('genreQuery', 'uplift')->assertSee('Electronic › Trance › Uplifting Trance')
            ->call('addGenre', $this->genre('Electronic › Trance › Uplifting Trance')->id)->assertSee('zugeordnet');
        app(ArtistGenres::class)->sync($atb);
        $this->assertTrue(DB::table('artist_genre')->where(['artist_id' => $atb->id, 'source' => 'manual'])->exists(), 'bleibt beim Neu-Verknüpfen');
        $page->call('removeGenre', $this->genre('Electronic › Trance › Uplifting Trance')->id);
        $this->assertFalse(DB::table('artist_genre')->where(['artist_id' => $atb->id, 'source' => 'manual'])->exists());
    }
}
