<?php

namespace Tests\Feature;

use App\Livewire\MiscPage;
use App\Support\BrokenTracks;
use App\Support\OpenCounts;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class OpenCountsTest extends TestCase
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

    public function test_badges_count_only_what_is_still_open_and_follow_changes(): void
    {
        $this->flac('inbox/Rock/01.flac', ['TITLE' => 'Gut', 'ARTIST' => 'A', 'ALBUM' => 'Rock', 'GENRE' => 'Rock/Pop']);
        $this->emptyFlac('inbox/Rock/02.flac', ['TITLE' => 'Leer', 'ARTIST' => 'A', 'ALBUM' => 'Rock', 'GENRE' => 'Rock/Pop']);
        app(Scanner::class)->scan('inbox');

        $misc = app(OpenCounts::class)->misc();
        $this->assertSame(1, $misc['kaputt']);
        $this->assertSame(1, app(OpenCounts::class)->genres()['unmapped'], '„Rock/Pop“ nicht zugeordnet');
        $nav = $this->getJson('/activity')->json('counts');
        $this->assertSame(array_sum($misc), $nav['misc']);
        $this->assertSame(1, $nav['genres']);

        // vorgemerkt zählt nicht mehr – Zähler rechnet nach der Änderung neu (nicht aus dem Zwischenspeicher)
        app(BrokenTracks::class)->trash();
        OpenCounts::touch();   // wie nach jeder Aktion – in derselben Sekunde ändert sich am Datenbank-Stand nichts
        $this->assertSame(0, app(OpenCounts::class)->misc()['kaputt']);
        $this->assertSame(array_sum($misc) - 1, $this->getJson('/activity')->json('counts.misc'));
        Livewire::test(MiscPage::class)->set('view', 'kaputt')->assertViewHas('counts', fn ($c) => $c['kaputt'] === 0);
    }

    public function test_an_action_in_the_page_refreshes_the_badges_at_once(): void
    {
        $this->flac('inbox/Rock/01.flac', ['TITLE' => 'Gut', 'ARTIST' => 'A', 'ALBUM' => 'Rock']);
        $this->emptyFlac('inbox/Rock/02.flac', ['TITLE' => 'Leer', 'ARTIST' => 'A', 'ALBUM' => 'Rock']);
        app(Scanner::class)->scan('inbox');
        $page = Livewire::test(MiscPage::class)->set('view', 'kaputt')->assertViewHas('counts', fn ($c) => $c['kaputt'] === 1);

        // gleiche Sekunde, Anzahl der Lieder gleich: erst die Aktion selbst sorgt fürs Neurechnen
        $page->call('trashBroken', null)->assertViewHas('counts', fn ($c) => $c['kaputt'] === 0);
        $this->assertSame(0, $this->getJson('/activity')->json('counts.misc') - array_sum(array_diff_key(app(OpenCounts::class)->misc(), ['kaputt' => 0])));
    }

    public function test_in_operation_pages_show_the_last_state_and_recount_after_the_response(): void
    {
        config(['musicwiz.counts_in_background' => true]);
        $this->flac('inbox/Rock/01.flac', ['TITLE' => 'Gut', 'ARTIST' => 'A', 'ALBUM' => 'Rock']);
        $this->emptyFlac('inbox/Rock/02.flac', ['TITLE' => 'Leer', 'ARTIST' => 'A', 'ALBUM' => 'Rock']);
        app(Scanner::class)->scan('inbox');
        $this->assertSame(1, app(OpenCounts::class)->misc()['kaputt'], 'noch nie gerechnet: einmal sofort');

        app(BrokenTracks::class)->trash();
        OpenCounts::touch();
        $this->assertSame(1, app(OpenCounts::class)->misc()['kaputt'], 'die Seite wartet nicht – zuerst der letzte Stand');
        $this->assertSame(1, app(OpenCounts::class)->misc()['kaputt'], 'zweiter Aufruf rechnet nicht noch einmal');
        $this->assertCount(1, app(DeferredCallbackCollection::class));

        app(DeferredCallbackCollection::class)->invoke();   // wie nach der Antwort
        $this->assertSame(0, app(OpenCounts::class)->misc()['kaputt']);
        $this->assertCount(0, app(DeferredCallbackCollection::class), 'aktuell – nichts mehr zu rechnen');

        // über die Leiste: Antwort mit dem alten Stand, danach neu gerechnet
        $this->flac('inbox/Rock/03.flac', ['TITLE' => 'Gut', 'ARTIST' => 'A', 'ALBUM' => 'Rock']);
        $this->emptyFlac('inbox/Rock/04.flac', ['TITLE' => 'Leer 2', 'ARTIST' => 'A', 'ALBUM' => 'Rock']);
        app(Scanner::class)->scan('inbox');
        OpenCounts::touch();
        $before = $this->getJson('/activity')->json('counts.misc');
        $after = $this->getJson('/activity')->json('counts.misc');
        config(['musicwiz.counts_in_background' => false]);
        $this->assertNotSame($before, $after);
        $this->assertSame(array_sum(app(OpenCounts::class)->misc()), $after);
    }
}
