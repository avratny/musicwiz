<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Livewire\Blackhole;
use App\Livewire\Library;
use App\Livewire\SettingsPage;
use App\Models\Album;
use App\Models\Operation;
use App\Models\Setting;
use App\Models\Track;
use App\Support\AlbumPlanner;
use App\Support\ArtistDirectory;
use App\Support\AutoCleanup;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Blackhole als eigener Bereich: eigener Scan, Lib → Blackhole, Blackhole → Inbox. Braucht python3 mit mutagen (Container).
 */
class BlackholeTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/music-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function song(string $path, string $artist, string $title, string $album, int $nr): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40));
        app(TagTool::class)->write([['path' => $path, 'tags' => ['title' => $title, 'artist' => $artist, 'album' => $album, 'year' => '2006', 'track' => (string) $nr]]]);
    }

    private function move(Album $album, string $category): Operation
    {
        $planner = app(AlbumPlanner::class);
        $plan = $planner->plan($album, ['album' => $album->title, 'albumartist' => 'Yanou', 'year' => '2006', 'category' => $category], [], true);
        $op = OperationRunner::dispatch('album', 'Test', $planner->actions($plan));

        return tap($op->refresh(), fn ($op) => $this->assertSame('done', $op->status, (string) $op->error));
    }

    public function test_separate_scan_and_not_part_of_lib(): void
    {
        $this->song($this->base.'/library/artists/A/Alpha/Gehört (2000)/01 - Eins.mp3', 'Alpha', 'Eins', 'Gehört', 1);
        $this->song($this->base.'/blackhole/artists/B/Beta/Ungehört (2001)/01 - Zwei.mp3', 'Beta', 'Zwei', 'Ungehört', 1);

        app(Scanner::class)->scan('library');
        $this->assertSame(['library'], Track::pluck('location')->all(), 'Lib-Scan liest das Blackhole nicht');
        app(Scanner::class)->scan('blackhole');
        $this->assertSame(['blackhole', 'library'], Track::orderBy('location')->pluck('location')->all());
        app(Scanner::class)->scan('library');
        $this->assertSame(2, Track::count(), 'Lib-Scan entfernt keine Blackhole-Einträge');

        $this->assertSame(['Alpha' => 1], app(ArtistDirectory::class)->namesInCollection());
        $this->assertSame(['LIB', 'LIB COMPILATION'], app(AutoCleanup::class)->columns());
        Livewire::test(Library::class)->assertSee('1 Interpreten · 1 Alben')->assertSee('Alpha')->assertDontSee('Beta')->assertDontSee('BLACKHOLE')
            ->set('q', 'Zwei')->assertDontSee('Ungehört');
        // Blackhole sieht aus wie die Lib: alle Interpreten links, Klick aufs Album klappt die Titel auf statt des Editors
        $album = Album::where('location', 'blackhole')->first();
        Livewire::test(Blackhole::class)->assertSee('1 Interpreten · 1 Alben')->assertSee('Beta')->assertDontSee('Alpha')
            ->assertSee('Alle')->assertSee('Ungehört')
            ->call('showArtist', 'Beta')->assertSee('Ungehört')->assertDontSee(route('library.album', $album))
            ->set('open', $album->id)->assertSee('Album → In die Inbox')->assertSee('Zwei')
            ->set('open', null)->set('q', 'Zwei')->assertSee('Ungehört')->assertDontSee('Gehört (2000)')
            ->call('setView', 'table')->assertSee('Zwei')->assertSee('→ In die Inbox');

        // Album im Blackhole wird nicht bearbeitet
        $this->assertStringContainsString('/blackhole?open='.$album->id, $album->editUrl());
        Livewire::test(AlbumEditor::class, ['album' => $album->id])->assertRedirect($album->editUrl());
    }

    public function test_lib_to_blackhole_and_back_to_inbox(): void
    {
        $this->song($this->base.'/inbox/Yanou/Sunrise/01.mp3', 'Yanou', 'King of My Castle', 'Sunrise', 1);
        $this->song($this->base.'/inbox/Yanou/Sunrise/02.mp3', 'Yanou', 'Sunrise', 'Sunrise', 2);
        file_put_contents($this->base.'/inbox/Yanou/Sunrise/cover.jpg', 'jpg');
        app(Scanner::class)->scan('inbox');
        $this->move(Album::where('location', 'inbox')->firstOrFail(), 'LIB');

        // Lib → Blackhole direkt
        $this->move(Album::where('location', 'library')->firstOrFail(), 'BLACKHOLE');
        $dir = $this->base.'/blackhole/artists/Y/Yanou/Sunrise (2006)';
        $this->assertFileExists($dir.'/01 - King of My Castle.mp3');
        $this->assertSame('BLACKHOLE', app(TagTool::class)->read([$dir.'/01 - King of My Castle.mp3'])[$dir.'/01 - King of My Castle.mp3']['tags']['comment']);
        $album = Album::firstOrFail();
        $this->assertSame(['blackhole', 'BLACKHOLE'], [$album->location, $album->category]);
        $this->assertSame(0, Track::where('location', 'library')->count());

        // ein Titel zurück in die Inbox
        $song = Track::where('title', 'Sunrise')->firstOrFail();
        Livewire::test(Blackhole::class)->call('trackToInbox', $song->id);
        $this->assertFileExists($this->base.'/inbox/Yanou - Sunrise (2006)/02 - Sunrise.mp3');
        $this->assertSame('inbox', Track::where('title', 'Sunrise')->value('location'));

        // das ganze Album: der Inbox-Ordner von eben ist belegt – nie mischen
        Livewire::test(Blackhole::class)->call('albumToInbox', Album::where('location', 'blackhole')->firstOrFail()->id);
        $back = $this->base.'/inbox/Yanou - Sunrise (2006) (2)';
        $this->assertFileExists($back.'/01 - King of My Castle.mp3');
        $this->assertFileExists($back.'/cover.jpg');
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertSame(0, Track::where('location', 'blackhole')->count());
        $this->assertSame(2, Track::where('location', 'inbox')->count());
        // Kategorie-Kommentar ist weg; vorausgewählt wird höchstens „Lib“ (nur ein Künstler), nie wieder Blackhole
        $this->assertSame('', app(TagTool::class)->read([$back.'/01 - King of My Castle.mp3'])[$back.'/01 - King of My Castle.mp3']['tags']['comment']);
        $inbox = Album::where('location', 'inbox')->where('dir', $back)->firstOrFail();
        $this->assertNull($inbox->category);
        Livewire::test(AlbumEditor::class, ['album' => $inbox->id])->assertSet('fields.category', 'LIB')->assertSet('categoryGuessed', 'LIB');

        // rückgängig: wieder im Blackhole, samt Kommentar
        OperationRunner::dispatchUndo(Operation::where('type', 'to_inbox')->latest('id')->first());
        $this->assertFileExists($dir.'/01 - King of My Castle.mp3');
        $this->assertSame('BLACKHOLE', app(TagTool::class)->read([$dir.'/01 - King of My Castle.mp3'])[$dir.'/01 - King of My Castle.mp3']['tags']['comment']);
        $this->assertSame(1, Track::where('location', 'blackhole')->count());
    }

    public function test_fixed_categories_and_single_inbox_from_old_settings(): void
    {
        Setting::where('key', 'inbox')->delete();
        Setting::updateOrCreate(['key' => 'inbox_paths'], ['value' => ['/alt/inbox', '/alt/zwei']]);
        Setting::updateOrCreate(['key' => 'categories'], ['value' => [
            ['name' => 'LIB', 'folder' => 'musik/artists', 'pattern' => '{titel}.{ext}', 'compilation' => false],
            ['name' => 'Extra', 'folder' => 'extra', 'pattern' => '{titel}.{ext}', 'compilation' => false],
        ]]);
        $settings = app(Settings::class);
        $this->assertSame('/alt/inbox', $settings->inbox());
        $this->assertSame(['LIB', 'LIB COMPILATION', 'BLACKHOLE', 'BLACKHOLE COMPILATION'], array_column($settings->categories(), 'name'));
        $this->assertSame('musik/artists', $settings->categories()[0]['folder']);
        $this->assertSame('blackhole', $settings->locationOf($this->base.'/blackhole/sampler/X/1.mp3'));
        $this->assertSame('library', $settings->locationOf($this->base.'/library/sampler/X/1.mp3'));
        $this->assertSame('inbox', $settings->locationOf('/alt/inbox/X/1.mp3'));

        // umbenennen und speichern: Rollen bleiben fest
        Livewire::test(SettingsPage::class)->set('s.categories.2.name', 'NIE')->set('s.inbox', '/neu/inbox/')->call('save')->assertHasNoErrors();
        $settings = app(Settings::class);
        $this->assertSame('/neu/inbox', $settings->inbox());
        $this->assertSame(['role' => 'blackhole', 'area' => 'blackhole', 'name' => 'NIE'], array_intersect_key($settings->categories()[2], array_flip(['role', 'area', 'name'])));
        $this->assertArrayHasKey('blackhole', Setting::where('key', 'categories')->value('value'));
    }

    public function test_migration_moves_existing_blackhole_entries(): void
    {
        Setting::updateOrCreate(['key' => 'inbox_paths'], ['value' => [$this->base.'/inbox']]);
        Setting::where('key', 'inbox')->delete();
        foreach (['library/artists/A/Alpha/X' => 'lib', 'blackhole/artists/B/Beta/Y' => 'bh'] as $dir => $n) {
            $album = Album::create(['location' => 'library', 'dir' => $this->base."/$dir", 'title' => $n]);
            Track::create(['album_id' => $album->id, 'location' => 'library', 'path' => $this->base."/$dir/1.mp3", 'dir' => $this->base."/$dir", 'filename' => '1.mp3', 'ext' => 'mp3']);
        }

        (require database_path('migrations/2026_11_08_001100_split_blackhole_location.php'))->up();

        $this->assertSame(['lib' => 'library', 'bh' => 'blackhole'], Album::pluck('location', 'title')->all());
        $this->assertSame(['blackhole', 'library'], Track::orderBy('location')->pluck('location')->all());
        $this->assertSame($this->base.'/inbox', Setting::where('key', 'inbox')->value('value'));
        $this->assertNull(Setting::where('key', 'inbox_paths')->first());
    }
}
