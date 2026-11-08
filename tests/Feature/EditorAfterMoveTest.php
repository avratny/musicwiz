<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Models\Album;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class EditorAfterMoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_survives_album_moving_away(): void
    {
        $base = storage_path('framework/testing/after-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox' => $base.'/inbox', 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $p = $base.'/inbox/VA/Hits/01 A - Song.mp3';
        File::ensureDirectoryExists(dirname($p));
        file_put_contents($p, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $p, 'tags' => ['artist' => 'A', 'title' => 'Song', 'album' => 'Hits', 'year' => '1998', 'track' => '1']]]);
        app(Scanner::class)->scan('inbox');

        $editor = Livewire::test(AlbumEditor::class, ['album' => Album::firstOrFail()])
            ->set('fields.category', 'LIB COMPILATION')->call('preview', true)->call('confirm');
        // das Album ist umgezogen – weitere Anfragen des offenen Editors dürfen nicht scheitern
        $editor->call('$refresh')->assertOk();
        $editor->set('fields.album', 'Hits')->assertOk();
        $editor->call('checkOperation')->assertRedirect();
        File::deleteDirectory($base);
    }
}
