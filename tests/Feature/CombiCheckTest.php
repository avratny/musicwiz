<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Livewire\Cleanup;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Support\ArtistQuickMatch;
use App\Support\CombiCheck;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Kombi-Prüfung: Act oder Zusammenarbeit – nach den eingetragenen Künstlern bei Deezer, Spotify und MusicBrainz. */
class CombiCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicwiz.mb_pause' => 0]);
        app(Settings::class)->set(['spotify_client_id' => 'id', 'spotify_client_secret' => 'secret']);
        $album = Album::create(['location' => 'library', 'dir' => '/m/X', 'title' => 'Mix', 'category' => 'LIB']);
        foreach (['Dr. Motte & WestBam' => 'Sunshine', 'Simon and Garfunkel' => 'The Boxer'] as $artist => $title) {
            Track::create(['album_id' => $album->id, 'location' => 'library', 'path' => '/m/X/'.md5($artist).'.mp3', 'dir' => '/m/X', 'filename' => md5($artist).'.mp3', 'ext' => 'mp3', 'writable' => true,
                'artist' => $artist, 'albumartist' => 'Various', 'title' => $title, 'album' => 'Mix', 'artists' => [], 'albumartists' => [], 'genres' => [], 'duration' => 200]);
        }
        // eingetragene Künstler je Dienst: „Sunshine“ zwei Künstler, „The Boxer“ ein Act
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            $sunshine = str_contains($url, 'Sunshine') || str_contains($url, '/track/1');
            if (str_contains($url, 'accounts.spotify.com')) {
                return Http::response(['access_token' => 'tok']);
            }
            if (str_contains($url, 'api.deezer.com/search/track')) {
                return Http::response(['data' => [['id' => $sunshine ? 1 : 2, 'title' => $sunshine ? 'Sunshine' : 'The Boxer']]]);
            }
            if (str_contains($url, 'api.deezer.com/track/')) {
                return Http::response(['contributors' => str_contains($url, '/track/1')
                    ? [['name' => 'Westbam', 'role' => 'Main'], ['name' => 'Dr. Motte', 'role' => 'Main']]
                    : [['name' => 'Simon & Garfunkel', 'role' => 'Main']]]);
            }
            if (str_contains($url, 'api.spotify.com/v1/search')) {
                return Http::response(['tracks' => ['items' => [['name' => $sunshine ? 'Sunshine' : 'The Boxer',
                    'artists' => $sunshine ? [['name' => 'Dr. Motte'], ['name' => 'WestBam']] : [['name' => 'Simon & Garfunkel']]]]]]);
            }
            if (str_contains($url, '/ws/2/recording')) {
                return Http::response(['recordings' => [['title' => $sunshine ? 'Sunshine' : 'The Boxer',
                    'artist-credit' => $sunshine ? [['name' => 'Dr. Motte', 'joinphrase' => ' & '], ['name' => 'WestBam']] : [['name' => 'Simon & Garfunkel']]]]]);
            }

            return Http::response([]);
        });
    }

    public function test_collaboration_and_act_are_told_apart_by_credits(): void
    {
        $check = app(CombiCheck::class);
        $collab = $check->check('Dr. Motte & WestBam');
        $this->assertSame('collab', $collab['verdict']);
        $this->assertSame(['Dr. Motte', 'WestBam'], $collab['parts'], 'Schreibweise von MusicBrainz (Deezer schreibt „Westbam“)');
        $this->assertEqualsCanonicalizing(['Deezer', 'MusicBrainz', 'Spotify'], array_column($collab['evidence'], 'source'));
        $this->assertStringContainsString('getrennte Künstler', $collab['reason']);

        $act = $check->check('Simon and Garfunkel');
        $this->assertSame('act', $act['verdict']);
        $this->assertSame(3, count(array_filter($act['evidence'], fn ($e) => $e['vote'] === 'act')));
        $this->assertSame($act, $check->cached('Simon and Garfunkel'), 'gespeichert');
    }

    public function test_results_drive_suggestions_and_directory(): void
    {
        app(CombiCheck::class)->check('Dr. Motte & WestBam');
        app(CombiCheck::class)->check('Simon and Garfunkel');

        // Aufräumen › Kombi: Zusammenarbeit wird mit den eingetragenen Namen getrennt
        $page = Livewire::test(Cleanup::class)->set('check', 'combined_manual')->assertSee('Trennen: Dr. Motte · WestBam');
        $this->assertSame('split', $page->viewData('combinedSuggest')['Dr. Motte & WestBam']['kind']);

        // Schnell erfassen: Kombi-Zeile zeigt das Ergebnis und bietet Trennen mit diesen Namen
        $rows = collect(app(ArtistQuickMatch::class)->rows())->keyBy('name');
        $this->assertSame('collab', $rows['Dr. Motte & WestBam']['combi']['verdict']);
        $this->assertSame(['Dr. Motte', 'WestBam'], $rows['Dr. Motte & WestBam']['split']);

        // Verzeichnis: Eintrag, der eine Zusammenarbeit ist, wird markiert; Trennen ist mit den Namen vorbelegt
        $entry = Artist::create(['name' => 'Dr. Motte & WestBam']);
        Livewire::test(Artists::class)->assertViewHas('filterCounts', fn ($c) => $c['collab'] === 1)
            ->call('select', $entry->id)->assertSee('Kombi-Prüfung')->assertSee('Laut Diensten: Zusammenarbeit')
            ->call('openSplitInto')->assertSet('splitInto.parts', ['Dr. Motte', 'WestBam'])
            ->call('toggleFilter', 'collab')->assertViewHas('artistTotal', 1);
    }

    public function test_votes_handle_typos_and_guests(): void
    {
        $vote = fn (string $name, array $credit) => (fn () => $this->vote($name, $credit))->call(app(CombiCheck::class));
        $this->assertSame('collab', $vote('Dr. Motte & WestBam', ['Westbam', 'Dr. Mote']), 'Tippfehler bei Deezer');
        $this->assertSame('act', $vote('Above & Beyond', ['Above & Beyond', 'Richard Bedford']), 'Act mit Gast');
        $this->assertSame('act', $vote('Simon and Garfunkel', ['Simon & Garfunkel']));
        $this->assertNull($vote('Beam & Yanou', ['Cascada']), 'anderer Künstler');
    }
}
