<?php

namespace Tests\Feature;

use App\Livewire\Cleanup;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicatesAlbumTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/dupes-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox_paths' => [$this->base.'/inbox'], 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** MP3 mit 128 oder 320 kbit/s, ca. 7 s */
    private function track(string $rel, int $kbit, array $tags): string
    {
        [$byte, $size] = $kbit === 320 ? ["\xE0", 1044] : ["\x90", 417];
        $path = $this->base.'/library/artists/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB".$byte."\x00".str_repeat("\x00", $size - 4), 270));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags + ['comment' => 'LIB']]]);

        return $path;
    }

    private function findings(): array
    {
        return collect(app(CleanupChecks::class)->findings('duplicates'))->keyBy('title')->all();
    }

    public function test_albums_are_never_broken(): void
    {
        // 1) Album gleich gut, lose Kopie -> lose raus
        $albumA = $this->track('A/ABBA/Gold/01 - Waterloo.mp3', 128, ['artist' => 'ABBA', 'title' => 'Waterloo', 'album' => 'Gold', 'track' => '1']);
        $looseA = $this->track('A/ABBA/Kein Album/Waterloo.mp3', 128, ['artist' => 'ABBA', 'title' => 'Waterloo']);
        // 2) lose Kopie besser -> Upgrade an den Album-Platz, mit Album-Tags
        $albumB = $this->track('A/ABBA/Gold/02 - SOS.mp3', 128, ['artist' => 'ABBA', 'title' => 'SOS', 'album' => 'Gold', 'track' => '2', 'genres' => ['Pop']]);
        $looseB = $this->track('A/ABBA/Kein Album/SOS.mp3', 320, ['artist' => 'ABBA', 'title' => 'SOS']);
        // 3) dasselbe Lied in zwei Alben -> kein Fund
        $this->track('A/ABBA/Gold/03 - Fernando.mp3', 128, ['artist' => 'ABBA', 'title' => 'Fernando', 'album' => 'Gold', 'track' => '3']);
        $this->track('A/ABBA/Arrival/01 - Fernando.mp3', 128, ['artist' => 'ABBA', 'title' => 'Fernando', 'album' => 'Arrival', 'track' => '1']);
        // 4) nur lose Kopien -> frei wählbar
        $this->track('A/ABBA/Kein Album/Chiquitita.mp3', 128, ['artist' => 'ABBA', 'title' => 'Chiquitita']);
        $this->track('A/ABBA/Kein Album/Chiquitita (2).mp3', 128, ['artist' => 'ABBA', 'title' => 'Chiquitita']);
        app(Scanner::class)->scan('library');

        $f = $this->findings();
        $this->assertArrayNotHasKey('ABBA – Fernando', $f, 'Lied in zwei Alben ist kein Fall für den Papierkorb');
        $this->assertSame([['type' => 'trash', 'path' => $looseA]], $f['ABBA – Waterloo']['actions']);
        $this->assertFalse($f['ABBA – Waterloo']['choosable']);
        $this->assertTrue($f['ABBA – Chiquitita']['choosable']);
        $this->assertStringContainsString('Upgrade', $f['ABBA – SOS']['detail']);

        $actions = collect($f)->flatMap(fn ($x) => $x['actions'])->all();
        $op = OperationRunner::dispatch('cleanup', 'Duplikate', $actions);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        // Album "Gold" ist vollständig: 01, 02, 03
        $this->assertSame(['01 - Waterloo.mp3', '02 - SOS.mp3', '03 - Fernando.mp3'], array_values(array_diff(scandir($this->base.'/library/artists/A/ABBA/Gold'), ['.', '..'])));
        $this->assertFileDoesNotExist($looseA);
        $this->assertFileDoesNotExist($looseB);
        // an Platz 02 liegt jetzt die 320er-Fassung mit den Album-Tags
        $tags = app(TagTool::class)->read([$albumB])[$albumB]['tags'];
        $this->assertSame(['Gold', '2', ['Pop']], [$tags['album'], $tags['track'], $tags['genres']]);
        $this->assertGreaterThan(filesize($albumA) * 2, filesize($albumB), 'bessere (größere) Fassung im Album');
        $this->assertFileExists($this->base.'/library/artists/A/ABBA/Arrival/01 - Fernando.mp3');

        // Rückgängig stellt alles wieder her
        OperationRunner::dispatchUndo($op);
        $this->assertFileExists($looseA);
        $this->assertFileExists($looseB);
        $this->assertLessThan(filesize($looseB), filesize($albumB), 'ursprüngliche 128er-Fassung wieder im Album');
    }

    public function test_mark_as_different_survives_changes_and_can_be_undone(): void
    {
        $a = $this->track('A/ABBA/Gold/01 - Waterloo.mp3', 128, ['artist' => 'ABBA', 'title' => 'Waterloo', 'album' => 'Gold', 'track' => '1']);
        $this->track('A/ABBA/Kein Album/Waterloo.mp3', 320, ['artist' => 'ABBA', 'title' => 'Waterloo']);
        app(Scanner::class)->scan('library');
        $id = $this->findings()['ABBA – Waterloo']['id'];

        $page = Livewire::test(Cleanup::class)->set('check', 'duplicates')
            ->call('markDifferent', $id)
            ->assertSee('als verschieden markiert');
        $this->assertArrayNotHasKey('ABBA – Waterloo', $this->findings());

        // Tags ändern + verschieben: bleibt ausgeblendet
        app(TagTool::class)->write([['path' => $a, 'tags' => ['genres' => ['Pop'], 'comment' => 'LIB']]]);
        $op = OperationRunner::dispatch('cleanup', 'move', [['type' => 'move', 'path' => $a, 'target' => $this->base.'/library/artists/A/ABBA/Gold (1974)/01 - Waterloo.mp3']]);
        app(Scanner::class)->scan('library');
        $this->assertArrayNotHasKey('ABBA – Waterloo', $this->findings());

        // dritte Kopie -> wieder melden
        $third = $this->track('A/ABBA/Kein Album/Waterloo (2).mp3', 128, ['artist' => 'ABBA', 'title' => 'Waterloo']);
        app(Scanner::class)->scan('library');
        $this->assertArrayHasKey('ABBA – Waterloo', $this->findings());
        unlink($third);
        app(Scanner::class)->scan('library');

        // wieder freigeben
        $page->set('showDismissed', true)->assertSee('wieder als Duplikat prüfen');
        $checks = app(CleanupChecks::class);
        $checks->withDismissed = true;
        $d = collect($checks->findings('duplicates'))->firstWhere('dismissed', true);
        $page->call('undismiss', $d['song_key'], $d['fingerprints']);
        $this->assertArrayHasKey('ABBA – Waterloo', $this->findings());
    }

    public function test_numbered_copy_with_other_length_is_no_duplicate(): void
    {
        $this->track('A/a-ha/Kein Album/The Living Daylights.mp3', 128, ['artist' => 'a-ha', 'title' => 'The Living Daylights']);
        $other = $this->base.'/library/artists/A/a-ha/Kein Album/The Living Daylights (3).mp3';
        file_put_contents($other, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 500)); // deutlich länger
        app(TagTool::class)->write([['path' => $other, 'tags' => ['artist' => 'a-ha', 'title' => 'The Living Daylights (Extended)']]]);
        app(Scanner::class)->scan('library');
        $this->assertSame([], $this->findings());
    }
}
