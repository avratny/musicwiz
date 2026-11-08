<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Livewire\Cleanup;
use App\Livewire\History;
use App\Livewire\Inbox;
use App\Livewire\Library;
use App\Livewire\SettingsPage;
use App\Models\Album;
use App\Models\Operation;
use App\Models\Track;
use App\Support\AlbumPlanner;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ende-zu-Ende: Inbox scannen, einordnen (Tags + Verschieben), rückgängig machen.
 * Braucht python3 mit mutagen (läuft im Container: ./dev.sh test).
 */
class EinordnenFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/music-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base.'/inbox/Yanou/Sunrise');
        app(Settings::class)->set([
            'base' => $this->base,
            'inbox' => $this->base.'/inbox',
            'trash' => $this->base.'/_papierkorb',
            'artist_images' => $this->base.'/_artists',
            'scan_throttle_ms' => 0,
        ]);

        $tool = app(TagTool::class);
        foreach ([1 => 'King of My Castle', 2 => 'Sunrise'] as $nr => $title) {
            $path = $this->base."/inbox/Yanou/Sunrise/0{$nr} Yanou - {$title}.mp3";
            file_put_contents($path, self::silentMp3());
            $tool->write([['path' => $path, 'tags' => [
                'title' => $title, 'artist' => $nr === 1 ? 'Yanou feat. Liz' : 'Yanou', 'album' => 'Sunrise',
                'year' => '2006', 'track' => (string) $nr, 'tracktotal' => '2', 'disc' => '1', 'disctotal' => '1',
            ]]]);
        }
        file_put_contents($this->base.'/inbox/Yanou/Sunrise/Folder.jpg', 'jpg');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** Ein paar stille MPEG-1-Layer-III-Frames (128 kbit/s, 44,1 kHz). */
    private static function silentMp3(): string
    {
        return str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40);
    }

    public function test_scan_einordnen_and_undo(): void
    {
        app(Scanner::class)->scan('inbox');
        $album = Album::where('location', 'inbox')->firstOrFail();
        $this->assertSame(2, $album->track_count);
        $this->assertSame('Sunrise', $album->title);

        $planner = app(AlbumPlanner::class);
        $edits = [];
        foreach ($album->tracks as $t) {
            $edits[$t->id] = ['title' => $t->title, 'artist' => $t->artist, 'track' => $t->track, 'disc' => $t->disc];
        }
        $plan = $planner->plan($album, ['album' => 'Sunrise', 'albumartist' => 'Yanou', 'year' => '2006', 'genres' => ['Trance'], 'category' => 'LIB'], $edits, true);
        $op = OperationRunner::dispatch('album', 'Test', $planner->actions($plan)); // Queue im Test: sync
        $op->refresh();
        $this->assertSame('done', $op->status, (string) $op->error);

        $target = $this->base.'/library/artists/Y/Yanou/Sunrise (2006)/01 - King of My Castle.mp3';
        $this->assertFileExists($target);
        $this->assertFileExists($this->base.'/library/artists/Y/Yanou/Sunrise (2006)/cover.jpg');
        $this->assertDirectoryDoesNotExist($this->base.'/inbox/Yanou');

        $tags = app(TagTool::class)->read([$target])[$target]['tags'];
        $this->assertSame('Yanou feat. Liz', $tags['artist']);
        $this->assertSame(['Yanou', 'Liz'], $tags['artists']);
        $this->assertSame(['Yanou'], $tags['albumartists']);
        $this->assertSame(['Electronic', 'Trance'], $tags['genres']);
        $this->assertSame('LIB', $tags['comment']);
        $this->assertFalse($tags['compilation']);

        $moved = Album::where('location', 'library')->firstOrFail();
        $this->assertSame('Yanou', $moved->folder_artist);
        $this->assertSame('LIB', $moved->category);

        // Rückgängig
        OperationRunner::dispatchUndo($op);
        $this->assertSame('undone', $op->fresh()->status);
        $original = $this->base.'/inbox/Yanou/Sunrise/01 Yanou - King of My Castle.mp3';
        $this->assertFileExists($original);
        $this->assertFileExists($this->base.'/inbox/Yanou/Sunrise/Folder.jpg');
        $this->assertDirectoryDoesNotExist($this->base.'/library/artists/Y');
        $tags = app(TagTool::class)->read([$original])[$original]['tags'];
        $this->assertSame([], $tags['artists']);
        $this->assertSame('', $tags['comment']);
        $this->assertSame([], $tags['genres']);
        $this->assertSame(1, Album::count());
        $this->assertSame('inbox', Album::first()->location);
    }

    public function test_conflicting_target_is_skipped(): void
    {
        app(Scanner::class)->scan('inbox');
        $album = Album::firstOrFail();
        $existing = $this->base.'/library/artists/Y/Yanou/Sunrise (2006)/02 - Sunrise.mp3';
        File::ensureDirectoryExists(dirname($existing));
        file_put_contents($existing, 'x');

        $planner = app(AlbumPlanner::class);
        $plan = $planner->plan($album, ['album' => 'Sunrise', 'albumartist' => 'Yanou', 'year' => '2006', 'category' => 'LIB'], [], true);
        $conflicts = array_values(array_filter($plan['rows'], fn ($r) => $r['conflict']));
        $this->assertCount(1, $conflicts);

        $actions = $planner->actions($plan, 'skip');
        $this->assertCount(1, array_filter($actions, fn ($a) => $a['type'] === 'apply'));
        $this->assertEmpty(array_filter($actions, fn ($a) => $a['type'] === 'album_files'), 'Cover bleibt, solange nicht alle Tracks umziehen');
    }

    public function test_remove_cover_and_undo(): void
    {
        $img = imagecreatetruecolor(20, 20);
        $jpg = $this->base.'/c.jpg';
        imagejpeg($img, $jpg);
        $dir = $this->base.'/inbox/Yanou/Sunrise';
        foreach (glob($dir.'/*.mp3') as $f) {
            app(TagTool::class)->write([['path' => $f, 'tags' => [], 'cover' => $jpg]]);
        }
        app(Scanner::class)->scan('inbox');
        $album = Album::firstOrFail();
        $this->assertTrue($album->cover_embedded);

        $planner = app(AlbumPlanner::class);
        $plan = $planner->plan($album, [], [], false, null, true);
        $this->assertSame([$dir.'/Folder.jpg'], $plan['cover_files']);
        $op = OperationRunner::dispatch('album', 'Cover entfernen', $planner->actions($plan));
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        $this->assertFileDoesNotExist($dir.'/Folder.jpg');
        $this->assertFileExists($this->base.'/_papierkorb/inbox/Yanou/Sunrise/Folder.jpg');
        $this->assertFalse(app(TagTool::class)->extractCover($dir.'/01 Yanou - King of My Castle.mp3', $this->base.'/x.jpg'));
        $album = Album::firstOrFail();
        $this->assertFalse($album->hasCover());

        OperationRunner::dispatchUndo($op);
        $this->assertFileExists($dir.'/Folder.jpg');
        $this->assertTrue(app(TagTool::class)->extractCover($dir.'/01 Yanou - King of My Castle.mp3', $this->base.'/x.jpg'));
        $this->assertTrue(Album::firstOrFail()->cover_embedded);
    }

    public function test_file_without_audio_is_reported_as_broken(): void
    {
        $broken = $this->base.'/inbox/Yanou/Sunrise/03 Yanou - Leer.flac';
        file_put_contents($broken, "fLaC\x80\x00\x00\x22".str_repeat("\x00", 34));
        app(Scanner::class)->scan('inbox');

        // Inbox: Hinweis im Editor (Track-Fehler), nicht im Aufräumen
        $track = Track::where('path', $broken)->firstOrFail();
        $this->assertStringContainsString('Keine Audiodaten', (string) $track->error);
        $this->assertSame([], app(CleanupChecks::class)->findings('broken'));

        // Bestand: Fund mit Papierkorb-Aktion
        $lib = $this->base.'/library/artists/Y/Yanou/Sunrise (2006)/03 - Leer.flac';
        File::ensureDirectoryExists(dirname($lib));
        rename($broken, $lib);
        app(Scanner::class)->scan('library');
        $findings = app(CleanupChecks::class)->findings('broken');
        $this->assertCount(1, $findings);
        $this->assertSame([['type' => 'trash', 'path' => $lib]], $findings[0]['actions']);
    }

    public function test_pages_render(): void
    {
        app(Scanner::class)->scan('inbox');
        $album = Album::firstOrFail();

        Livewire::test(Inbox::class)->assertOk()->assertSee('Sunrise');
        Livewire::test(Library::class)->assertOk()->set('q', 'King')->assertOk()->call('showSampler')->assertOk()->call('showArtist', 'Yanou')->assertOk()->set('q', '')->call('showRecent')->assertSee('Zuletzt hinzugefügt')->call('showAll')->assertSet('recent', false);
        Livewire::test(History::class)->assertOk();
        Livewire::test(Cleanup::class)->assertOk();
        Livewire::test(SettingsPage::class)->assertOk()->set('tab', 'categories')->assertSee('library/artists/Y/Yanou/Sunrise (2006)/2-05 - King of My Castle');

        Livewire::test(AlbumEditor::class, ['album' => $album])
            ->assertOk()
            ->assertSet('fields.category', 'LIB') // nur ein Künstler: Lib vorausgewählt
            ->set('fields.category', null)
            ->call('preview', true)->assertHasErrors('category')
            ->set('fields.category', 'LIB')
            ->call('preview', true)
            ->assertSee('library/artists/Y/Yanou/Sunrise (2006)/01 - King of My Castle.mp3')
            ->call('confirm');

        $this->assertSame('done', Operation::latest('id')->first()->status);
        $this->assertFileExists($this->base.'/library/artists/Y/Yanou/Sunrise (2006)/02 - Sunrise.mp3');
    }

    public function test_inbox_preselects_lib_only_for_a_single_artist(): void
    {
        $album = function (string $dir, array $artists, string $albumartist = '') {
            $a = Album::create(['location' => 'inbox', 'dir' => $dir, 'title' => 'X', 'track_count' => count($artists)]);
            foreach ($artists as $i => $artist) {
                Track::create(['album_id' => $a->id, 'location' => 'inbox', 'path' => "$dir/$i.mp3", 'dir' => $dir, 'filename' => "$i.mp3", 'ext' => 'mp3',
                    'title' => "T$i", 'artist' => $artist, 'albumartist' => $albumartist ?: null, 'album' => 'X', 'track' => (string) ($i + 1), 'duration' => 100]);
            }

            return $a->id;
        };

        Livewire::test(AlbumEditor::class, ['album' => $album('/i/a', ['Yanou', 'Yanou feat. Liz', 'yanou'])])
            ->assertSet('fields.category', 'LIB')->assertSee('alle Titel haben denselben Künstler')
            ->set('fields.category', 'BLACKHOLE')->assertDontSee('alle Titel haben denselben Künstler');
        Livewire::test(AlbumEditor::class, ['album' => $album('/i/b', ['Yanou', 'ATB'])])->assertSet('fields.category', null);
        Livewire::test(AlbumEditor::class, ['album' => $album('/i/c', ['Yanou', ''])])->assertSet('fields.category', null);
        // „Various Artists“ als Album-Interpret: Sampler statt Lib
        Livewire::test(AlbumEditor::class, ['album' => $album('/i/d', ['Yanou', 'Yanou'], 'Various Artists')])->assertSet('fields.category', 'LIB COMPILATION');
    }
}
