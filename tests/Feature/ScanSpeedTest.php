<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Track;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_rescan_without_changes_writes_nothing(): void
    {
        foreach (range(1, 3) as $album) {
            foreach (range(1, 4) as $n) {
                $path = $this->base."/library/artists/A/ABBA/Album $album/0$n - T.mp3";
                File::ensureDirectoryExists(dirname($path));
                file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 30));
                app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'ABBA', 'album' => "Album $album", 'track' => (string) $n, 'title' => "T$n"]]]);
            }
        }
        app(Scanner::class)->scan('library');
        $before = Track::orderBy('id')->get(['id', 'album_id', 'updated_at'])->map(fn ($t) => $t->id.'/'.$t->album_id.'/'.$t->updated_at)->all();
        $albums = Album::orderBy('id')->get(['id', 'updated_at'])->map(fn ($a) => $a->id.'/'.$a->updated_at)->all();
        $this->assertSame(0, Track::whereNull('album_id')->count());

        Carbon::setTestNow(now()->addMinutes(5));
        app(Scanner::class)->scan('library');

        $this->assertSame($before, Track::orderBy('id')->get(['id', 'album_id', 'updated_at'])->map(fn ($t) => $t->id.'/'.$t->album_id.'/'.$t->updated_at)->all(), 'Titel unverändert');
        $this->assertSame($albums, Album::orderBy('id')->get(['id', 'updated_at'])->map(fn ($a) => $a->id.'/'.$a->updated_at)->all(), 'Alben unverändert');
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        $path = $this->base.'/cover.jpg';
        imagejpeg($img, $path);

        return $path;
    }
}
