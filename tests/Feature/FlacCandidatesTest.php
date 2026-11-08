<?php

namespace Tests\Feature;

use App\Livewire\Cleanup;
use App\Livewire\FlacCandidatesPage;
use App\Models\Album;
use App\Models\PurchaseMark;
use App\Models\Track;
use App\Support\FlacCandidates;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/** FLAC-Kandidaten: nur verlustbehaftete Lib-Alben, Qobuz-Suchlink, Merkliste/Ausblenden über Interpret + Album. */
class FlacCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/flac-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $this->file('library/artists/A/ABBA/Gold (1992)/01 - SOS.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'genres' => ['Pop'], 'comment' => 'LIB']);
        $this->file('library/artists/A/ABBA/Arrival (1976)/01 - Dancing Queen.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Arrival', 'year' => '1976', 'track' => '1', 'comment' => 'LIB']);
        $this->file('library/artists/Y/Yanou/Sunrise (2006)/01 - King.flac', ['artist' => 'Yanou', 'albumartist' => 'Yanou', 'album' => 'Sunrise', 'year' => '2006', 'track' => '1', 'comment' => 'LIB'], 'flac');
        $this->file('library/sampler/B/Bravo Hits 39 (2002)/01 - X - Y.mp3', ['artist' => 'X', 'albumartist' => 'Various Artists', 'album' => 'Bravo Hits 39', 'year' => '2002', 'track' => '1', 'compilation' => true, 'comment' => 'LIB COMPILATION']);
        app(Scanner::class)->scan('library');
        Track::where('album', 'Gold')->update(['rating' => 5]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function file(string $rel, array $tags, string $ext = 'mp3'): void
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        $ext === 'flac'
            ? Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '1', $path])->throw()
            : file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags + ['title' => pathinfo($rel, PATHINFO_FILENAME)]]]);
    }

    public function test_lists_lossy_albums_with_qobuz_link(): void
    {
        $this->assertEqualsCanonicalizing(['Gold', 'Arrival', 'Bravo Hits 39'], Album::whereIn('id', FlacCandidates::albumIds())->pluck('title')->all());

        $gold = Album::where('title', 'Gold')->first();
        $this->assertSame('https://www.qobuz.com/de-de/search/albums/ABBA%20Gold', app(FlacCandidates::class)->qobuzUrl($gold));
        $this->assertSame('https://www.qobuz.com/de-de/search/albums/ABBA', app(FlacCandidates::class)->qobuzArtistUrl($gold));
        $bravo = Album::where('title', 'Bravo Hits 39')->first();
        $this->assertSame('https://www.qobuz.com/de-de/search/albums/Bravo%20Hits%2039', app(FlacCandidates::class)->qobuzUrl($bravo));
        $this->assertNull(app(FlacCandidates::class)->qobuzArtistUrl($bravo), 'Sampler: keine Interpreten-Suche');

        Livewire::test(FlacCandidatesPage::class)
            ->assertSeeInOrder(['Gold', 'Arrival']) // beste Bewertung zuerst
            ->assertDontSee('Sunrise')->assertDontSee('Bravo Hits 39') // FLAC bzw. Sampler (ohne Schalter)
            ->set('samplers', true)->assertSee('Bravo Hits 39')
            ->set('minRating', 5)->assertSee('Gold')->assertDontSee('Arrival');
        $this->get(route('flac-candidates'))->assertOk()->assertSee('Komplett ↗', false)->assertSee('Interpret ↗', false);
    }

    public function test_incomplete_albums_tab_any_format(): void
    {
        // Sunrise (FLAC) mit Lücke: Titel 1 und 3 von 3
        $this->file('library/artists/Y/Yanou/Sunrise (2006)/03 - Sunrise.flac', ['artist' => 'Yanou', 'albumartist' => 'Yanou', 'album' => 'Sunrise', 'year' => '2006', 'track' => '3', 'comment' => 'LIB'], 'flac');
        app(Scanner::class)->scan('library');

        Livewire::test(FlacCandidatesPage::class)
            ->assertDontSee('Sunrise') // FLAC: kein MP3-Kandidat
            ->set('show', 'incomplete')
            ->assertSee('Sunrise')->assertSee('unvollständig: 2 von 3 – es fehlen 2')
            ->assertSee('https://www.qobuz.com/de-de/search/albums/Yanou%20Sunrise', false)
            ->assertDontSee('Arrival');
        Livewire::test(Cleanup::class)->set('check', 'incomplete')->assertSee('Komplett nachkaufen');
    }

    public function test_watch_and_hide_survive_moving(): void
    {
        $arrival = Album::where('title', 'Arrival')->first();
        Livewire::test(FlacCandidatesPage::class)
            ->call('mark', $arrival->id, 'hidden')->assertDontSee('Arrival')
            ->call('mark', Album::where('title', 'Gold')->value('id'), 'watch')
            ->set('show', 'watch')->assertSee('Gold')->assertDontSee('Arrival')
            ->set('show', 'hidden')->assertSee('Arrival')
            ->call('unmark', $arrival->id)->assertDontSee('Arrival');
        $this->assertSame(['abba|gold' => 'watch'], PurchaseMark::pluck('state', 'item_key')->all());

        // anderer Ordner (z. B. nach dem Verschieben): gleicher Interpret + Album → gleicher Schlüssel
        $gold = Album::where('title', 'Gold')->first();
        $gold->dir = $this->base.'/library/artists/A/ABBA/Gold';
        $this->assertSame('abba|gold', FlacCandidates::key($gold));
    }
}
