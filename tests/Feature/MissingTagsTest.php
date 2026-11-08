<?php

namespace Tests\Feature;

use App\Support\CleanupChecks;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** „Fehlende Tags“: Tracknummer nur bei Alben mit mehreren Tracks verlangen. */
class MissingTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_number_only_required_with_several_tracks(): void
    {
        $base = storage_path('framework/testing/missing-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $files = [
            'A/ABBA/Single (1976)/Fernando.mp3' => 'Single',
            'A/ABBA/Gold (1992)/Waterloo.mp3' => 'Gold',
            'A/ABBA/Gold (1992)/SOS.mp3' => 'Gold',
            'A/ABBA/Kein Album/Mamma Mia.mp3' => '',
            'A/ABBA/Kein Album/Chiquitita.mp3' => '',
        ];
        foreach ($files as $rel => $album) {
            $path = $base.'/library/artists/'.$rel;
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'ABBA', 'album' => $album, 'title' => pathinfo($rel, PATHINFO_FILENAME), 'comment' => 'LIB']]]);
        }
        app(Scanner::class)->scan('library');

        $findings = app(CleanupChecks::class)->findings('missing_tags');
        $byTitle = collect($findings)->mapWithKeys(fn ($f) => [$f['title'] => $f['detail']]);
        $this->assertCount(2, $findings, 'Einzeltrack-Album fehlt in der Liste: '.$byTitle->toJson(JSON_UNESCAPED_UNICODE));
        $this->assertSame(['Tracknummer fehlt (2)'], $byTitle->filter(fn ($d) => str_contains($d, 'Tracknummer'))->values()->all(), 'nur „Gold“ mit zwei Tracks');
        $this->assertSame(1, $byTitle->filter(fn ($d) => $d === 'Album fehlt (2)')->count(), '„Kein Album“: fehlendes Album ja, Tracknummer nein');
        File::deleteDirectory($base);
    }
}
