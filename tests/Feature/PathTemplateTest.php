<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Models\Track;
use App\Support\PathTemplate;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class PathTemplateTest extends TestCase
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

    private function render(string $template, array $tags): string
    {
        $track = new Track(['path' => '/music/library/x/Datei.MP3', 'filename' => 'Datei.MP3', 'location' => 'library'] + $tags);

        return (new PathTemplate)->render($template, (new PathTemplate)->values($track));
    }

    public function test_placeholders_are_filled_and_cleaned(): void
    {
        $artist = PathTemplate::TEMPLATES['template_library'][3];
        $this->assertSame('artists/A/ABBA/Gold (1992)/03 - Dancing Queen.mp3',
            $this->render($artist, ['artist' => 'ABBA', 'album' => 'Gold', 'year' => '1992-09-21', 'track' => 3, 'title' => 'Dancing Queen']));
        $this->assertSame('artists/0-9/2 Unlimited/Kein Album/Get Ready - Mix.mp3',
            $this->render($artist, ['artist' => '2 Unlimited', 'title' => 'Get Ready: Mix?']), 'ohne Album: kein Jahr, keine Nummer samt „ - “');
        $this->assertSame('artists/O/Ölkanne/Ä Album (2001)/01 - Datei.mp3',
            $this->render($artist, ['albumartist' => 'Ölkanne', 'artist' => 'Gast', 'album' => 'Ä/Album', 'year' => '2001', 'track' => '1/12']), 'Album-Interpret zählt, Titel fehlt → Dateiname');
        $this->assertSame('38 Special.mp3', $this->render('{titel}.{ext}', ['title' => '.38 Special']), 'kein unsichtbarer Name');

        $this->assertSame(['1999', '2005', ''], [PathTemplate::albumYear(['1999', '1999', '2005']), PathTemplate::albumYear(['1999', '2005', '2003']), PathTemplate::albumYear([null])]);
    }

    public function test_templates_are_validated_saved_and_shown_with_examples(): void
    {
        $this->mp3('library/S/CD1/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Gast', 'TPE2' => 'Various Artists', 'TALB' => 'Hits', 'TRCK' => '1', 'TPOS' => '1/2', 'TDRC' => '2001']);
        $this->mp3('library/S/CD2/01.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Gast', 'TPE2' => 'Various Artists', 'TALB' => 'Hits', 'TRCK' => '1', 'TPOS' => '2/2', 'TDRC' => '2001']);
        app(Scanner::class)->scan('library');

        $page = Livewire::test(SettingsPage::class)->set('tab', 'ordner')
            ->assertSee('sampler/H/Hits (2001)/1-01 - Gast - Eins.mp3')->assertSee('Keine passenden Lieder');

        $page->set('paths.template_library', '{interpret}/{titel}')->assertSee('muss mit „.{ext}“ enden')
            ->set('paths.template_library', '{kuenstler}/{titel}.{ext}')->assertSee('Unbekannter Platzhalter: {kuenstler}')
            ->set('paths.template_library', '../{titel}.{ext}')->assertSee('ohne „/“ am Anfang')
            ->set('paths.template_library', '{interpret}.{ext}')->assertSee('Ohne {titel} oder {nr}')
            ->call('savePaths')->assertDontSee('Gespeichert');
        $this->assertNull(app(Settings::class)->get('template_library'));

        $page->set('paths.template_library_compilation', 'VA/{album}/{nr} {titel}.{ext}')->assertSee('VA/Hits/1-01 Eins.mp3')
            ->call('resetTemplate', 'template_library')->set('paths.replace_slash', '/')->call('savePaths')->assertSee('nicht erlaubt');
        $page->set('paths.replace_slash', '_')->call('savePaths')->assertSee('Gespeichert');
        $this->assertSame('VA/{album}/{nr} {titel}.{ext}', app(Settings::class)->get('template_library_compilation'));
        $this->assertSame('_', app(Settings::class)->get('replace_slash'));
    }
}
