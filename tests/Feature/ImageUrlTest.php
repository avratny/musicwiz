<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Livewire\Artists;
use App\Models\Album;
use App\Models\Artist;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ImageUrlTest extends TestCase
{
    use RefreshDatabase;

    private static function jpeg(): string
    {
        $img = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($img);

        return ob_get_clean();
    }

    public function test_album_cover_from_url_goes_into_preview(): void
    {
        Http::fake(['example.com/cover.jpg' => Http::response(self::jpeg()), 'example.com/text' => Http::response('kein Bild')]);
        $album = Album::create(['location' => 'inbox', 'dir' => '/tmp/x', 'title' => 'X']);

        Livewire::test(AlbumEditor::class, ['album' => $album])
            ->set('coverUrl', 'ftp://example.com/a.jpg')->call('loadCoverUrl')->assertHasErrors('coverUrl')
            ->set('coverUrl', 'https://example.com/text')->call('loadCoverUrl')->assertHasErrors('coverUrl')
            ->set('coverUrl', 'https://example.com/cover.jpg')->call('loadCoverUrl')->assertHasNoErrors()
            ->assertSet('coverUrl', '')
            ->assertSee('neues Cover');
    }

    public function test_artist_image_from_url(): void
    {
        Http::fake(['example.com/*' => Http::response(self::jpeg())]);
        $dir = storage_path('framework/testing/artists-'.bin2hex(random_bytes(3)));
        app(Settings::class)->set(['artist_images' => $dir]);
        $artist = Artist::create(['name' => 'A-Teens']);

        Livewire::test(Artists::class)->call('select', $artist->id)
            ->set('imageUrl', 'https://example.com/a.jpg')->call('loadImageUrl')->assertHasNoErrors();

        $this->assertSame('A-Teens.jpg', $artist->fresh()->image);
        $this->assertFileExists($dir.'/A-Teens.jpg');

        app(Settings::class)->set(['trash' => $dir.'/trash', 'base' => $dir]);
        Livewire::test(Artists::class)->call('select', $artist->id)->call('removeImage');
        $this->assertNull($artist->fresh()->image);
        $this->assertFileDoesNotExist($dir.'/A-Teens.jpg');
        $this->assertFileExists($dir.'/trash/A-Teens.jpg');
        File::deleteDirectory($dir);
    }
}
