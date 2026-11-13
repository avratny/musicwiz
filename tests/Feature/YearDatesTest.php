<?php

namespace Tests\Feature;

use App\Livewire\MiscPage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Scanner;
use App\Support\YearDates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class YearDatesTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TALB' => 'Gold', 'TPE1' => 'ABBA', 'TDRC' => '1992-09-21']);
        $this->mp3('library/ABBA/Gold/02.mp3', ['TIT2' => 'SOS', 'TALB' => 'Gold', 'TPE1' => 'ABBA', 'TDRC' => '1992']);
        $this->flac('inbox/Cher/Believe/01.flac', ['TITLE' => 'Believe', 'ALBUM' => 'Believe', 'ARTIST' => 'Cher', 'DATE' => '1998 (Remaster)']);
        $this->flac('inbox/Ohne/01.flac', ['TITLE' => 'Ohne', 'ALBUM' => 'Ohne', 'DATE' => 'unbekannt']);
        $this->flac('blackhole/X/01.flac', ['TITLE' => 'Schwarz', 'ALBUM' => 'X', 'DATE' => '2001 04 27']);
        foreach (['library', 'inbox', 'blackhole'] as $location) {
            app(Scanner::class)->scan($location);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function year(string $title): ?string
    {
        return Track::where('title', $title)->value('year');
    }

    public function test_lists_albums_and_keeps_only_the_year(): void
    {
        $this->assertSame('1992', YearDates::year('1992-09-21'));
        $this->assertNull(YearDates::year('unbekannt'));

        $page = Livewire::test(MiscPage::class, ['view' => 'jahr'])->set('view', 'jahr')
            ->assertSee('Gold')->assertSee('1992-09-21')->assertSee('Believe')->assertSee('1998 (Remaster)')
            ->assertDontSee('unbekannt')->assertDontSee('2001 04 27');
        $this->assertSame(2, app(YearDates::class)->count());

        $page->call('fixYears', Track::where('title', 'Waterloo')->value('album_hash'))->assertSee('bei 1 Lied');
        $this->assertSame(['1992', '1992', '1998 (Remaster)'], [$this->year('Waterloo'), $this->year('SOS'), $this->year('Believe')]);
        $this->assertSame('manual', TagChange::where('field', 'year')->sole()->source);

        $page->call('fixYears', null);
        $this->assertSame(['1998', 'unbekannt', '2001 04 27'], [$this->year('Believe'), $this->year('Ohne'), $this->year('Schwarz')]);
        $this->assertSame(0, app(YearDates::class)->count());
    }
}
