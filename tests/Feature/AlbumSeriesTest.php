<?php

namespace Tests\Feature;

use App\Livewire\AlbumList;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\AlbumSeries;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class AlbumSeriesTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        $albums = ['20 Top Hits Aus Den Charts 1/99', '20 Top Hits aus den Charts 2/2000', '20 Top Hits aus den Charts 3/2001',
            'Dream Dance Vol. 1', 'Dream Dance Vol. 2', 'Dream Dance Volume 3', 'Dream Dance - Best Of', 'Gold', 'Hits 2000'];
        foreach ($albums as $i => $album) {
            $this->mp3('inbox/A'.$i.'/01.mp3', ['TIT2' => 'Lied '.$i, 'TPE1' => 'Wer', 'TALB' => $album]);
            $this->mp3('inbox/A'.$i.'/02.mp3', ['TIT2' => 'Lied '.$i.'b', 'TPE1' => 'Wer', 'TALB' => $album]);
        }
        app(Scanner::class)->scan('inbox');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_series_get_one_pattern_with_four_digit_years_and_one_spelling_of_vol(): void
    {
        $series = app(AlbumSeries::class)->series()->keyBy('pattern');

        $this->assertSame(['20 Top Hits aus den Charts {Nr}/{Jahr}', 'Dream Dance Vol. {Nr}'], $series->keys()->all());
        $charts = $series['20 Top Hits aus den Charts {Nr}/{Jahr}'];
        $this->assertSame([['20 Top Hits Aus Den Charts 1/99', '20 Top Hits aus den Charts 1/1999']], array_map(fn ($c) => [$c['from'], $c['to']], $charts['changes']));
        $dream = $series['Dream Dance Vol. {Nr}'];
        $this->assertSame([['Dream Dance Volume 3', 'Dream Dance Vol. 3']], array_map(fn ($c) => [$c['from'], $c['to']], $dream['changes']));
        $this->assertSame([], $dream['others']);   // „Best Of“ ohne Zahl gehört nicht dazu

        $changed = app(AlbumSeries::class)->apply($charts['key']);
        $this->assertSame(2, $changed);
        $this->assertSame(2, Track::where('album', '20 Top Hits aus den Charts 1/1999')->count());
        $this->assertSame('manual', TagChange::where('field', 'album')->first()->source);
        $this->assertSame(1, app(AlbumSeries::class)->count());
    }

    public function test_series_change_only_songs_with_the_old_album_name_and_skip_loose_folders(): void
    {
        $this->mp3('inbox/A5/03.mp3', ['TIT2' => 'Fremd', 'TPE1' => 'Wer', 'TALB' => 'Etwas anderes']);
        $this->mp3('inbox/Kein Album/01.mp3', ['TIT2' => 'K1', 'TPE1' => 'Wer', 'TALB' => 'Dream Dance Volume 4']);
        $this->mp3('inbox/Kein Album/02.mp3', ['TIT2' => 'K2', 'TPE1' => 'Wer', 'TALB' => 'Bravo']);
        app(Scanner::class)->scan('inbox');
        $dream = app(AlbumSeries::class)->series()->firstWhere('pattern', 'Dream Dance Vol. {Nr}');
        $this->assertSame(['Dream Dance Volume 3'], array_column($dream['changes'], 'from'), '„Kein Album“ gehört zu keiner Serie');

        $this->assertSame(2, app(AlbumSeries::class)->apply($dream['key']));
        $this->assertSame('Etwas anderes', Track::where('title', 'Fremd')->value('album'));
        $this->assertSame('Dream Dance Volume 4', Track::where('title', 'K1')->value('album'));
    }

    public function test_page_shows_series_and_takes_or_exempts_them(): void
    {
        $key = app(AlbumSeries::class)->series()->firstWhere('pattern', 'Dream Dance Vol. {Nr}')['key'];

        $page = Livewire::test(AlbumList::class)->set('check', 'serien')
            ->assertSee('Dream Dance Vol. {Nr}')->assertSee('Dream Dance Volume 3')->assertSee('Dream Dance Vol. 3')
            ->call('exemptSeries', $key, true)->assertSee('als „keine Serie“ ausgenommen');
        $this->assertSame(1, app(AlbumSeries::class)->count());

        $page->call('exemptSeries', $key, false)->call('fixSeries', null)->assertSee('Muster übernommen – bei 4 Liedern');
        $this->assertSame(0, app(AlbumSeries::class)->count());
        $this->assertSame(['Dream Dance Vol. 3'], Track::where('dir', 'like', '%/A5')->pluck('album')->unique()->values()->all());

        // alles einheitlich: nur mit „Alle Serien zeigen“ sichtbar – dort lässt sich trotzdem ein Muster festlegen
        $page->assertSee('Alle Serien folgen ihrem Muster')->set('allSeries', true)
            ->assertSee('Dream Dance Vol. {Nr}')->assertSee('alle folgen dem Muster')
            ->call('chooseSeries', $key, 'Dream Dance – Teil {Nr}')->assertSee('Dream Dance – Teil 3')->assertSee('3 weichen ab');
        $this->assertSame(1, app(AlbumSeries::class)->count());
    }

    public function test_vol_is_optional_and_the_pattern_can_be_chosen_or_set(): void
    {
        foreach (['Viva Hits 15', 'Viva Hits 19', 'Viva Hits, Vol. 5', 'Viva Hits, Vol. 8'] as $i => $album) {
            $this->mp3('inbox/V'.$i.'/01.mp3', ['TIT2' => 'Viva '.$i, 'TPE1' => 'Wer', 'TALB' => $album]);
        }
        app(Scanner::class)->scan('inbox');
        $viva = app(AlbumSeries::class)->series()->firstWhere('name', 'Viva Hits');

        // eine Serie, zwei Muster je 2 Alben – vorbelegt eins davon (immer dasselbe)
        $this->assertSame(['Viva Hits {Nr}', 'Viva Hits, Vol. {Nr}'], array_column($viva['patterns'], 'pattern'));
        $this->assertSame(['Viva Hits 5', 'Viva Hits 8'], array_column($viva['changes'], 'to'));

        // das andere wählen – gespeichert, gilt beim nächsten Aufruf weiter
        $page = Livewire::test(AlbumList::class)->set('check', 'serien')
            ->call('chooseSeries', $viva['key'], 'Viva Hits, Vol. {Nr}')
            ->assertSee('Viva Hits, Vol. 15')->assertSee('Viva Hits, Vol. 19');
        $this->assertSame(['Viva Hits, Vol. 15', 'Viva Hits, Vol. 19'], array_column(app(AlbumSeries::class)->series()->firstWhere('key', $viva['key'])['changes'], 'to'));

        // eigenes Muster – falsche Zahl an Platzhaltern wird abgelehnt
        $page->call('chooseSeries', $viva['key'], 'VIVA Hits')->assertSee('Das Muster braucht 1 Platzhalter')
            ->call('chooseSeries', $viva['key'], 'VIVA Hits Vol. {Nr}')->assertSee('(eigenes)')
            ->assertSee('VIVA Hits Vol. 15')->assertSee('VIVA Hits Vol. 5')
            ->call('fixSeries', $viva['key'])->assertSee('bei 4 Liedern');
        $this->assertSame(['VIVA Hits Vol. 15', 'VIVA Hits Vol. 19', 'VIVA Hits Vol. 5', 'VIVA Hits Vol. 8'],
            Track::where('title', 'like', 'Viva %')->orderBy('title')->pluck('album')->all());

        // ein neues Album der Serie: Vorschlag nach dem gespeicherten Muster
        $this->mp3('inbox/V9/01.mp3', ['TIT2' => 'Viva 9', 'TPE1' => 'Wer', 'TALB' => 'Viva Hits 21']);
        app(Scanner::class)->scan('inbox');
        $this->assertSame([['Viva Hits 21', 'VIVA Hits Vol. 21']], array_map(fn ($c) => [$c['from'], $c['to']], app(AlbumSeries::class)->series()->firstWhere('key', $viva['key'])['changes']));
    }
}
