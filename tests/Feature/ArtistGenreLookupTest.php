<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Models\GenreMapping;
use App\Models\Track;
use App\Support\Genres;
use App\Support\Lookup;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Standard-Genre aus MusicBrainz und Discogs ermitteln und auf die eigene Genre-Liste abbilden. */
class ArtistGenreLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicwiz.mb_pause' => 0]);
    }

    public function test_external_values_map_to_list_case_insensitive_and_via_mapping(): void
    {
        GenreMapping::updateOrCreate(['source' => 'Euro House'], ['target' => 'Eurodance']);

        $r = app(Genres::class)->fromExternal(['electronic' => 3, 'trance' => 3, 'Euro House' => 1, 'german' => 5]);

        $this->assertSame('Trance', $r['genre'], 'Gleichstand: Unterstil vor Hauptgenre');
        $mapped = $r['mapped'];
        ksort($mapped);
        $this->assertSame(['Euro House' => 'Eurodance', 'electronic' => 'Electronic', 'trance' => 'Trance'], $mapped);
        $this->assertSame(['german'], $r['unmapped']);
    }

    public function test_tie_is_reported_instead_of_picking_one(): void
    {
        $r = app(Genres::class)->fromExternal(['electronic' => 1, 'house' => 1, 'trance' => 1]);

        $this->assertEqualsCanonicalizing(['House', 'Trance'], $r['tied']);
    }

    public function test_musicbrainz_artist_genres_use_votes(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response([
            'genres' => [['name' => 'trance', 'count' => 4], ['name' => 'house', 'count' => 0]],
            'tags' => [['name' => 'german', 'count' => 2], ['name' => 'trance', 'count' => 1]],
        ])]);

        $this->assertSame(['trance' => 4, 'german' => 2], app(Lookup::class)->musicbrainzArtistGenres('x'));
    }

    public function test_discogs_genres_only_count_releases_of_this_artist(): void
    {
        app(Settings::class)->set(['discogs_token' => 't']);
        Http::fake(['api.discogs.com/*' => Http::response(['results' => [
            ['title' => 'Taucher - Bizarre', 'genre' => ['Electronic'], 'style' => ['Trance']],
            ['title' => 'Taucher (2) - Live', 'genre' => ['Electronic'], 'style' => ['Trance', 'Hard Trance']],
            ['title' => 'Tauchers Band - Polka', 'genre' => ['Folk'], 'style' => ['Polka']],
        ]])]);

        $this->assertSame(['Trance' => 4, 'Electronic' => 2, 'Hard Trance' => 2], app(Lookup::class)->discogsArtistGenres('Taucher'));
    }

    public function test_wizard_uses_musicbrainz_genre_when_collection_has_none(): void
    {
        Track::create(['location' => 'library', 'path' => '/m/1.mp3', 'dir' => '/m', 'filename' => '1.mp3', 'ext' => 'mp3',
            'artist' => 'Taucher', 'title' => 'Bizarre', 'album' => 'A', 'genres' => [], 'artists' => [], 'albumartists' => []]);
        Http::fake(function ($request) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, '/ws/2/artist/beck') => Http::response(['genres' => [['name' => 'trance', 'count' => 3]]]),
                str_contains($url, '/ws/2/artist') => Http::response(['artists' => [['id' => 'beck', 'name' => 'Taucher', 'score' => 100]]]),
                str_contains($url, '/ws/2/recording') => Http::response(['count' => 1]),
                default => Http::response(['data' => []]),
            };
        });

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->call('wizLookup')
            ->assertSet('wizMbid', 'beck')
            ->assertSet('wizGenre', 'Trance')
            ->assertSee('MusicBrainz:');
    }

    public function test_directory_can_look_up_genres_online(): void
    {
        $a = Artist::create(['name' => 'Taucher', 'mbid' => 'beck']);
        Http::fake(['musicbrainz.org/*' => Http::response(['genres' => [['name' => 'trance', 'count' => 3]]])]);

        Livewire::test(Artists::class)->call('select', $a->id)->call('lookupGenres')
            ->assertSet('genreProposals.0.source', 'MusicBrainz')
            ->assertSet('genreProposals.0.genre', 'Trance')
            ->call('$set', 'form.default_genre', 'Trance');

        $this->assertSame('Trance', $a->fresh()->default_genre);
    }
}
