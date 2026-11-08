<?php

namespace Tests\Feature;

use App\Livewire\Blackhole;
use App\Livewire\Library;
use App\Models\Album;
use App\Models\Operation;
use App\Models\Track;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LibraryViewTest extends TestCase
{
    use RefreshDatabase;

    private function album(string $location, string $artist, string $title, string $year, array $songs, bool $compilation = false): Album
    {
        $dir = "/m/$location/$artist/$title";
        $album = Album::create(['location' => $location, 'dir' => $dir, 'title' => $title, 'year' => $year, 'folder_artist' => $compilation ? null : $artist,
            'letter' => $artist[0], 'is_compilation' => $compilation, 'category' => $compilation ? 'LIB COMPILATION' : 'LIB', 'track_count' => count($songs)]);
        foreach ($songs as $i => [$song, $ext, $genre, $seconds]) {
            Track::create(['album_id' => $album->id, 'location' => $location, 'path' => "$dir/$i.$ext", 'dir' => $dir, 'filename' => "$i.$ext", 'ext' => $ext,
                'title' => $song, 'artist' => $artist, 'album' => $title, 'track' => (string) ($i + 1), 'year' => $year, 'genres' => [$genre], 'duration' => $seconds]);
        }

        return $album;
    }

    public function test_table_view_with_album_separators_sorting_and_filters(): void
    {
        $this->album('library', 'ABBA', 'Gold', '1992', [['Waterloo', 'flac', 'Pop', 170], ['Dancing Queen', 'flac', 'Pop', 230]]);
        $this->album('library', 'ABBA', 'Arrival', '1976', [['Money Money Money', 'mp3', 'Disco', 190]]);
        $this->album('library', 'Bravo', 'Hits 1', '2000', [['Zappel', 'mp3', 'Dance', 200]], true);

        $page = Livewire::test(Library::class)->call('showArtist', 'ABBA')
            ->assertSee('Gold')->assertDontSee('Waterloo')                       // Alben-Raster: keine Titel
            ->call('setView', 'table')
            ->assertSeeInOrder(['Arrival', 'Money Money Money', 'Gold', 'Waterloo', 'Dancing Queen'])
            ->assertSee('2 Alben · 3 Titel');

        // Titel sortieren: innerhalb des Albums
        $page->call('sortBy', 'title')->assertSeeInOrder(['Gold', 'Dancing Queen', 'Waterloo'])
            ->call('sortBy', 'title')->assertSeeInOrder(['Gold', 'Waterloo', 'Dancing Queen']);
        // Jahr ordnet die Alben
        $page->call('sortBy', 'year')->call('sortBy', 'year')->assertSeeInOrder(['Gold', 'Arrival']);

        // Filter: Text, Format, Genre – auch das Raster zeigt nur passende Alben
        $page->set('filter', 'queen')->assertSee('Dancing Queen')->assertDontSee('Waterloo')->assertDontSee('Money Money')->assertSee('1 Album · 1 Titel')
            ->call('clearFilters')->set('format', 'mp3')->assertSee('Money Money Money')->assertDontSee('Waterloo')
            ->call('setView', 'albums')->assertSee('Arrival')->assertDontSee('>Gold<', false)
            ->set('format', '')->set('genre', 'Pop')->assertSee('Gold')->assertDontSee('Arrival');

        // Wechsel der Auswahl setzt Format/Genre zurück, Ansicht bleibt
        $page->call('showSampler')->assertSet('genre', '')->assertSet('view', 'albums')->assertSee('Hits 1');

        // Standard links: Alle – alle Alben des Bereichs, auch Sampler
        Livewire::test(Library::class)->assertSet('artist', '')->assertSeeInOrder(['Arrival', 'Gold', 'Hits 1'])
            ->call('setView', 'table')->assertSee('3 Alben · 4 Titel')->assertSee('Zappel');

        // Suche in der Tabelle: nach Album gruppiert
        Livewire::test(Library::class)->call('setView', 'table')->set('q', 'Money')->assertSeeInOrder(['Arrival', 'Money Money Money'])->assertDontSee('Waterloo');
    }

    public function test_blackhole_table_offers_inbox_actions(): void
    {
        $this->album('blackhole', 'Beta', 'Ungehört', '2001', [['Zwei', 'mp3', 'Pop', 100]]);
        Livewire::test(Blackhole::class)->call('setView', 'table')
            ->assertSee('Ungehört')->assertSee('Zwei')->assertSee('Album → In die Inbox')->assertSee('trackToInbox', false);
    }

    public function test_filter_from_youtube(): void
    {
        $this->album('library', 'ABBA', 'Gold', '1992', [['Waterloo', 'mp3', 'Pop', 170], ['Dancing Queen', 'mp3', 'Pop', 230]]);
        Track::where('title', 'Dancing Queen')->update(['source' => 'YouTube']);

        Livewire::test(Library::class)->call('setView', 'table')->assertSee('Waterloo')
            ->toggle('youtube')->assertSee('Dancing Queen')->assertDontSee('Waterloo')->assertSee('1 Album · 1 Titel')->assertSee('Von YouTube')
            ->call('clearFilters')->assertSet('youtube', false)->assertSee('Waterloo');
    }

    public function test_source_tag_is_read_and_survives_filing(): void
    {
        $base = storage_path('framework/testing/music-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'scan_throttle_ms' => 0]);
        $path = $base.'/inbox/YouTube/Einzeltitel/Artist - Song.mp3';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40));
        // wie yt-dlp: Herkunft als TXXX:SOURCE
        (new Process(['python3', '-c', 'import sys; from mutagen.id3 import ID3, TXXX; t = ID3(); t.add(TXXX(encoding=3, desc="SOURCE", text=["YouTube"])); t.save(sys.argv[1])', $path]))->mustRun();
        app(TagTool::class)->write([['path' => $path, 'tags' => ['title' => 'Song', 'artist' => 'Artist', 'comment' => 'LIB']]]);

        app(Scanner::class)->scan('inbox');
        $this->assertTrue(Track::where('path', $path)->firstOrFail()->isFromYouTube(), 'SOURCE bleibt beim Schreiben anderer Tags erhalten');
        File::deleteDirectory($base);
    }

    public function test_filter_by_rating(): void
    {
        $this->album('library', 'ABBA', 'Gold', '1992', [['Waterloo', 'mp3', 'Pop', 170], ['Dancing Queen', 'mp3', 'Pop', 230], ['SOS', 'mp3', 'Pop', 200]]);
        Track::where('title', 'Waterloo')->update(['rating' => 1]);
        Track::where('title', 'Dancing Queen')->update(['rating' => 5]);

        $page = Livewire::test(Library::class)->call('setView', 'table');
        $page->set('rating', '1')->assertSee('Waterloo')->assertDontSee('Dancing Queen')->assertDontSee('SOS')->assertSee('1 Album · 1 Titel');
        $page->set('rating', 'none')->assertSee('SOS')->assertDontSee('Waterloo');
        $page->set('rating', '4+')->assertSee('Dancing Queen')->assertDontSee('Waterloo');
        $page->call('showSampler')->assertSet('rating', '4+')->call('clearFilters')->assertSet('rating', '');
    }

    public function test_genre_filter_matches_whole_genre_and_album_grid_loads_more(): void
    {
        $this->album('library', 'ABBA', 'Gold', '1992', [['Waterloo', 'mp3', 'Pop', 170]]);
        $this->album('library', 'BTS', 'Wings', '2016', [['Blood Sweat', 'mp3', 'K-Pop', 200]]);
        Livewire::test(Library::class)->set('genre', 'Pop')->assertSee('Gold')->assertDontSee('Wings')
            ->assertViewHas('genres', ['K-Pop', 'Pop']);

        for ($i = 1; $i <= Library::PAGE + 3; $i++) {
            Album::create(['location' => 'library', 'dir' => "/m/x/$i", 'title' => sprintf('Album %03d', $i), 'folder_artist' => 'Zed', 'letter' => 'Z', 'category' => 'LIB', 'track_count' => 1]);
        }
        Livewire::test(Library::class)
            ->assertViewHas('albums', fn ($a) => $a->count() === Library::PAGE)->assertSee('Mehr laden')->assertSee('noch 5 Alben')
            ->call('loadMore')->assertViewHas('albums', fn ($a) => $a->count() === Library::PAGE + 5)->assertDontSee('Mehr laden')
            ->set('filter', 'x')->assertSet('shown', Library::PAGE);
    }

    public function test_blackhole_item_on_its_way_to_inbox_is_not_sent_twice(): void
    {
        app(Settings::class)->set(['inbox' => '/m/inbox']);
        $album = $this->album('blackhole', 'Beta', 'Ungehört', '2001', [['Zwei', 'mp3', 'Pop', 100]]);
        $track = Track::sole();
        Operation::create(['type' => 'to_inbox', 'description' => 'unterwegs', 'status' => 'pending', 'total' => 1,
            'payload' => ['actions' => [['type' => 'move', 'path' => $track->path, 'target' => '/m/inbox/x.mp3']], 'options' => []]]);

        Livewire::test(Blackhole::class)->call('setView', 'table')
            ->assertSee('wird verschoben')->assertSeeHtml('wire:poll.3s')
            ->call('trackToInbox', $track->id)->assertSee('Wird bereits in die Inbox verschoben')
            ->call('albumToInbox', $album->id);

        $this->assertSame(1, Operation::count());
    }
}
