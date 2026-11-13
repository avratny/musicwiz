<?php

namespace Tests\Feature;

use App\Livewire\MiscPage;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\GenreChecks;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class GenreChecksTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        // ABBA: Pop auf den Alben – „Rocky“ weicht ab, „Voulez“ hat ein Lied ohne Genre, „Mixed“ ist uneinheitlich
        foreach (['Gold' => ['Pop', 'Pop'], 'Arrival' => ['Pop', 'Pop'], 'Rocky' => ['Trance', 'Trance'], 'Voulez' => ['Pop', ''], 'Mixed' => ['Pop', 'Pop', 'Synthpop']] as $album => $genres) {
            foreach ($genres as $i => $genre) {
                $this->mp3('library/ABBA/'.$album.'/0'.($i + 1).'.mp3', ['TIT2' => $album.' '.($i + 1), 'TALB' => $album, 'TPE1' => 'ABBA'] + ($genre !== '' ? ['TCON' => $genre] : []));
            }
        }
        // Sampler: je Lied am eigenen Interpreten gemessen
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Hit ABBA', 'TALB' => 'Hits', 'TPE1' => 'ABBA', 'TCON' => 'Trance']);
        $this->mp3('inbox/Hits/02.mp3', ['TIT2' => 'Hit Cher', 'TALB' => 'Hits', 'TPE1' => 'Cher feat. Madonna', 'TCON' => 'Pop']);
        $this->mp3('inbox/Hits/03.mp3', ['TIT2' => 'Hit Cher leer', 'TALB' => 'Hits', 'TPE1' => 'Cher']);
        $this->mp3('inbox/Hits/04.mp3', ['TIT2' => 'Hit Madonna', 'TALB' => 'Hits', 'TPE1' => 'Madonna', 'TCON' => 'Pop']);
        // Einzeltitel
        $this->mp3('inbox/Einzeln/01.mp3', ['TIT2' => 'Einzeln', 'TALB' => 'Single', 'TPE1' => 'ABBA', 'TCON' => 'Trance']);
        foreach (['library', 'inbox'] as $location) {
            app(Scanner::class)->scan($location);
        }
        // Cher: manuell Rock (schlägt alles andere)
        DB::table('artist_genre')->insert(['artist_id' => Artist::where('name', 'Cher')->value('id'), 'genre_id' => Genre::whereNull('parent_id')->where('name', 'Rock')->value('id'), 'source' => 'manual', 'weight' => 1]);
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function genre(string $title): ?string
    {
        return Track::where('title', $title)->value('genre');
    }

    private function hash(string $album): string
    {
        return Track::where('album', $album)->value('album_hash');
    }

    public function test_finds_missing_deviating_and_mixed_genres(): void
    {
        $checks = app(GenreChecks::class);

        $missing = $checks->findings('missing');
        $this->assertSame(['ABBA/Voulez', 'Hits'], $missing->pluck('dir')->values()->all());
        $voulez = $missing[$this->hash('Voulez')];
        $this->assertSame('album', $voulez['kind']);
        $this->assertSame(['Pop', 'wie 1 anderes Lied im Album'], [$voulez['suggestions'][0]['value'], $voulez['suggestions'][0]['reason']]);
        $hits = $missing[$this->hash('Hits')];
        $this->assertSame('sampler', $hits['kind']);
        $this->assertSame(['Hit Cher leer'], array_column($hits['items'], 'title'));
        $this->assertSame(['Rock', 'manuell zugeordnet'], [$hits['items'][0]['suggestions'][0]['value'], $hits['items'][0]['suggestions'][0]['reason']]);

        // ABBA hat durch „Rocky“ 20 % Electronic: bei 15 % passt Trance außerhalb von „Rocky“ noch
        $mismatch = $checks->findings('mismatch');
        $this->assertEqualsCanonicalizing(['Rocky', 'Hits'], $mismatch->pluck('title')->all());
        $this->assertSame(['Hit Cher'], array_column($mismatch[$this->hash('Hits')]['items'], 'title'), 'Madonna ohne Genre-Daten, Gast zählt nicht');

        app(Settings::class)->set(['genre_share' => 25]);
        $checks->forget();
        $mismatch = $checks->findings('mismatch');
        $this->assertEqualsCanonicalizing(['Rocky', 'Hits', 'Einzeln'], $mismatch->pluck('title')->all());
        $rocky = $mismatch[$this->hash('Rocky')];
        $this->assertSame(['Trance' => 2], $rocky['current']);
        $this->assertSame('Pop', $rocky['suggestions'][0]['value'], 'die übrigen Alben – das eigene zählt nicht');
        $this->assertStringContainsString('Pop 100 %', $rocky['profile']);
        $this->assertSame(['Hit ABBA', 'Hit Cher'], array_column($mismatch[$this->hash('Hits')]['items'], 'title'));
        $this->assertSame('single', $mismatch->firstWhere('title', 'Einzeln')['kind']);
        $this->assertSame(['Hit ABBA', 'Hit Cher'], array_column($mismatch[$this->hash('Hits')]['songs'], 'title'), 'Sampler: nur betroffene Lieder zum Reinhören');
        $this->assertSame(['Rocky 1', 'Rocky 2'], array_column($rocky['songs'], 'title'));
        $this->assertSame([false, true], array_column($missing[$this->hash('Voulez')]['songs'], 'affected'), 'Album: alle Lieder, betroffene markiert');

        $mixed = $checks->findings('mixed');
        $this->assertSame(['Mixed'], $mixed->pluck('title')->values()->all());
        $this->assertSame(['Pop', 'Synthpop'], array_column($mixed->first()['suggestions'], 'value'));
    }

    public function test_suggestion_from_services_is_the_most_specific_genre_plus_its_main_genre(): void
    {
        $this->mp3('inbox/Hits/05.mp3', ['TIT2' => 'Hit Madonna leer', 'TALB' => 'Hits', 'TPE1' => 'Madonna']);
        app(Scanner::class)->scan('inbox');
        $madonna = Artist::where('name', 'Madonna')->value('id');
        DB::table('artist_genre')->insert(['artist_id' => $madonna, 'genre_id' => Genre::where('name', 'Synthpop')->value('id'), 'source' => 'musicbrainz', 'weight' => 1]);

        $item = collect(app(GenreChecks::class)->findings('missing')[$this->hash('Hits')]['items'])->firstWhere('title', 'Hit Madonna leer');
        $this->assertSame(['Synthpop; Pop', 'laut Diensten'], [$item['suggestions'][0]['value'], $item['suggestions'][0]['reason']]);
    }

    public function test_take_suggestions_custom_genre_and_passt_so(): void
    {
        $page = Livewire::test(MiscPage::class)->set('view', 'genre_fehlt')->assertSee('Voulez')->assertSee('Hit Cher leer')->assertSee('Lieder zum Reinhören');
        $page->call('takeGenreSuggestions', null)->assertSee('bei 2 Liedern');
        $this->assertSame(['Pop', 'Rock'], [$this->genre('Voulez 2'), $this->genre('Hit Cher leer')]);
        $this->assertSame('Genre fehlte', TagChange::where('field', 'genre')->first()->reason);
        $this->assertSame(0, app(GenreChecks::class)->count('missing'));

        app(Settings::class)->set(['genre_share' => 25]);
        $page = Livewire::test(MiscPage::class)->set('view', 'genre_abweichend')->assertSee('Rocky');
        $page->set('custom.g'.$this->hash('Rocky'), 'Synthpop; Pop')->call('takeGenre', $this->hash('Rocky'), null, null)->assertSee('bei 2 Liedern');
        $this->assertSame('Synthpop; Pop', $this->genre('Rocky 1'));
        $hitAbba = Track::where('title', 'Hit ABBA')->value('id');
        $page->call('takeGenre', $this->hash('Hits'), $hitAbba, 'Pop');
        $this->assertSame(['Pop', 'Pop'], [$this->genre('Hit ABBA'), $this->genre('Hit Cher')], 'nur das eine Lied');

        $single = 't'.Track::where('title', 'Einzeln')->value('id');
        $page->call('exemptGenre', $single, true)->assertSee('1 mit „Passt so“ ausgenommen');
        $this->assertArrayNotHasKey($single, app(GenreChecks::class)->findings('mismatch')->all());
        $page->call('exemptGenre', $single, false);
        $this->assertArrayHasKey($single, app(GenreChecks::class)->findings('mismatch')->all());

        $page->set('view', 'genre_uneinheitlich')->call('takeGenre', $this->hash('Mixed'), null, 'Pop');
        $this->assertSame(['Pop'], Track::where('album', 'Mixed')->pluck('genre')->unique()->values()->all());
        $page->call('takeGenre', $this->hash('Mixed'), null, ' ; ')->assertSee('Bitte ein Genre angeben.');
    }
}
