<?php

namespace Tests\Feature;

use App\Jobs\TakeStandardArtists;
use App\Livewire\Artists;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Support\AlbumPlanner;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\SortNames;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Eigenes Kategorie-Feld (MUSICWIZ_CATEGORY, zusätzlich zum Kommentar) und Sortiernamen (ARTISTSORT/ALBUMARTISTSORT).
 * Braucht python3 mit mutagen und ffmpeg (läuft im Container: ./dev.sh test).
 */
class CategoryAndSortNamesTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/sort-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base.'/inbox');
        app(Settings::class)->set([
            'base' => $this->base,
            'inbox' => $this->base.'/inbox',
            'trash' => $this->base.'/_papierkorb',
            'artist_images' => $this->base.'/_artists',
            'scan_throttle_ms' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function file(string $rel, array $tags, string $ext = 'mp3'): string
    {
        $path = $this->base.'/'.$rel.'.'.$ext;
        File::ensureDirectoryExists(dirname($path));
        $ext === 'flac'
            ? Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '1', $path])->throw()
            : file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags]]);

        return $path;
    }

    public function test_tag_tool_reads_writes_and_removes_the_new_fields(): void
    {
        $fields = ['category' => 'LIB', 'artistsort' => 'Beatles, The', 'albumartistsort' => 'Beatles, The'];
        foreach ([['mp3', 4], ['mp3', 3], ['flac', 4]] as [$ext, $version]) {
            app(Settings::class)->set(['id3_version' => $version]);
            $path = $this->file("x/$ext-$version", ['artist' => 'The Beatles'] + $fields, $ext);
            $tool = app(TagTool::class);
            $tags = $tool->read([$path])[$path]['tags'];
            $this->assertSame($fields, array_intersect_key($tags, $fields), "$ext v$version");

            $tool->write([['path' => $path, 'tags' => ['category' => '', 'artistsort' => '', 'albumartistsort' => '']]]);
            $tags = $tool->read([$path])[$path]['tags'];
            $this->assertSame(['category' => '', 'artistsort' => '', 'albumartistsort' => ''], array_intersect_key($tags, $fields), "$ext v$version");
            $this->assertSame('The Beatles', $tags['artist']);
        }
    }

    public function test_sort_names_follow_articles_overrides_and_existing_inversions(): void
    {
        $sort = app(SortNames::class);
        $this->assertSame('Beatles, The', $sort->byRule('The Beatles'));
        $this->assertSame('Toten Hosen, Die', $sort->byRule('Die Toten Hosen'));
        $this->assertSame('Theatre of Tragedy', $sort->byRule('Theatre of Tragedy'));
        $this->assertSame('The', $sort->byRule('The'));

        $this->assertSame('Beatles, The feat. Liz', $sort->forText('The Beatles feat. Liz'));
        $this->assertSame('', $sort->forText('ABBA'), 'gleich dem Namen: kein Feld');
        $this->assertSame('', $sort->forText('Various Artists'));
        // vorhandener umgedrehter Personenname (z. B. von Picard) bleibt
        $this->assertSame('Jürgens, Udo', $sort->forText('Udo Jürgens', 'Jürgens, Udo'));
        $this->assertSame('', $sort->forText('Udo Jürgens', 'Irgendwas'));

        Artist::create(['name' => 'Udo Jürgens', 'sort_name' => 'Juergens, Udo']);
        $sort = app(SortNames::class);
        $this->assertSame('Juergens, Udo', $sort->forText('Udo Jürgens', 'Jürgens, Udo'), 'eigener Eintrag geht vor');

        app(Settings::class)->set(['sort_articles' => 'The']);
        $this->assertSame('Die Toten Hosen', app(SortNames::class)->byRule('Die Toten Hosen'));
    }

    public function test_einordnen_writes_category_field_and_sort_names(): void
    {
        foreach ([1 => 'Help', 2 => 'Yesterday'] as $nr => $title) {
            $this->file("inbox/Beatles/Help/0$nr $title", ['title' => $title, 'artist' => $nr === 1 ? 'The Beatles feat. Liz' : 'The Beatles',
                'album' => 'Help', 'year' => '1965', 'track' => (string) $nr]);
        }
        app(Scanner::class)->scan('inbox');
        $album = Album::where('location', 'inbox')->firstOrFail();
        $planner = app(AlbumPlanner::class);
        $plan = $planner->plan($album, ['album' => 'Help', 'albumartist' => 'The Beatles', 'year' => '1965', 'category' => 'LIB'], [], true);
        $op = OperationRunner::dispatch('album', 'Test', $planner->actions($plan));
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        $target = $this->base.'/library/artists/T/The Beatles/Help (1965)/01 - Help.mp3';
        $tags = app(TagTool::class)->read([$target])[$target]['tags'];
        $this->assertSame('LIB', $tags['comment']);
        $this->assertSame('LIB', $tags['category']);
        $this->assertSame('Beatles, The feat. Liz', $tags['artistsort']);
        $this->assertSame('Beatles, The', $tags['albumartistsort']);
    }

    public function test_inbox_category_comes_from_the_field_when_the_comment_is_missing(): void
    {
        $this->file('inbox/Hits/01 A', ['title' => 'A', 'artist' => 'X', 'album' => 'Hits', 'track' => '1', 'category' => 'LIB COMPILATION']);
        app(Scanner::class)->scan('inbox');
        $this->assertSame('LIB COMPILATION', Album::where('location', 'inbox')->firstOrFail()->category);
    }

    public function test_cleanup_finds_missing_category_field_and_sort_names(): void
    {
        $this->file('library/artists/T/The Beatles/Help (1965)/01 - Help', ['title' => 'Help', 'artist' => 'The Beatles', 'albumartist' => 'The Beatles',
            'album' => 'Help', 'year' => '1965', 'track' => '1', 'comment' => 'LIB']);
        $this->file('library/artists/A/ABBA/Gold (1992)/01 - SOS', ['title' => 'SOS', 'artist' => 'ABBA', 'albumartist' => 'ABBA',
            'album' => 'Gold', 'year' => '1992', 'track' => '1', 'comment' => 'LIB', 'category' => 'LIB']);
        app(Scanner::class)->scan('library');
        $checks = app(CleanupChecks::class);

        $comment = collect($checks->findings('comment'))->sole();
        $this->assertStringContainsString('Feld fehlt', $comment['detail']);
        $this->assertSame(['comment' => 'LIB', 'category' => 'LIB'], $comment['actions'][0]['tags']);

        $sort = collect($checks->findings('sortnames'))->sole();
        $this->assertSame(['artistsort' => 'Beatles, The', 'albumartistsort' => 'Beatles, The'], $sort['actions'][0]['tags']);
        $this->assertSame('LIB', Track::where('artist', 'ABBA')->value('category'));
    }

    public function test_artist_page_shows_and_saves_the_sort_name(): void
    {
        $a = Artist::create(['name' => 'The Beatles']);
        Livewire::test(Artists::class)->call('select', $a->id)
            ->assertSee('ARTISTSORT = Beatles, The')
            ->set('form.sort_name', 'Beatles')
            ->assertSee('ARTISTSORT = Beatles')
            ->call('saveArtist');
        $this->assertSame('Beatles', $a->fresh()->sort_name);

        // gleich der Regel: kein eigener Eintrag
        Livewire::test(Artists::class)->call('select', $a->id)->set('form.sort_name', 'Beatles, The')->call('saveArtist');
        $this->assertNull($a->fresh()->sort_name);

        $abba = Artist::create(['name' => 'ABBA']);
        Livewire::test(Artists::class)->call('select', $abba->id)->assertSee('kein Sortierfeld nötig');
    }

    public function test_quick_standard_image_choice(): void
    {
        $page = Livewire::test(Artists::class);
        $page->call('quickStandardImage', 'k1', 'https://img/b.jpg');
        $this->assertSame(['k1' => 'https://img/b.jpg'], TakeStandardArtists::imageChoices());
        // nochmal auf das gewählte Bild: ohne Bild
        $page->call('quickStandardImage', 'k1', 'https://img/b.jpg', true);
        $this->assertSame(['k1' => ''], TakeStandardArtists::imageChoices());
        // zurück zum Vorschlag
        $page->call('quickStandardImage', 'k1', null);
        $this->assertSame([], TakeStandardArtists::imageChoices());
    }
}
