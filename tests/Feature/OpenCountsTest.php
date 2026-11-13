<?php

namespace Tests\Feature;

use App\Livewire\MiscPage;
use App\Support\BrokenTracks;
use App\Support\OpenCounts;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(0, app(OpenCounts::class)->misc()['kaputt']);
        $this->assertSame(array_sum($misc) - 1, $this->getJson('/activity')->json('counts.misc'));
        Livewire::test(MiscPage::class)->set('view', 'kaputt')->assertViewHas('counts', fn ($c) => $c['kaputt'] === 0);
    }
}
