<?php

namespace Tests\Feature;

use App\Livewire\ArtistCreateModal;
use App\Livewire\ArtistList;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Activity;
use App\Support\ArtistRegistry;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class ArtistsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        File::ensureDirectoryExists($this->base.'/_artists');
        config(['musicwiz.artist_images' => $this->base.'/_artists']);
        $this->jpeg('_artists/ABBA.jpg');
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TPE2' => 'ABBA']);
        $this->mp3('library/ABBA/Gold/02.mp3', ['TIT2' => 'SOS', 'TPE1' => 'Abba', 'TALB' => 'Gold', 'TPE2' => 'ABBA']);
        $this->mp3('library/Sampler/Hits/01.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher', 'TALB' => 'Hits', 'TPE2' => 'Various Artists']);
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_scanning_registers_and_links_every_exact_spelling(): void
    {
        app(Scanner::class)->scan('library');

        $this->assertSame(['ABBA', 'Abba', 'Cher'], Artist::orderBy('name')->pluck('name')->all(), 'Groß/klein zählt, Various Artists ist kein Interpret');
        $this->assertFalse(Artist::where('name', 'ABBA')->value('confirmed'));
        $this->assertSame('ABBA.jpg', Artist::where('name', 'ABBA')->value('image'), 'Bild aus dem Bilderordner');
        $this->assertStringContainsString('3 neue Interpreten', Activity::current('scan')['message']);

        $abba = Artist::where('name', 'ABBA')->first();
        $this->assertSame(['SOS', 'Waterloo'], $abba->tracks()->orderBy('title')->pluck('title')->all(), 'Waterloo als Interpret, SOS als Album-Interpret');
        $this->assertSame(['Waterloo'], $abba->tracks('artist')->pluck('title')->all());
        $this->assertSame(['SOS'], Artist::where('name', 'Abba')->first()->tracks()->pluck('title')->all(), 'genaue Schreibweise, nicht „ABBA“');
    }

    public function test_editing_the_artist_of_a_track_links_it_at_once_new_names_become_artists(): void
    {
        app(Scanner::class)->scan('library');
        $believe = Track::where('title', 'Believe')->first();

        app(TagEditor::class)->update($believe, ['artist' => 'Niemand']);

        $new = Artist::where('name', 'Niemand')->first();
        $this->assertNotNull($new, 'sofort als Künstler angelegt');
        $this->assertSame([$believe->id], $new->tracks()->pluck('id')->all());
        $this->assertSame(0, Artist::where('name', 'Cher')->first()->tracks()->count());

        // Kombi mit Gast: einzeln verbunden, ARTISTS zieht mit, die Kombi selbst ist kein Interpret
        app(TagEditor::class)->update($believe->fresh(), ['artist' => 'Cher feat. Niemand']);
        $this->assertSame('Cher; Niemand', $believe->fresh()->artists);
        $this->assertNull(Artist::where('name', 'Cher feat. Niemand')->first());
        $this->assertSame([$believe->id], Artist::where('name', 'Cher')->first()->tracks('artist')->pluck('id')->all());
        $this->assertSame([$believe->id], $new->tracks('guest')->pluck('id')->all());
    }

    public function test_rename_merge_and_alias_change_the_tags_as_logged_system_changes(): void
    {
        app(Scanner::class)->scan('library');
        $registry = app(ArtistRegistry::class);
        [$abba, $abbaLower, $cher] = [Artist::where('name', 'ABBA')->first(), Artist::where('name', 'Abba')->first(), Artist::where('name', 'Cher')->first()];

        // umbenennen: alter Name wird Schreibweise, Lieder bekommen den neuen Namen
        $registry->rename($cher, 'CHER');
        $this->assertSame(['CHER', ['Cher']], [$cher->fresh()->name, $cher->fresh()->aliases->pluck('alias')->all()]);
        $this->assertSame('CHER', Track::where('title', 'Believe')->value('artist'));
        $change = TagChange::sole();
        $this->assertSame(['artist', 'Cher', 'CHER', TagEditor::SYSTEM, 'Interpret umbenannt: Cher → CHER'],
            [$change->field, $change->old_value, $change->new_value, $change->source, $change->reason]);
        $this->assertSame(1, $cher->fresh()->tracks()->count(), 'weiter verbunden');

        // zusammenführen: „Abba“ ist „ABBA“ → SOS bekommt „ABBA“ als Interpret
        $registry->merge($abba, $abbaLower);
        $this->assertSame('ABBA', Track::where('title', 'SOS')->value('artist'));
        $this->assertSame('„Abba“ ist „ABBA“ (zusammengeführt)', TagChange::where('field', 'artist')->latest('id')->value('reason'));
        $this->assertSame(2, $abba->fresh()->tracks()->count());
        $this->assertNull(Artist::where('name', 'Abba')->first());

        // Schreibweise, die schon ein anderer Künstler ist: Hinweis statt stiller Änderung
        $this->expectException(InvalidArgumentException::class);
        $registry->addAlias($cher->fresh(), 'ABBA');
    }

    public function test_removing_a_spelling_makes_it_its_own_artist_again_without_changing_tags(): void
    {
        app(Scanner::class)->scan('library');
        $abba = Artist::where('name', 'ABBA')->first();
        // Schreibweise per Hand eingetragen, dann ein Lied mit genau dieser Schreibweise
        app(ArtistRegistry::class)->addAlias($abba, 'ABBA (Band)');
        $waterloo = Track::where('title', 'Waterloo')->first();
        app(TagEditor::class)->update($waterloo, ['artist' => 'ABBA (Band)']);
        $this->assertSame(['Waterloo'], $abba->tracks('artist')->pluck('title')->all(), 'Schreibweise gehört zu ABBA');

        app(ArtistRegistry::class)->removeAlias($abba, 'ABBA (Band)');

        $this->assertSame('ABBA (Band)', $waterloo->fresh()->artist, 'Tag bleibt');
        $this->assertSame(['Waterloo'], Artist::where('name', 'ABBA (Band)')->first()->tracks()->pluck('title')->all());
    }

    public function test_artist_list_search_status_and_flyout_with_songs(): void
    {
        app(Scanner::class)->scan('library');
        $abba = Artist::where('name', 'ABBA')->first();
        ArtistAlias::create(['artist_id' => $abba->id, 'alias' => 'Agnetha Björn Benny Anni-Frid']);

        $page = Livewire::test(ArtistList::class)->assertSee('ABBA')->assertSee('Cher');
        $page->set('q', 'björn')->assertSee('ABBA')->assertDontSee('Cher');
        $page->set('q', '')->set('status', 'confirmed')->assertDontSee('ABBA');

        $page->set('status', 'all')->call('open', $abba->id)->assertSee('2 Lieder · 1 Album')->assertSee('Waterloo')->assertSee('SOS')
            ->call('toggleConfirmed')->assertSee('Schreibweise bestätigt');
        $this->assertTrue($abba->fresh()->confirmed);

        $page->set('newAlias', 'A.B.B.A.')->call('addAlias')->assertSee('Schreibweise hinzugefügt');
        $page->set('newAlias', 'Cher')->call('addAlias')->assertSee('gehört schon zu „Cher“');

        $page->set('mergeQuery', 'abb')->assertSee('hierher übernehmen')
            ->call('merge', Artist::where('name', 'Abba')->value('id'))->assertSee('„Abba“ übernommen')->assertDontSee('gehört schon');
        $this->assertSame(['ABBA', 'A.B.B.A.', 'Abba', 'Agnetha Björn Benny Anni-Frid'], $abba->fresh()->spellings(), 'Name und Schreibweisen');
        $this->assertSame(2, $abba->fresh()->tracks()->count(), 'Waterloo (Interpret) und SOS (Album-Interpret)');

        $this->get('/interpreten')->assertOk()->assertSee('Interpreten');
    }

    public function test_artists_are_sorted_by_column_header(): void
    {
        app(Scanner::class)->scan('library');
        Artist::where('name', 'Cher')->update(['confirmed' => true]);
        ArtistAlias::create(['artist_id' => Artist::where('name', 'Cher')->value('id'), 'alias' => 'Cherilyn']);

        $page = Livewire::test(ArtistList::class)->assertSeeInOrder(['ABBA', 'Abba', 'Cher']);
        $page->call('sortBy', 'name')->assertSet('dir', 'desc')->assertSeeInOrder(['Cher', 'Abba', 'ABBA']);
        $page->call('sortBy', 'tracks')->assertSet('dir', 'desc')->assertSeeInOrder(['ABBA', 'Abba', 'Cher']); // ABBA: 2 Lieder (auch als Album-Interpret)
        $page->call('sortBy', 'confirmed')->assertSeeInOrder(['Cher', 'ABBA']);
        $page->call('sortBy', 'aliases')->assertSeeInOrder(['Cher', 'ABBA']);
        $page->call('sortBy', 'albums')->call('sortBy', 'albums')->assertSet('dir', 'asc');
        $page->call('sortBy', 'unbekannt')->assertSet('sort', 'albums');
    }

    public function test_image_upload_is_stored_as_jpeg_and_served(): void
    {
        app(Scanner::class)->scan('library');
        $cher = Artist::where('name', 'Cher')->first();

        Livewire::test(ArtistList::class)->call('open', $cher->id)->set('photo', UploadedFile::fake()->image('cher.png', 900, 600))->assertSee('Bild gespeichert');

        $this->assertSame('Cher.jpg', $cher->fresh()->image);
        $this->assertFileExists($this->base.'/_artists/Cher.jpg');
        $this->get('/artist-image/'.$cher->id.'/64')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        Livewire::test(ArtistList::class)->call('open', $cher->id)->call('removeImage');
        $this->assertNull($cher->fresh()->image);
        $this->assertFileExists($this->base.'/_artists/Cher.jpg', 'Datei bleibt liegen');
        $this->get('/artist-image/'.$cher->id.'/64')->assertNotFound();
    }

    public function test_artist_can_be_created_by_hand_and_opens_in_the_flyout(): void
    {
        Artist::create(['name' => 'Cher', 'confirmed' => true]);
        $page = Livewire::test(ArtistList::class)->assertSee('Interpret anlegen');

        $modal = Livewire::test(ArtistCreateModal::class)->dispatch('open-artist-create', context: 'artist-list')->assertSet('open', true);
        $modal->set('name', 'Cher')->call('save')->assertSee('„Cher“ gibt es schon');
        $modal->set('name', 'Bananarama')->call('save')->assertSet('open', false)
            ->assertDispatched('artist-created', fn ($event, $params) => $params['context'] === 'artist-list');

        $new = Artist::where('name', 'Bananarama')->sole();
        $this->assertTrue($new->confirmed);
        $page->dispatch('artist-created', id: $new->id, context: 'artist-list')->assertSet('selected', $new->id)->assertSee('Interpret angelegt.');
        $page->dispatch('artist-created', id: $new->id, context: 'anderswo')->assertSet('selected', $new->id);
    }

    public function test_merging_keeps_what_the_other_artist_knew(): void
    {
        $registry = app(ArtistRegistry::class);
        $empty = $registry->add('The Beatles', false);
        $full = $registry->add('Beatles, The', false);
        $full->update(['mbid' => 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d', 'discogs_id' => '82730', 'sort_name' => 'Beatles, The']);
        $genre = DB::table('genres')->value('id');
        DB::table('artist_genre')->insert(['artist_id' => $full->id, 'genre_id' => $genre, 'source' => 'manual', 'weight' => 1]);
        DB::table('artist_sources')->insert(['artist_id' => $full->id, 'service' => 'musicbrainz', 'external_id' => $full->mbid, 'name' => 'The Beatles', 'chosen' => true]);

        $registry->merge($empty, $full);

        $empty->refresh();
        $this->assertSame(['b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d', '82730', 'Beatles, The'], [$empty->mbid, $empty->discogs_id, $empty->sort_name]);
        $this->assertTrue(DB::table('artist_genre')->where('artist_id', $empty->id)->where('genre_id', $genre)->where('source', 'manual')->exists());
        $this->assertSame([1, 1], [DB::table('artist_sources')->where('artist_id', $empty->id)->count(), DB::table('artist_sources')->where('artist_id', $empty->id)->where('chosen', true)->count()]);

        // verschiedene MusicBrainz-Einträge: automatisch nie, nur ausdrücklich
        $other = $registry->add('Beatles Revival', false);
        $other->update(['mbid' => 'ffffffff-0000-0000-0000-000000000001']);
        $this->assertTrue(ArtistRegistry::conflict($empty, $other));
        try {
            $registry->merge($empty, $other);
            $this->fail('verschiedene MusicBrainz-Einträge');
        } catch (InvalidArgumentException) {
        }
        $this->assertTrue(Artist::whereKey($other->id)->exists());
        $registry->merge($empty, $other, true);
        $this->assertFalse(Artist::whereKey($other->id)->exists());
        $this->assertSame('b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d', $empty->fresh()->mbid, 'der eigene Eintrag bleibt');
    }
}
