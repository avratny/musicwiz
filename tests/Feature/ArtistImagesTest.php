<?php

namespace Tests\Feature;

use App\Jobs\WriteChanges;
use App\Models\Artist;
use App\Models\Track;
use App\Support\ArtistImages;
use App\Support\ArtistRegistry;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class ArtistImagesTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        $this->dir = $this->base.'/_artists';
        config(['musicwiz.artist_images' => $this->dir]);
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function artist(string $name, ?string $mbid = null): Artist
    {
        $artist = Artist::create(['name' => $name, 'mbid' => $mbid, 'confirmed' => true]);
        app(ArtistRegistry::class)->setImage($artist, $this->jpeg());

        return $artist->fresh();
    }

    public function test_copies_under_musicbrainz_id_and_exact_name_for_navidrome(): void
    {
        $abba = $this->artist('ABBA', 'd87e52c5-bb8d-4da8-b941-9f4928627dc8');
        $nsync = $this->artist('*NSYNC', '603ccc0b-6d4c-4b8e-9e2d-d4c4a5b0f9b0');
        $marrs = $this->artist('M|A|R|R|S');
        $acdc = $this->artist('AC/DC');
        $this->assertSame(['-NSYNC.jpg', 'AC-DC.jpg'], [$nsync->image, $acdc->image]);

        $result = app(ArtistImages::class)->sync();

        $files = collect(scandir($this->dir))->reject(fn ($f) => str_starts_with($f, '.'))->values()->all();
        sort($files);
        $this->assertSame(['*NSYNC.jpg', '-NSYNC.jpg', '603ccc0b-6d4c-4b8e-9e2d-d4c4a5b0f9b0.jpg', 'ABBA.jpg', 'AC-DC.jpg', 'M-A-R-R-S.jpg',
            'M|A|R|R|S.jpg', 'd87e52c5-bb8d-4da8-b941-9f4928627dc8.jpg'], $files);   // AC/DC: „/“ geht in keinem Dateinamen
        $this->assertSame(4, $result['written']);
        $this->assertSame(md5_file($nsync->imagePath()), md5_file($this->dir.'/*NSYNC.jpg'));
        $this->assertSame(0, app(ArtistImages::class)->sync()['written'], 'unverändert: nichts neu schreiben');

        // neues Bild: Kopien ziehen beim nächsten Abgleich nach
        app(ArtistRegistry::class)->setImage($abba, $this->jpeg('anders.jpg', 200));
        $this->assertSame(1, app(ArtistImages::class)->sync()['written']);
        $this->assertSame(md5_file($abba->fresh()->imagePath()), md5_file($this->dir.'/d87e52c5-bb8d-4da8-b941-9f4928627dc8.jpg'));
    }

    public function test_renaming_takes_the_image_along(): void
    {
        $artist = $this->artist("Mark 'Oh");
        $this->assertSame("Mark 'Oh.jpg", $artist->image);

        app(ArtistRegistry::class)->rename($artist, 'Mark ’Oh');

        $this->assertSame('Mark ’Oh.jpg', $artist->fresh()->image);
        $this->assertFileExists($this->dir.'/Mark ’Oh.jpg');
        $this->assertFileDoesNotExist($this->dir."/Mark 'Oh.jpg");
    }

    public function test_writing_lays_down_the_copies(): void
    {
        $this->mp3('inbox/A/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'ABBA']);
        app(Scanner::class)->scan('inbox');
        Artist::where('name', 'ABBA')->update(['mbid' => 'd87e52c5-bb8d-4da8-b941-9f4928627dc8']);
        app(ArtistRegistry::class)->setImage(Artist::where('name', 'ABBA')->sole(), $this->jpeg());
        app(TagEditor::class)->update(Track::sole(), ['year' => '1975']);

        WriteChanges::start();

        $this->assertFileExists($this->dir.'/d87e52c5-bb8d-4da8-b941-9f4928627dc8.jpg');
    }
}
