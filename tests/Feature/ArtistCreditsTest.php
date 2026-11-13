<?php

namespace Tests\Feature;

use App\Livewire\ArtistCombos;
use App\Livewire\ArtistList;
use App\Models\Artist;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Activity;
use App\Support\ArtistCredits;
use App\Support\ArtistRegistry;
use App\Support\Lookup\NameCheck;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class ArtistCreditsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        File::ensureDirectoryExists($this->base.'/_artists');
        config(['musicwiz.artist_images' => $this->base.'/_artists']);
        $this->mp3('library/a/01.mp3', ['TIT2' => 'This Is What You Came For', 'TPE1' => 'Calvin Harris ft. Rihanna', 'TPE2' => 'Calvin Harris Featuring Rihanna', 'TALB' => 'A']);
        $this->mp3('library/a/02.mp3', ['TIT2' => 'Positiv', 'TPE1' => 'Kyau vs. Albert', 'TALB' => 'A']);
        $this->mp3('library/a/03.mp3', ['TIT2' => 'Sunshine', 'TPE1' => 'Beam & Yanou', 'TALB' => 'A']);
        $this->mp3('library/a/04.mp3', ['TIT2' => 'Mrs. Robinson', 'TPE1' => 'Simon & Garfunkel', 'TALB' => 'A']);
        $this->mp3('library/a/05.mp3', ['TIT2' => 'Under the Boardwalk', 'TPE1' => 'Drifters, The', 'TALB' => 'A']);
        $this->mp3('library/a/06.mp3', ['TIT2' => 'Work (feat. Drake)', 'TPE1' => 'Rihanna', 'TALB' => 'A']);
        $this->mp3('library/a/07.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher', 'TXXX:ARTISTS' => 'Cher', 'TALB' => 'A']);
        $this->flac('library/a/08.flac', ['TITLE' => 'Wake Me Up', 'ARTIST' => 'Avicii x Aloe Blacc', 'ARTISTS' => ['Avicii', 'Aloe Blacc'], 'ALBUM' => 'A']);
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function track(string $title): Track
    {
        return Track::where('title', $title)->sole();
    }

    private function roles(string $title): array
    {
        $track = $this->track($title);

        return \DB::table('track_artist')->join('artists', 'artists.id', '=', 'track_artist.artist_id')->where('track_id', $track->id)
            ->orderBy('role')->orderBy('name')->get()->map(fn ($r) => $r->role.':'.$r->name)->all();
    }

    public function test_scanning_splits_guests_and_vs_automatically_and_reads_artists_from_the_file(): void
    {
        app(Scanner::class)->scan('library');

        $calvin = $this->track('This Is What You Came For');
        $this->assertSame(['Calvin Harris feat. Rihanna', 'Calvin Harris; Rihanna', 'Calvin Harris', null],
            [$calvin->artist, $calvin->artists, $calvin->albumartist, $calvin->albumartists], '„feat.“ einheitlich, Gast fällt beim Album-Interpret weg');
        $this->assertSame(['albumartist:Calvin Harris', 'artist:Calvin Harris', 'guest:Rihanna'], $this->roles('This Is What You Came For'));
        $this->assertSame(TagEditor::SYSTEM, TagChange::where('track_id', $calvin->id)->where('field', 'artists')->value('source'));
        $this->assertStringStartsWith('Kombi:', TagChange::where('track_id', $calvin->id)->where('field', 'artists')->value('reason'));

        $this->assertSame('Kyau; Albert', $this->track('Positiv')->artists);
        $this->assertSame('Avicii; Aloe Blacc', $this->track('Wake Me Up')->artists, 'aus der Datei');
        $this->assertSame(['artist:Aloe Blacc', 'artist:Avicii'], $this->roles('Wake Me Up'));
        $this->assertNull($this->track('Sunshine')->artists, '& erst nach Entscheidung');

        $names = Artist::pluck('name')->all();
        foreach (['Calvin Harris', 'Rihanna', 'Kyau', 'Albert', 'Beam & Yanou', 'Drifters, The'] as $name) {
            $this->assertContains($name, $names);
        }
        foreach (['Calvin Harris ft. Rihanna', 'Calvin Harris feat. Rihanna', 'Kyau vs. Albert', 'Avicii x Aloe Blacc'] as $name) {
            $this->assertNotContains($name, $names, 'Kombi ist kein Interpret');
        }

        $open = array_column(app(ArtistCredits::class)->open(), 'parts', 'name');
        $this->assertSame(['Beam & Yanou' => ['Beam', 'Yanou'], 'Simon & Garfunkel' => ['Simon', 'Garfunkel']], $open, '„Drifters, The“ ist keine Kombi');
    }

    public function test_feat_in_a_band_name_is_not_a_guest(): void
    {
        File::deleteDirectory($this->base.'/library');
        $this->mp3('library/lf/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Little Feat & Bonnie Raitt', 'TPE2' => 'Little Feat & Friends', 'TALB' => 'L']);
        $this->mp3('library/lf/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Little Feat feat. Emmylou Harris', 'TALB' => 'L']);
        $this->mp3('library/lf/03.mp3', ['TIT2' => 'Drei', 'TPE1' => 'A ft. B', 'TPE2' => 'A (Featuring B)', 'TALB' => 'L']);
        $this->mp3('library/lf/04.mp3', ['TIT2' => 'Vier', 'TPE1' => 'Mike feat. Ike', 'TALB' => 'L']);
        $credits = app(ArtistCredits::class);
        app(ArtistRegistry::class)->add('Mike feat. Ike');
        app(Scanner::class)->scan('library');

        $this->assertSame(['Little Feat & Bonnie Raitt', 'Little Feat & Friends'], [$this->track('Eins')->artist, $this->track('Eins')->albumartist], 'nichts umgeschrieben');
        $this->assertSame(['Little Feat feat. Emmylou Harris', 'Little Feat; Emmylou Harris'], [$this->track('Zwei')->artist, $this->track('Zwei')->artists]);
        $this->assertSame(['A feat. B', 'A'], [$this->track('Drei')->artist, $this->track('Drei')->albumartist], 'eindeutige Gäste wie bisher');
        $this->assertSame(['Mike feat. Ike', null], [$this->track('Vier')->artist, $this->track('Vier')->artists], 'bestätigter Name bleibt ganz');
        $this->assertFalse(Artist::where('name', 'Little')->exists());
        // „Feat“ ohne Punkt wartet als Kombi auf eine Entscheidung
        $open = array_column($credits->open(), 'parts', 'name');
        $this->assertSame(['Little Feat', 'Bonnie Raitt'], $open['Little Feat & Bonnie Raitt'] ?? null);
        $this->assertSame(['Band', 'Gast'], ArtistCredits::suggest('Band Feat Gast'), '„Feat“ ohne Punkt: Vorschlag zum Trennen');
    }

    public function test_split_keep_and_undo_decisions(): void
    {
        app(Scanner::class)->scan('library');
        $credits = app(ArtistCredits::class);

        $this->assertSame(1, $credits->split('Beam & Yanou', ['Beam', ' Yanou ']));
        $this->assertSame('Beam; Yanou', $this->track('Sunshine')->artists);
        $this->assertSame('Beam & Yanou', $this->track('Sunshine')->artist, 'Anzeige bleibt');
        $this->assertSame(['artist:Beam', 'artist:Yanou'], $this->roles('Sunshine'), 'Teile angelegt und verbunden');
        $this->assertNull(Artist::where('name', 'Beam & Yanou')->first(), 'Kombi-Eintrag ohne Lieder entfernt');

        $credits->keepWhole('Simon & Garfunkel');
        $this->assertTrue(Artist::where('name', 'Simon & Garfunkel')->value('confirmed'), 'bleibt ganz = bestätigt');
        $this->assertSame([], $credits->open());

        $credits->undo('Beam & Yanou');
        $this->assertNull($this->track('Sunshine')->artists);
        $this->assertSame(0, TagChange::where('track_id', $this->track('Sunshine')->id)->count(), 'Änderung hebt sich auf');
        $this->assertSame(['artist:Beam & Yanou'], $this->roles('Sunshine'));
        $this->assertSame(['Beam & Yanou'], array_column($credits->open(), 'name'));
    }

    public function test_feat_in_title_rename_inside_combos_and_manual_edits(): void
    {
        app(Scanner::class)->scan('library');
        $credits = app(ArtistCredits::class);

        $this->assertSame([['Rihanna feat. Drake', 'Work']], array_map(fn ($s) => [$s['artist'], $s['title']], $credits->titleSuggestions()));
        $this->assertSame(1, $credits->moveTitleGuests([$this->track('Work (feat. Drake)')->id]));
        $work = $this->track('Work');
        $this->assertSame(['Rihanna feat. Drake', 'Rihanna; Drake'], [$work->artist, $work->artists]);
        $this->assertSame(['artist:Rihanna', 'guest:Drake'], $this->roles('Work'));

        // umbenennen: auch innerhalb der Kombi und in ARTISTS
        app(ArtistRegistry::class)->rename(Artist::where('name', 'Rihanna')->sole(), 'RIHANNA');
        $calvin = $this->track('This Is What You Came For');
        $this->assertSame(['Calvin Harris feat. RIHANNA', 'Calvin Harris; RIHANNA'], [$calvin->artist, $calvin->artists]);

        // ARTISTS nur mit dem Interpreten selbst (aus der Datei): zieht mit; von Hand gepflegt: bleibt
        $believe = $this->track('Believe');
        app(TagEditor::class)->update($believe, ['artist' => 'Cher feat. Eros Ramazzotti']);
        $this->assertSame('Cher; Eros Ramazzotti', $believe->fresh()->artists);
        app(TagEditor::class)->update($believe->fresh(), ['artists' => 'Cher;Eros']);
        app(TagEditor::class)->update($believe->fresh(), ['artist' => 'Cher & Eros']);
        $this->assertSame('Cher; Eros', $believe->fresh()->artists);
    }

    public function test_combos_page_splits_keeps_and_lists_decisions(): void
    {
        app(Scanner::class)->scan('library');

        $page = Livewire::test(ArtistCombos::class)->assertSee('Beam &amp; Yanou', false)->assertSee('Simon &amp; Garfunkel', false);
        $page->call('split', 'Beam & Yanou', ['Beam', 'Yanou'])->assertSee('getrennt: Beam · Yanou – 1 Lied angepasst.');
        $page->call('split', 'Simon & Garfunkel', ['Simon', ''])->assertSee('mindestens zwei Teile');
        $page->call('keepWhole', 'Simon & Garfunkel')->assertSee('bleibt ganz')->assertSee('Keine offenen Kombis');

        $page->set('tab', 'titel')->assertSee('Rihanna feat. Drake – Work');
        $page->set('tab', 'entschieden')->assertSee('Beam · Yanou')->call('undo', 'Beam & Yanou')->assertSee('aufgehoben');

        $this->get('/interpreten?ansicht=kombis')->assertOk()->assertSee('Kombis');
        Livewire::test(ArtistList::class)->call('open', Artist::where('name', 'Rihanna')->value('id'))->assertSee('2 Lieder')->assertSee('davon 1 als Gast');
    }

    public function test_combo_names_and_their_parts_are_checked_with_the_services(): void
    {
        app(Scanner::class)->scan('library');
        config(['musicwiz.lookup_rates' => array_fill_keys(['musicbrainz', 'spotify', 'discogs', 'audiodb'], 1_000_000)]);
        app(Settings::class)->set(['audiodb_key' => 'ak']);   // Spotify und Discogs ohne Zugang: übersprungen
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, 'musicbrainz.org/ws/2/artist/mb-group')) {
                return Http::response(['id' => 'mb-group', 'name' => 'Beam & Yanou', 'type' => 'Group']);
            }
            if (str_contains($url, 'musicbrainz.org/ws/2/artist') && str_contains($url, '"Simon"')) {
                return Http::response('kaputt', 500);
            }
            if (str_contains($url, 'musicbrainz.org/ws/2/artist')) {
                return Http::response(['artists' => str_contains($url, '"Beam & Yanou"') ? [['id' => 'mb-group', 'name' => 'Beam & Yanou', 'score' => 100]] : []]);
            }
            if (str_contains($url, 'theaudiodb.com')) {
                return Http::response(['artists' => str_contains($url, 's=Yanou') ? [['idArtist' => '7', 'strArtist' => 'Yanou']] : null]);
            }

            return Http::response([], 404);
        });

        $page = Livewire::test(ArtistCombos::class)->assertSee('6 Namen offen')->assertSee('noch nicht geprüft');
        $page->call('check');   // Warteschlange in Tests: sofort

        $results = NameCheck::results(['Beam & Yanou', 'Beam', 'Yanou']);
        $this->assertSame(['ok', 'Beam & Yanou', 'exakt', 'Group'], array_values(array_intersect_key($results['Beam & Yanou']['musicbrainz'], array_flip(['status', 'found', 'match', 'type']))));
        $this->assertSame('uebersprungen', $results['Beam & Yanou']['spotify']['status']);
        $this->assertSame('nicht_gefunden', $results['Beam']['musicbrainz']['status']);
        $this->assertSame(['ok', 'https://www.theaudiodb.com/artist/7'], [$results['Yanou']['audiodb']['status'], $results['Yanou']['audiodb']['url']]);
        $this->assertSame('fehler', $results = NameCheck::results(['Simon'])['Simon']['musicbrainz']['status']);
        $this->assertFalse(Activity::current('combos')['running'], 'Durchlauf endet trotz Fehler');
        $this->assertSame([['type' => NameCheck::NAME, 'subject' => 'Simon']], NameCheck::pending(), 'Fehler: beim nächsten Mal erneut fragen, sonst gespeichert');
        $page->call('refresh')->assertSee('1 Name offen')->assertSee('Group');

        $page->call('check');
        $this->assertSame(2, Http::recorded(fn ($r) => str_contains(urldecode($r->url()), '"Simon"'))->count(), 'nur der Name mit Fehler noch einmal');
    }

    public function test_parts_show_their_own_tracks_alone_and_with_others_and_come_first(): void
    {
        $this->mp3('library/b/01.mp3', ['TIT2' => 'Solo', 'TPE1' => 'Beam', 'TALB' => 'B']);
        $this->mp3('library/b/02.mp3', ['TIT2' => 'Duett', 'TPE1' => 'Yanou feat. Do', 'TALB' => 'B']);
        $this->mp3('library/b/03.mp3', ['TIT2' => 'The Boxer', 'TPE1' => 'Simon & Garfunkel', 'TALB' => 'B']);
        app(Scanner::class)->scan('library');
        $credits = app(ArtistCredits::class);

        $open = array_column($credits->open(), null, 'name');
        $this->assertSame([1, 0], [$open['Beam & Yanou']['known']['Beam']['alone'], $open['Beam & Yanou']['known']['Beam']['others']]);
        $this->assertSame([0, 1], [$open['Beam & Yanou']['known']['Yanou']['alone'], $open['Beam & Yanou']['known']['Yanou']['others']], 'feat. zählt als mit anderen');
        $this->assertFalse(ArtistCredits::allAlone($open['Beam & Yanou']));
        $this->assertSame(['Beam & Yanou', 'Simon & Garfunkel'], array_column($credits->open(), 'name'), 'alle Teile bekannt vor unbekannten, trotz weniger Liedern');

        // Garfunkel bekannt, aber nur mit anderen; Simon & Garfunkel hat mehr Lieder – Beam & Yanou (jeder Teil allein) trotzdem zuerst
        $this->mp3('library/b/04.mp3', ['TIT2' => 'Allein', 'TPE1' => 'Yanou', 'TALB' => 'B']);
        $this->mp3('library/b/05.mp3', ['TIT2' => 'Solo 2', 'TPE1' => 'Simon', 'TALB' => 'B']);
        $this->mp3('library/b/06.mp3', ['TIT2' => 'Gast', 'TPE1' => 'Simon feat. Garfunkel', 'TALB' => 'B']);
        app(Scanner::class)->scan('library');
        $open = $credits->open();
        $this->assertSame(['Beam & Yanou', 'Simon & Garfunkel'], array_column($open, 'name'), 'jeder Teil hat eigene Lieder: zuerst');
        $this->assertSame([true, false], [ArtistCredits::allAlone($open[0]), ArtistCredits::allAlone($open[1])]);
        Livewire::test(ArtistCombos::class)->assertSee('jeder Teil hat eigene Lieder');
    }
}
