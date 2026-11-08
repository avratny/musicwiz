<?php

namespace Tests\Feature;

use App\Jobs\PrefetchArtistLookup;
use App\Jobs\PrefetchUnknownArtists;
use App\Jobs\ScanJob;
use App\Livewire\Artists;
use App\Models\Artist;
use App\Models\Track;
use App\Support\AutoCleanup;
use App\Support\Lookup;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Nachschlagen bei MusicBrainz & Co.: 10 Tage Zwischenspeicher, „Aktuelle Daten laden“, Vorladen im Hintergrund. */
class LookupPrefetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicwiz.mb_pause' => 0]);
        Http::fake(fn ($request) => str_contains($request->url(), '/ws/2/artist')
            ? Http::response(['artists' => [['id' => 'mb-'.md5($request->url()), 'name' => 'Taucher', 'score' => 100]]])
            : Http::response(['data' => [], 'recordings' => []]));
    }

    private function track(string $location, string $artist, string $title): void
    {
        static $i = 0;
        $i++;
        Track::create(['location' => $location, 'path' => "/m/$location/$i.mp3", 'dir' => "/m/$location", 'filename' => "$i.mp3", 'ext' => 'mp3',
            'artist' => $artist, 'title' => $title, 'album' => 'A', 'artists' => [], 'albumartists' => []]);
    }

    private function mbRequests(): int
    {
        return collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), 'musicbrainz.org'))->count();
    }

    public function test_answers_are_kept_ten_days_unless_fresh_data_is_requested(): void
    {
        $lookup = app(Lookup::class);
        $lookup->musicbrainzArtists('Taucher');
        $lookup->musicbrainzArtists('Taucher');
        $this->assertSame(1, $this->mbRequests(), 'zweites Mal aus dem Zwischenspeicher');

        $this->travel(9)->days();
        $lookup->musicbrainzArtists('Taucher');
        $this->assertSame(1, $this->mbRequests(), 'nach 9 Tagen noch gespeichert');

        $lookup->fresh = true;
        $lookup->musicbrainzArtists('Taucher');
        $this->assertSame(2, $this->mbRequests(), 'aktuelle Daten auf Wunsch');

        $this->travel(11)->days();
        $lookup->fresh = false;
        $lookup->musicbrainzArtists('Taucher');
        $this->assertSame(3, $this->mbRequests(), 'nach 10 Tagen abgelaufen');
    }

    public function test_wizard_reload_bypasses_the_cache(): void
    {
        $this->track('library', 'Taucher', 'Bizarre');
        $page = Livewire::test(Artists::class, ['tab' => 'unknown'])->call('wizLookup')->call('wizLookupDetails');
        $before = $this->mbRequests();

        $page->call('wizLookup'); // schon geladen: nichts Neues
        $this->assertSame($before, $this->mbRequests());

        $page->assertSee('Aktuelle Daten laden')->call('wizRefresh')->assertSet('wizFresh', true)->call('wizLookupDetails')->assertSet('wizFresh', false);
        $this->assertGreaterThan($before, $this->mbRequests());
    }

    public function test_unknown_artists_are_prefetched_in_chunks_inbox_first(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        Artist::create(['name' => 'Bekannt']);
        $this->track('library', 'Bekannt', 'X');
        foreach (range(1, 6) as $n) {
            $this->track('library', "Lib $n", "Lied $n");
        }
        $this->track('library', 'Lib 1', 'Noch eins');
        $this->track('inbox', 'Neu aus der Inbox', 'Song');

        $names = array_column(PrefetchUnknownArtists::unknownNames(), 'name');
        $this->assertSame('Neu aus der Inbox', $names[0], 'Inbox zuerst');
        $this->assertSame('Lib 1', $names[1], 'dann Lib nach Häufigkeit');
        $this->assertNotContains('Bekannt', $names);

        $this->assertTrue(PrefetchUnknownArtists::start());
        $this->assertFalse(PrefetchUnknownArtists::start(), 'nicht doppelt');
        $this->assertSame(7, PrefetchUnknownArtists::status()['total']);
        $this->assertSame([1, 6], [PrefetchUnknownArtists::status()['inbox'], PrefetchUnknownArtists::status()['library']]);
        Livewire::test(Artists::class, ['tab' => 'unknown'])->assertSeeText('6 Namen noch nicht im Verzeichnis')->assertSeeText('1 aus der Inbox, 6 aus der Lib');
        Queue::assertPushedOn('research', PrefetchUnknownArtists::class);

        // erstes Häppchen: 5 Namen, dann reicht sich der Job weiter
        (new PrefetchUnknownArtists)->handle(app(Lookup::class));
        $this->assertSame(5, PrefetchUnknownArtists::status()['done']);
        $this->assertTrue(PrefetchUnknownArtists::status()['running']);
        Queue::assertPushed(PrefetchUnknownArtists::class, 2);
        $this->assertTrue(PrefetchArtistLookup::isFresh('Neu aus der Inbox'));

        (new PrefetchUnknownArtists)->handle(app(Lookup::class));
        $this->assertSame(7, PrefetchUnknownArtists::status()['done']);
        $this->assertFalse(PrefetchUnknownArtists::status()['running']);

        // alles vorgeladen: nichts mehr zu tun; Seite zeigt den Knopf
        $this->assertFalse(PrefetchUnknownArtists::start());
        Livewire::test(Artists::class, ['tab' => 'unknown'])->assertSee('Alle im Hintergrund vorladen');
    }

    public function test_scan_starts_prefetching(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $base = storage_path('framework/testing/pf-'.bin2hex(random_bytes(4)));
        @mkdir($base.'/inbox/X', 0775, true);
        file_put_contents($base.'/inbox/X/1.mp3', str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 4));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'scan_throttle_ms' => 0]);
        app(TagTool::class)->write([['path' => $base.'/inbox/X/1.mp3', 'tags' => ['artist' => 'Neue Band', 'title' => 'Lied']]]);

        (new ScanJob('inbox'))->handle(app(Scanner::class), app(AutoCleanup::class));

        Queue::assertPushed(PrefetchUnknownArtists::class);
        File::deleteDirectory($base);
    }
}
