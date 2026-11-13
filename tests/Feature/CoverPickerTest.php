<?php

namespace Tests\Feature;

use App\Livewire\AlbumList;
use App\Livewire\ChangeLog;
use App\Livewire\CoverPicker;
use App\Livewire\TrackTable;
use App\Models\CoverImage;
use App\Models\FolderImage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\AlbumCovers;
use App\Support\Covers;
use App\Support\CoverStore;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

/** Cover setzen: Vorschläge aus Album und Netz, per URL – nur in der Datenbank, zurücksetzbar, aufgeräumt */
class CoverPickerTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        Cache::flush();
        config(['musicwiz.lookup_rates' => array_fill_keys(['musicbrainz', 'coverartarchive', 'deezer', 'itunes'], 1_000_000)]);
        // kleines eingebettetes Cover (Motiv A, 300 px) – „Cover zu klein“
        $small = $this->motif('src/a300.jpg', 'A', 300);
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Hunting High and Low', 'APIC' => $small]);
        $this->mp3('inbox/Hits/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Hunting High and Low', 'APIC' => $small]);
        $this->motif('inbox/Hits/folder.jpg', 'A', 400);
        app(Scanner::class)->scan('inbox');
        $this->hash = Track::value('album_hash');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    /** Verlauf als JPEG – Motiv A: links dunkel, rechts hell; Motiv B: umgekehrt */
    private function motif(string $rel, string $motif, int $size): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        $img = imagecreatetruecolor($size, $size);
        for ($x = 0; $x < $size; $x++) {
            $v = (int) round(255 * $x / max(1, $size - 1));
            $v = $motif === 'A' ? $v : 255 - $v;
            imagefilledrectangle($img, $x, 0, $x, $size - 1, imagecolorallocate($img, $v, $v, $v));
        }
        imagejpeg($img, $path, 90);

        return $path;
    }

    /** Dienste: nichts gefunden – und nie echt ins Netz */
    private function quietServices(array $more = []): void
    {
        Http::preventStrayRequests();
        // eigene Antworten zuerst (gleiche Muster ersetzen die leeren)
        Http::fake($more + [
            'musicbrainz.org/*' => Http::response(['releases' => []]),
            'api.deezer.com/*' => Http::response(['data' => []]),
            'itunes.apple.com/*' => Http::response(['results' => []]),
        ]);
    }

    public function test_cover_per_url_is_a_change_in_the_database_and_fixes_too_small(): void
    {
        $this->quietServices(['example.com/big.jpg' => Http::response(file_get_contents($this->motif('src/b1000.jpg', 'B', 1000)), 200, ['Content-Type' => 'image/jpeg'])]);
        $this->assertCount(1, app(AlbumCovers::class)->albums('small')['albums']);
        $before = Covers::url(Track::first());

        Livewire::test(CoverPicker::class)->call('show', $this->hash)->assertSet('open', true)->assertSee('Cover setzen')->assertSee('Nichts gefunden')
            ->set('url', 'https://example.com/big.jpg')->call('useUrl')
            ->assertSet('open', false)->assertDispatched('cover-set');

        $image = CoverImage::sole();
        $this->assertSame([1000, 1000, 'https://example.com/big.jpg'], [$image->width, $image->height, $image->source]);
        $this->assertFileExists(CoverStore::path($image));
        $this->assertSame([$image->hash], Track::pluck('cover')->unique()->values()->all());
        $this->assertSame(2, TagChange::where('field', 'cover')->where('reason', CoverStore::REASON)->where('source', 'manual')->count());
        // gilt für die Prüfungen: groß genug, und das alte Bild im Ordner wird beim Schreiben ersetzt
        $this->assertSame([], app(AlbumCovers::class)->albums('small')['albums']);
        $this->assertSame([], app(AlbumCovers::class)->albums('different')['albums']);

        // Anzeige: neues Cover (neue Adresse für den Browser), aus der Datei weiterhin abrufbar
        $track = Track::first();
        $this->assertNotSame($before, Covers::url($track));
        $new = $this->get(Covers::url($track, 300))->assertOk();
        $this->assertStringContainsString('max-age='.Covers::BROWSER_CACHE, $new->headers->get('Cache-Control'));
        [$w] = getimagesize($new->getFile()->getPathname());
        $this->assertSame(300, $w);
        $this->assertNotSame(md5_file($new->getFile()->getPathname()), md5_file($this->get(Covers::url($track, 300, true))->getFile()->getPathname()));
        $this->assertStringContainsString('no-cache', $this->get('/cover/'.$track->id.'/64')->headers->get('Cache-Control'), 'ohne Version: nachfragen');
    }

    public function test_reverting_restores_the_file_cover_and_scanning_removes_the_image(): void
    {
        $this->quietServices(['example.com/big.jpg' => Http::response(file_get_contents($this->motif('src/b1000.jpg', 'B', 1000)))]);
        app(CoverStore::class)->apply($this->hash, app(CoverStore::class)->fromUrl('https://example.com/big.jpg'));
        $path = CoverStore::path(CoverImage::sole());
        $this->get('/aenderungen')->assertOk()->assertSee('aus der Datei');

        Livewire::test(ChangeLog::class)->call('revertAll');
        $this->assertSame([null], Track::pluck('cover')->unique()->values()->all());
        $this->assertCount(1, app(AlbumCovers::class)->albums('small')['albums'], 'wieder zu klein');

        app(Scanner::class)->scan('inbox');
        $this->assertSame(0, CoverImage::count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_suggestions_from_the_album_and_the_net(): void
    {
        $big = file_get_contents($this->motif('src/b1200.jpg', 'B', 1200));
        $this->quietServices([
            'musicbrainz.org/ws/2/release*' => Http::response(['releases' => [['id' => 'rel-1', 'title' => 'Hunting High and Low', 'date' => '1985-06-01']]]),
            'coverartarchive.org/release/rel-1' => Http::response(['images' => [['front' => true, 'image' => 'https://ia.example/full.jpg',
                'thumbnails' => ['500' => 'https://ia.example/500.jpg', '1200' => 'https://ia.example/1200.jpg']], ['front' => false, 'image' => 'https://ia.example/back.jpg']]]),
            'api.deezer.com/*' => Http::response(['data' => [['title' => 'Hunting High and Low', 'artist' => ['name' => 'a-ha'],
                'cover_medium' => 'https://dz.example/250.jpg', 'cover_xl' => 'https://dz.example/1000.jpg']]]),
            'itunes.apple.com/*' => Http::response(['results' => [['collectionName' => 'Hunting High and Low (Remastered)', 'artistName' => 'a-ha',
                'releaseDate' => '1985-06-01T07:00:00Z', 'artworkUrl100' => 'https://is.example/x/100x100bb.jpg']]]),
            'ia.example/1200.jpg' => Http::response($big),
        ]);

        // in der Karte („Cover zu klein“): Fassungen aus dem Album sofort, im Netz erst auf Knopfdruck
        $picker = Livewire::test(CoverPicker::class, ['album' => $this->hash, 'inline' => true])
            ->assertSee('eingebettet · 2 Lieder')->assertSee('folder.jpg')->assertSee('Vorschläge suchen')->assertDontSee('Deezer · 1000');
        Http::assertNothingSent();

        $picker->call('search')->assertSee('Cover Art Archive · bis 1200 px')->assertSee('Deezer · 1000×1000 px')->assertSee('iTunes · bis 1200 px')
            ->assertSee('1985');
        $this->assertSame(['https://ia.example/1200.jpg', 'https://dz.example/1000.jpg', 'https://is.example/x/1200x1200bb.jpg'], array_column($picker->get('suggestions'), 'url'));

        $picker->call('useUrl', 'https://ia.example/1200.jpg')->assertDispatched('cover-set');
        $this->assertSame([1200], CoverImage::pluck('width')->all());
    }

    public function test_versions_from_the_album_and_errors(): void
    {
        $this->quietServices(['example.com/page' => Http::response('<html>kein Bild</html>', 200, ['Content-Type' => 'text/html'])]);
        $picker = Livewire::test(CoverPicker::class, ['album' => $this->hash, 'inline' => true]);

        $picker->set('url', 'https://example.com/page')->call('useUrl')->assertSet('error', 'Das ist kein Bild.');
        $picker->set('url', 'ftp://example.com/x.jpg')->call('useUrl')->assertSee('Bitte eine Adresse mit http:// oder https:// angeben.');
        $this->assertSame(0, TagChange::count());

        // Bild im Ordner übernehmen; die Seite meldet es
        $picker->call('useFolder', FolderImage::value('id'))->assertDispatched('cover-set');
        $this->assertSame(['Bild im Ordner „folder.jpg“', 400], [CoverImage::value('source'), CoverImage::value('width')]);
        Livewire::test(AlbumList::class, ['check' => 'cover-klein'])->dispatch('cover-set', album: $this->hash, message: 'Cover gesetzt (400×400 px)')
            ->assertSee('Cover gesetzt (400×400 px)');

        // eingebettete Fassung (beide Lieder haben dasselbe Bild): dazu das Bild im Ordner – zusammen zwei
        $picker->call('useTrack', Track::where('filename', '01.mp3')->value('id'));
        $picker->call('useTrack', Track::where('filename', '02.mp3')->value('id'));
        $this->assertSame(2, CoverImage::count(), 'gleiches Bild nur einmal gespeichert');
        $this->assertSame('eingebettet in „01.mp3“', CoverImage::where('width', 300)->value('source'));
    }

    public function test_too_small_shows_only_bigger_suggestions_and_rejects_small_images(): void
    {
        $this->quietServices([
            'api.deezer.com/*' => Http::response(['data' => [['title' => 'Hunting High and Low', 'artist' => ['name' => 'a-ha'],
                'cover_medium' => 'https://dz.example/250.jpg', 'cover_xl' => 'https://dz.example/1000.jpg']]]),
            'itunes.apple.com/*' => Http::response(['results' => [['collectionName' => 'Hunting High and Low', 'artistName' => 'a-ha',
                'artworkUrl100' => 'https://is.example/x/100x100bb.jpg']]]),
            // „bis 1200 px“ – tatsächlich nur 400
            'is.example/*' => Http::response(file_get_contents($this->motif('src/b400.jpg', 'B', 400))),
        ]);

        // eingebettet (300) und Bild im Ordner (400) lösen das Problem nicht: ausgeblendet
        $picker = Livewire::test(CoverPicker::class, ['album' => $this->hash, 'inline' => true, 'min' => 500])
            ->assertDontSee('folder.jpg')->assertDontSee('eingebettet · 2 Lieder')->assertSee('Nur Bilder ab 500 px – 2 kleinere ausgeblendet.')
            ->assertDontSee('@if', false)->assertDontSee('@endif', false);
        $picker->call('search')->assertSee('Deezer · 1000×1000 px')->assertSee('iTunes · bis 1200 px');

        $picker->call('useUrl', 'https://is.example/x/1200x1200bb.jpg')
            ->assertSet('error', 'Das Bild hat nur 400×400 px – kleiner als 500 px, nicht gesetzt.')->assertNotDispatched('cover-set');
        $this->assertSame(0, TagChange::count());

        // ohne Mindestgröße (Modal aus „Alle“): alles sichtbar
        Livewire::test(CoverPicker::class, ['album' => $this->hash, 'inline' => true])->assertSee('folder.jpg')->assertSee('eingebettet · 2 Lieder');
    }

    public function test_click_on_a_cover_opens_the_picker_for_the_album_of_the_track(): void
    {
        $track = Track::first();
        $this->get('/inbox')->assertOk()->assertSee("open-cover-picker', { album: '".$this->hash."' }", false)->assertSee('Cover ändern');
        Livewire::test(TrackTable::class, ['slug' => 'inbox'])->set('grouped', false)
            ->assertSee("open-cover-picker', { track: ".$track->id.' }', false);

        Livewire::test(CoverPicker::class)->dispatch('open-cover-picker', track: $track->id)
            ->assertSet('open', true)->assertSet('album', $this->hash)->assertSee('Hunting High and Low')->assertSee('Bild hochladen');

        // Blackhole und Papierkorb: kein Cover setzen
        $track->update(['location' => 'trash']);
        Track::whereKeyNot($track->id)->update(['location' => 'trash']);
        Livewire::test(CoverPicker::class)->dispatch('open-cover-picker', track: $track->id)->assertSet('open', false);
        $this->get('/papierkorb')->assertOk()->assertDontSee("open-cover-picker', { album", false);
    }

    public function test_single_tracks_get_their_own_cover_and_category_per_row(): void
    {
        $this->quietServices([]);
        $this->mp3('inbox/a-ha/Kein Album/Take On Me.mp3', ['TIT2' => 'Take On Me', 'TPE1' => 'a-ha', 'TDRC' => '1984']);
        $this->mp3('inbox/a-ha/Kein Album/The Sun Always Shines.mp3', ['TIT2' => 'The Sun Always Shines on TV', 'TPE1' => 'a-ha', 'TDRC' => '1985']);
        app(Scanner::class)->scan('inbox');
        [$one, $two] = [Track::where('title', 'Take On Me')->sole(), Track::where('title', 'The Sun Always Shines on TV')->sole()];

        // Kopf ohne gemeinsames Cover, Jahr und Bewertung; Cover und Kategorie je Zeile
        Livewire::test(TrackTable::class, ['slug' => 'inbox'])
            ->assertSee('Einzeltitel')->assertDontSee("open-cover-picker', { album: '".$one->album_hash."' }", false)
            ->assertSee("open-cover-picker', { track: ".$one->id.' }', false)->assertSee('Cover dieses Lieds ändern')
            ->assertDontSee('1984 · ')->assertSee('für alle Lieder – auch je Lied wählbar')
            // je Zeile nur Lib | Blackhole – Sampler ja/nein der Kategorie bleibt
            ->assertSee('setCategory(['.$one->id.'], &quot;BLACKHOLE&quot;)', false);

        Livewire::test(CoverPicker::class)->dispatch('open-cover-picker', track: $one->id)
            ->assertSet('track', $one->id)->assertSee('Nur dieses Lied: Take On Me')
            ->set('upload', UploadedFile::fake()->image('single.png', 600, 600))->assertSet('open', false);
        $this->assertNotNull($one->refresh()->cover);
        $this->assertNull($two->refresh()->cover);

        // Klick auf ein Album-Cover: weiterhin das ganze Album
        Livewire::test(CoverPicker::class)->dispatch('open-cover-picker', track: Track::where('title', 'Eins')->value('id'))->assertSet('track', null);
    }

    public function test_uploaded_image_is_set_for_the_album(): void
    {
        $this->quietServices([]);
        Livewire::test(CoverPicker::class)->call('show', $this->hash)
            ->set('upload', UploadedFile::fake()->image('scan.png', 800, 800))
            ->assertSet('open', false)->assertSet('upload', null)->assertDispatched('cover-set');

        $image = CoverImage::sole();
        $this->assertSame([800, 800, 'png', 'hochgeladen: „scan.png“'], [$image->width, $image->height, $image->ext, $image->source]);
        $this->assertSame([$image->hash], Track::pluck('cover')->unique()->values()->all());

        // große Scans (über die 12 MB von Livewire hinaus) bis 50 MB
        $this->assertContains('max:51200', config('livewire.temporary_file_upload.rules'));
        $big = UploadedFile::fake()->image('gross.png', 800, 800)->size(20 * 1024);
        Livewire::test(CoverPicker::class)->call('show', $this->hash)->set('upload', $big)->assertHasNoErrors();

        // kein Bild: Meldung, nichts gesetzt
        Livewire::test(CoverPicker::class)->call('show', $this->hash)
            ->set('upload', UploadedFile::fake()->createWithContent('notiz.jpg', 'kein Bild'))
            ->assertSet('open', true)->assertSet('error', 'Das ist kein Bild.');
        $this->assertSame(1, CoverImage::count());
    }
}
