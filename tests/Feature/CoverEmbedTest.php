<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Support\AlbumPlanner;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Vorhandenes Cover in Tracks einbetten, denen es fehlt (beim Einordnen und als Aufräum-Prüfung). */
class CoverEmbedTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $jpg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/cover-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base.'/inbox/Yanou/Sunrise');
        app(Settings::class)->set([
            'base' => $this->base,
            'inbox' => $this->base.'/inbox',
            'trash' => $this->base.'/_papierkorb',
            'artist_images' => $this->base.'/_artists',
            'scan_throttle_ms' => 0,
        ]);
        $img = imagecreatetruecolor(40, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        $this->jpg = $this->base.'/c.jpg';
        imagejpeg($img, $this->jpg);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** @return list<string> */
    private function album(string $dir, string $artist, string $album, int $count): array
    {
        File::ensureDirectoryExists($dir);
        $paths = [];
        for ($nr = 1; $nr <= $count; $nr++) {
            $path = $dir."/0{$nr} {$artist} - Lied {$nr}.mp3";
            file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40));
            app(TagTool::class)->write([['path' => $path, 'tags' => [
                'title' => 'Lied '.$nr, 'artist' => $artist, 'album' => $album, 'year' => '2006', 'track' => (string) $nr,
            ]]]);
            $paths[] = $path;
        }

        return $paths;
    }

    private function hasCover(string $path): bool
    {
        return app(TagTool::class)->extractCover($path, $this->base.'/probe.jpg');
    }

    public function test_filing_embeds_cover_from_other_track_and_undo_removes_it(): void
    {
        $paths = $this->album($this->base.'/inbox/Yanou/Sunrise', 'Yanou', 'Sunrise', 3);
        app(TagTool::class)->write([['path' => $paths[0], 'tags' => [], 'cover' => $this->jpg]]);
        app(Scanner::class)->scan('inbox');
        $album = Album::firstOrFail();

        $planner = app(AlbumPlanner::class);
        $plan = $planner->plan($album, ['category' => 'LIB'], [], true);
        $this->assertSame(2, count($plan['embed_cover']['tracks']));
        $this->assertSame($paths[0], $plan['embed_cover']['from']);

        $op = OperationRunner::dispatch('album', 'Einordnen', $planner->actions($plan));
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $dir = $this->base.'/library/artists/Y/Yanou/Sunrise (2006)';
        foreach (['01 - Lied 1.mp3', '02 - Lied 2.mp3', '03 - Lied 3.mp3'] as $f) {
            $this->assertTrue($this->hasCover($dir.'/'.$f), $f);
        }
        $this->assertSame(0, Album::where('location', 'library')->first()->tracks()->where('cover_embedded', false)->count());

        OperationRunner::dispatchUndo($op);
        $this->assertTrue($this->hasCover($paths[0]));
        $this->assertFalse($this->hasCover($paths[1]));
        $this->assertFalse($this->hasCover($paths[2]));
    }

    public function test_cover_file_is_embedded_when_no_track_has_one(): void
    {
        $paths = $this->album($this->base.'/inbox/Yanou/Sunrise', 'Yanou', 'Sunrise', 2);
        copy($this->jpg, $this->base.'/inbox/Yanou/Sunrise/Folder.jpg');
        app(Scanner::class)->scan('inbox');

        $planner = app(AlbumPlanner::class);
        $plan = $planner->plan(Album::firstOrFail(), [], [], false);
        $this->assertSame($this->base.'/inbox/Yanou/Sunrise/Folder.jpg', $plan['embed_cover']['from']);

        OperationRunner::dispatch('album', 'Tags speichern', $planner->actions($plan));
        $this->assertTrue($this->hasCover($paths[0]));
        $this->assertTrue($this->hasCover($paths[1]));
    }

    public function test_unreadable_cover_file_is_not_offered(): void
    {
        $this->album($this->base.'/inbox/Yanou/Sunrise', 'Yanou', 'Sunrise', 2);
        file_put_contents($this->base.'/inbox/Yanou/Sunrise/Folder.jpg', 'kein bild');
        app(Scanner::class)->scan('inbox');

        $plan = app(AlbumPlanner::class)->plan(Album::firstOrFail(), [], [], false);
        $this->assertNull($plan['embed_cover']);
    }

    public function test_flac_picture_block_has_dimensions(): void
    {
        // kleinste gültige FLAC-Datei: nur STREAMINFO (44,1 kHz, Stereo, 16 Bit, keine Samples)
        $flac = $this->base.'/t.flac';
        $info = pack('nn', 4096, 4096)."\0\0\0\0\0\0".pack('J', (44100 << 44) | (1 << 41) | (15 << 36)).str_repeat("\0", 16);
        file_put_contents($flac, 'fLaC'."\x80\x00\x00\x22".$info);

        $result = app(TagTool::class)->write([['path' => $flac, 'tags' => ['title' => 'x'], 'cover' => $this->jpg]])[$flac];
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $probe = Process::run(['python3', '-c',
            'import sys; from mutagen.flac import FLAC; p = FLAC(sys.argv[1]).pictures[0]; print(p.width, p.height, p.depth)', $flac]);
        $this->assertSame('40 40 24', trim($probe->output()), $probe->errorOutput());
    }

    public function test_cleanup_check_embeds_missing_covers_in_library(): void
    {
        File::ensureDirectoryExists($this->base.'/library/artists');
        $paths = $this->album($this->base.'/library/sampler/Hits (2006)', 'Various', 'Hits', 3);
        app(TagTool::class)->write([['path' => $paths[0], 'tags' => [], 'cover' => $this->jpg]]);
        app(Scanner::class)->scan('library');

        $findings = app(CleanupChecks::class)->findings('cover_embed');
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2 von 3 Tracks', $findings[0]['detail']);
        $this->assertCount(2, $findings[0]['actions']);

        $op = OperationRunner::dispatch('cleanup', 'Cover einbetten', $findings[0]['actions']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $this->assertTrue($this->hasCover($paths[1]));
        $this->assertTrue($this->hasCover($paths[2]));
        $this->assertSame([], app(CleanupChecks::class)->findings('cover_embed'));
    }
}
