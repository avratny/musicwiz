<?php

namespace Tests\Feature;

use App\Livewire\Cleanup;
use App\Models\CleanupException;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\SettingsBackup;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** „Electronic ohne Unterstil“: pro Album „Electronic reicht hier“ wegklicken. */
class ElectronicSubDismissTest extends TestCase
{
    use RefreshDatabase;

    public function test_album_can_be_dismissed_and_stays_dismissed_after_move(): void
    {
        $base = storage_path('framework/testing/esub-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox_paths' => [$base.'/inbox'], 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $path = $base.'/library/artists/J/Jeff Zarling/Northwind (2026)/01 - Spirit.mp3';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'Jeff Zarling', 'albumartist' => 'Jeff Zarling', 'album' => 'Northwind', 'genres' => ['Electronic'], 'comment' => 'LIB']]]);
        app(Scanner::class)->scan('library');
        $findings = fn () => app(CleanupChecks::class)->findings('electronic_sub');
        $this->assertCount(1, $findings());

        $page = Livewire::test(Cleanup::class)->set('check', 'electronic_sub')
            ->assertSee('Electronic reicht hier')
            ->call('dismissAlbum', $findings()[0]['id']);
        $this->assertSame([], $findings());
        $this->assertSame(0, app(CleanupChecks::class)->counts()['electronic_sub']);
        $page->assertSee('1 mit „Electronic reicht hier“', false)
            ->set('showDismissed', true)->assertSee('wieder melden');
        $this->assertSame(0, app(CleanupChecks::class)->counts()['electronic_sub'], 'aufgeklappte Ausnahmen zählen nicht mit');

        // Ordner umbenennen: Ausnahme hängt an Album-Interpret|Album, nicht am Pfad
        OperationRunner::dispatch('test', 'verschieben', [['type' => 'move', 'path' => $path, 'target' => $base.'/library/artists/J/Jeff Zarling/Northwind/01 - Spirit.mp3']]);
        $this->assertSame([], $findings());

        $this->assertSame([['check' => 'electronic_sub', 'item_key' => CleanupException::value('item_key'), 'label' => CleanupException::value('label')]],
            app(SettingsBackup::class)->export()['cleanup_exceptions'], 'steht in der Sicherung');

        $page->call('undismissAlbum', CleanupException::value('item_key'));
        $this->assertCount(1, $findings(), 'wieder gemeldet');
        File::deleteDirectory($base);
    }
}
