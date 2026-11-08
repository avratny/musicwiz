<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\Track;
use App\Support\AutoCleanup;
use App\Support\OperationRunner;
use App\Support\Quality;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Dateien dürfen nie kaputtgehen oder verloren gehen – auch nicht bei Abbrüchen, Lesefehlern oder fehlendem Share. */
class FileSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/safe-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base.'/inbox');
        $this->settings($this->base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function settings(string $base, ?string $inbox = null): void
    {
        app(Settings::class)->set([
            'base' => $base,
            'inbox' => $inbox ?? $base.'/inbox',
            'trash' => $base.'/_papierkorb',
            'artist_images' => $base.'/_artists',
            'scan_throttle_ms' => 0,
        ]);
    }

    private function mp3(string $path, array $tags = ['title' => 'Lied', 'artist' => 'Yanou']): string
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags]]);

        return $path;
    }

    private function image(string $path, array $rgb, string $type = 'jpg'): string
    {
        $img = imagecreatetruecolor(20, 20);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        $type === 'png' ? imagepng($img, $path) : imagejpeg($img, $path);

        return $path;
    }

    /** Eingebettete Bilder: [[typ, mime, md5], …] */
    private function pictures(string $path): array
    {
        $script = <<<'PY'
import sys, json, hashlib
from mutagen.id3 import ID3
from mutagen.flac import FLAC
p = sys.argv[1]
pics = FLAC(p).pictures if p.endswith(".flac") else ID3(p).getall("APIC")
print(json.dumps(sorted([[x.type, x.mime, hashlib.md5(x.data).hexdigest()] for x in pics])))
PY;

        return json_decode(Process::run(['python3', '-c', $script, $path])->throw()->output(), true);
    }

    private function addPicture(string $path, string $image, int $type): void
    {
        $script = <<<'PY'
import sys
from mutagen.id3 import ID3, APIC
t = ID3(sys.argv[1])
t.add(APIC(encoding=3, mime="image/png", type=int(sys.argv[3]), desc="Rückseite", data=open(sys.argv[2], "rb").read()))
t.save()
PY;
        Process::run(['python3', '-c', $script, $path, $image, (string) $type])->throw();
    }

    // ---------------------------------------------------------------- A1: sicheres Schreiben

    public function test_tag_write_goes_through_a_checked_copy(): void
    {
        $path = $this->mp3($this->base.'/inbox/a.mp3');
        $result = app(TagTool::class)->write([['path' => $path, 'tags' => ['title' => 'Neu']]])[$path];

        $this->assertTrue($result['ok']);
        $this->assertSame('Neu', app(TagTool::class)->read([$path])[$path]['tags']['title']);
        $this->assertSame(['a.mp3'], array_values(array_diff(scandir(dirname($path)), ['.', '..'])), 'keine Zwischendatei');
    }

    public function test_failed_write_leaves_original_untouched(): void
    {
        $path = $this->mp3($this->base.'/inbox/a.mp3');
        $before = md5_file($path);

        $result = app(TagTool::class)->write([['path' => $path, 'tags' => ['title' => 'Neu'], 'cover' => $this->base.'/fehlt.jpg']])[$path];

        $this->assertFalse($result['ok']);
        $this->assertSame($before, md5_file($path));
        $this->assertSame(['a.mp3'], array_values(array_diff(scandir(dirname($path)), ['.', '..'])));
    }

    public function test_leftover_write_copy_counts_as_junk(): void
    {
        $path = $this->mp3($this->base.'/library/artists/Y/Yanou/Album (2006)/01 - Lied.mp3');
        copy($path, dirname($path).'/.01 - Lied.mp3'.TagTool::WRITE_SUFFIX);

        app(Scanner::class)->scan('library');

        $this->assertSame(1, Track::count());
        $this->assertDatabaseHas('leftovers', ['path' => dirname($path).'/.01 - Lied.mp3'.TagTool::WRITE_SUFFIX, 'kind' => 'junk']);
    }

    // ---------------------------------------------------------------- A2: Cover ändern behält andere Bilder

    public function test_mp3_cover_change_keeps_other_pictures_and_undo_restores_front(): void
    {
        $path = $this->mp3($this->base.'/inbox/a.mp3');
        $red = $this->image($this->base.'/red.jpg', [200, 0, 0]);
        $blue = $this->image($this->base.'/blue.png', [0, 0, 200], 'png');
        $green = $this->image($this->base.'/green.jpg', [0, 200, 0]);
        app(TagTool::class)->write([['path' => $path, 'tags' => [], 'cover' => $red]]);
        $this->addPicture($path, $blue, 4);
        $before = $this->pictures($path);
        $this->assertCount(2, $before);

        $op = OperationRunner::dispatch('album', 'Cover', [['type' => 'apply', 'path' => $path, 'cover' => $green]]);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $after = $this->pictures($path);
        $this->assertCount(2, $after);
        $this->assertContains([3, 'image/jpeg', md5_file($green)], $after);
        $this->assertContains([4, 'image/png', md5_file($blue)], $after, 'Rückseite bleibt');

        OperationRunner::dispatchUndo($op);
        $this->assertEquals($before, $this->pictures($path));
    }

    public function test_undo_without_front_cover_does_not_promote_back_cover(): void
    {
        $path = $this->mp3($this->base.'/inbox/a.mp3');
        $blue = $this->image($this->base.'/blue.png', [0, 0, 200], 'png');
        $this->addPicture($path, $blue, 4);
        $before = $this->pictures($path);

        $op = OperationRunner::dispatch('album', 'Cover', [['type' => 'apply', 'path' => $path, 'cover' => $this->image($this->base.'/g.jpg', [0, 200, 0])]]);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        OperationRunner::dispatchUndo($op);

        $this->assertEquals($before, $this->pictures($path));
    }

    public function test_png_cover_is_embedded_with_png_mime_in_flac(): void
    {
        $path = $this->base.'/inbox/a.flac';
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '1', $path])->throw();
        $png = $this->image($this->base.'/blue.png', [0, 0, 200], 'png');

        $this->assertTrue(app(TagTool::class)->write([['path' => $path, 'tags' => ['title' => 'X'], 'cover' => $png]])[$path]['ok']);
        $this->assertSame([[3, 'image/png', md5_file($png)]], $this->pictures($path));
    }

    // ---------------------------------------------------------------- A3: Groß/klein auf Dateisystemen, die unterscheiden

    public function test_case_variant_target_is_a_conflict_on_case_sensitive_disks(): void
    {
        $base = sys_get_temp_dir().'/musicwiz-case-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($base.'/Abba');
        File::ensureDirectoryExists($base.'/ABBA');
        if (count(array_diff(scandir($base), ['.', '..'])) !== 2) {
            File::deleteDirectory($base);
            $this->markTestSkipped('Dateisystem unterscheidet Groß/klein nicht');
        }
        try {
            $this->settings($base);
            file_put_contents($base.'/Abba/song.mp3', 'quelle');
            file_put_contents($base.'/ABBA/song.mp3', 'ziel');

            $op = OperationRunner::dispatch('test', 'Test', [['type' => 'move', 'path' => $base.'/Abba/song.mp3', 'target' => $base.'/ABBA/song.mp3']]);

            $this->assertSame('failed', $op->fresh()->status);
            $this->assertSame('quelle', file_get_contents($base.'/Abba/song.mp3'));
            $this->assertSame('ziel', file_get_contents($base.'/ABBA/song.mp3'));
        } finally {
            File::deleteDirectory($base);
        }
    }

    // ---------------------------------------------------------------- A4: Lesefehler überschreiben den Index nicht

    public function test_read_failure_keeps_previous_index_values(): void
    {
        $path = $this->mp3($this->base.'/library/artists/Y/Yanou/Album (2006)/01 - Lied.mp3', ['title' => 'Lied', 'artist' => 'Yanou', 'album' => 'Album', 'comment' => 'LIB']);
        app(Scanner::class)->scan('library');
        $track = Track::sole();
        $this->assertSame('Album', $track->album);

        // Tag-Tool meldet Lesefehler (Share kurz weg) – auch beim zweiten Versuch
        Process::fake(fn () => Process::result(json_encode(['results' => [['path' => $path, 'ok' => false, 'error' => 'OSError: Share weg']]])));
        app(Scanner::class)->rescanPaths([$path]);

        $track->refresh();
        $this->assertSame('Album', $track->album);
        $this->assertSame('LIB', $track->comment);
        $this->assertGreaterThan(0.1, $track->duration);
        $this->assertStringContainsString('Share weg', $track->error);
        $this->assertSame(0, $track->mtime, 'beim nächsten Scan neu lesen');
    }

    public function test_tag_tool_outage_keeps_previous_index_values(): void
    {
        $path = $this->mp3($this->base.'/library/artists/Y/Yanou/Album (2006)/01 - Lied.mp3', ['title' => 'Lied', 'artist' => 'Yanou', 'album' => 'Album']);
        app(Scanner::class)->scan('library');

        Process::fake(fn () => Process::result('', 'Traceback …', 1));
        app(Scanner::class)->rescanPaths([$path]);

        $this->assertSame('Album', Track::sole()->album);
        $this->assertNotNull(Track::sole()->error);
    }

    public function test_unreadable_new_file_is_still_stored_as_broken(): void
    {
        File::ensureDirectoryExists($this->base.'/library/artists/Y/Yanou/Album (2006)');
        file_put_contents($this->base.'/library/artists/Y/Yanou/Album (2006)/01 - Lied.mp3', 'kaputt');

        app(Scanner::class)->scan('library');

        $this->assertNull(Track::sole()->duration);
        $this->assertNotNull(Track::sole()->error);
    }

    public function test_auto_broken_only_for_old_files_that_are_still_broken(): void
    {
        $broken = $this->base.'/library/kaputt.mp3';
        File::ensureDirectoryExists(dirname($broken));
        file_put_contents($broken, 'kaputt');
        $this->assertFalse(AutoCleanup::stillBroken($broken), 'neue Datei – wird vielleicht gerade kopiert');

        touch($broken, time() - 2 * 86400);
        $this->assertTrue(AutoCleanup::stillBroken($broken));

        $good = $this->mp3($this->base.'/library/gut.mp3');
        touch($good, time() - 2 * 86400);
        $this->assertFalse(AutoCleanup::stillBroken($good), 'beim Nachlesen intakt');
    }

    // ---------------------------------------------------------------- A5: Qualitätsvergleich

    public function test_missing_index_duration_does_not_count_as_broken(): void
    {
        $path = $this->mp3($this->base.'/inbox/a.mp3');
        Track::create(['path' => $path, 'location' => 'inbox', 'dir' => dirname($path), 'filename' => 'a.mp3', 'ext' => 'mp3', 'size' => 1, 'mtime' => 1, 'duration' => null, 'bitrate' => null]);

        $q = Quality::of($path);

        $this->assertFalse($q['broken']);
        $this->assertGreaterThan(0, $q['bitrate']);
    }

    public function test_file_unreadable_right_now_is_never_trashed_by_best(): void
    {
        $source = $this->mp3($this->base.'/inbox/a.mp3');
        $target = $this->base.'/library/a.mp3';
        File::ensureDirectoryExists(dirname($target));
        file_put_contents($target, 'gerade nicht lesbar');
        Track::create(['path' => $target, 'location' => 'library', 'dir' => dirname($target), 'filename' => 'a.mp3', 'ext' => 'mp3', 'size' => 1, 'mtime' => 1, 'duration' => 200.0, 'bitrate' => 320000]);

        $this->assertSame('both', Quality::compare($source, $target)['winner']);

        $op = OperationRunner::dispatch('test', 'Test', [['type' => 'move', 'path' => $source, 'target' => $target]], ['on_conflict' => 'best']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $this->assertFileExists($target);
        $this->assertFileExists($this->base.'/library/a (2).mp3');
        $this->assertDirectoryDoesNotExist($this->base.'/_papierkorb');
    }

    // ---------------------------------------------------------------- A6: Share nicht eingehängt

    public function test_operation_aborts_when_category_root_with_indexed_music_is_missing(): void
    {
        $path = $this->mp3($this->base.'/library/artists/Y/Yanou/Album (2006)/01 - Lied.mp3');
        app(Scanner::class)->scan('library');
        File::deleteDirectory($this->base.'/library'); // Share weg
        $new = $this->mp3($this->base.'/inbox/neu.mp3');

        $op = OperationRunner::dispatch('album', 'Einordnen', [['type' => 'move', 'path' => $new, 'target' => $this->base.'/library/artists/Y/Yanou/Neu/neu.mp3']]);

        $this->assertSame('failed', $op->fresh()->status);
        $this->assertStringContainsString('nicht erreichbar', $op->fresh()->error);
        $this->assertFileExists($new);
        $this->assertDirectoryDoesNotExist($this->base.'/library');
        $this->assertSame(0, $op->entries()->count());
    }

    public function test_nothing_is_created_inside_an_empty_base(): void
    {
        $inbox = $this->base.'-inbox';
        $base = $this->base.'/leer';
        File::ensureDirectoryExists($base);
        $this->settings($base, $inbox);
        $new = $this->mp3($inbox.'/neu.mp3');

        try {
            $op = OperationRunner::dispatch('album', 'Einordnen', [['type' => 'move', 'path' => $new, 'target' => $base.'/library/artists/neu.mp3']]);

            $this->assertSame('failed', $op->fresh()->status);
            $this->assertStringContainsString('eingehängt', $op->fresh()->error);
            $this->assertFileExists($new);
            $this->assertSame([], array_values(array_diff(scandir($base), ['.', '..'])));
        } finally {
            File::deleteDirectory($inbox);
        }
    }

    public function test_operations_on_other_roots_still_run(): void
    {
        $this->mp3($this->base.'/blackhole/artists/Y/Yanou/Alt (2000)/01 - Alt.mp3');
        app(Scanner::class)->scan('blackhole');
        $path = $this->mp3($this->base.'/library/artists/Y/Yanou/Album (2006)/01 - Lied.mp3');
        File::deleteDirectory($this->base.'/blackhole');

        $op = OperationRunner::dispatch('rating', 'Bewertung', [['type' => 'apply', 'path' => $path, 'tags' => ['rating' => 4]]]);

        $this->assertSame('done', Operation::find($op->id)->status, (string) $op->fresh()->error);
    }
}
