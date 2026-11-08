<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Support\CleanupChecks;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Tracks ohne Album (Scan vor „Alben bilden“ abgebrochen) sind im Aufräumen kein gemeinsames „?“-Album. */
class TracksWithoutAlbumTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracks_without_album_are_not_grouped_into_one_album(): void
    {
        $base = storage_path('framework/testing/noalbum-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $files = [
            'A/ABBA/Gold (1992)/01 - Waterloo.mp3' => ['ABBA', 'Gold'],
            'B/Bon Jovi/Crush (2000)/01 - It\'s My Life.mp3' => ['Bon Jovi', 'Crush'],
            'B/Bon Jovi/Kein Titel/01 - Always.mp3' => ['Bon Jovi', ''],
        ];
        foreach ($files as $rel => [$artist, $album]) {
            $path = $base.'/library/artists/'.$rel;
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => $artist, 'albumartist' => $artist, 'album' => $album, 'title' => 'Titel', 'comment' => 'LIB']]]);
        }
        app(Scanner::class)->scan('library');
        // Zustand nach einem Scan, der vor „Alben bilden“ abgebrochen wurde
        Track::query()->update(['album_id' => null]);

        $this->assertSame([], app(CleanupChecks::class)->findings('album_mixed'));
        File::deleteDirectory($base);
    }
}
