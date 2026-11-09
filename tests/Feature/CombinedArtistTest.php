<?php

namespace Tests\Feature;

use App\Jobs\PrefetchArtistLookup;
use App\Livewire\Cleanup;
use App\Livewire\NewArtistModal;
use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistSplit;
use App\Models\PendingChange;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistNames;
use App\Support\CleanupChecks;
use App\Support\Lookup;
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
        // „Trennen“ merkt ARTISTS gleich vor (digitaler Zwilling) – nichts mehr im Aufräumen zu tun
        $this->assertSame([], $checks->findings('combined'));
        $this->assertSame(['Yanou', 'Liz'], Track::where('path', '/m/S/1.mp3')->value('artists'));
        $this->assertSame(['artists' => []], PendingChange::sole()->tags_before);
        $this->assertArrayHasKey('Liz', app(ArtistDirectory::class)->namesInCollection(), 'Liz taucht bei „Neue Künstler erfassen“ auf');

        // steht als ein Künstler im Verzeichnis → nicht trennen
        Artist::create(['name' => 'Simon & Garfunkel']);
        $page->call('openSplit', 'Simon & Garfunkel')->call('saveSplit')->assertSee('steht als ein Künstler im Verzeichnis');

        // Sicherung nimmt die Trennung mit
        $export = app(SettingsBackup::class)->export(false);
        $this->assertSame([['name' => 'Yanou & Liz', 'parts' => ['Yanou', 'Liz']]], $export['artist_splits']);
    }

    public function test_clear_combis_get_a_suggestion_and_can_be_taken_all_at_once(): void
    {
        config(['musicwiz.mb_pause' => 0]);
        Artist::create(['name' => 'Yanou']);
        Artist::create(['name' => 'Liz']);
        $album = Album::create(['location' => 'library', 'dir' => '/m/C', 'title' => 'Mix', 'category' => 'LIB']);
        foreach (['Yanou & Liz' => 'King of My Castle', 'Simon and Garfunkel' => 'The Boxer', 'Foo & Bar' => 'Unbekannt'] as $artist => $title) {
            Track::create(['album_id' => $album->id, 'location' => 'library', 'path' => '/m/C/'.md5($artist).'.mp3', 'dir' => '/m/C', 'filename' => md5($artist).'.mp3', 'ext' => 'mp3', 'writable' => true,
                'artist' => $artist, 'albumartist' => 'Yanou', 'title' => $title, 'album' => 'Mix', 'artists' => [], 'albumartists' => [], 'genres' => [], 'duration' => 200]);
        }
        // Vorladen: MusicBrainz kennt „Simon & Garfunkel“ mit „The Boxer“, „Foo & Bar“ nicht
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, '/ws/2/artist/')) {
                return Http::response(['id' => 'mb-sg', 'name' => 'Simon & Garfunkel', 'aliases' => [], 'genres' => [], 'tags' => []]);
            }
            if (str_contains($url, '/ws/2/recording')) {
                return Http::response(['recordings' => [['title' => 'The Boxer', 'artist-credit' => [['artist' => ['id' => 'mb-sg']]]]]]);
            }

            return Http::response(['artists' => str_contains($url, 'Garfunkel') ? [['id' => 'mb-sg', 'name' => 'Simon & Garfunkel', 'score' => 100, 'type' => 'Group']] : []]);
        });
        PrefetchArtistLookup::prefetch(app(Lookup::class), 'Simon and Garfunkel', ['The Boxer']);
        PrefetchArtistLookup::prefetch(app(Lookup::class), 'Foo & Bar', ['Unbekannt']);

        $page = Livewire::test(Cleanup::class)->set('check', 'combined_manual')
            ->assertSee('Trennen: Yanou · Liz')
            ->assertSee('Ist ein Künstler: Simon &amp; Garfunkel', false)
            ->assertSee('Alle Vorschläge übernehmen (2)');
        $this->assertArrayNotHasKey('Foo & Bar', $page->viewData('combinedSuggest'), 'unbekannt: von Hand entscheiden');

        $page->call('acceptAllCombined')->assertSee('getrennt');
        $this->assertSame(['Yanou', 'Liz'], ArtistSplit::where('name_key', 'yanou & liz')->sole()->parts);
        $this->assertSame(['Yanou', 'Liz'], Track::where('artist', 'Yanou & Liz')->sole()->artists, 'ARTISTS im Zwilling vorgemerkt');
        $sg = Artist::where('name', 'Simon & Garfunkel')->sole();
        $this->assertSame('mb-sg', $sg->mbid);
        $this->assertTrue($sg->aliases()->where('alias', 'Simon and Garfunkel')->exists(), 'Bestandsname bleibt Schreibweise');
        $this->assertFalse(Artist::where('name', 'Foo & Bar')->exists());
        $this->assertGreaterThan(0, PendingChange::count());
    }
}
