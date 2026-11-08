<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\Track;
use App\Support\CleanupChecks;
use App\Support\FileOps;
use App\Support\Fingerprint;
use App\Support\OperationRunner;
use App\Support\Quality;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ConflictBestTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/conflict-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** MPEG-1 Layer III, 44,1 kHz: 128 kbit/s (Index 9) oder 320 kbit/s (Index 14) */
    private static function mp3(int $kbit, int $frames = 120, string $salt = ''): string
    {
        [$byte, $size] = $kbit === 320 ? ["\xE0", 1044] : ["\x90", 417];

        return str_repeat("\xFF\xFB".$byte."\x00".str_repeat("\x00", $size - 4), $frames).$salt;
    }

    private function file(string $rel, string $data): string
    {
        $path = $this->base.'/library/artists/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $data);

        return $path;
    }

    /** Für Tests mit künstlichen Dateien ohne echten Klang: Klangvergleich gilt als "dasselbe Lied". */
    private function sameSoundAlways(): void
    {
        $this->app->instance(Fingerprint::class, new class extends Fingerprint
        {
            public function similarity(string $a, string $b): ?float
            {
                return 1.0;
            }
        });
    }

    /** Echtes Audio: kleine "Melodie" (Ton wechselt je nach Variante), als FLAC oder MP3. */
    private function song(string $rel, int $variant, string $format, array $tags): string
    {
        $path = $this->base.'/library/artists/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        [$f, $step] = $variant === 1 ? [300, 200] : [500, 150];
        $expr = "0.5*sin(2*PI*($f+$step*floor(mod(t*".($variant + 1).'\,4)))*t)';
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', "aevalsrc=$expr:s=44100:d=30", ...($format === 'mp3' ? ['-b:a', '128k'] : []), $path])->throw();
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags]]);

        return $path;
    }

    public function test_only_the_same_song_is_ever_trashed(): void
    {
        $tags = ['artist' => 'ABBA', 'title' => 'Waterloo'];
        // dieselbe Aufnahme, FLAC ersetzt MP3
        $same = Quality::compare($this->song('Old/a.flac', 1, 'flac', $tags), $this->song('New/a.mp3', 1, 'mp3', $tags));
        $this->assertSame('source', $same['winner'], $same['reason']);
        // gleiche Tags und Länge, aber anderer Klang
        $other = Quality::compare($this->song('Old/b.flac', 1, 'flac', $tags), $this->song('New/b.mp3', 2, 'mp3', $tags));
        $this->assertSame('both', $other['winner']);
        $this->assertStringContainsString('klingt anders', $other['reason']);
        // gleicher Klang, aber laut Tags ein anderes Lied
        $tagged = Quality::compare($this->song('Old/c.flac', 1, 'flac', $tags), $this->song('New/c.mp3', 1, 'mp3', ['artist' => 'ABBA', 'title' => 'SOS']));
        $this->assertSame('both', $tagged['winner']);
        $this->assertStringContainsString('anderes Lied', $tagged['reason']);
        // nicht vergleichbar (kein Klang): im Zweifel beide behalten
        $this->assertSame('both', Quality::compare($this->file('Old/d (2).mp3', self::mp3(320)), $this->file('New/d.mp3', self::mp3(128)))['winner']);
    }

    public function test_keep_best_version_on_occupied_target(): void
    {
        $this->sameSoundAlways();
        $cases = [
            // [Quelle, Ziel, Gewinner]
            'identisch' => [self::mp3(128), self::mp3(128), 'target'],
            'quelle besser' => [self::mp3(320), self::mp3(128), 'source'],
            'ziel defekt' => [self::mp3(128), str_repeat("\x00", 200), 'source'],
            'gleichwertig' => [self::mp3(128, 120, 'a'), self::mp3(128, 121), 'target'],
        ];
        $actions = [];
        $expect = [];
        foreach ($cases as $name => [$src, $dst, $winner]) {
            $source = $this->file("Old/$name (2).mp3", $src);
            $target = $this->file("New/$name.mp3", $dst);
            $actions[] = ['type' => 'move', 'path' => $source, 'target' => $target];
            $expect[$name] = [$source, $target, $winner, md5_file($winner === 'source' ? $source : $target)];
        }
        app(Scanner::class)->scan('library');
        $this->assertSame('identische Kopie – wird aussortiert', Quality::compare($expect['identisch'][0], $expect['identisch'][1])['reason']);

        $op = OperationRunner::dispatch('cleanup', 'Test', $actions, ['on_conflict' => 'best']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        foreach ($expect as $name => [$source, $target, $winner, $md5]) {
            $this->assertFileDoesNotExist($source, $name);
            $this->assertSame($md5, md5_file($target), $name.': richtige Fassung am Ziel');
        }
        $this->assertSame(4, $op->entries()->where('kind', 'trash')->count(), 'Verlierer immer im Papierkorb');

        OperationRunner::dispatchUndo($op);
        foreach ($expect as $name => [$source, $target]) {
            $this->assertFileExists($source, $name.' zurück');
            $this->assertFileExists($target, $name.' zurück');
        }
        $this->assertSame('undone', Operation::find($op->id)->status);
    }

    public function test_folder_case_is_corrected_and_index_follows(): void
    {
        $old = $this->file('A/A-ha/Kein Album/Take On Me.MP3', self::mp3(128));
        $this->file('A/A-ha/Kein Album/Hunting High.mp3', self::mp3(128));
        app(Scanner::class)->scan('library');
        if (! file_exists($this->base.'/library/artists/A/A-HA')) {
            $this->markTestSkipped('Dateisystem unterscheidet Groß/klein – Fall tritt hier nicht auf.');
        }

        file_put_contents($this->base.'/library/artists/A/A-ha/Kein Album/cover.jpg', 'jpg');
        $hunting = $this->base.'/library/artists/A/A-ha/Kein Album/Hunting High.mp3';
        $target = $this->base.'/library/artists/A/A-Ha/Kein Album/Take On Me.mp3';
        $op = OperationRunner::dispatch('cleanup', 'Test', [
            ['type' => 'move', 'path' => $old, 'target' => $target],
            ['type' => 'move', 'path' => $hunting, 'target' => $this->base.'/library/artists/A/A-Ha/Kein Album/Hunting High.mp3'],
            ['type' => 'album_files', 'from_dir' => $this->base.'/library/artists/A/A-ha/Kein Album', 'to_dir' => $this->base.'/library/artists/A/A-Ha/Kein Album'],
        ], ['on_conflict' => 'best']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        $this->assertContains('A-Ha', scandir($this->base.'/library/artists/A'), 'Ordner hat jetzt die richtige Schreibweise');
        $this->assertFileExists($this->base.'/library/artists/A/A-Ha/Kein Album/cover.jpg', 'Cover darf bei reiner Schreibweisen-Änderung nicht aussortiert werden');
        $this->assertSame(0, $op->entries()->where('kind', 'trash')->count());
        $this->assertContains('Take On Me.mp3', scandir($this->base.'/library/artists/A/A-Ha/Kein Album'));
        $paths = Track::pluck('path')->sort()->values()->all();
        $this->assertSame([
            $this->base.'/library/artists/A/A-Ha/Kein Album/Hunting High.mp3',
            $this->base.'/library/artists/A/A-Ha/Kein Album/Take On Me.mp3',
        ], $paths, 'kein veralteter .MP3-Eintrag, Nachbardatei mit umbenannt');

        OperationRunner::dispatchUndo($op);
        $this->assertContains('A-ha', scandir($this->base.'/library/artists/A'));
        $this->assertContains('Take On Me.MP3', scandir($this->base.'/library/artists/A/A-ha/Kein Album'));
    }

    public function test_same_file_with_other_unicode_form_is_renamed_not_trashed(): void
    {
        $nfd = "Aliz\u{0065}\u{0301}e";          // é als e + Akzent (so speichert macOS)
        $nfc = "Aliz\u{00E9}e";                   // é als ein Zeichen (so bildet die App den Zielnamen)
        $source = $this->file("A/$nfd/Kein Album/Toc de mac.mp3", self::mp3(128));
        $target = $this->base."/library/artists/A/$nfc/Kein Album/Toc de mac.mp3";
        if (! FileOps::sameFile($source, $target)) {
            $this->markTestSkipped('Dateisystem unterscheidet Unicode-Formen – Fall tritt hier nicht auf.');
        }
        app(Scanner::class)->scan('library');

        $op = OperationRunner::dispatch('cleanup', 'Test', [['type' => 'move', 'path' => $source, 'target' => $target]], ['on_conflict' => 'best']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $this->assertSame(0, $op->entries()->where('kind', 'trash')->count(), 'dieselbe Datei darf nie aussortiert werden');
        $this->assertFileExists($target);
        $this->assertContains($nfc, scandir($this->base.'/library/artists/A'), 'Ordner in einheitlicher Unicode-Form');
        $this->assertSame([$target], Track::pluck('path')->all());
    }

    /** Minimale FLAC-Datei mit STREAMINFO (für den Vergleich reichen Abtastrate, Bittiefe und Länge). */
    private static function flac(int $rate, int $bits, float $seconds, string $salt = ''): string
    {
        $samples = (int) round($rate * $seconds);
        $v = ($rate << 44) | (1 << 41) | (($bits - 1) << 36) | $samples; // 20 Bit Rate, 3 Bit Kanäle-1, 5 Bit Bits-1, 36 Bit Samples
        $info = pack('nn', 4096, 4096)."\x00\x00\x00\x00\x00\x00".pack('J', $v).str_repeat("\x00", 16);

        return "fLaC\x80\x00\x00\x22".$info.str_repeat('a', 2000).$salt;
    }

    public function test_flac_versions_compared_by_resolution_and_length(): void
    {
        $this->sameSoundAlways();
        $pairs = [
            'hires' => [self::flac(96000, 24, 200), self::flac(44100, 16, 200), 'source', 'höhere Auflösung'],
            'gleich' => [self::flac(44100, 16, 200, 'x'), self::flac(44100, 16, 200.5), 'target', 'gleichwertig'],
            'flac vs mp3' => [self::flac(44100, 16, 3.1), self::mp3(320, 120), 'source', 'verlustfrei'],
            'abgeschnitten' => [self::flac(44100, 16, 200), self::flac(44100, 16, 120), 'both', 'Längen weichen ab'],
        ];
        foreach ($pairs as $name => [$src, $dst, $winner, $reason]) {
            $source = $this->file("Old/$name (2).flac", $src);
            $target = $this->file("New/$name.".($name === 'flac vs mp3' ? 'mp3' : 'flac'), $dst);
            $cmp = Quality::compare($source, $target);
            $this->assertSame($winner, $cmp['winner'], $name.': '.$cmp['reason']);
            $this->assertStringContainsString($reason, $cmp['reason']);
        }

        // "unklar" behält beide
        $source = $this->base.'/library/artists/Old/abgeschnitten (2).flac';
        $target = $this->base.'/library/artists/New/abgeschnitten.flac';
        $op = OperationRunner::dispatch('cleanup', 'Test', [['type' => 'move', 'path' => $source, 'target' => $target]], ['on_conflict' => 'best']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $this->assertFileExists($target);
        $this->assertFileExists($this->base.'/library/artists/New/abgeschnitten (2).flac');
        $this->assertSame(0, $op->entries()->where('kind', 'trash')->count());
    }

    public function test_inconsistent_artist_casing_converges_instead_of_ping_pong(): void
    {
        app(Settings::class)->set(['categories' => [['name' => 'LIB', 'folder' => 'library/artists', 'pattern' => '{buchstabe}/{ordner_interpret}/{album}/{nr} - {titel}.{ext}', 'compilation' => false]]]);
        $tool = app(TagTool::class);
        foreach (['ATB' => ['Two Worlds', 'Ecstasy'], 'Atb' => ['Movin Melodies', 'Killer']] as $artist => [$album, $title]) {
            $path = $this->file("A/$artist-old/$album/$title.mp3", self::mp3(128));
            $tool->write([['path' => $path, 'tags' => ['artist' => $artist, 'albumartist' => $artist, 'album' => $album, 'title' => $title, 'track' => '1', 'comment' => 'LIB']]]);
        }
        $extra = $this->file('A/ATB-old/Two Worlds/Bonus.mp3', self::mp3(128));
        $tool->write([['path' => $extra, 'tags' => ['artist' => 'ATB', 'albumartist' => 'ATB', 'album' => 'Two Worlds', 'title' => 'Bonus', 'track' => '2', 'comment' => 'LIB']]]);
        app(Scanner::class)->scan('library');

        for ($run = 1; $run <= 3; $run++) {
            $actions = collect(app(CleanupChecks::class)->findings('misplaced'))->flatMap(fn ($f) => $f['actions'])->all();
            if (! $actions) {
                break;
            }
            OperationRunner::dispatch('cleanup', 'Lauf '.$run, $actions, ['on_conflict' => 'best']);
        }
        $this->assertSame(0, count(app(CleanupChecks::class)->findings('misplaced')), 'nach dem ersten Lauf stabil');
        $this->assertSame(2, $run, 'genau ein Lauf nötig');
        $this->assertSame(['ATB'], array_values(array_diff(scandir($this->base.'/library/artists/A'), ['.', '..'])), 'ein Ordner, häufigste Schreibweise');
        $killer = $this->base.'/library/artists/A/ATB/Movin Melodies/01 - Killer.mp3';
        $this->assertFileExists($killer);
        $tags = app(TagTool::class)->read([$killer])[$killer]['tags'];
        $this->assertSame(['ATB', 'ATB'], [$tags['artist'], $tags['albumartist']], 'Ordner = Künstler im Tag: Tag wurde angeglichen');
    }

    public function test_kept_second_version_counts_as_placed(): void
    {
        $this->assertTrue(CleanupChecks::isKeptVariant(
            $this->file('A/X/Kein Album/Song (2).mp3', self::mp3(128)), $this->file('A/X/Kein Album/Song.mp3', self::mp3(320))));
        $this->assertFalse(CleanupChecks::isKeptVariant(
            $this->file('A/Y/Kein Album/Song (2).mp3', self::mp3(128)), $this->base.'/library/artists/A/Y/Kein Album/Song.mp3'), 'Ziel frei → verschieben');
    }
}
