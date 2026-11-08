<?php

namespace Tests\Feature;

use App\Jobs\PrefetchArtistLookup;
use App\Livewire\AlbumEditor;
use App\Livewire\Artists;
use App\Livewire\NewArtistModal;
use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
                // eine Abfrage für alle Kandidaten: Treffer nur beim richtigen Taucher
                return Http::response(['recordings' => str_contains($url, 'arid:beck') ? [['title' => 'Bizarre', 'artist-credit' => [['artist' => ['id' => 'beck']]]]] : []]);
            }

            return Http::response(['data' => []]); // Deezer
        });

        Livewire::test(Artists::class, ['tab' => 'unknown'])
            ->call('wizLookup')
            ->assertSet('wizMbid', 'beck')
            ->assertSee('passt zu euren Liedern')
            ->assertSee('lädt Bilder, Genres und Schreibweisen') // Treffer zuerst, Rest im zweiten Schritt
            ->call('wizLookupDetails')
            ->assertSet('wizGenre', 'Trance');

        // nur eine Abfrage für die Liedprüfung (statt einer je Kandidat und Titel)
        Http::assertSentCount(collect(Http::recorded())->count());
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), '/ws/2/recording'))->count());
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

    public function test_modal_in_editor_handles_only_the_clicked_inbox_artist(): void
    {
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, '/ws/2/artist')) {
                return Http::response(['artists' => [['id' => 'yan', 'name' => 'Yanou', 'score' => 100, 'disambiguation' => 'DJ', 'country' => 'DE']]]);
            }
            if (str_contains($url, '/ws/2/recording')) {
                // Liedprüfung mit den Titeln aus dem Inbox-Album
                return Http::response(['recordings' => str_contains($url, 'King of My Castle') ? [['title' => 'King of My Castle (Radio Edit)', 'artist-credit' => [['artist' => ['id' => 'yan']]]]] : []]);
            }

            return Http::response(['data' => []]);
        });
        $album = Album::create(['location' => 'inbox', 'dir' => '/i/Sunrise', 'title' => 'Sunrise', 'track_count' => 2]);
        foreach (['King of My Castle', 'Sunrise'] as $i => $title) {
            Track::create(['album_id' => $album->id, 'location' => 'inbox', 'path' => "/i/Sunrise/$i.mp3", 'dir' => '/i/Sunrise', 'filename' => "$i.mp3", 'ext' => 'mp3',
                'artist' => $i ? 'Yanou feat. Liz' : 'Yanou', 'title' => $title, 'album' => 'Sunrise', 'track' => (string) ($i + 1), 'duration' => 100]);
        }

        // im Editor: unbekannte Namen als Knopf, der den Dialog öffnet
        Livewire::test(AlbumEditor::class, ['album' => $album->id])
            ->assertSee('Yanou erfassen')->assertSee('Liz erfassen')
            ->assertSeeHtml('open-new-artist')->assertSeeLivewire(NewArtistModal::class);

        $modal = Livewire::test(NewArtistModal::class)
            ->call('openFor', 'Yanou', $album->id)
            ->assertSet('open', true)
            ->assertSee('Neuer Künstler · 2 Titel in diesem Album')
            ->assertDontSee('Namen noch nicht im Verzeichnis') // keine Warteschlange
            ->assertDontSee('Taucher')
            ->call('wizLookup')
            ->assertSet('wizMbid', 'yan')
            ->assertSee('passt zu euren Liedern')
            ->call('wizLookupDetails')
            ->call('wizSave')
            ->assertSet('open', false)
            ->assertDispatched('artist-directory-changed');

        $this->assertSame('yan', Artist::where('name', 'Yanou')->sole()->mbid);
        $this->assertNull(Artist::where('name', 'Taucher')->first(), 'nur der angeklickte Name');

        // danach ist Yanou bekannt: im Editor nur noch Liz als neu
        Livewire::test(AlbumEditor::class, ['album' => $album->id])->assertDontSee('Yanou erfassen')->assertSee('Liz erfassen');

        // Abbrechen speichert nichts
        Livewire::test(NewArtistModal::class)->call('openFor', 'Liz', $album->id)->call('close')->assertSet('open', false);
        $this->assertNull(Artist::where('name', 'Liz')->first());
    }

    public function test_unknown_artists_are_prefetched_in_background(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $album = Album::create(['location' => 'inbox', 'dir' => '/i/S', 'title' => 'S', 'track_count' => 1]);
        Track::create(['album_id' => $album->id, 'location' => 'inbox', 'path' => '/i/S/1.mp3', 'dir' => '/i/S', 'filename' => '1.mp3', 'ext' => 'mp3',
            'artist' => 'Yanou feat. Liz', 'title' => 'King of My Castle', 'album' => 'S', 'track' => '1', 'duration' => 100]);

        Livewire::test(AlbumEditor::class, ['album' => $album->id])->call('$refresh');

        Queue::assertPushedOn('research', PrefetchArtistLookup::class);
        Queue::assertPushed(PrefetchArtistLookup::class, fn ($job) => $job->name === 'Yanou' && $job->titles === ['King of My Castle']);
        Queue::assertPushed(PrefetchArtistLookup::class, 2); // Yanou und Liz – je einmal, auch nach erneutem Rendern

        // auf der Künstler-Seite: die nächsten zwei Namen der Warteschlange
        Livewire::test(Artists::class, ['tab' => 'unknown']);
        Queue::assertPushed(PrefetchArtistLookup::class, fn ($job) => $job->name === 'Chuck Berry');
    }
}
