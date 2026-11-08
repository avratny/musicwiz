<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Livewire\Blackhole;
use App\Livewire\Library;
use App\Models\Album;
use App\Models\Operation;
use App\Models\Track;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/** Bewertungen in den Dateien (MP3: POPM wie MediaMonkey, FLAC: RATING). Braucht python3 mit mutagen (Container). */
class RatingTest extends TestCase
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

    private function mp3(string $path, string $title, int $nr): string
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40));
        app(TagTool::class)->write([['path' => $path, 'tags' => ['title' => $title, 'artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => (string) $nr, 'comment' => 'LIB']]]);

        return $path;
    }

    private function flac(string $path): string
    {
        File::ensureDirectoryExists(dirname($path));
        $info = pack('nn', 4096, 4096)."\0\0\0\0\0\0".pack('J', (44100 << 44) | (1 << 41) | (15 << 36)).str_repeat("\0", 16);
        file_put_contents($path, 'fLaC'."\x80\x00\x00\x22".$info);

        return $path;
    }

    private function read(string $path): array
    {
        return app(TagTool::class)->read([$path])[$path]['tags'];
    }

    public function test_rating_tags_mediamonkey_compatible(): void
    {
        $mp3 = $this->mp3($this->base.'/a.mp3', 'A', 1);
        $flac = $this->flac($this->base.'/b.flac');
        $tool = app(TagTool::class);

        $tool->write([['path' => $mp3, 'tags' => ['rating' => 4]], ['path' => $flac, 'tags' => ['rating' => 3]]]);
        $this->assertSame(4, $this->read($mp3)['rating']);
        $this->assertSame(3, $this->read($flac)['rating']);
        $probe = Process::run(['python3', '-c', 'import sys; from mutagen.id3 import ID3; from mutagen.flac import FLAC; p = ID3(sys.argv[1]).getall("POPM")[0]; print(p.email, p.rating, FLAC(sys.argv[2])["RATING"][0])', $mp3, $flac]);
        $this->assertSame('no@email 196 60', trim($probe->output()), $probe->errorOutput());

        // fremde Werte (z. B. MediaMonkey mit halben Sternen, FLAC 1–5) werden eingeordnet
        Process::run(['python3', '-c', 'import sys; from mutagen.id3 import ID3, POPM; t = ID3(sys.argv[1]); t.delall("POPM"); t.add(POPM(email="Windows Media Player 9 Series", rating=242, count=7)); t.save(sys.argv[1])', $mp3])->throw();
        $this->assertSame(5, $this->read($mp3)['rating']);
        $tool->write([['path' => $mp3, 'tags' => ['rating' => 2]]]);
        $probe = Process::run(['python3', '-c', 'import sys; from mutagen.id3 import ID3; print([(p.email, p.rating, p.count) for p in ID3(sys.argv[1]).getall("POPM")])', $mp3]);
        $this->assertSame("[('no@email', 64, 7)]", trim($probe->output()), 'alte Bewertung ersetzt, Abspielzähler bleibt');

        // 0 = entfernen
        $tool->write([['path' => $mp3, 'tags' => ['rating' => 0]], ['path' => $flac, 'tags' => ['rating' => 0]]]);
        $this->assertSame(0, $this->read($flac)['rating']);
        $this->assertSame(0, $this->read($mp3)['rating']);
    }

    public function test_rate_track_and_album_in_lib_editor_and_player_with_undo(): void
    {
        $dir = $this->base.'/library/artists/A/ABBA/Gold (1992)';
        $a = $this->mp3($dir.'/01 - Waterloo.mp3', 'Waterloo', 1);
        $b = $this->mp3($dir.'/02 - SOS.mp3', 'SOS', 2);
        app(Scanner::class)->scan('library');
        $album = Album::firstOrFail();
        $waterloo = Track::where('path', $a)->firstOrFail();

        // Lib: Titel bewerten
        Livewire::test(Library::class)->call('setView', 'table')->call('showArtist', 'ABBA')
            ->call('rateTrack', $waterloo->id, 4)->assertSee('Bewertung: 4 von 5');
        $this->assertSame(4, $this->read($a)['rating']);
        $this->assertSame(4, Track::where('path', $a)->value('rating'));

        // Album bewerten setzt alle Titel
        Livewire::test(Library::class)->call('rateAlbum', $album->id, 2);
        $this->assertSame([2, 2], [$this->read($a)['rating'], $this->read($b)['rating']]);
        $this->assertSame(['rating'], array_keys(Operation::where('type', 'rating')->latest('id')->first()->entries()->first()->new_tags));

        // Rückgängig: wieder 4 bzw. keine
        OperationRunner::dispatchUndo(Operation::where('type', 'rating')->latest('id')->first());
        $this->assertSame([4, 0], [$this->read($a)['rating'], $this->read($b)['rating']]);

        // Editor: Sterne pro Titel, sofort sichtbar
        $sos = Track::where('path', $b)->firstOrFail();
        Livewire::test(AlbumEditor::class, ['album' => $album->id])->call('rateTrack', $sos->id, 5)
            ->assertSet('rows.1.rating', 5);
        $this->assertSame(5, $this->read($b)['rating']);

        // Player: lesen und setzen; derselbe Wert erneut = entfernen macht der Player selbst (sendet 0)
        $this->getJson(route('rating', $sos))->assertJson(['rating' => 5, 'rateable' => true]);
        $this->postJson(route('rating.set', $sos), ['rating' => 0])->assertOk();
        $this->assertSame(0, $this->read($b)['rating']);
        $this->postJson(route('rating.set', $sos), ['rating' => 9])->assertStatus(422);
    }

    public function test_blackhole_is_read_only(): void
    {
        $path = $this->mp3($this->base.'/blackhole/artists/B/Beta/X (2001)/01.mp3', 'Zwei', 1);
        app(Scanner::class)->scan('blackhole');
        $track = Track::firstOrFail();

        Livewire::test(Blackhole::class)->call('setView', 'table')->assertDontSee('rateTrack', false)->call('rateTrack', $track->id, 3);
        $this->assertSame(0, $this->read($path)['rating']);
        $this->getJson(route('rating', $track))->assertJson(['rateable' => false]);
        $this->postJson(route('rating.set', $track), ['rating' => 3])->assertStatus(422);
    }
}
