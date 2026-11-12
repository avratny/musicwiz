<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Original-Datum, Veröffentlichungsdatum und MusicBrainz-Album-ID: danach trennt Navidrome Alben (z. B. ein Titel ohne
 * TDOR, 99 mit TDOR 1960 → zwei Alben). Braucht python3 mit mutagen und ffmpeg (läuft im Container: ./dev.sh test).
 */
class ReleaseFieldsTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function file(string $rel, array $tags, string $ext = 'mp3'): string
    {
        $path = $this->base.'/library/sampler/'.$rel.'.'.$ext;
        File::ensureDirectoryExists(dirname($path));
        $ext === 'flac'
            ? Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '1', $path])->throw()
            : file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags]]);

        return $path;
    }

    private function read(string $path): array
    {
        return app(TagTool::class)->read([$path])[$path]['tags'];
    }

    public function test_tag_tool_reads_writes_and_removes_release_fields(): void
    {
        $fields = ['originaldate' => '1960', 'releasedate' => '2011-03-01', 'mb_albumid' => 'f4a2b9c1-0000-4000-8000-000000000001'];
        foreach (['mp3', 'flac'] as $ext) {
            $path = $this->file("x/$ext", ['title' => 'Money'] + $fields, $ext);
            $this->assertSame($fields, array_intersect_key($this->read($path), $fields), $ext);

            app(TagTool::class)->write([['path' => $path, 'tags' => ['originaldate' => '', 'releasedate' => '', 'mb_albumid' => '']]]);
            $this->assertSame(['originaldate' => '', 'releasedate' => '', 'mb_albumid' => ''], array_intersect_key($this->read($path), $fields), $ext.': entfernt');
            $this->assertSame('Money', $this->read($path)['title'], 'andere Felder bleiben');
        }
    }

    public function test_one_track_without_original_date_is_found_and_filled(): void
    {
        $tags = ['artist' => 'Barrett Strong', 'albumartist' => 'Various Artists', 'album' => '100 Greatest 60s Hits', 'year' => '2011', 'compilation' => true,
            'comment' => 'LIB COMPILATION', 'category' => 'LIB COMPILATION'];
        $dir = '1/100 Greatest 60s Hits (2011)/';
        $this->file($dir.'24 - Barrett Strong - Money', $tags + ['track' => '24', 'title' => 'Money', 'originaldate' => '1960']);
        $edsels = $this->file($dir.'25 - The Edsels - Rama Lama Ding Dong', ['artist' => 'The Edsels', 'track' => '25', 'title' => 'Rama Lama Ding Dong'] + $tags);
        $this->file($dir.'26 - Linda Scott - Star', $tags + ['track' => '26', 'title' => 'Star', 'originaldate' => '1960']);
        app(Scanner::class)->scan('library');
        $this->assertSame('1960', Track::where('track', '24')->value('originaldate'), 'beim Scan gelesen');

        $f = app(CleanupChecks::class)->findings('album_mixed')[0];
        $this->assertStringContainsString('Original-Datum: 1960 (2) / ∅ (1)', $f['detail']);
        $this->assertSame([['type' => 'apply', 'path' => $edsels, 'tags' => ['originaldate' => '1960']]], $f['actions']);

        $op = OperationRunner::dispatch('cleanup', 'Album uneinheitlich', $f['actions'])->fresh();
        $this->assertSame('done', $op->status, (string) $op->error);
        $this->assertSame('1960', $this->read($edsels)['originaldate']);
        $this->assertSame([], app(CleanupChecks::class)->findings('album_mixed'));
    }

    public function test_original_year_per_track_in_a_sampler_is_fine_but_different_album_ids_are_not(): void
    {
        $tags = ['albumartist' => 'Various Artists', 'album' => 'Hits', 'year' => '2011', 'compilation' => true, 'comment' => 'LIB COMPILATION'];
        $this->file('H/Hits (2011)/01 - A - One', $tags + ['artist' => 'A', 'track' => '1', 'title' => 'One', 'originaldate' => '1960', 'mb_albumid' => 'id-1']);
        $this->file('H/Hits (2011)/02 - B - Two', $tags + ['artist' => 'B', 'track' => '2', 'title' => 'Two', 'originaldate' => '1964', 'mb_albumid' => 'id-2']);
        app(Scanner::class)->scan('library');

        $f = app(CleanupChecks::class)->findings('album_mixed')[0];
        $this->assertStringContainsString('MusicBrainz-Album-ID: id-1 (1) / id-2 (1) – verschieden, bitte selbst entscheiden', $f['detail']);
        $this->assertStringNotContainsString('Original-Datum', $f['detail'], 'Jahr der Original-Single je Titel ist üblich');
        $this->assertSame([], $f['actions'], 'welche ID stimmt, entscheidet der Nutzer');
    }
}
