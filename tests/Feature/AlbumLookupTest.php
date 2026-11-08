<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Models\Album;
use App\Models\GenreMapping;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AlbumLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_compilation_lookup_by_album_title_with_year_and_genre(): void
    {
        Http::fake([
            'musicbrainz.org/ws/2/release-group/rg1*' => Http::response(['genres' => [['name' => 'rock and roll']], 'tags' => []]),
            'musicbrainz.org/ws/2/release-group*' => function ($request) {
                // Sampler: keine Suche nach dem Interpreten des ersten Tracks
                $this->assertStringNotContainsString('Tommy Steele', urldecode($request->url()));

                return Http::response(['release-groups' => [
                    ['id' => 'rg1', 'title' => '50’s No1s, Volume 2', 'first-release-date' => '1988-05-01', 'secondary-types' => ['Compilation'], 'artist-credit' => [['name' => 'Various Artists']]],
                    ['id' => 'rg2', 'title' => 'Ganz anderes Album', 'first-release-date' => '1990', 'secondary-types' => ['Compilation'], 'artist-credit' => [['name' => 'Various Artists']]],
                ]]);
            },
            'api.deezer.com/*' => Http::response(['data' => []]),
        ]);
        $album = Album::create(['location' => 'inbox', 'dir' => '/music/inbox/VA/50s', 'title' => "50's NO1s Volume 2", 'is_compilation' => true]);
        Track::create(['album_id' => $album->id, 'location' => 'inbox', 'path' => '/music/inbox/VA/50s/01.mp3', 'dir' => '/music/inbox/VA/50s',
            'filename' => '01.mp3', 'ext' => 'mp3', 'artist' => 'Tommy Steele', 'title' => 'Singing the Blues', 'album' => "50's NO1s Volume 2", 'genres' => ['Blues']]);
        GenreMapping::updateOrCreate(['source' => 'Rock And Roll'], ['target' => 'Rock']);

        Livewire::test(AlbumEditor::class, ['album' => $album])
            ->call('lookupGenres')
            ->assertSee('50’s No1s, Volume 2')->assertDontSee('Ganz anderes Album')
            ->assertSee('Jahr 1988 übernehmen')->assertSee('Genre Rock übernehmen')
            ->call('useYear', '1988')->assertSet('fields.year', '1988')
            ->call('useGenres', ['Rock'])->assertSet('fields.genres', ['Rock']);
    }
}
