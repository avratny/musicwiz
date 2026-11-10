<?php

namespace Tests\Feature;

use App\Jobs\ImportMusicBrainzAliases;
use App\Livewire\AlbumEditor;
use App\Livewire\Artists;
use App\Livewire\Inbox;
use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\RejectedAlias;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistNames;
use App\Support\Lookup;
use App\Support\SettingsBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Schreibweisen von MusicBrainz ins Verzeichnis übernehmen und in der Inbox Umbenennen vorschlagen. */
class MusicBrainzAliasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicwiz.mb_pause' => 0]);
    }

    private function fakeBobo(): void
    {
        Http::fake(function ($request) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, '/ws/2/artist/bobo') => Http::response(['name' => 'DJ BoBo', 'aliases' => [
                    ['name' => 'DJ Bobo', 'type' => 'Artist name'], ['name' => 'Bobo', 'type' => 'Search hint'],
                    ['name' => 'D.J. BoBo', 'type' => null], ['name' => 'René Baumann', 'type' => 'Legal name'],
                ]]),
                str_contains($url, '/ws/2/artist') => Http::response(['artists' => [['id' => 'bobo', 'name' => 'DJ BoBo', 'score' => 100]]]),
                default => Http::response(['data' => []]),
            };
        });
    }

    public function test_import_adds_new_spellings_without_overwriting(): void
    {
        $bobo = Artist::create(['name' => 'DJ BoBo']);
        $other = Artist::create(['name' => 'Bobo Band']);
        $other->aliases()->create(['alias' => 'The Bobos']);
        Artist::create(['name' => 'Adam [DE]', 'base_name' => 'Adam', 'suffix' => 'DE']);

        $added = app(ArtistDirectory::class)->importMusicBrainzAliases($bobo, ['DJ BoBo', 'DJ Bobo', 'D.J. Bo-Bo', 'Bobo Band', 'The Bobos', 'Adam', 'BoBo']);

        // "DJ Bobo": nur Groß/klein – erkennt das Verzeichnis ohnehin; "Bobo Band"/"The Bobos" gehören anderen; "Adam" ist ein mehrdeutiger Grundname
        $this->assertSame(['D.J. Bo-Bo', 'BoBo'], $added);
        $this->assertSame(['musicbrainz'], ArtistAlias::whereIn('alias', $added)->distinct()->pluck('source')->all());
        $this->assertSame($other->id, ArtistAlias::where('alias', 'The Bobos')->value('artist_id'));
    }

    public function test_deleted_spelling_is_not_fetched_again(): void
    {
        $this->fakeBobo();
        $a = Artist::create(['name' => 'DJ BoBo', 'mbid' => 'bobo']);
        $page = Livewire::test(Artists::class)->call('select', $a->id)->call('loadMusicBrainzAliases');
        $this->assertEqualsCanonicalizing(['Bobo', 'D.J. BoBo'], $a->aliases()->pluck('alias')->all());

        // „Bobo“ löschen und erneut holen: bleibt weg, wird angezeigt
        $page->call('removeAlias', ArtistAlias::where('alias', 'Bobo')->value('id'))
            ->call('loadMusicBrainzAliases')
            ->assertSee('Keine neuen Schreibweisen bei MusicBrainz')
            ->assertSee('Gelöscht:')->assertSee('Bobo');
        $this->assertSame(['D.J. BoBo'], $a->aliases()->pluck('alias')->all());
        $this->assertSame([], app(ArtistDirectory::class)->importMusicBrainzAliases($a, ['bobo']), 'auch in anderer Groß/Kleinschreibung');

        // wieder zulassen → nächstes Holen trägt sie ein
        $page->call('allowAlias', RejectedAlias::where('artist_id', $a->id)->value('id'))->call('loadMusicBrainzAliases');
        $this->assertContains('Bobo', $a->aliases()->pluck('alias')->all());

        // von Hand eingetragen: Ablehnung fällt weg
        $page->call('removeAlias', ArtistAlias::where('alias', 'Bobo')->value('id'))->set('newAlias', 'Bobo')->call('addAlias');
        $this->assertSame(0, RejectedAlias::count());

        // Sicherung nimmt Ablehnungen mit
        RejectedAlias::remember($a->id, 'D.J. BoBo');
        $export = app(SettingsBackup::class)->export(false);
        $this->assertSame(['D.J. BoBo'], collect($export['artists'])->firstWhere('name', 'DJ BoBo')['rejected_aliases']);
    }

    public function test_choosing_musicbrainz_id_in_directory_imports_spellings(): void
    {
        $this->fakeBobo();
        $a = Artist::create(['name' => 'DJ BoBo']);

        Livewire::test(Artists::class)->call('select', $a->id)->call('lookupMusicBrainz')->call('useMusicBrainz', 0)->call('saveArtist')
            ->assertSee('2 Schreibweise(n) von MusicBrainz übernommen');

        $this->assertEqualsCanonicalizing(['Bobo', 'D.J. BoBo'], $a->aliases()->pluck('alias')->all());
        $this->assertFalse(ArtistAlias::where('alias', 'René Baumann')->exists());
    }

    public function test_bulk_job_imports_for_all_artists_with_id(): void
    {
        $this->fakeBobo();
        Artist::create(['name' => 'DJ BoBo', 'mbid' => 'bobo']);
        Artist::create(['name' => 'Ohne ID']);

        Livewire::test(Artists::class)->call('loadAllMusicBrainzAliases'); // Queue im Test: sync

        $this->assertSame(2, ArtistAlias::where('source', 'musicbrainz')->count());
        $this->assertSame(['running' => false, 'done' => 1, 'total' => 1, 'added' => 2, 'errors' => 0],
            array_intersect_key(cache(ImportMusicBrainzAliases::STATUS_KEY), array_flip(['running', 'done', 'total', 'added', 'errors'])));
    }

    public function test_inbox_and_editor_suggest_renaming(): void
    {
        $bobo = Artist::create(['name' => 'DJ BoBo']);
        $bobo->aliases()->create(['alias' => 'D.J. BoBo', 'source' => 'musicbrainz']);
        $album = Album::create(['location' => 'inbox', 'dir' => '/music/inbox/Bobo/Hits', 'title' => 'Hits', 'albumartist' => 'D.J. BoBo']);
        Track::create(['album_id' => $album->id, 'location' => 'inbox', 'path' => '/music/inbox/Bobo/Hits/01.mp3', 'dir' => '/music/inbox/Bobo/Hits',
            'filename' => '01.mp3', 'ext' => 'mp3', 'artist' => 'D.J. BoBo feat. Irene', 'albumartist' => 'D.J. BoBo', 'title' => 'Chihuahua', 'album' => 'Hits', 'genres' => []]);

        Livewire::test(Inbox::class)->assertSee('umbenennen: DJ BoBo');

        Livewire::test(AlbumEditor::class, ['album' => $album])
            ->assertSee('Künstler umbenennen?')
            ->assertSee('Schreibweise laut MusicBrainz')
            ->call('applyAllArtistCorrections')
            ->assertSet('fields.albumartist', 'DJ BoBo')
            ->assertSet('rows.0.artist', 'DJ BoBo feat. Irene')
            ->assertDontSee('Künstler umbenennen?');
    }

    public function test_collaborations_are_never_imported_as_spellings(): void
    {
        $yanou = Artist::create(['name' => 'Yanou']);
        $sg = Artist::create(['name' => 'Simon & Garfunkel']);
        $dir = app(ArtistDirectory::class);

        $this->assertSame(['Ya-Nou'], $dir->importMusicBrainzAliases($yanou, ['Yanou feat. Liz', 'Yanou ft. Liz', 'Yanou vs. DJ X', 'Yanou & Liz', 'Yanou pres. Liz', 'Yanou; Liz', 'Yanou pts. Liz', 'Yanou Pts Liz', 'Yanou presents Liz', 'Yanou introducing Liz', 'Yanou aka Liz', 'Yanou + Liz', 'Yanou / Liz', 'Ya-Nou']));
        $this->assertFalse(ArtistNames::looksCollaboration('AC/DC'), 'Schrägstrich ohne Leerzeichen gehört zum Namen');
        $this->assertSame(0, ArtistAlias::where('artist_id', $yanou->id)->where('alias', 'like', '%Liz%')->count());
        $this->assertSame(['Simon and Garfunkel'], $dir->importMusicBrainzAliases($sg, ['Simon and Garfunkel']));
        // umgedrehter Name ist eine echte Schreibweise
        $this->assertSame(['Berry, Chuck'], $dir->importMusicBrainzAliases(Artist::create(['name' => 'Chuck Berry']), ['Berry, Chuck', 'Chuck Berry, Bo Diddley & Co']));
        // "Yanou feat. Liz" wird weiter getrennt
        $this->assertSame(['Yanou', 'Liz'], ArtistNames::split('Yanou feat. Liz'));
    }

    public function test_lookup_drops_collaboration_aliases(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response(['name' => 'Yanou', 'aliases' => [
            ['name' => 'Yanou feat. Liz', 'type' => 'Artist name'], ['name' => 'Yanou & Do', 'type' => null], ['name' => 'Ya-Nou', 'type' => 'Search hint'],
        ]])]);

        $this->assertSame(['Yanou', 'Ya-Nou'], app(Lookup::class)->musicbrainzArtistAliases('y'));
    }
}
