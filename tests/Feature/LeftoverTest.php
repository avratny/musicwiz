<?php

namespace Tests\Feature;

use App\Models\Leftover;
use App\Models\Track;
use App\Support\AutoCleanup;
use App\Support\CleanupChecks;
use App\Support\FileOps;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LeftoverTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/left-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function mp3(string $rel): string
    {
        $p = $this->base.'/library/artists/'.$rel;
        File::ensureDirectoryExists(dirname($p));
        file_put_contents($p, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));

        return $p;
    }

    public function test_stuck_rename_is_music_and_gets_renamed_back_not_trashed(): void
    {
        // Endung klein machen über den Zwischennamen, auf dem NAS hängengeblieben: „Cambodia.MP3.musicwiz-tmp“
        $dir = $this->base.'/library/artists/K/Kim Wilde/Kein Album';
        rename($this->mp3('K/Kim Wilde/Kein Album/Cambodia.MP3'), $dir.'/Cambodia.MP3'.FileOps::RENAME_SUFFIX);
        app(Scanner::class)->scan('library');

        $findings = app(CleanupChecks::class)->findings('leftovers');
        $this->assertCount(1, $findings, 'kein „Ordner ohne Musik“');
        $this->assertStringContainsString('Umbenennen abgebrochen', $findings[0]['detail']);
        $this->assertSame([['type' => 'move', 'path' => $dir.'/Cambodia.MP3'.FileOps::RENAME_SUFFIX, 'target' => $dir.'/Cambodia.mp3']], $findings[0]['actions']);

        OperationRunner::dispatch('cleanup', 'Reste', $findings[0]['actions']);
        $this->assertFileExists($dir.'/Cambodia.mp3');
        $this->assertDirectoryDoesNotExist($this->base.'/_papierkorb');
        $this->assertSame($dir.'/Cambodia.mp3', Track::value('path'), 'wieder im Bestand');
        $this->assertSame([], app(CleanupChecks::class)->findings('leftovers'));
    }

    public function test_folder_without_music_is_reported_and_trashed(): void
    {
        $this->mp3('A/A-Teens/Super Trouper (2004)/01 - Super Trouper.mp3');
        File::ensureDirectoryExists($this->base.'/library/artists/A/A-Teens/Super Trouper');
        file_put_contents($this->base.'/library/artists/A/A-Teens/Super Trouper/Folder.jpg', 'jpg');
        app(Scanner::class)->scan('library');

        $f = collect(app(CleanupChecks::class)->findings('leftovers'))->firstWhere('title', 'library/artists/A/A-Teens/Super Trouper');
        $this->assertNotNull($f);
        $this->assertStringContainsString('Ordner ohne Musik (enthält: Folder.jpg)', $f['detail']);
        $this->assertSame('trash', $f['actions'][0]['type']);

        OperationRunner::dispatch('cleanup', 'Reste', $f['actions']);
        $this->assertDirectoryDoesNotExist($this->base.'/library/artists/A/A-Teens/Super Trouper');
        $this->assertFileExists($this->base.'/_papierkorb/library/artists/A/A-Teens/Super Trouper/Folder.jpg');
        $this->assertSame([], app(CleanupChecks::class)->findings('leftovers'), 'Liste ohne neuen Scan aktuell');
    }

    public function test_inbox_is_not_part_of_cleanup(): void
    {
        // defekte Datei, leerer Ordner und Rest-Datei in der Inbox: alles kein Fall fürs Aufräumen
        File::ensureDirectoryExists($this->base.'/inbox/Album/Leer');
        file_put_contents($this->base.'/inbox/Album/01 kaputt.mp3', 'x');
        file_put_contents($this->base.'/inbox/Album/.DS_Store', 'x');
        $this->mp3('A/ABBA/Gold/01 - Waterloo.mp3');
        app(Scanner::class)->scan('inbox');
        app(Scanner::class)->scan('library');

        $this->assertSame(0, Leftover::count());
        $checks = app(CleanupChecks::class);
        foreach (array_keys(CleanupChecks::definitions()) as $key) {
            foreach ($checks->findings($key) as $f) {
                $this->assertStringNotContainsString('inbox', json_encode($f), $key);
            }
        }
        $this->assertNotContains(AutoCleanup::OTHER, app(AutoCleanup::class)->columns());
    }

    public function test_moving_an_album_takes_all_remaining_files_along(): void
    {
        $song = $this->mp3('A/ABBA/Gold/1-01  Waterloo.mp3');
        $old = dirname($song);
        file_put_contents($old.'/Folder.jpg', 'cover');
        file_put_contents($old.'/large_cover.jpg', 'big');
        File::ensureDirectoryExists($old.'/albumart_backup');
        file_put_contents($old.'/albumart_backup/cover.jpg', 'backup');
        app(Scanner::class)->scan('library');

        $new = $this->base.'/library/artists/A/ABBA/Gold (1992)';
        OperationRunner::dispatch('cleanup', 'Regelort', [
            ['type' => 'move', 'path' => $song, 'target' => $new.'/01 - Waterloo.mp3'],
            ['type' => 'album_files', 'from_dir' => $old, 'to_dir' => $new],
        ]);

        $this->assertDirectoryDoesNotExist($old, 'kein Rest-Ordner');
        foreach (['01 - Waterloo.mp3', 'cover.jpg', 'large_cover.jpg', 'albumart_backup/cover.jpg'] as $f) {
            $this->assertFileExists($new.'/'.$f);
        }
        $this->assertSame([], array_column(app(CleanupChecks::class)->findings('leftovers'), 'title'));
    }

    public function test_orphaned_images_go_to_the_album_they_belong_to(): void
    {
        // alter Zustand: Lieder umgezogen, Bilder liegengeblieben (wie vor der Korrektur)
        $song = $this->mp3('A/A-teens/Super Trouper/1-1  Super Trouper.mp3');
        $old = dirname($song);
        file_put_contents($old.'/Folder.jpg', 'same');
        file_put_contents($old.'/large_cover.jpg', 'big-and-different');
        app(Scanner::class)->scan('library');
        $new = $this->base.'/library/artists/A/A-Teens/Super Trouper (2004)';
        OperationRunner::dispatch('cleanup', 'alter Umzug', [['type' => 'move', 'path' => $song, 'target' => $new.'/01 - Super Trouper.mp3']]);
        file_put_contents($new.'/cover.jpg', 'same');
        $this->assertDirectoryExists($old);

        $f = collect(app(CleanupChecks::class)->findings('leftovers'))->first(fn ($x) => str_ends_with($x['title'], 'Super Trouper'));
        $this->assertSame('album_files', $f['actions'][0]['type']);
        $this->assertStringContainsString('Super Trouper (2004)', $f['detail']);

        OperationRunner::dispatch('cleanup', 'Reste', $f['actions']);
        $this->assertDirectoryDoesNotExist($old);
        $this->assertFileExists($new.'/large_cover.jpg', 'anderes Bild kommt mit');
        $this->assertSame('same', file_get_contents($new.'/cover.jpg'));
        $this->assertFileDoesNotExist($new.'/cover (2).jpg', 'gleiches Bild nicht doppelt');
    }
}
