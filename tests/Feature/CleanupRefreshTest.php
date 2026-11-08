<?php

namespace Tests\Feature;

use App\Jobs\RefreshCleanup;
use App\Livewire\Cleanup;
use App\Models\Operation;
use App\Models\Track;
use App\Support\CleanupChecks;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Aufräumen rechnet nie alle Prüfungen im Seitenaufruf: veraltet → letzten Stand zeigen, Beheben sperren, im Hintergrund neu. */
class CleanupRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_check_is_refreshed_first_and_unlocked_early(): void
    {
        Track::create(['location' => 'library', 'path' => '/m/a.Mp3', 'dir' => '/m', 'filename' => 'a.Mp3', 'ext' => 'mp3', 'duration' => 100]);
        app(CleanupChecks::class)->refreshAll();
        Queue::fake();
        Carbon::setTestNow(now()->addMinute());
        Track::query()->update(['title' => 'Neu']);

        // Seite mit „Uneinheitliche Endungen“ offen → diese Prüfung kommt zuerst dran
        Livewire::test(Cleanup::class)->set('check', 'extension')->assertSee('Diese Prüfung wird gerade neu berechnet');
        $this->assertSame('partial', app()->make(CleanupChecks::class)->refreshAll(0)); // ein Häppchen = eine Prüfung
        Livewire::test(Cleanup::class)->set('check', 'extension')
            ->assertSee('Diese Prüfung ist aktuell – beheben geht')
            ->assertSee('a.Mp3');
        [, $staleOther] = app()->make(CleanupChecks::class)->displayFindings('broken');
        $this->assertTrue($staleOther, 'andere Prüfungen kommen danach');

        // Datenstand ändert sich unterwegs → neu ansetzen
        Carbon::setTestNow(now()->addMinute());
        $checks = app()->make(CleanupChecks::class);
        $checks->version();
        Track::query()->update(['title' => 'Neuer']);
        $this->assertSame('changed', $checks->refreshAll());

        // Job: in Häppchen, bis alles frisch ist
        do {
            Queue::fake();
            (new RefreshCleanup)->handle();
        } while (Queue::pushed(RefreshCleanup::class)->isNotEmpty());
        $this->assertTrue(app()->make(CleanupChecks::class)->isFresh());
        $this->assertFalse(RefreshCleanup::running());
    }

    public function test_preview_of_thousands_of_findings_keeps_page_state_small(): void
    {
        $now = now();
        $rows = [];
        foreach (range(1, 3000) as $i) {
            $rows[] = ['location' => 'library', 'path' => "/m/a$i/t$i.Mp3", 'dir' => "/m/a$i", 'filename' => "t$i.Mp3", 'ext' => 'mp3', 'duration' => 100,
                'artists' => '[]', 'albumartists' => '[]', 'genres' => '[]', 'created_at' => $now, 'updated_at' => $now];
            if (count($rows) === 500) {
                DB::table('tracks')->insert($rows);
                $rows = [];
            }
        }
        app(CleanupChecks::class)->refreshAll();

        $page = Livewire::test(Cleanup::class)->set('check', 'extension')->call('previewFix', true)
            ->assertSee('3000 Funde, 3000 Schritte')->assertSee('… und 2700 weitere');
        $size = strlen(json_encode($page->snapshot));
        $this->assertLessThan(200 * 1024, $size, 'Seitenzustand '.round($size / 1024).' KB');

        Queue::fake();
        $page->call('confirm');
        $this->assertCount(3000, Operation::latest('id')->first()->payload['actions'], 'alle Aktionen aus dem Zwischenspeicher');
    }

    public function test_without_any_state_the_page_computes_nothing(): void
    {
        Queue::fake(); // Worker ist beschäftigt (z. B. großer Scan)
        Track::create(['location' => 'library', 'path' => '/m/a.Mp3', 'dir' => '/m', 'filename' => 'a.Mp3', 'ext' => 'mp3', 'duration' => 100]);

        Livewire::test(Cleanup::class)->set('check', 'extension')
            ->assertSee('Funde werden im Hintergrund berechnet')
            ->assertSee('Wird berechnet')
            ->assertDontSee('Keine Funde');
        $this->assertFalse(Cache::has('cleanup.counts.'.app(CleanupChecks::class)->version()), 'im Seitenaufruf nichts berechnet');
        Queue::assertPushed(RefreshCleanup::class);

        // Worker noch nicht dran: Wartehinweis; dann läuft er: Prüfung x von y
        Livewire::test(Cleanup::class)->assertSee('Wartet auf den Hintergrund-Worker');
        Cache::put(CleanupChecks::PROGRESS, ['done' => 2, 'total' => 33, 'current' => 'Mögliche Duplikate', 'started_at' => now()->subMinute()->toIso8601String()]);
        Livewire::test(Cleanup::class)->assertSee('Prüfung 3 von 33: Mögliche Duplikate')->assertSee('läuft seit 1 Minute');

        (new RefreshCleanup)->handle();
        $this->assertNull(CleanupChecks::progress(), 'nach dem Lauf aufgeräumt');
        Livewire::test(Cleanup::class)->set('check', 'extension')->assertSee('a.Mp3');
    }

    public function test_stale_state_is_shown_locked_and_refreshed_in_background(): void
    {
        $base = storage_path('framework/testing/refresh-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $path = $base.'/library/artists/A/ABBA/Gold/01 - SOS.Mp3';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'track' => '1', 'title' => 'SOS', 'comment' => 'LIB']]]);
        app(Scanner::class)->scan('library');

        // frischer Stand (synchrone Warteschlange rechnet sofort)
        Livewire::test(Cleanup::class)->set('check', 'extension')
            ->assertDontSee('Funde werden im Hintergrund aktualisiert')->assertSee('SOS');
        $this->assertTrue(app(CleanupChecks::class)->isFresh());

        // eine Bewertung ändert nichts an den Funden: bleibt frisch
        Carbon::setTestNow(now()->addMinute());
        Track::query()->update(['rating' => 4]);
        $this->assertTrue(app(CleanupChecks::class)->isFresh(), 'Bewertung zählt nicht als neuer Stand');

        // Datenstand ändert sich, Hintergrund-Job läuft noch nicht
        Queue::fake();
        Carbon::setTestNow(now()->addMinute());
        Track::query()->update(['title' => 'Neu']);
        $this->assertFalse(app(CleanupChecks::class)->isFresh());

        $page = Livewire::test(Cleanup::class)->set('check', 'extension')
            ->assertSee('Diese Prüfung wird gerade neu berechnet')
            ->assertSee('SOS') // letzter Stand bleibt sichtbar
            ->assertSee('disabled', false);
        $page->set('check', 'misplaced'); // weitere Aufrufe: kein zweiter Job
        Queue::assertPushedTimes(RefreshCleanup::class, 1);
        $this->assertSame(1, app(CleanupChecks::class)->displayCounts()['extension']);

        (new RefreshCleanup)->handle();
        $this->assertTrue(app(CleanupChecks::class)->isFresh());
        Livewire::test(Cleanup::class)->set('check', 'extension')->assertDontSee('wird gerade neu berechnet')->assertDontSee('übrigen Prüfungen');
        File::deleteDirectory($base);
    }
}
