<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** „Neue Künstler erfassen“: einer nach dem anderen, mit Schreibweise, MusicBrainz/Discogs, Bild und Genre. */
class NewArtistWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicwiz.mb_pause' => 0]);
        $i = 0;
        foreach (['Taucher' => ['Bizarre', 'Child of the Universe', 'Atlanta'], 'Chuck Berry' => ['Maybelline'], 'Tauscher' => ['Bizarre (Radio Mix)']] as $artist => $titles) {
            foreach ($titles as $title) {
                $i++;
                Track::create(['location' => 'library', 'path' => "/m/$i.mp3", 'dir' => '/m', 'filename' => "$i.mp3", 'ext' => 'mp3',
                    'artist' => $artist, 'title' => $title, 'album' => 'A', 'genres' => ['Trance'], 'artists' => [], 'albumartists' => []]);
            }
        }
    }

    public function test_queue_starts_with_most_frequent_name(): void
    {
        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->assertSet('wizName', 'Taucher')
            ->assertSee('Neuer Künstler · 3 Dateien im Bestand');
    }

    public function test_lookup_prefers_the_musicbrainz_artist_with_our_songs(): void
    {
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, '/ws/2/artist')) {
                return Http::response(['artists' => [
                    ['id' => 'other', 'name' => 'Taucher', 'score' => 100, 'disambiguation' => 'Band aus Chile'],
                    ['id' => 'beck', 'name' => 'Taucher', 'score' => 100, 'disambiguation' => 'Ralph Armand Beck', 'country' => 'DE'],
                ]]);
            }
            if (str_contains($url, '/ws/2/recording')) {
                return Http::response(['count' => str_contains($url, 'arid:beck') ? 1 : 0]);
            }

            return Http::response(['data' => []]); // Deezer
        });

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->call('wizLookup')
            ->assertSet('wizMbid', 'beck')
            ->assertSet('wizGenre', 'Trance')
            ->assertSee('passt zu euren Liedern');
    }

    public function test_save_creates_artist_and_moves_on(): void
    {
        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->set('wizMbid', 'beck')
            ->set('wizGenre', 'Trance')
            ->call('wizSave')
            ->assertSet('wizName', 'Chuck Berry');

        $a = Artist::where('name', 'Taucher')->sole();
        $this->assertSame('beck', $a->mbid);
        $this->assertSame('Trance', $a->default_genre);
    }

    public function test_changed_spelling_keeps_original_as_alias(): void
    {
        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->call('wizOpen', 'Chuck Berry')
            ->set('wizFinal', 'Chuck Berry Sr.')
            ->call('wizSave');

        $this->assertSame('Chuck Berry Sr.', ArtistAlias::where('alias', 'Chuck Berry')->sole()->artist->name);
    }

    public function test_typo_can_be_assigned_to_similar_directory_entry(): void
    {
        $taucher = Artist::create(['name' => 'Taucher']);

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->call('wizOpen', 'Tauscher')
            ->assertSee('Schreibweise von „Taucher“')
            ->call('wizAliasOf', $taucher->id)
            ->assertSet('wizName', 'Chuck Berry');

        $this->assertSame($taucher->id, ArtistAlias::where('alias', 'Tauscher')->sole()->artist_id);
    }

    public function test_skip_moves_on_without_saving(): void
    {
        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->call('wizSkip')
            ->assertSet('wizName', 'Chuck Berry');

        $this->assertSame(0, Artist::count());
    }

    public function test_search_again_with_other_spelling(): void
    {
        $searched = [];
        Http::fake(function ($request) use (&$searched) {
            $url = urldecode($request->url());
            if (str_contains($url, '/ws/2/artist')) {
                $searched[] = $url;

                return Http::response(['artists' => str_contains($url, 'Tauscher')
                    ? [['id' => 'tauscher', 'name' => 'Tauscher', 'score' => 100]]
                    : [['id' => 'other', 'name' => 'Taucher', 'score' => 100, 'disambiguation' => 'Band aus Chile']]]);
            }
            if (str_contains($url, '/ws/2/recording')) {
                // Liedprüfung mit den Titeln aus dem Bestand des ursprünglichen Namens
                return Http::response(['count' => str_contains($url, 'arid:tauscher') && str_contains($url, 'Bizarre') ? 1 : 0]);
            }

            return Http::response(['data' => []]);
        });

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->call('wizLookup')
            ->assertSet('wizMbid', 'other')
            ->assertSee('Mit dieser Schreibweise suchen')
            ->call('wizSearchAgain', 'Tauscher')
            ->assertSet('wizFinal', 'Tauscher')
            ->assertSet('wizSearched', 'Tauscher')
            ->assertSet('wizMbid', 'tauscher')
            ->assertSet('wizName', 'Taucher')
            ->assertSee('gesucht mit „Tauscher“', false);

        $this->assertNotEmpty(array_filter($searched, fn ($u) => str_contains($u, 'Tauscher')), 'bei MusicBrainz mit der neuen Schreibweise gesucht');
    }

    public function test_name_from_url_prefills_spelling(): void
    {
        Livewire::withQueryParams(['tab' => 'unknown', 'neu' => 'Chuck Berry'])->test(Artists::class)
            ->assertSet('wizName', 'Chuck Berry')
            ->assertSet('wizFinal', 'Chuck Berry')
            ->assertSet('wizSearched', 'Chuck Berry');
    }
}
