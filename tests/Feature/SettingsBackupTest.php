<?php

namespace Tests\Feature;

use App\Livewire\Inbox;
use App\Livewire\SettingsPage;
use App\Models\Album;
use App\Models\Artist;
use App\Models\DiscSet;
use App\Models\DuplicateException;
use App\Models\Genre;
use App\Models\GenreMapping;
use App\Models\Leftover;
use App\Models\Operation;
use App\Models\Track;
use App\Support\Settings;
use App\Support\SettingsBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsBackupTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): void
    {
        app(Settings::class)->set(['no_album' => 'Singles', 'discogs_token' => 'geheim', 'auto_cleanup' => ['extension' => ['LIB' => true]]]);
        Genre::create(['name' => 'Polka']);
        GenreMapping::updateOrCreate(['source' => 'Mein Eigenes Genre'], ['target' => 'Eurodance']);
        Artist::create(['name' => 'Adam [DE]', 'base_name' => 'Adam', 'suffix' => 'DE'])->aliases()->create(['alias' => 'ADAM']);
        DiscSet::create(['members' => [['dir' => '/x/CD1', 'disc' => 1], ['dir' => '/x/CD2', 'disc' => 2]]]);
    }

    public function test_export_reset_import_roundtrip(): void
    {
        $this->seedData();
        $response = $this->get(route('settings.export', ['images' => 0]));
        $response->assertOk()->assertHeader('Content-Disposition');
        $json = $response->getContent();
        $this->assertStringNotContainsString('geheim', $json, 'Zugangsdaten nur auf Wunsch');
        $this->assertStringContainsString('geheim', $this->get(route('settings.export', ['secrets' => 1, 'images' => 0]))->getContent());

        // Bestätigung ist Pflicht
        $page = Livewire::test(SettingsPage::class)->set('tab', 'backup')->set('resetMode', 'sections')
            ->set('resetSections', array_keys(SettingsBackup::SECTIONS))
            ->call('runReset')->assertHasErrors('resetConfirm');
        $this->assertSame('Singles', app(Settings::class)->get('no_album'));

        $page->set('resetConfirm', 'ZURÜCKSETZEN')->call('runReset')->assertHasNoErrors();
        $fresh = new Settings;
        $this->assertSame('Kein Album', $fresh->get('no_album'));
        $this->assertSame(0, Artist::count());
        $this->assertFalse(GenreMapping::where('source', 'Mein Eigenes Genre')->exists(), 'eigene Zuordnung weg');
        $this->assertSame('Rock \'n\' Roll / Rockabilly', GenreMapping::where('source', 'Rockabilly')->value('target'), 'Start-Zuordnungen wieder da');
        $this->assertFalse(Genre::where('name', 'Polka')->exists());
        $this->assertTrue(Genre::where('name', 'Trance')->exists(), 'Genre-Vorlage wiederhergestellt');

        // Einspielen
        $file = UploadedFile::fake()->createWithContent('backup.json', $json);
        $page->set('importFile', $file)
            ->assertSet('importSummary', fn ($s) => $s['artists'] === 1 && $s['disc_sets'] === 1)
            ->call('runImport');
        $fresh = new Settings;
        $this->assertSame('Singles', $fresh->get('no_album'));
        $this->assertSame(['extension' => ['LIB' => true]], $fresh->get('auto_cleanup'));
        $this->assertSame(['ADAM'], Artist::where('name', 'Adam [DE]')->first()->aliases->pluck('alias')->all());
        $this->assertSame('Eurodance', GenreMapping::where('source', 'Mein Eigenes Genre')->value('target'));
        $this->assertTrue(Genre::where('name', 'Polka')->exists());
        $this->assertSame(1, DiscSet::count());
    }

    public function test_reset_only_selected_sections(): void
    {
        $this->seedData();
        Livewire::test(SettingsPage::class)->set('tab', 'backup')->set('resetMode', 'sections')
            ->assertSet('resetSections', ['settings'])
            ->set('resetConfirm', 'ZURÜCKSETZEN')->call('runReset');
        $this->assertSame('Kein Album', (new Settings)->get('no_album'));
        $this->assertSame(1, Artist::count(), 'Künstlerverzeichnis bleibt');
        $this->assertTrue(GenreMapping::where('source', 'Mein Eigenes Genre')->exists(), 'Zuordnung bleibt');
    }

    public function test_invalid_file_is_rejected(): void
    {
        Livewire::test(SettingsPage::class)->set('tab', 'backup')
            ->set('importFile', UploadedFile::fake()->createWithContent('x.json', '{"hallo": 1}'))
            ->assertHasErrors('importFile')->assertSee('keine MusicWiz-Sicherung');
    }

    public function test_zip_backup_restores_artist_images(): void
    {
        $dir = storage_path('framework/testing/img-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($dir);
        app(Settings::class)->set(['artist_images' => $dir, 'trash' => $dir.'/_papierkorb', 'base' => $dir]);
        file_put_contents($dir.'/A-Teens.jpg', 'original-bild');
        Artist::create(['name' => 'A-Teens', 'image' => 'A-Teens.jpg']);

        $zip = app(SettingsBackup::class)->exportZip(false, true);
        // Verlust: Verzeichnis zurückgesetzt, Bild weg, anderes Bild am Platz
        app(SettingsBackup::class)->reset(['artists']);
        file_put_contents($dir.'/A-Teens.jpg', 'falsches-bild');

        $file = UploadedFile::fake()->createWithContent('sicherung.zip', file_get_contents($zip));
        Livewire::test(SettingsPage::class)->set('tab', 'backup')
            ->set('importFile', $file)->assertSet('importImages', 1)->assertSee('1 Bilder')
            ->set('importSections', ['artists'])->call('runImport');

        $this->assertSame('A-Teens.jpg', Artist::where('name', 'A-Teens')->value('image'));
        $this->assertSame('original-bild', file_get_contents($dir.'/A-Teens.jpg'));
        $this->assertSame('falsches-bild', file_get_contents($dir.'/_papierkorb/A-Teens.jpg'), 'vorhandenes Bild nicht überschrieben, sondern im Papierkorb');
        File::deleteDirectory($dir);
        @unlink($zip);
    }

    public function test_reset_scan_data_empties_inbox_and_library(): void
    {
        $album = Album::create(['location' => 'inbox', 'dir' => '/music/inbox/X', 'title' => 'X']);
        Track::create(['album_id' => $album->id, 'location' => 'inbox', 'path' => '/music/inbox/X/a.mp3', 'dir' => '/music/inbox/X', 'filename' => 'a.mp3', 'ext' => 'mp3']);
        Livewire::test(Inbox::class)->assertSee('X');

        Livewire::test(SettingsPage::class)->set('tab', 'backup')->set('resetMode', 'sections')
            ->set('resetSections', ['index'])->set('resetConfirm', 'ZURÜCKSETZEN')->call('runReset');

        $this->assertSame(0, Album::count());
        $this->assertSame(0, Track::count());
        Livewire::test(Inbox::class)->assertSee('Keine Alben in der Inbox');
    }

    public function test_factory_reset_empties_everything(): void
    {
        $this->seedData();
        $album = Album::create(['location' => 'library', 'dir' => '/music/library/artists/A/X', 'title' => 'X']);
        Track::create(['album_id' => $album->id, 'location' => 'library', 'path' => '/m/a.mp3', 'dir' => '/m', 'filename' => 'a.mp3', 'ext' => 'mp3']);
        $op = Operation::create(['type' => 'cleanup', 'description' => 'alt', 'status' => 'done']);
        $op->entries()->create(['kind' => 'move', 'old_path' => '/a', 'new_path' => '/b']);
        Leftover::create(['path' => '/m/x', 'kind' => 'junk']);
        DuplicateException::create(['song_key' => 'a|b', 'members' => ['x']]);
        Storage::put('covers/album-1.jpg', 'x');
        Cache::put('cleanup.counts.test', [1]);

        Livewire::test(SettingsPage::class)->set('tab', 'backup')
            ->assertSet('resetMode', 'all')
            ->set('resetConfirm', 'ZURÜCKSETZEN')->call('runReset')
            ->assertRedirect(route('inbox'));

        $this->assertFalse(GenreMapping::where('source', 'Mein Eigenes Genre')->exists());
        foreach (['settings', 'albums', 'tracks', 'artists', 'artist_aliases', 'operations', 'history_entries',
            'leftovers', 'disc_sets', 'duplicate_exceptions', 'jobs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' leer');
        }
        $this->assertTrue(Genre::where('name', 'Trance')->exists(), 'Genre-Vorlage');
        $this->assertFalse(Genre::where('name', 'Polka')->exists());
        $this->assertFalse(Storage::exists('covers/album-1.jpg'));
        $this->assertNull(Cache::get('cleanup.counts.test'));
        $this->assertSame('Kein Album', app(Settings::class)->get('no_album'));
    }
}
