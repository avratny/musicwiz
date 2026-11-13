<?php

namespace Tests\Feature;

use App\Jobs\CheckRecordings;
use App\Livewire\ArtistClear;
use App\Livewire\ArtistList;
use App\Models\Artist;
use App\Models\ArtistSource;
use App\Support\ArtistTools;
use App\Support\ClearArtists;
use App\Support\Lookup\ArtistLookup;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\SpellingAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesLookupServices;
use Tests\Concerns\MakesAudioFiles;
use Tests\Concerns\MakesMusicBrainzDumps;
use Tests\TestCase;

/** Interpreten › Eindeutig: ohne Zweifel zugeordnete Interpreten gesammelt bestätigen, mit Genres und Bild */
class ClearArtistsTest extends TestCase
{
    use FakesLookupServices, MakesAudioFiles, MakesMusicBrainzDumps, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        File::ensureDirectoryExists($this->base.'/_artists');
        config(['musicwiz.artist_images' => $this->base.'/_artists',
            'musicwiz.lookup_rates' => array_fill_keys(['musicbrainz', 'spotify', 'discogs', 'audiodb', 'fanart', 'lastfm', 'images'], 1_000_000)]);
        Cache::flush();
        app(Settings::class)->set(['spotify_client_id' => 'id', 'spotify_client_secret' => 'secret', 'discogs_token' => 'tok', 'fanart_key' => 'fk', 'audiodb_key' => 'ak']);
        $this->mp3('library/a-ha/Hits/01.mp3', ['TIT2' => 'Take On Me', 'TPE1' => 'a-ha', 'TALB' => 'Hits']);
        app(Scanner::class)->scan('library');
        $this->fakeServices();
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        $this->tearDownMusicBrainzDumps();
        parent::tearDown();
    }

    /** lokale MusicBrainz-Daten: a‐ha mit Verweisen zu Spotify und Discogs (+ weitere Einträge) */
    private function musicBrainz(array $more = [], array $releaseGroups = []): void
    {
        $this->loadMusicBrainz([['id' => self::MBID, 'name' => 'a‐ha', 'type' => 'Group', 'country' => 'NO', 'genres' => [['name' => 'synth-pop', 'count' => 5]], 'relations' => [
            ['target-type' => 'url', 'type' => 'free streaming', 'url' => ['resource' => 'https://open.spotify.com/artist/2jzc5TC5TVFLXQlBNiIUzE']],
            ['target-type' => 'url', 'type' => 'discogs', 'url' => ['resource' => 'https://www.discogs.com/artist/37757-A-ha']],
        ]], ...$more], $releaseGroups);
    }

    /** Ein Interpret mit einem Lied und Treffern wie aus dem Abgleich (nur MusicBrainz) */
    private function artistWithSources(string $name, array $sources): Artist
    {
        $this->mp3('library/'.$name.'/Single/01.mp3', ['TIT2' => 'Song', 'TPE1' => $name, 'TALB' => 'Single']);
        app(Scanner::class)->scan('library');
        $artist = Artist::where('name', $name)->sole();
        $artist->update(['looked_up_at' => now()]);
        foreach ($sources as [$mbid, $sourceName, $match, $score]) {
            ArtistSource::create(['artist_id' => $artist->id, 'service' => 'musicbrainz', 'external_id' => $mbid, 'mbid' => $mbid,
                'name' => $sourceName, 'match' => $match, 'score' => $score, 'data' => []]);
        }

        return $artist;
    }

    private function names(string $level): array
    {
        return array_map(fn ($c) => $c['artist']->name, app(ClearArtists::class)->candidates($level));
    }

    private function lookUp(): void
    {
        app(ArtistLookup::class)->run(Artist::where('name', 'a-ha')->sole());
    }

    public function test_clear_artist_is_confirmed_with_ids_genres_and_image(): void
    {
        $this->musicBrainz();
        $this->lookUp();

        // eindeutig über MusicBrainz: Spotify nicht gefragt (die ID steht als Verweis bei MusicBrainz)
        $this->assertSame(['uebersprungen', 'nicht nötig – eindeutig über MusicBrainz'],
            [DB::table('artist_lookups')->where('service', 'spotify')->value('status'), DB::table('artist_lookups')->where('service', 'spotify')->value('message')]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.spotify.com'));
        // Discogs ebenso (der Engpass beim Abgleich) – die ID kommt beim Übernehmen aus dem Verweis bei MusicBrainz
        $this->assertSame('uebersprungen', DB::table('artist_lookups')->where('service', 'discogs')->value('status'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.discogs.com'));

        $candidates = app(ClearArtists::class)->candidates();
        $this->assertSame(['a-ha'], array_map(fn ($c) => $c['artist']->name, $candidates));
        $this->assertSame('Fanart.tv', ArtistSource::SERVICES[$candidates[0]['image']->source->service], 'Porträt bevorzugt von Fanart.tv');
        $this->assertSame(1, ArtistTools::counts()['eindeutig']);

        Livewire::test(ArtistClear::class)->assertSee('a-ha')->assertSee('Alle 1 übernehmen')->call('confirmAll');   // Warteschlange in Tests: sofort

        $artist = Artist::where('name', 'a-ha')->sole();
        $this->assertTrue($artist->confirmed);
        $this->assertSame([self::MBID, '37757', '2jzc5TC5TVFLXQlBNiIUzE'], [$artist->mbid, $artist->discogs_id, $artist->spotify_id]);
        $this->assertNotNull($artist->imagePath(), 'Bild übernommen');
        $this->assertTrue(DB::table('artist_genre')->where('artist_id', $artist->id)->where('source', '!=', 'tags')->exists(), 'Genres aus dem Treffer');
        $this->assertSame([], app(ClearArtists::class)->candidates(), 'bestätigt: nicht mehr in der Liste');
        $this->assertSame('a-ha', Artist::where('name', 'a-ha')->value('name'), 'Name und Lieder bleiben');
    }

    public function test_doubt_keeps_artists_out(): void
    {
        // ohne lokale MusicBrainz-Daten: nichts, mit Hinweis
        $this->lookUp();
        $this->assertSame([], app(ClearArtists::class)->candidates());
        Livewire::test(ArtistClear::class)->assertSee('lokalen MusicBrainz-Daten');

        // ein Gleichnamiger bei MusicBrainz
        $this->musicBrainz([['id' => 'cccccccc-0000-0000-0000-000000000001', 'name' => 'A-ha', 'type' => 'Person', 'disambiguation' => 'someone else']]);
        Artist::where('name', 'a-ha')->update(['looked_up_at' => null]);
        $this->lookUp();
        $this->assertSame([], app(ClearArtists::class)->candidates(), 'mehrdeutig');

        // offene Ähnlich-Gruppe
        $this->musicBrainz();
        $this->lookUp();
        $this->assertCount(1, app(ClearArtists::class)->candidates());
        Artist::create(['name' => 'A-Ha']);
        $this->assertSame([], app(ClearArtists::class)->candidates(), 'erst „Ähnlich“ klären');
    }

    public function test_confirming_one_shows_clearly_what_happened(): void
    {
        $this->musicBrainz();
        $this->lookUp();
        $id = Artist::where('name', 'a-ha')->value('id');

        Livewire::test(ArtistClear::class)->assertSee('Übernehmen')
            ->call('confirm', $id)
            ->assertSee('„a-ha“ übernommen: bestätigt')
            ->assertSee('übernommen – bestätigt')                 // die Zeile bleibt mit Haken stehen
            ->assertSee('Bild übernommen')
            ->assertSet('done', [$id => ['name' => 'a-ha', 'summary' => 'bestätigt · 2 Genres · Bild übernommen']]);
    }

    public function test_single_musicbrainz_entry_is_enough_and_weak_side_hits_do_not_count(): void
    {
        $this->musicBrainz([
            ['id' => 'dddddddd-0000-0000-0000-000000000001', 'name' => 'Tijeritas', 'type' => 'Person'],
            ['id' => 'dddddddd-0000-0000-0000-000000000002', 'name' => 'Tijeras', 'type' => 'Group'],
        ]);
        // nur MusicBrainz (keine zwei weiteren Dienste), dazu ein schwacher Nebentreffer mit anderem Namen
        $this->artistWithSources('Tijeritas', [
            ['dddddddd-0000-0000-0000-000000000001', 'Tijeritas', 'exakt', 100],
            ['dddddddd-0000-0000-0000-000000000002', 'Tijeras', 'aehnlich', 70],
        ]);

        $this->assertSame(['Tijeritas'], $this->names('eindeutig'));
        $this->assertSame('einziger', app(ClearArtists::class)->candidates()[0]['reason']);
        Livewire::test(ArtistClear::class)->assertSee('einziger Eintrag dieses Namens bei MusicBrainz');
    }

    public function test_namesakes_are_likely_and_songs_decide(): void
    {
        $other = 'cccccccc-0000-0000-0000-000000000001';
        $this->musicBrainz([['id' => $other, 'name' => 'A-ha', 'type' => 'Person', 'disambiguation' => 'someone else']],
            array_map(fn ($i) => ['id' => 'rg'.$i, 'title' => 'Album '.$i, 'artist-credit' => [['name' => 'a‐ha', 'artist' => ['id' => self::MBID]]]], range(1, 5)));
        $this->lookUp();
        $id = Artist::where('name', 'a-ha')->value('id');

        // ohne Beleg: nur wahrscheinlich, mit dem Gleichnamigen daneben
        $this->assertSame([], $this->names('eindeutig'));
        $this->assertSame(['a-ha'], $this->names('wahrscheinlich'));
        $this->assertSame([$id], app(ClearArtists::class)->evidenceOpen());
        Livewire::test(ArtistClear::class, ['level' => 'wahrscheinlich'])->assertSee('bekanntester Gleichnamiger')->assertSee('someone else')->assertSee('Lieder prüfen');

        // unser Titel steht beim Bekannten → eindeutig
        Http::fake(['musicbrainz.org/ws/2/recording*' => Http::response(['recordings' => [
            ['title' => 'Take On Me', 'artist-credit' => [['name' => 'a‐ha', 'artist' => ['id' => self::MBID, 'name' => 'a‐ha']]]],
            ['title' => 'Something Else', 'artist-credit' => [['name' => 'A-ha', 'artist' => ['id' => $other, 'name' => 'A-ha']]]],
        ]])]);
        CheckRecordings::start();   // Warteschlange in Tests: sofort
        $this->assertSame([self::MBID => 1], json_decode(DB::table('artist_recording_checks')->where('artist_id', $id)->value('hits'), true));
        $this->assertSame([], app(ClearArtists::class)->evidenceOpen(), 'geprüft');
        $this->assertSame(['a-ha'], $this->names('eindeutig'));
        $this->assertSame('lied', app(ClearArtists::class)->candidates()[0]['reason']);
        $this->assertSame([], $this->names('wahrscheinlich'));

        // steht der Titel beim Namensvetter, ist der gemeint – mit seiner Schreibweise
        $hits = fn (array $h) => DB::table('artist_recording_checks')->where('artist_id', $id)->update(['hits' => json_encode($h)]);
        $hits([$other => 1]);
        $this->assertSame(['A-ha'], array_map(fn ($c) => $c['rename'], app(ClearArtists::class)->candidates('schreibweise')));

        // bei beiden oder bei einem, den der Abgleich nicht kennt: in keiner Liste
        foreach ([[self::MBID => 1, $other => 1], ['ffffffff-0000-0000-0000-000000000001' => 2]] as $h) {
            $hits($h);
            $this->assertSame([[], [], []], [$this->names('eindeutig'), $this->names('wahrscheinlich'), $this->names('schreibweise')]);
        }
    }

    public function test_different_spelling_renames_on_confirm(): void
    {
        $mbid = 'eeeeeeee-0000-0000-0000-000000000001';
        $this->musicBrainz([['id' => $mbid, 'name' => 'C. C. Catch', 'type' => 'Person']]);
        $artist = $this->artistWithSources('C.C. Catch', [[$mbid, 'C. C. Catch', 'zeichen', 90]]);

        $this->assertSame([], $this->names('eindeutig'));
        $this->assertSame(['C.C. Catch'], $this->names('schreibweise'));
        $this->assertSame(1, ArtistTools::counts()['schreibweise']);

        Livewire::test(ArtistClear::class, ['level' => 'schreibweise'])->assertSee('C. C. Catch')
            ->call('confirm', $artist->id)->assertSee('umbenannt · bestätigt');

        $artist->refresh();
        $this->assertSame(['C. C. Catch', true, $mbid], [$artist->name, $artist->confirmed, $artist->mbid]);
        $this->assertSame(['C.C. Catch'], $artist->aliases->pluck('alias')->all(), 'alter Name bleibt Schreibweise');
        $this->assertSame('C. C. Catch', $artist->tracks()->value('artist'), 'Tag in der DB geändert');
        $this->assertTrue(DB::table('tag_changes')->where('new_value', 'C. C. Catch')->exists(), 'und protokolliert');
    }

    public function test_all_shows_unconfirmed_badge(): void
    {
        Artist::create(['name' => 'Bestätigt', 'confirmed' => true]);

        Livewire::test(ArtistList::class)->assertSeeHtml('1 unbestätigt – anzeigen')
            ->call('showOpen')->assertSet('status', 'open')->assertSee('a-ha')->assertDontSee('Bestätigt</');
    }

    public function test_claude_decides_the_spelling(): void
    {
        $mbid = 'eeeeeeee-0000-0000-0000-000000000001';
        $this->musicBrainz([['id' => $mbid, 'name' => 'Willy DeVille', 'type' => 'Person']]);
        $artist = $this->artistWithSources('Willy Deville', [[$mbid, 'Willy DeVille', 'gross_klein', 95]]);
        app(Settings::class)->set(['anthropic_api_key' => 'sk-test']);
        $asked = [];
        $answer = ['source' => 'ours', 'spelling' => 'Willy Deville', 'reason' => 'So steht es auf den Platten.'];
        $this->app->instance(SpellingAi::class, new class(app(Settings::class), $answer, $asked) extends SpellingAi
        {
            public function __construct(Settings $settings, private array $answer, private array &$asked)
            {
                parent::__construct($settings);
            }

            protected function ask(string $ours, string $theirs, string $context): array
            {
                $this->asked[] = compact('ours', 'theirs', 'context');

                return [self::clean($ours, $theirs, $this->answer), 'claude-opus-5-5'];
            }
        });

        $page = Livewire::test(ArtistClear::class, ['level' => 'schreibweise'])->assertSee('Claude fragen')->assertSee('So lassen')
            ->call('askClaude', $artist->id)->assertSee('Claude: „Willy Deville“')->assertSee('So steht es auf den Platten.');
        $this->assertSame(['Willy Deville', 'Willy DeVille'], [$asked[0]['ours'], $asked[0]['theirs']]);
        $this->assertStringContainsString('Titel: Song', $asked[0]['context'], 'Lieder als Zusammenhang');

        // „Alle übernehmen“ folgt Claude: Name bleibt, bestätigt
        $page->call('confirmAll');
        $artist->refresh();
        $this->assertSame(['Willy Deville', true, $mbid], [$artist->name, $artist->confirmed, $artist->mbid]);
        $this->assertSame(0, DB::table('tag_changes')->count(), 'nichts umbenannt');

        // weder noch: dritte Schreibweise
        $this->assertSame(['source' => 'other', 'spelling' => 'Willy De Ville', 'reason' => ''], SpellingAi::clean('Willy Deville', 'Willy DeVille', ['source' => 'other', 'spelling' => ' Willy De Ville ']));
        $this->assertSame('musicbrainz', SpellingAi::clean('a', 'b', ['source' => 'ours', 'spelling' => 'b'])['source'], 'die Schreibweise zählt');
        $this->assertSame('unsure', SpellingAi::clean('Dr. Dre', 'Dr Dre', ['source' => 'other', 'spelling' => 'Andre Young (Dr. Dre)'])['source'], 'ein anderer Name');
    }

    public function test_an_unsure_answer_from_claude_is_never_taken_without_asking(): void
    {
        $mbid = 'eeeeeeee-0000-0000-0000-000000000001';
        $this->musicBrainz([['id' => $mbid, 'name' => 'Paul van Dyk', 'type' => 'Person']]);
        $artist = $this->artistWithSources('Paul Van Dyk', [[$mbid, 'Paul van Dyk', 'gross_klein', 95]]);
        DB::table('artist_spelling_checks')->insert(['artist_id' => $artist->id, 'ours' => 'Paul Van Dyk', 'theirs' => 'Paul van Dyk',
            'spelling' => 'Matthias Paul', 'source' => 'unsure', 'reason' => 'bürgerlicher Name', 'asked_at' => now()]);
        // gespeichert für eine andere Groß/klein-Schreibung: gilt nicht
        $this->assertNull(DB::table('artist_spelling_checks')->where('ours', 'PAUL VAN DYK')->first());

        Livewire::test(ArtistClear::class, ['level' => 'schreibweise'])->assertSee('unsicher')->assertSee('„Matthias Paul“')->call('confirm', $artist->id);

        $this->assertSame(['Paul van Dyk', true], [$artist->fresh()->name, $artist->fresh()->confirmed], 'MusicBrainz statt der unsicheren Antwort');
    }

    public function test_spelling_can_be_kept_or_chosen_per_row(): void
    {
        $mbid = 'eeeeeeee-0000-0000-0000-000000000001';
        $this->musicBrainz([['id' => $mbid, 'name' => 'Paul van Dyk', 'type' => 'Person']]);
        $artist = $this->artistWithSources('Paul Van Dyk', [[$mbid, 'Paul van Dyk', 'gross_klein', 95]]);

        Livewire::test(ArtistClear::class, ['level' => 'schreibweise'])->assertDontSeeHtml('askClaude')   // ohne API-Key kein Knopf
            ->call('confirmAs', $artist->id, 'Paul Van Dyk')->assertSee('übernommen – bestätigt');
        $this->assertSame(['Paul Van Dyk', true], [$artist->fresh()->name, $artist->fresh()->confirmed]);
    }
}
