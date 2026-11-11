<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\Track;
use App\Support\Fingerprint;
use App\Support\OperationRunner;
use App\Support\PendingChanges;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Mehrere Alben mit demselben Zielordner: nie mischen, doppelte Lieder nur in der besten Fassung. */
class AlbumMergeTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/merge-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        // künstliche Dateien ohne echten Klang: Klangvergleich gilt als „dasselbe Lied“ (entschieden wird über Tags)
        $this->app->instance(Fingerprint::class, new class extends Fingerprint
        {
            public function similarity(string $a, string $b): ?float
            {
                return 1.0;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** MP3 mit 128 oder 320 kbit/s (gleiche Länge) */
    private function mp3(string $rel, int $track, string $title, int $kbit = 128, string $album = 'Gold'): string
    {
        $path = $this->base.'/library/artists/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        [$byte, $size] = $kbit === 320 ? ["\xE0", 1044] : ["\x90", 417];
        file_put_contents($path, str_repeat("\xFF\xFB".$byte."\x00".str_repeat("\x00", $size - 4), 120));
        app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => $album, 'year' => '1992',
            'track' => (string) $track, 'title' => $title, 'comment' => 'LIB']]]);

        return $path;
    }

    /** Wie auf dem NAS: etwas vormerken, dann „Änderungen schreiben“ für alles. */
    private function write(array $paths): Operation
    {
        app(Scanner::class)->scan('library');
        app(PendingChanges::class)->stage(array_map(fn ($p) => ['type' => 'apply', 'path' => $p, 'tags' => ['genres' => ['Pop']]], $paths), 'Genre');

        return OperationRunner::dispatch('pending', 'Schreiben', app(PendingChanges::class)->actions()['actions'], ['on_conflict' => 'skip'])->fresh();
    }

    private function filesIn(string $dir): array
    {
        return collect(File::files($dir))->map->getFilename()->sort()->values()->all();
    }

    public function test_same_album_twice_keeps_each_song_once_in_the_best_version(): void
    {
        $this->mp3('A/ABBA/Gold/1-01 Waterloo.mp3', 1, 'Waterloo');
        $this->mp3('A/ABBA/Gold/1-02 SOS.mp3', 2, 'SOS');
        $this->mp3('A/ABBA/Gold/1-03 Fernando.mp3', 3, 'Fernando');
        // dieselbe CD noch einmal, unter anderer Ordner-Schreibweise: Titel in anderer Schreibweise, Fernando in besserer Qualität, ein Titel mehr
        $copy = $this->mp3('A/Abba Gold/Gold/01 Waterloo.mp3', 1, 'Waterloo');
        $this->mp3('A/Abba Gold/Gold/02 Sos.mp3', 2, 'Sos');
        $this->mp3('A/Abba Gold/Gold/03 Fernando.mp3', 3, 'Fernando', 320);
        $this->mp3('A/Abba Gold/Gold/04 Chiquitita.mp3', 4, 'Chiquitita');

        $op = $this->write([$this->base.'/library/artists/A/ABBA/Gold/1-01 Waterloo.mp3', $copy]);

        $dir = $this->base.'/library/artists/A/ABBA/Gold (1992)';
        // das vollständigere Album gibt die Ziele vor (Schreibweise „Sos“), jedes Lied einmal
        $this->assertSame(['01 - Waterloo.mp3', '02 - Sos.mp3', '03 - Fernando.mp3', '04 - Chiquitita.mp3'], $this->filesIn($dir));
        $this->assertGreaterThan(100000, filesize($dir.'/03 - Fernando.mp3'), 'die 320er-Fassung hat gewonnen');
        $this->assertCount(3, File::allFiles($this->base.'/_papierkorb'), 'die schlechteren bzw. gleichwertigen Doppel im Papierkorb');
        $this->assertDirectoryDoesNotExist($this->base.'/library/artists/A/Abba Gold/Gold');
        $this->assertSame(4, Track::count());
        $this->assertSame([], $op->entries()->where('kind', 'error')->pluck('error')->all());
    }

    public function test_folder_names_differing_only_in_case_are_the_same_album(): void
    {
        // schon am Regelort, Albumname in Großbuchstaben; auf dem NAS ist „GOLD (1992)“ derselbe Ordner wie „Gold (1992)“
        $this->mp3('A/ABBA/GOLD (1992)/01 - Waterloo.mp3', 1, 'Waterloo', 128, 'GOLD');
        $this->mp3('A/ABBA/GOLD (1992)/02 - SOS.mp3', 2, 'SOS', 128, 'GOLD');
        $copy = $this->mp3('A/Abba Gold/Gold/01 Waterloo.mp3', 1, 'Waterloo', 320);
        $this->mp3('A/Abba Gold/Gold/02 SOS.mp3', 2, 'SOS');

        $this->write([$copy]);

        $files = collect(File::allFiles($this->base.'/library'))->map->getFilename()->sort()->values()->all();
        $this->assertSame(['01 - Waterloo.mp3', '02 - SOS.mp3'], $files, 'kein Lied doppelt');
        $this->assertGreaterThan(100000, filesize($this->base.'/library/artists/A/ABBA/GOLD (1992)/01 - Waterloo.mp3'), '320er-Fassung ersetzt die vorhandene');
        $this->assertCount(2, File::allFiles($this->base.'/_papierkorb'));
    }

    public function test_other_album_with_the_same_folder_is_not_mixed_in(): void
    {
        $this->mp3('A/ABBA/Gold/1-01 Waterloo.mp3', 1, 'Waterloo');
        $this->mp3('A/ABBA/Gold/1-02 SOS.mp3', 2, 'SOS');
        // gleicher Album-Name, aber andere Lieder auf denselben Nummern
        $other = $this->mp3('A/Abba Gold/Gold/01 Dancing Queen.mp3', 1, 'Dancing Queen');
        $this->mp3('A/Abba Gold/Gold/02 Mamma Mia.mp3', 2, 'Mamma Mia');

        $op = $this->write([$this->base.'/library/artists/A/ABBA/Gold/1-01 Waterloo.mp3', $other]);

        $this->assertSame(['01 - Waterloo.mp3', '02 - SOS.mp3'], $this->filesIn($this->base.'/library/artists/A/ABBA/Gold (1992)'));
        $this->assertSame(['01 Dancing Queen.mp3', '02 Mamma Mia.mp3'], $this->filesIn(dirname($other)), 'bleibt, wo es ist');
        $this->assertSame(['Pop'], $this->tagsOf($other)['genres'], 'Tags trotzdem geschrieben');
        $this->assertStringContainsString('anderes Album', (string) $op->entries()->where('kind', 'info')->value('error'));
        $this->assertDirectoryDoesNotExist($this->base.'/_papierkorb');
    }

    public function test_parts_of_one_album_in_two_folders_are_joined(): void
    {
        $this->mp3('A/ABBA/Gold/1-01 Waterloo.mp3', 1, 'Waterloo');
        $this->mp3('A/ABBA/Gold/1-02 SOS.mp3', 2, 'SOS');
        $rest = $this->mp3('A/Abba Gold/Gold/03 Fernando.mp3', 3, 'Fernando');
        $this->mp3('A/Abba Gold/Gold/04 Chiquitita.mp3', 4, 'Chiquitita');
        file_put_contents(dirname($rest).'/booklet.pdf', 'Booklet');

        $this->write([$this->base.'/library/artists/A/ABBA/Gold/1-01 Waterloo.mp3', $rest]);

        $this->assertSame(['01 - Waterloo.mp3', '02 - SOS.mp3', '03 - Fernando.mp3', '04 - Chiquitita.mp3', 'booklet.pdf'],
            $this->filesIn($this->base.'/library/artists/A/ABBA/Gold (1992)'), 'ergänzt sich: ein Album, Extras ziehen mit');
    }

    private function tagsOf(string $path): array
    {
        return app(TagTool::class)->read([$path])[$path]['tags'];
    }
}
