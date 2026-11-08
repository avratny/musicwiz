<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Livewire\Inbox;
use App\Models\Album;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class NextInboxAlbumTest extends TestCase
{
    use RefreshDatabase;

    public function test_after_filing_go_to_next_inbox_album(): void
    {
        $base = storage_path('framework/testing/next-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox_paths' => [$base.'/inbox'], 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        foreach (['ABBA' => 'Gold', 'Cher' => 'Believe', 'Eminem' => 'Encore'] as $artist => $album) {
            $p = "$base/inbox/$artist/$album/01 Song.mp3";
            File::ensureDirectoryExists(dirname($p));
            file_put_contents($p, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $p, 'tags' => ['artist' => $artist, 'albumartist' => $artist, 'title' => 'Song', 'album' => $album, 'track' => '1']]]);
        }
        app(Scanner::class)->scan('inbox');
        $order = Inbox::orderedQuery()->pluck('title')->all();
        $this->assertSame(['Gold', 'Believe', 'Encore'], $order);

        $cher = Album::where('title', 'Believe')->firstOrFail();
        $editor = Livewire::test(AlbumEditor::class, ['album' => $cher])
            ->set('fields.category', 'LIB')->call('preview', true)->call('confirm'); // Queue sync
        $encore = Album::where('location', 'inbox')->where('title', 'Encore')->firstOrFail();
        $editor->call('checkOperation')->assertRedirect($encore->editUrl());

        // letztes Album: zurück zum vorherigen
        $editor2 = Livewire::test(AlbumEditor::class, ['album' => $encore])
            ->set('fields.category', 'LIB')->call('preview', true)->call('confirm');
        $gold = Album::where('location', 'inbox')->where('title', 'Gold')->firstOrFail();
        $editor2->call('checkOperation')->assertRedirect($gold->editUrl());

        // nur Tags speichern: bleibt beim Album
        Livewire::test(AlbumEditor::class, ['album' => $gold])
            ->set('fields.year', '1992')->call('preview', false)->call('confirm')
            ->call('checkOperation')->assertNoRedirect();
        File::deleteDirectory($base);
    }
}
