<?php

namespace Tests\Feature;

use App\Livewire\TrashPage;
use App\Models\HistoryEntry;
use App\Models\Track;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\Trash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class TrashTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/trash-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        File::ensureDirectoryExists($this->base.'/library/artists/A/ABBA/Gold');
        foreach (['01 - Waterloo.mp3', '02 - SOS.mp3'] as $f) {
            file_put_contents($this->base.'/library/artists/A/ABBA/Gold/'.$f, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        }
        app(Scanner::class)->scan('library');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_restore_and_delete_forever(): void
    {
        $waterloo = $this->base.'/library/artists/A/ABBA/Gold/01 - Waterloo.mp3';
        $sos = $this->base.'/library/artists/A/ABBA/Gold/02 - SOS.mp3';
        OperationRunner::dispatch('cleanup', 'aussortieren', [['type' => 'trash', 'path' => $waterloo], ['type' => 'trash', 'path' => $sos]]);
        HistoryEntry::where('kind', 'trash')->update(['error' => 'Kopie in „Kein Album“']);
        $this->assertSame(2, app(Trash::class)->count());
        $this->assertSame(0, Track::count());

        $page = Livewire::test(TrashPage::class)
            ->assertSee('library/artists/A/ABBA/Gold/01 - Waterloo.mp3')
            ->assertSee('Kopie in „Kein Album“')
            ->set('selected', ['library/artists/A/ABBA/Gold/01 - Waterloo.mp3'])
            ->call('restore');
        $this->assertFileExists($waterloo);
        $this->assertSame(1, Track::where('path', $waterloo)->count(), 'wiederhergestellte Datei ist wieder im Bestand');

        $page->call('$refresh')->assertSee('wiederhergestellt')
            ->set('selected', ['library/artists/A/ABBA/Gold/02 - SOS.mp3'])
            ->call('deleteForever');
        $this->assertFileDoesNotExist($this->base.'/_papierkorb/library/artists/A/ABBA/Gold/02 - SOS.mp3');
        $this->assertSame([], app(Trash::class)->files());
        $page->call('$refresh')->assertSee('Der Papierkorb ist leer');
    }

    public function test_delete_forever_only_inside_trash(): void
    {
        $this->expectException(RuntimeException::class);
        app(Trash::class)->delete($this->base.'/library/artists/A/ABBA/Gold/01 - Waterloo.mp3');
    }
}
