<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistGroupAnalyzer;
use App\Support\SettingsBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Assistent: ähnliche, noch nicht erfasste Namen und Schreibweisen von MusicBrainz mitspeichern (mit Herkunft). */
class WizardRelatedNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicwiz.mb_pause' => 0]);
    }

    private function tracks(array $byArtist): void
    {
        static $i = 0;
        foreach ($byArtist as $artist => $count) {
            for ($n = 0; $n < $count; $n++) {
                $i++;
                Track::create(['location' => 'library', 'path' => "/m/$i.mp3", 'dir' => '/m', 'filename' => "$i.mp3", 'ext' => 'mp3',
                    'artist' => $artist, 'title' => 'Lied '.$i, 'album' => 'A', 'genres' => [], 'artists' => [], 'albumartists' => []]);
            }
        }
    }

    private function fakeMusicBrainz(): void
    {
        Http::fake(function ($request) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, '/ws/2/artist/bobo') => Http::response(['name' => 'DJ BoBo', 'aliases' => [
                    ['name' => 'DJ Bobo', 'type' => 'Artist name'], ['name' => 'Bobo', 'type' => 'Search hint'], ['name' => 'René Baumann', 'type' => 'Legal name'],
                ]]),
                str_contains($url, '/ws/2/artist') => Http::response(['artists' => [['id' => 'bobo', 'name' => 'DJ BoBo', 'score' => 100]]]),
                str_contains($url, '/ws/2/recording') => Http::response(['count' => 1]),
                default => Http::response(['data' => []]),
            };
        });
    }

    public function test_same_spelling_is_checked_and_saved_with_the_artist(): void
    {
        $this->tracks(['Mr. President' => 3, 'Mr President' => 1]);

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->assertSee('gleiche Schreibweise bis auf')
            ->call('wizSave')
            ->assertSee('1 Schreibweise(n) mitgespeichert');

        $alias = ArtistAlias::where('alias', 'Mr President')->sole();
        $this->assertSame('Mr. President', $alias->artist->name);
        $this->assertNull($alias->source);
    }

    public function test_musicbrainz_aliases_are_offered_and_stored_with_source(): void
    {
        $this->tracks(['DJ BoBo' => 3, 'Bobo' => 1, 'René Baumann' => 1]);
        $this->fakeMusicBrainz();

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->call('wizLookup')
            ->call('wizLookupDetails')
            ->assertSee('laut MusicBrainz eine Schreibweise')
            ->assertDontSee('René Baumann – ', false)
            ->call('wizSave');

        $this->assertSame('musicbrainz', ArtistAlias::where('alias', 'Bobo')->sole()->source);
        $this->assertFalse(ArtistAlias::where('alias', 'René Baumann')->exists(), 'bürgerlicher Name ist keine Schreibweise');
    }

    public function test_possible_typo_is_offered_but_not_checked(): void
    {
        $this->tracks(['Taucher' => 3, 'Tauscher' => 1]);

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->assertSee('möglicher Tippfehler')
            ->call('wizSave')
            ->assertSet('wizName', 'Tauscher');

        $this->assertSame(0, ArtistAlias::count());
    }

    public function test_checked_typo_is_saved_too(): void
    {
        $this->tracks(['Taucher' => 3, 'Tauscher' => 1]);

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->set('wizTake.'.md5('Tauscher'), true)
            ->call('wizSave')
            ->assertSet('wizName', null);

        $this->assertSame('Taucher', ArtistAlias::where('alias', 'Tauscher')->sole()->artist->name);
    }

    public function test_backup_keeps_alias_source(): void
    {
        $a = Artist::create(['name' => 'DJ BoBo']);
        $a->aliases()->create(['alias' => 'Bobo', 'source' => 'musicbrainz']);
        $a->aliases()->create(['alias' => 'DJ Bo Bo']);
        $backup = app(SettingsBackup::class);
        $data = $backup->export(false);
        $this->assertContains(['alias' => 'Bobo', 'source' => 'musicbrainz'], $data['artists'][0]['aliases']);
        $this->assertContains('DJ Bo Bo', $data['artists'][0]['aliases']);

        ArtistAlias::query()->delete();
        $backup->import($data, ['artists']);

        $this->assertSame('musicbrainz', ArtistAlias::where('alias', 'Bobo')->sole()->source);
        $this->assertNull(ArtistAlias::where('alias', 'DJ Bo Bo')->sole()->source);
    }

    public function test_artists_page_ignores_inbox(): void
    {
        $this->tracks(['Taucher' => 2]);
        Track::create(['location' => 'inbox', 'path' => '/inbox/1.mp3', 'dir' => '/inbox', 'filename' => '1.mp3', 'ext' => 'mp3',
            'artist' => 'Nur In Der Inbox', 'title' => 'x', 'album' => 'A', 'genres' => ['Pop'], 'artists' => [], 'albumartists' => []]);
        Track::create(['location' => 'inbox', 'path' => '/inbox/2.mp3', 'dir' => '/inbox', 'filename' => '2.mp3', 'ext' => 'mp3',
            'artist' => 'Tauscher', 'title' => 'y', 'album' => 'A', 'genres' => [], 'artists' => [], 'albumartists' => []]);

        $directory = app(ArtistDirectory::class);
        $this->assertSame(['Taucher' => 2], $directory->namesInCollection());
        $this->assertSame([], $directory->candidateGroups(), 'Tauscher liegt nur in der Inbox');
        $this->assertArrayNotHasKey('Nur In Der Inbox', app(ArtistGroupAnalyzer::class)->profiles());
        Livewire::test(Artists::class, ['tab' => 'unknown'])->assertDontSee('Nur In Der Inbox');
    }

    public function test_directory_shows_songs_of_name_and_aliases(): void
    {
        $this->tracks(['Shakira' => 2, 'Shakira Mebarak' => 1]);
        $a = Artist::create(['name' => 'Shakira']);
        $a->aliases()->create(['alias' => 'Shakira Mebarak']);
        Track::create(['location' => 'inbox', 'path' => '/inbox/9.mp3', 'dir' => '/inbox', 'filename' => '9.mp3', 'ext' => 'mp3',
            'artist' => 'Alice Deejay', 'title' => 'Better Off Alone', 'album' => 'Who Needs Guitars', 'genres' => [], 'artists' => [], 'albumartists' => []]);
        $alice = Artist::create(['name' => 'Alice Deejay']);

        Livewire::test(Artists::class)->call('select', $a->id)->assertViewHas('artistTracks', fn ($t) => count($t) === 3);
        Livewire::test(Artists::class)->call('select', $alice->id)->assertSee('1 Lied liegt noch in der Inbox');
    }
}
