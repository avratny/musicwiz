<?php

namespace Tests\Feature;

use App\Jobs\RunAutoCleanup;
use App\Jobs\ScanJob;
use App\Models\Album;
use App\Models\Track;
use App\Support\AutoCleanup;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** Ein Scan darf den Index nicht leeren, nur weil das Share kurz weg oder ein Ordner nicht lesbar ist. */
class ScanSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/scan-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base.'/inbox');
        app(Settings::class)->set([
            'base' => $this->base,
            'inbox' => $this->base.'/inbox',
            'trash' => $this->base.'/_papierkorb',
            'scan_throttle_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        @chmod($this->base.'/inbox/Locked', 0775);
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function files(string $dir, int $count): void
    {
        File::ensureDirectoryExists($this->base.'/inbox/'.$dir);
        for ($i = 1; $i <= $count; $i++) {
            file_put_contents($this->base."/inbox/{$dir}/{$i}.mp3", str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 4));
        }
    }

    public function test_missing_root_aborts_without_touching_index(): void
    {
        $this->files('Album', 3);
        app(Scanner::class)->scan('inbox');
        $this->assertSame(3, Track::count());

        File::deleteDirectory($this->base.'/inbox');
        try {
            app(Scanner::class)->scan('inbox');
            $this->fail('Scan hätte abbrechen müssen');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nicht erreichbar', $e->getMessage());
        }
        $this->assertSame(3, Track::count());
    }

    public function test_unreadable_folder_keeps_its_tracks(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root kann jeden Ordner lesen');
        }
        $this->files('Open', 2);
        $this->files('Locked', 2);
        app(Scanner::class)->scan('inbox');
        $this->assertSame(4, Track::count());

        chmod($this->base.'/inbox/Locked', 0000);
        $result = app(Scanner::class)->scan('inbox');

        $this->assertSame(4, Track::count());
        $this->assertSame(1, $result['unreadable']);
        $this->assertSame([$this->base.'/inbox/Locked'], app(Scanner::class)->status()['unreadable']);
    }

    public function test_mass_removal_is_held_until_confirmed(): void
    {
        $this->files('Big', 60);
        $this->files('Small', 2);
        app(Scanner::class)->scan('inbox');
        $this->assertSame(62, Track::count());

        File::deleteDirectory($this->base.'/inbox/Big');
        $result = app(Scanner::class)->scan('inbox');
        $this->assertSame(0, $result['removed']);
        $this->assertSame(60, $result['held']);
        $this->assertSame(62, Track::count());
        $this->assertSame(60, app(Scanner::class)->status()['held']['count']);

        $result = app(Scanner::class)->scan('inbox', allowMassRemoval: true);
        $this->assertSame(60, $result['removed']);
        $this->assertSame(2, Track::count());
        $this->assertNull(app(Scanner::class)->status()['held']);
    }

    public function test_few_missing_files_are_removed_directly(): void
    {
        $this->files('A', 3);
        $this->files('B', 3);
        app(Scanner::class)->scan('inbox');
        File::deleteDirectory($this->base.'/inbox/B');

        $this->assertSame(3, app(Scanner::class)->scan('inbox')['removed']);
        $this->assertSame(3, Track::count());
    }

    public function test_rebuild_albums_with_many_folders(): void
    {
        // früher: "Expression tree is too large" ab ca. 600 Ordnern (nach großen Aktionen)
        $this->files('Real', 2);
        app(Scanner::class)->scan('inbox');
        $dirs = array_map(fn ($i) => $this->base.'/library/artists/X/Artist '.$i.'/Album', range(1, 1500));
        $dirs[] = $this->base.'/inbox/Real';

        app(Scanner::class)->rebuildAlbums(null, $dirs);

        $this->assertSame(1, Album::count());
        $this->assertSame(2, Album::first()->track_count);
    }

    public function test_automations_are_skipped_after_incomplete_scan(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root kann jeden Ordner lesen');
        }
        app(Settings::class)->set(['auto_cleanup' => ['leftovers' => ['LIB' => true]]]);
        $locked = $this->base.'/library/artists/L/Locked';
        foreach (['library/artists/O/Open', 'library/artists/L/Locked'] as $dir) {
            File::ensureDirectoryExists($this->base.'/'.$dir);
            file_put_contents($this->base.'/'.$dir.'/1.mp3', str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 4));
        }
        Bus::fake([RunAutoCleanup::class]);

        (new ScanJob('library'))->handle(app(Scanner::class), app(AutoCleanup::class));
        Bus::assertDispatchedTimes(RunAutoCleanup::class, 1);

        chmod($locked, 0000);
        try {
            (new ScanJob('library'))->handle(app(Scanner::class), app(AutoCleanup::class));
        } finally {
            chmod($locked, 0775);
        }
        Bus::assertDispatchedTimes(RunAutoCleanup::class, 1);
        $this->assertStringContainsString('nicht ausgeführt', app(Scanner::class)->status()['auto_skipped']);
    }
}
