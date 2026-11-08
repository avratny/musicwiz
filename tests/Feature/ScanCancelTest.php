<?php

namespace Tests\Feature;

use App\Jobs\ScanJob;
use App\Livewire\Inbox;
use App\Livewire\Library;
use App\Models\Track;
use App\Support\AutoCleanup;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** Scan abbrechen: Gelesenes bleibt (mit Alben), nichts wird entfernt, der nächste Scan macht weiter. */
class ScanCancelTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/cancel-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $mp3 = str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 30);
        foreach (range(1, 90) as $n) {
            $path = $this->base.sprintf('/library/artists/A/ABBA/Album %02d/01 - T.mp3', intdiv($n, 10));
            File::ensureDirectoryExists(dirname($path));
            file_put_contents(str_replace('01 - T', sprintf('%02d - T', $n), $path), $mp3);
        }
        // bekannte Datei, die es nicht mehr gibt – darf bei einem Abbruch nicht entfernt werden
        Track::create(['location' => 'library', 'path' => $this->base.'/library/artists/W/Weg/x.mp3', 'dir' => $this->base.'/library/artists/W/Weg', 'filename' => 'x.mp3', 'ext' => 'mp3', 'duration' => 100]);
    }

    protected function tearDown(): void
    {
        Scanner::clearCancel();
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_cancel_while_reading_keeps_what_was_read(): void
    {
        // nach dem ersten Lesepaket abbrechen (wie ein Klick auf „Abbrechen“ mitten im Scan)
        $this->app->extend(TagReader::class, fn ($reader) => new class($reader) extends TagReader
        {
            public function __construct(private TagReader $inner) {}

            public function readMany(array $paths): array
            {
                $out = $this->inner->readMany($paths);
                app(Scanner::class)->requestCancel();

                return $out;
            }
        });
        app(Scanner::class)->status(['running' => true]);

        $result = app(Scanner::class)->scan('library');

        $this->assertTrue($result['cancelled']);
        $this->assertSame(80, Track::where('path', 'like', $this->base.'/library/artists/A/%')->count(), 'erstes Paket gespeichert');
        $this->assertSame(0, Track::where('path', 'like', $this->base.'/library/artists/A/%')->whereNull('album_id')->count(), 'mit Alben');
        $this->assertTrue(Track::where('filename', 'x.mp3')->exists(), 'nichts entfernt');
        $status = app(Scanner::class)->status();
        $this->assertFalse($status['running']);
        $this->assertSame(['read' => 80, 'total' => 90], $status['cancelled']);
        $this->assertFalse(app(Scanner::class)->cancelRequested());

        // nächster Scan macht weiter: liest nur den Rest
        $this->app->forgetExtenders(TagReader::class);
        $this->app->forgetInstance(TagReader::class);
        $this->app->forgetInstance(Scanner::class);
        $next = app(Scanner::class)->scan('library');
        $this->assertSame(10, $next['read']);
        $this->assertSame(1, $next['removed'], 'vollständiger Scan entfernt die fehlende Datei');
        $this->assertNull(app(Scanner::class)->status()['cancelled']);
    }

    public function test_unreadable_folder_reports_reason(): void
    {
        $locked = $this->base.'/library/artists/G/Gesperrt';
        File::ensureDirectoryExists($locked.'/Album');
        chmod($locked, 0000);
        clearstatcache();
        if (@scandir($locked) !== false) {
            chmod($locked, 0755);
            $this->markTestSkipped('Dateisystem bzw. Benutzer setzt Rechte nicht durch (z. B. root).');
        }
        try {
            $this->assertSame(0, app(Scanner::class)->scan('library')['cancelled'] ?? 0);
            $status = app(Scanner::class)->status();
            $this->assertSame(1, $status['unreadable_count']);
            $this->assertStringStartsWith('keine Berechtigung (gehört ', $status['unreadable_reasons'][$locked]);
            $this->assertStringContainsString('Rechte 000', $status['unreadable_reasons'][$locked]);
            $this->assertSame(['keine Berechtigung' => 1], $status['unreadable_summary']);
            Livewire::test(Library::class)->assertSee('1 Ordner nicht lesbar (1× keine Berechtigung)')->assertSee('Rechte 000');
        } finally {
            chmod($locked, 0755);
        }
    }

    public function test_reason_texts(): void
    {
        $this->assertSame('nicht (mehr) vorhanden', Scanner::unreadableReason('/gibt/es/nicht', 'scandir(/gibt/es/nicht): Failed to open directory: No such file or directory'));
        $this->assertSame('Lesefehler (Share bzw. Netzwerk)', Scanner::unreadableReason('/x', 'scandir(/x): Failed to open directory: Input/output error'));
        $this->assertSame('unbekannter Fehler', Scanner::unreadableReason('/x', null));
    }

    public function test_cancel_while_walking_changes_nothing(): void
    {
        app(Scanner::class)->status(['running' => true]);
        app(Scanner::class)->requestCancel();

        $this->assertTrue(app(Scanner::class)->scan('library')['cancelled']);
        $this->assertSame(1, Track::count());
    }

    public function test_cancel_before_queued_scan_starts_and_buttons(): void
    {
        Livewire::test(Inbox::class)->assertDontSee('Abbrechen');
        app(Scanner::class)->status(['running' => true, 'location' => 'inbox', 'phase' => 'wartet']);
        Livewire::test(Inbox::class)->assertSee('Abbrechen')->call('cancelScan');
        $this->assertTrue(app(Scanner::class)->cancelRequested());

        (new ScanJob('inbox'))->handle(app(Scanner::class), app(AutoCleanup::class));
        $this->assertSame('abgebrochen', app(Scanner::class)->status()['phase']);
        $this->assertSame(1, Track::count(), 'gar nicht erst gescannt');
    }
}
