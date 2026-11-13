<?php

namespace Tests\Feature;

use App\Livewire\AlbumList;
use App\Models\Track;
use App\Support\SamplerFlags;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class SamplerFlagsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        // Sampler ohne Kennzeichnung
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Eins', 'TALB' => 'Hits', 'TPE1' => 'ABBA']);
        $this->mp3('inbox/Hits/02.mp3', ['TIT2' => 'Zwei', 'TALB' => 'Hits', 'TPE1' => 'Cher']);
        // Interpreten-Album mit Flag und Various Artists (Gast zählt nicht)
        $this->mp3('library/Cher/Believe/01.mp3', ['TIT2' => 'Believe', 'TALB' => 'Believe', 'TPE1' => 'Cher', 'TPE2' => 'Various Artists', 'TCMP' => '1']);
        $this->mp3('library/Cher/Believe/02.mp3', ['TIT2' => 'Duett', 'TALB' => 'Believe', 'TPE1' => 'Cher feat. Eros Ramazzotti', 'TPE2' => 'Various Artists', 'TCMP' => '1']);
        // richtig: Sampler gekennzeichnet, Interpreten-Album ohne; einzelnes Lied aus einem Sampler bleibt außen vor
        $this->mp3('library/Bravo/01.mp3', ['TIT2' => 'B1', 'TALB' => 'Bravo', 'TPE1' => 'ABBA', 'TPE2' => 'Various Artists', 'TCMP' => '1']);
        $this->mp3('library/Bravo/02.mp3', ['TIT2' => 'B2', 'TALB' => 'Bravo', 'TPE1' => 'Cher', 'TPE2' => 'Various Artists', 'TCMP' => '1']);
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'G1', 'TALB' => 'Gold', 'TPE1' => 'ABBA']);
        $this->mp3('library/ABBA/Gold/02.mp3', ['TIT2' => 'G2', 'TALB' => 'Gold', 'TPE1' => 'ABBA']);
        $this->mp3('library/ABBA/Gold/03.mp3', ['TIT2' => 'G3', 'TALB' => 'Gold', 'TPE1' => 'ABBA & Friends']); // offen: ABBA auf den meisten
        // eigener Album-Interpret auf den meisten Liedern, sonst nur offene Kombis: kein Sampler
        $this->mp3('library/Stevie/01.mp3', ['TIT2' => 'S1', 'TALB' => 'Woman in Red', 'TPE1' => 'Stevie Wonder', 'TPE2' => 'Stevie Wonder']);
        $this->mp3('library/Stevie/02.mp3', ['TIT2' => 'S2', 'TALB' => 'Woman in Red', 'TPE1' => 'Stevie Wonder & Dionne Warwick', 'TPE2' => 'Stevie Wonder']);
        $this->mp3('library/Stevie/03.mp3', ['TIT2' => 'S3', 'TALB' => 'Woman in Red', 'TPE1' => 'Stevie Wonder & Ben Bridges', 'TPE2' => 'Stevie Wonder']);
        $this->mp3('library/Santana/01.mp3', ['TIT2' => 'Maria 1', 'TALB' => 'Maria Maria', 'TPE1' => 'Santana', 'TPE2' => 'Santana & The Product G&B']);
        $this->mp3('library/Santana/02.mp3', ['TIT2' => 'Maria 2', 'TALB' => 'Maria Maria', 'TPE1' => 'Santana & The Product G&B', 'TPE2' => 'Santana & The Product G&B']);
        $this->mp3('inbox/Einzeln/01.mp3', ['TIT2' => 'Einzeln', 'TALB' => 'Top Hits', 'TPE1' => 'Cher', 'TPE2' => 'Various Artists', 'TCMP' => '1']);
        // Blackhole wird ignoriert
        $this->mp3('blackhole/Mix/01.mp3', ['TIT2' => 'M1', 'TALB' => 'Mix', 'TPE1' => 'ABBA']);
        $this->mp3('blackhole/Mix/02.mp3', ['TIT2' => 'M2', 'TALB' => 'Mix', 'TPE1' => 'Cher']);
        foreach (['inbox', 'library', 'blackhole'] as $location) {
            app(Scanner::class)->scan($location);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_a_no_album_folder_is_no_sampler(): void
    {
        $this->mp3('inbox/Kein Album/01.mp3', ['TIT2' => 'K1', 'TALB' => 'Single A', 'TPE1' => 'ABBA']);
        $this->mp3('inbox/Kein Album/02.mp3', ['TIT2' => 'K2', 'TPE1' => 'Cher']);
        $this->mp3('inbox/Kein Album/03.mp3', ['TIT2' => 'K3', 'TPE1' => 'Madonna']);
        $this->mp3('inbox/Mix/01.mp3', ['TIT2' => 'X1', 'TALB' => 'Single X', 'TPE1' => 'ABBA']);
        $this->mp3('inbox/Mix/02.mp3', ['TIT2' => 'X2', 'TALB' => 'Single Y', 'TPE1' => 'Cher']);
        app(Scanner::class)->scan('inbox');

        $hashes = Track::whereIn('title', ['K1', 'X1'])->pluck('album_hash');
        $found = app(SamplerFlags::class)->findings();
        foreach ($hashes as $hash) {
            $this->assertFalse($found->has($hash), 'Einzeltitel-Ordner sind keine Sampler');
        }
    }

    private function tags(string $title): array
    {
        $t = Track::where('title', $title)->firstOrFail();

        return [$t->compilation, $t->albumartist];
    }

    public function test_finds_both_directions_and_fixes_them(): void
    {
        $kinds = app(SamplerFlags::class)->findings()->mapWithKeys(fn ($f, $hash) => [Track::where('album_hash', $hash)->value('album') => $f['kind']])->all();
        $this->assertEqualsCanonicalizing(['Hits' => 'missing', 'Believe' => 'wrong'], $kinds);

        $page = Livewire::test(AlbumList::class)->set('check', 'sampler')
            ->assertSee('Sampler ohne Kennzeichnung')->assertSee('Kein Sampler, aber gekennzeichnet')->assertSee('alle von Cher')
            ->assertDontSee('Bravo')->assertDontSee('Gold');

        $page->call('fixSampler', Track::where('title', 'Eins')->value('album_hash'))->assertSee('bei 2 Liedern');
        $this->assertSame([true, 'Various Artists'], $this->tags('Zwei'));
        $this->assertSame([true, 'Various Artists'], $this->tags('Believe'), 'anderes Album unverändert');

        $page->call('fixSampler', null);
        $this->assertSame([false, 'Cher'], $this->tags('Believe'));
        $this->assertSame([false, 'Cher'], $this->tags('Duett'));
        $this->assertSame([false, null], $this->tags('M1'));
        $this->assertSame(0, app(SamplerFlags::class)->count());
    }
}
