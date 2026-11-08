<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Schneller einlesen: jede Datei nur einmal öffnen (Technik vom Tag-Tool), mehrere Leseprozesse gleichzeitig. */
class ScanSpeedTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/speed-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base);
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_parallel_read_returns_every_file_with_technical_info(): void
    {
        config(['musicwiz.scan_workers' => 4]);
        $paths = [];
        foreach (range(1, 25) as $n) {
            $path = $this->base.sprintf('/library/artists/A/ABBA/Gold/%02d - T.%s', $n, $n % 2 ? 'mp3' : 'flac');
            File::ensureDirectoryExists(dirname($path));
            Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', ...($n % 2 ? ['-b:a', '128k'] : []), $path])->throw();
            $paths[] = $path;
        }
        app(TagTool::class)->write([['path' => $paths[0], 'tags' => ['title' => 'Eins'], 'cover' => $this->jpeg(500, 400)]]);

        $results = app(TagTool::class)->read($paths);
        $this->assertEqualsCanonicalizing($paths, array_keys($results), 'jede Datei genau einmal, egal aus welchem Prozess');
        foreach ($results as $path => $r) {
            $this->assertTrue($r['ok'], $path);
            $this->assertEqualsWithDelta(2.0, $r['info']['duration'], 0.1, $path);
            $this->assertGreaterThan(0, $r['info']['bitrate'], $path);
        }
        $this->assertSame(['duration' => $results[$paths[0]]['info']['duration'], 'bitrate' => $results[$paths[0]]['info']['bitrate'], 'cover_embedded' => true, 'cover_size' => 400], $results[$paths[0]]['info']);
        $this->assertSame('Eins', $results[$paths[0]]['tags']['title']);

        app(Scanner::class)->scan('library');
        $this->assertSame(25, Track::count());
        $first = Track::where('path', $paths[0])->first();
        $this->assertTrue($first->cover_embedded);
        $this->assertSame(400, $first->cover_size);
        $this->assertSame('mp3', $first->format);
        $this->assertEqualsWithDelta(128000, $first->bitrate, 8000);
        $this->assertSame(0, Track::whereNull('duration')->count());
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        $path = $this->base.'/cover.jpg';
        imagejpeg($img, $path);

        return $path;
    }
}
