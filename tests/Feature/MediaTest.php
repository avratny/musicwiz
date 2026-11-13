<?php

namespace Tests\Feature;

use App\Models\FolderImage;
use App\Models\Track;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_embedded_cover_is_extracted_and_scaled(): void
    {
        $this->mp3('library/A/01.mp3', ['TIT2' => 'Mit Cover', 'APIC' => $this->jpeg()]);
        app(Scanner::class)->scan('library');
        $track = Track::first();

        $response = $this->get('/cover/'.$track->id.'/64')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        [$w, $h] = getimagesize($response->getFile()->getPathname());
        $this->assertSame([64, 64], [$w, $h]);
    }

    public function test_cover_from_folder_image_otherwise_404(): void
    {
        $this->mp3('library/A/01.mp3', ['TIT2' => 'Ordnerbild']);
        $this->jpeg('library/A/folder.jpg', 800);
        $this->mp3('library/B/01.mp3', ['TIT2' => 'Ohne']);
        app(Scanner::class)->scan('library');

        $this->get('/cover/'.Track::where('title', 'Ordnerbild')->value('id').'/300')->assertOk();
        $this->get('/cover/'.Track::where('title', 'Ohne')->value('id').'/300')->assertNotFound();
    }

    public function test_cover_copies_without_reference_are_removed_after_scanning(): void
    {
        $this->mp3('library/A/01.mp3', ['TIT2' => 'Bleibt', 'APIC' => $this->jpeg()]);
        $this->mp3('library/B/01.mp3', ['TIT2' => 'Ändert sich', 'APIC' => $this->jpeg()]);
        $this->mp3('library/C/01.mp3', ['TIT2' => 'Fällt weg', 'APIC' => $this->jpeg()]);
        $this->jpeg('library/D/cover.jpg');
        $this->mp3('library/D/01.mp3', ['TIT2' => 'Ordnerbild']);
        app(Scanner::class)->scan('library');
        $ids = Track::pluck('id', 'title');
        foreach ($ids as $id) {
            $this->get('/cover/'.$id.'/64')->assertOk();
        }
        $this->get('/folder-image/'.FolderImage::value('id').'/300')->assertOk();
        $dir = config('musicwiz.cover_cache');
        file_put_contents($dir.'/artist-999999-abc123-64.jpg', 'x');   // gelöschter Interpret
        touch($dir.'/'.sha1('weg').'.src', time() - 7200);            // abgebrochene Zwischendatei
        $files = fn () => collect(scandir($dir))->reject(fn ($f) => $f[0] === '.')->values();
        $this->assertCount(7, $files());

        // B geändert, C gelöscht → deren Kopien sind überholt
        $this->mp3('library/B/01.mp3', ['TIT2' => 'Ändert sich jetzt', 'APIC' => $this->jpeg('other.jpg', 300)]);
        touch($this->base.'/library/B/01.mp3', time() + 10);
        unlink($this->base.'/library/C/01.mp3');
        app(Scanner::class)->scan('library');

        $left = $files();
        $this->assertCount(3, $left, 'A (Lied), D (Lied über Ordnerbild) und das Ordnerbild selbst');
        $a = Track::where('title', 'Bleibt')->first();
        $this->assertContains(sha1($a->path.'|'.$a->mtime.'|'.$a->size).'-64.jpg', $left->all());
        $this->assertSame(1, $left->filter(fn ($f) => str_starts_with($f, 'folder-'))->count());
    }

    public function test_audio_is_streamed_with_ranges(): void
    {
        $path = $this->mp3('library/A/01.mp3', ['TIT2' => 'Lied']);
        app(Scanner::class)->scan('library');

        $response = $this->get('/audio/'.Track::first()->id, ['Range' => 'bytes=0-99']);

        $response->assertStatus(206)->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertSame(filesize($path), $response->getFile()->getSize());
    }
}
