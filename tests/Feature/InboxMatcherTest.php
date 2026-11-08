<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Livewire\Inbox;
use App\Models\Album;
use App\Support\InboxMatcher;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class InboxMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_album_and_songs_are_shown(): void
    {
        $base = storage_path('framework/testing/match-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox_paths' => [$base.'/inbox'], 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $put = function (string $rel, array $tags) use ($base) {
            $p = $base.'/'.$rel;
            File::ensureDirectoryExists(dirname($p));
            file_put_contents($p, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $p, 'tags' => $tags]]);
        };
        // Bestand
        $put('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.flac.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'title' => 'Waterloo', 'track' => '1', 'comment' => 'LIB']);
        $put('library/artists/A/ABBA/Kein Album/Fernando.mp3', ['artist' => 'ABBA', 'title' => 'Fernando', 'comment' => 'LIB']);
        // Inbox: gleiches Album anders geschrieben, plus ein Album mit einem schon vorhandenen Lied
        $put('inbox/ABBA/GOLD [Disc 1]/01 Waterloo.mp3', ['artist' => 'ABBA', 'albumartist' => 'Abba', 'album' => 'GOLD [Disc 1]', 'title' => 'Waterloo', 'track' => '1']);
        $put('inbox/ABBA/Arrival/01 Fernando.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Arrival', 'title' => 'Fernando', 'track' => '1']);
        $put('inbox/ABBA/Arrival/02 Dancing Queen.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Arrival', 'title' => 'Dancing Queen', 'track' => '2']);
        app(Scanner::class)->scan('library');
        app(Scanner::class)->scan('inbox');

        $gold = Album::where('location', 'inbox')->where('title', 'like', 'GOLD%')->firstOrFail();
        $arrival = Album::where('location', 'inbox')->where('title', 'Arrival')->firstOrFail();
        $m = app(InboxMatcher::class);
        $this->assertSame('Gold', $m->match($gold)['album']?->title);
        $this->assertNull($m->match($arrival)['album']);
        $this->assertSame([1, 2], [$m->match($arrival)['songs'], $m->match($arrival)['total']]);

        $this->assertTrue($m->match($gold)['duplicate']);
        Livewire::test(Inbox::class)->assertSee('im Bestand')->assertSee('1/2 im Bestand');
        Livewire::test(AlbumEditor::class, ['album' => $gold])->assertSee('Dieses Album gibt es schon im Bestand')->assertSee('im Bestand: Gold · MP3');
        Livewire::test(AlbumEditor::class, ['album' => $arrival])->assertSee('1× ohne Album (ABBA)');
        File::deleteDirectory($base);
    }

    public function test_same_album_with_other_songs_is_only_a_part(): void
    {
        $base = storage_path('framework/testing/match2-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox_paths' => [$base.'/inbox'], 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $put = function (string $rel, array $tags) use ($base) {
            $p = $base.'/'.$rel;
            File::ensureDirectoryExists(dirname($p));
            file_put_contents($p, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $p, 'tags' => $tags + ['compilation' => true, 'album' => 'Formel Eins']]]);
        };
        $put('library/sampler/Formel Eins/2-05 - ATB - Killer.mp3', ['artist' => 'ATB', 'title' => 'Killer', 'disc' => '2', 'disctotal' => '2', 'track' => '5', 'comment' => 'LIB COMPILATION']);
        $put('inbox/VA/Formel Eins, Disc 1/01 Song A.mp3', ['artist' => 'A', 'title' => 'Song A', 'disc' => '1', 'disctotal' => '2', 'track' => '1']);
        $put('inbox/VA/Formel Eins, Disc 1/02 Song B.mp3', ['artist' => 'B', 'title' => 'Song B', 'disc' => '1', 'disctotal' => '2', 'track' => '2']);
        app(Scanner::class)->scan('library');
        app(Scanner::class)->scan('inbox');

        $inbox = Album::where('location', 'inbox')->firstOrFail();
        $r = app(InboxMatcher::class)->match($inbox);
        $this->assertNotNull($r['album']);
        $this->assertFalse($r['duplicate']);
        $this->assertSame([[2], [1]], [$r['album_discs'], $r['inbox_discs']]);
        Livewire::test(Inbox::class)->assertSee('Teil im Bestand')->assertDontSee('>im Bestand<', false);
        Livewire::test(AlbumEditor::class, ['album' => $inbox])->assertSee('Ein Teil dieses Albums liegt schon im Bestand')->assertSee('Dort: CD 2 (1 Lied) – hier: CD 1 (2 Lieder)');
        File::deleteDirectory($base);
    }
}
