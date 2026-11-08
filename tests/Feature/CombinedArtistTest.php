<?php

namespace Tests\Feature;

use App\Livewire\Cleanup;
use App\Livewire\NewArtistModal;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistNames;
use App\Support\CleanupChecks;
use App\Support\SettingsBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** „Kombi mit &“: direkt als ein Künstler bestätigen – über den Dialog „Neuen Künstler erfassen“. */
class CombinedArtistTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_name_can_be_confirmed_as_artist_from_cleanup(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        config(['musicwiz.mb_pause' => 0]);
        $album = Album::create(['location' => 'library', 'dir' => '/m/A', 'title' => 'Bridge', 'category' => 'LIB']);
        foreach (['Mrs. Robinson', 'The Boxer'] as $i => $title) {
            Track::create(['album_id' => $album->id, 'location' => 'library', 'path' => "/m/A/$i.mp3", 'dir' => '/m/A', 'filename' => "$i.mp3", 'ext' => 'mp3',
                'artist' => 'Simon & Garfunkel', 'title' => $title, 'album' => 'Bridge', 'artists' => [], 'albumartists' => [], 'genres' => [], 'duration' => 200]);
        }

        Livewire::test(Cleanup::class)->set('check', 'combined_manual')
            ->assertSee('Simon &amp; Garfunkel', false)
            ->assertSee('Ist ein Künstler – erfassen');

        // Dialog ohne Album öffnen (wie der Knopf im Aufräumen): Hörproben aus der Lib
        $modal = Livewire::test(NewArtistModal::class)->dispatch('open-new-artist', name: 'Simon & Garfunkel')
            ->assertSet('open', true)->assertSet('wizName', 'Simon & Garfunkel')
            ->assertSee('Mrs. Robinson');
        $modal->call('wizSave')->assertSet('open', false)->assertDispatched('artist-directory-changed');

        $this->assertTrue(Artist::where('name', 'Simon & Garfunkel')->exists());
        $this->assertSame([], app()->make(CleanupChecks::class)->findings('combined_manual'));
    }

    public function test_combined_name_can_be_split_and_is_remembered(): void
    {
        Artist::create(['name' => 'Yanou']);
        $album = Album::create(['location' => 'library', 'dir' => '/m/S', 'title' => 'Sunrise', 'category' => 'LIB']);
        Track::create(['album_id' => $album->id, 'location' => 'library', 'path' => '/m/S/1.mp3', 'dir' => '/m/S', 'filename' => '1.mp3', 'ext' => 'mp3', 'writable' => true,
            'artist' => 'Yanou & Liz', 'albumartist' => 'Yanou', 'title' => 'King of My Castle', 'album' => 'Sunrise', 'artists' => [], 'albumartists' => [], 'genres' => [], 'duration' => 200]);
        $this->assertSame(['Yanou & Liz'], ArtistNames::split('Yanou & Liz'), 'ohne Regel: nie automatisch bei &');

        $page = Livewire::test(Cleanup::class)->set('check', 'combined_manual')
            ->assertSee('Trennen …')
            ->call('openSplit', 'Yanou & Liz')
            ->assertSet('split.parts', ['Yanou', 'Liz'])
            ->assertSee('im Verzeichnis')->assertSee('neu')
            ->call('saveSplit')
            ->assertSet('split', null)
            ->assertSet('splitNewArtists', ['Liz'])
            ->assertSee('„Liz“ erfassen');

        $this->assertSame(['Yanou', 'Liz'], ArtistNames::split('Yanou & Liz'));
        $this->assertSame(['Yanou', 'Liz', 'Gast'], ArtistNames::split('yanou & liz feat. Gast'), 'gilt unabhängig von Groß/klein und mit feat.');
        $checks = app()->make(CleanupChecks::class);
        $this->assertSame([], $checks->findings('combined_manual'), 'entschieden – nicht mehr gemeldet');
        $this->assertSame(['Yanou', 'Liz'], $checks->findings('combined')[0]['actions'][0]['tags']['artists'], 'ARTISTS-Vorschlag für die Dateien');
        $this->assertArrayHasKey('Liz', app(ArtistDirectory::class)->namesInCollection(), 'Liz taucht bei „Neue Künstler erfassen“ auf');

        // steht als ein Künstler im Verzeichnis → nicht trennen
        Artist::create(['name' => 'Simon & Garfunkel']);
        $page->call('openSplit', 'Simon & Garfunkel')->call('saveSplit')->assertSee('steht als ein Künstler im Verzeichnis');

        // Sicherung nimmt die Trennung mit
        $export = app(SettingsBackup::class)->export(false);
        $this->assertSame([['name' => 'Yanou & Liz', 'parts' => ['Yanou', 'Liz']]], $export['artist_splits']);
    }
}
