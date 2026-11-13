<?php

namespace Tests\Feature;

use App\Livewire\AlbumList;
use App\Livewire\SettingsPage;
use App\Models\FolderImage;
use App\Models\Track;
use App\Support\AlbumCovers;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

/** Alben › Unterschiedliche Cover / Cover zu klein: Fingerabdruck und Maße beim Einlesen, Grund und Vorschlag */
class AlbumCoversTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    /** Verlauf als JPEG – Motiv A: links dunkel, rechts hell; Motiv B: umgekehrt (verschiedene Fingerabdrücke) */
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

    private function song(string $rel, string $title, ?string $cover = null): void
    {
        $this->mp3($rel, ['TIT2' => $title, 'TPE1' => 'a-ha', 'TALB' => basename(dirname($rel)), ...($cover ? ['APIC' => $cover] : [])]);
    }

    /** Befunde: Album => [Gründe, Vorschlag] */
    private function found(string $check): array
    {
        $out = [];
        foreach (app(AlbumCovers::class)->albums($check)['albums'] as $a) {
            $out[$a['album']] = [$a['reasons'], $a['suggest']];
        }

        return $out;
    }

    public function test_cover_daten_beim_einlesen(): void
    {
        $a = $this->motif('src/a.jpg', 'A', 600);
        $this->song('inbox/Hits/01.mp3', 'Eins', $a);
        $this->song('inbox/Hits/02.mp3', 'Zwei');
        $this->motif('inbox/Hits/cover.jpg', 'A', 1200);
        app(Scanner::class)->scan('inbox');

        $one = Track::where('title', 'Eins')->first();
        $this->assertSame([600, 600, Scanner::READ_VERSION], [$one->cover_width, $one->cover_height, $one->read_version]);
        $this->assertSame(16, strlen($one->cover_phash));
        $this->assertNull(Track::where('title', 'Zwei')->value('cover_phash'));
        $image = FolderImage::sole();
        $this->assertSame([1200, 1200], [$image->width, $image->height]);
        // gleiches Motiv in anderer Größe: (fast) gleicher Fingerabdruck
        $this->assertLessThanOrEqual(10, AlbumCovers::distance($one->cover_phash, $image->phash));

        // Bild weg: wird beim nächsten Einlesen entfernt
        unlink($this->base.'/inbox/Hits/cover.jpg');
        app(Scanner::class)->scan('inbox');
        $this->assertSame(0, FolderImage::count());
    }

    public function test_aelterer_stand_wird_einmal_neu_gelesen(): void
    {
        $this->song('inbox/Hits/01.mp3', 'Eins', $this->motif('src/a.jpg', 'A', 600));
        app(Scanner::class)->scan('inbox');
        Track::query()->update(['read_version' => 1, 'cover_phash' => null, 'cover_width' => null]);

        $this->assertSame(1, app(AlbumCovers::class)->unread());
        Livewire::test(AlbumList::class, ['check' => 'cover-klein'])->assertSee('1 Lied hat noch keine Cover-Daten');

        $stats = app(Scanner::class)->scan('inbox');
        $this->assertSame(1, $stats['read']);
        $this->assertSame([600, Scanner::READ_VERSION], [Track::value('cover_width'), Track::value('read_version')]);
        $this->assertSame(0, app(Scanner::class)->scan('inbox')['read']);
    }

    public function test_unterschiedliche_cover_mit_grund_und_vorschlag(): void
    {
        $a = $this->motif('src/a.jpg', 'A', 600);
        $b = $this->motif('src/b.jpg', 'B', 600);
        // zwei Motive eingebettet, bei einem Lied fehlt es
        $this->song('inbox/Hits/01.mp3', 'Eins', $a);
        $this->song('inbox/Hits/02.mp3', 'Zwei', $a);
        $this->song('inbox/Hits/03.mp3', 'Drei', $b);
        $this->song('inbox/Hits/04.mp3', 'Vier');
        // Bild im Ordner zeigt ein anderes Motiv als die eingebetteten
        $this->song('inbox/Gold/01.mp3', 'Eins', $a);
        $this->motif('inbox/Gold/cover.jpg', 'B', 1000);
        // gleiches Motiv, nur größer im Ordner: einheitlich
        $this->song('inbox/Best/01.mp3', 'Eins', $a);
        $this->motif('inbox/Best/folder.jpg', 'A', 1000);
        app(Scanner::class)->scan('inbox');

        $found = $this->found('different');
        $this->assertSame(['Gold', 'Hits'], array_keys($found));
        $this->assertSame(['Kein eingebettetes Cover bei 1 Lied von 4.', 'Die eingebetteten Cover zeigen 2 verschiedene Motive.'], $found['Hits'][0]);
        $this->assertSame('eingebettetes Cover (600×600 px) in alle Lieder einbetten und als Bild im Ordner verwenden', $found['Hits'][1]);
        $this->assertSame(['Das Bild im Ordner („cover.jpg“) zeigt ein anderes Motiv als die eingebetteten Cover – Navidrome zeigt das Bild im Ordner.'], $found['Gold'][0]);

        Livewire::test(AlbumList::class, ['check' => 'cover-verschieden'])->assertSee('2 verschiedene Motive')->assertSee('Motiv 2')
            ->assertSee('Motiv mit den meisten Liedern (2 von 4)')->assertViewHas('counts', fn ($c) => $c['cover-verschieden'] === 2);
    }

    public function test_cover_zu_klein_nach_einstellung(): void
    {
        // nur klein eingebettet: kein Vorschlag
        $this->song('inbox/Klein/01.mp3', 'Eins', $this->motif('src/a300.jpg', 'A', 300));
        // Bild im Ordner klein, eingebettet groß (gleiches Motiv): das große nehmen
        $this->song('inbox/Gross/01.mp3', 'Eins', $this->motif('src/a800.jpg', 'A', 800));
        $this->motif('inbox/Gross/cover.jpg', 'A', 300);
        // groß genug
        $this->song('inbox/Gut/01.mp3', 'Eins', $this->motif('src/a600.jpg', 'A', 600));
        app(Scanner::class)->scan('inbox');

        $found = $this->found('small');
        $this->assertSame(['Gross', 'Klein'], array_keys($found));
        $this->assertSame([['Bild im Ordner („cover.jpg“, 300×300 px) ist kleiner als 500 px.'], 'eingebettetes Cover (800×800 px) nehmen'], $found['Gross']);
        $this->assertSame([['Eingebettetes Cover (300×300 px) ist kleiner als 500 px – bei 1 Lied.'], null], $found['Klein']);
        Livewire::test(AlbumList::class, ['check' => 'cover-klein'])->assertSee('größeres Cover suchen');

        app(Settings::class)->set(['cover_min_px' => 250]);
        $this->assertSame([], $this->found('small'));
    }

    public function test_mindestgroesse_unter_sonstiges(): void
    {
        $this->get('/einstellungen?tab=sonstiges')->assertOk()->assertSee('Mindestgröße Cover');
        Livewire::test(SettingsPage::class, ['tab' => 'sonstiges'])->assertSet('other.cover_min_px', '500')
            ->set('other.cover_min_px', '10')->call('saveOther')->assertSee('Ganze Zahl von 50 bis 5000.')
            ->set('other.cover_min_px', '600')->call('saveOther')->assertSee('Gespeichert.');
        $this->assertSame(600, app(Settings::class)->other('cover_min_px'));
    }
}
