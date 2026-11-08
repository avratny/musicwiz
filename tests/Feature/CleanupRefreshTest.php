<?php

namespace Tests\Feature;

use App\Jobs\RefreshCleanup;
use App\Livewire\Cleanup;
use App\Models\Track;
use App\Support\CleanupChecks;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Aufräumen rechnet nie alle Prüfungen im Seitenaufruf: veraltet → letzten Stand zeigen, Beheben sperren, im Hintergrund neu. */
class CleanupRefreshTest extends TestCase
{
    use RefreshDatabase;

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

        // Datenstand ändert sich, Hintergrund-Job läuft noch nicht
        Queue::fake();
        Carbon::setTestNow(now()->addMinute());
        Track::query()->update(['rating' => 4]);
        $this->assertFalse(app(CleanupChecks::class)->isFresh());

        $page = Livewire::test(Cleanup::class)->set('check', 'extension')
            ->assertSee('Funde werden im Hintergrund aktualisiert')
            ->assertSee('SOS') // letzter Stand bleibt sichtbar
            ->assertSee('disabled', false);
        $page->set('check', 'misplaced'); // weitere Aufrufe: kein zweiter Job
        Queue::assertPushedTimes(RefreshCleanup::class, 1);
        $this->assertSame(1, app(CleanupChecks::class)->displayCounts()['extension']);

        (new RefreshCleanup)->handle();
        $this->assertTrue(app(CleanupChecks::class)->isFresh());
        Livewire::test(Cleanup::class)->set('check', 'extension')->assertDontSee('Funde werden im Hintergrund aktualisiert');
        File::deleteDirectory($base);
    }
}
