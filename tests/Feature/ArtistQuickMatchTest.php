<?php

namespace Tests\Feature;

use App\Jobs\PrefetchArtistLookup;
use App\Livewire\Artists;
use App\Livewire\NewArtistModal;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\PendingChange;
use App\Models\Track;
use App\Support\ArtistNames;
use App\Support\Lookup;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** „Schnell erfassen“: eindeutige MusicBrainz-Treffer gesammelt anlegen, alles Unklare bleibt beim Assistenten. */
class ArtistQuickMatchTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicwiz.mb_pause' => 0]);
        $this->base = storage_path('framework/testing/quick-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'artist_images' => $this->base.'/_artists', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function mp3(string $rel, array $tags): void
    {
        $path = $this->base.'/library/artists/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags + ['comment' => 'LIB']]]);
    }

    public function test_clear_matches_are_created_in_one_go(): void
    {
        $this->mp3('Y/Yanou/Sunrise (2006)/01 - King of My Castle.mp3', ['artist' => 'Yanou', 'albumartist' => 'Yanou', 'album' => 'Sunrise', 'year' => '2006', 'track' => '1', 'title' => 'King of My Castle', 'genres' => ['Dance']]);
        $this->mp3('A/Ac-Dc/Back in Black (1980)/01 - Hells Bells.mp3', ['artist' => 'Ac/Dc', 'albumartist' => 'Ac/Dc', 'album' => 'Back in Black', 'year' => '1980', 'track' => '1', 'title' => 'Hells Bells']);
        $this->mp3('M/Madonna/Like a Prayer (1989)/01 - Like a Prayer.mp3', ['artist' => 'Madonna', 'albumartist' => 'Madonna', 'album' => 'Like a Prayer', 'year' => '1989', 'track' => '1', 'title' => 'Like a Prayer']);
        app(Scanner::class)->scan('library');

        $img = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($img);
        $jpeg = ob_get_clean();
        Http::fake(function ($request) use ($jpeg) {
            $url = urldecode($request->url());
            if (str_contains($url, 'cdn.test/')) {
                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_contains($url, 'api.deezer.com')) {
                // Deezer sucht unscharf: nur Treffer mit genau diesem Namen taugen als Vorschlag
                return Http::response(['data' => str_contains($url, 'Yanou') ? [
                    ['name' => 'Yanou', 'picture_xl' => 'https://cdn.test/yanou-xl.jpg', 'picture_medium' => 'https://cdn.test/yanou-m.jpg'],
                    ['name' => 'Yanou Fan Club', 'picture_xl' => 'https://cdn.test/fan-xl.jpg', 'picture_medium' => 'https://cdn.test/fan-m.jpg'],
                ] : []]);
            }
            if (str_contains($url, '/ws/2/artist/')) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));

                return Http::response(['id' => $id, 'name' => $id === 'mb-acdc' ? 'AC/DC' : 'Yanou', 'aliases' => [], 'genres' => [['name' => 'hard rock', 'count' => 3]], 'tags' => []]);
            }
            if (str_contains($url, '/ws/2/recording')) {
                // Liedprüfung: Yanou und AC/DC haben die Lieder, die Madonnas nicht
                return Http::response(['recordings' => [
                    ['title' => 'King of My Castle', 'artist-credit' => [['artist' => ['id' => 'mb-yanou']]]],
                    ['title' => 'Hells Bells', 'artist-credit' => [['artist' => ['id' => 'mb-acdc']]]],
                ]]);
            }
            $artists = match (true) {
                str_contains($url, 'Yanou') => [['id' => 'mb-yanou', 'name' => 'Yanou', 'score' => 100, 'type' => 'Person', 'country' => 'DE']],
                str_contains($url, 'Ac/Dc') => [['id' => 'mb-acdc', 'name' => 'AC/DC', 'score' => 100, 'type' => 'Group', 'country' => 'AU']],
                str_contains($url, 'Madonna') => [['id' => 'mb-m1', 'name' => 'Madonna', 'score' => 100], ['id' => 'mb-m2', 'name' => 'Madonna', 'score' => 100, 'disambiguation' => 'Schweizer Band']],
                default => [],
            };

            return Http::response(['artists' => $artists]);
        });
        $titles = ['Yanou' => ['King of My Castle'], 'Ac/Dc' => ['Hells Bells'], 'Madonna' => ['Like a Prayer']];
        foreach ($titles as $name => $t) {
            PrefetchArtistLookup::prefetch(app(Lookup::class), $name, $t);
        }

        $page = Livewire::test(Artists::class, ['tab' => 'quick'])
            ->assertSee('Lied „King of My Castle“ gefunden')
            ->assertSee('Schreibweise „AC/DC“ übernehmen')
            ->assertDontSee('Madonna');
        $this->assertSame(2, $page->viewData('quickCounts')['sure']);
        $this->assertSame(1, $page->viewData('quickCounts')['unsure']);
        $page->set('quickKind', 'unsure')->assertSee('2 gleichnamige Künstler')
            ->assertSee('einzeln erfassen …')->assertSeeLivewire(NewArtistModal::class); // Dialog statt Tabwechsel
        Livewire::test(NewArtistModal::class)->dispatch('open-new-artist', name: 'Madonna')->assertSet('open', true)->assertSet('wizName', 'Madonna');

        $page->set('quickKind', 'sure')->assertSee('https://cdn.test/yanou-m.jpg')->assertDontSee('https://cdn.test/fan-m.jpg');
        $this->assertFalse($page->get('quickTake')[md5('Yanou')], 'nichts vorab angehakt');
        $page->call('quickPickImage', md5('Yanou'), 'https://cdn.test/yanou-xl.jpg')
            ->assertSet('quickTake.'.md5('Yanou'), true) // Bild wählen hakt an
            ->set('quickTake.'.md5('Ac/Dc'), true)        // ohne Bild von Hand
            ->call('quickSave')->assertSee('2 Künstler übernommen')->assertSee('1 Bild(er) werden im Hintergrund geladen');

        $yanou = Artist::where('name', 'Yanou')->sole();
        $this->assertSame('mb-yanou', $yanou->mbid);
        $this->assertFileExists($this->base.'/_artists/'.$yanou->image, 'gewähltes Bild gespeichert');
        $this->assertSame('Dance / Pop-Dance', $yanou->default_genre, 'Genre aus dem Bestand zuerst (Listen-Genre)');
        $acdc = Artist::where('name', 'AC/DC')->sole();
        $this->assertSame('mb-acdc', $acdc->mbid);
        $this->assertSame($acdc->id, ArtistAlias::where('alias', 'Ac/Dc')->sole()->artist_id, 'Bestandsname bleibt Schreibweise');
        $this->assertSame('AC/DC', Track::where('title', 'Hells Bells')->value('artist'), 'Tags im Zwilling vorgemerkt');
        $this->assertSame(1, PendingChange::count());
        $this->assertFalse(Artist::where('name', 'Madonna')->exists(), 'unklar: bleibt beim Assistenten');

        // danach nicht mehr offen
        $this->assertSame(0, Livewire::test(Artists::class, ['tab' => 'quick'])->viewData('quickCounts')['sure'] ?? 0);
    }

    public function test_combined_name_can_be_split_right_in_the_list(): void
    {
        // umgedreht ist nur, wo eine Seite ein einzelnes Wort ist – sonst zwei Personen
        foreach (['Berry, Chuck', 'Beatles, The', 'Lee Hooker, John', 'Brown, James Arthur'] as $inverted) {
            $this->assertTrue(ArtistNames::isInverted($inverted), $inverted);
        }
        $this->assertFalse(ArtistNames::isInverted('Gerhard Polt, Gisela Schneeberger'));
        $this->assertTrue(ArtistNames::looksCombined('Gerhard Polt, Gisela Schneeberger'));

        $this->mp3('P/Polt/Kein Album/Der Musterbürger.mp3', ['artist' => 'Gerhard Polt, Gisela Schneeberger', 'albumartist' => '', 'album' => '', 'title' => 'Der Musterbürger']);
        app(Scanner::class)->scan('library');

        Livewire::test(Artists::class, ['tab' => 'quick'])->set('quickKind', 'pending')
            ->assertSee('Trennen: Gerhard Polt · Gisela Schneeberger')
            ->call('quickSplit', 'Gerhard Polt, Gisela Schneeberger')
            ->assertSee('getrennt')
            ->assertSee('Gerhard Polt')->assertDontSee('Trennen: Gerhard Polt');

        $this->assertSame(['Gerhard Polt', 'Gisela Schneeberger'], Track::sole()->artists, 'ARTISTS im Zwilling vorgemerkt');
        $this->assertSame(1, PendingChange::count());
    }

    public function test_typographic_punctuation_from_musicbrainz_becomes_plain(): void
    {
        $this->assertSame('Lexy & K-Paul', ArtistNames::plainPunctuation("Lexy & K\u{2010}Paul"));
        $this->assertSame("Sluts'n'Strings & 909", ArtistNames::plainPunctuation("Sluts\u{2019}n\u{2019}Strings & 909"));

        // vorhandene Einträge: Migration benennt um, alter Name bleibt Schreibweise, die normale Schreibweise als Alias fällt weg
        $artist = Artist::create(['name' => "Lexy & K\u{2010}Paul"]);
        $artist->aliases()->create(['alias' => 'Lexy & K-Paul', 'source' => 'musicbrainz']);
        (require database_path('migrations/2026_11_09_000100_plain_punctuation_in_artist_names.php'))->up();

        $artist->refresh();
        $this->assertSame('Lexy & K-Paul', $artist->name);
        $this->assertSame(["Lexy & K\u{2010}Paul"], $artist->aliases()->pluck('alias')->all());
    }
}
