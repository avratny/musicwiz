<?php

namespace Tests\Feature;

use App\Livewire\ArtistCombos;
use App\Livewire\ArtistList;
use App\Models\Artist;
use App\Models\Track;
use App\Support\ArtistCredits;
use App\Support\ArtistTools;
use App\Support\Lookup\NameCheck;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\Concerns\MakesMusicBrainzDumps;
use Tests\TestCase;

/** Kombis, die eine Gruppe sind: Mitglieder laut MusicBrainz, genau geprüft, „Bleibt ganz + Mitglieder“ (ARTISTS = Gruppe; Mitglieder) */
class ComboMembersTest extends TestCase
{
    use MakesAudioFiles, MakesMusicBrainzDumps, RefreshDatabase;

    private const DUO = 'aaaaaaaa-0000-0000-0000-000000000001';

    private const BEAM = 'aaaaaaaa-0000-0000-0000-000000000002';

    private const BEAM_OTHER = 'aaaaaaaa-0000-0000-0000-000000000003';

    private const YANOU = 'aaaaaaaa-0000-0000-0000-000000000004';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        File::ensureDirectoryExists($this->base.'/_artists');
        config(['musicwiz.artist_images' => $this->base.'/_artists', 'musicwiz.lookup_rates' => array_fill_keys(['musicbrainz', 'spotify', 'discogs', 'audiodb', 'lastfm'], 1_000_000)]);
        $this->mp3('library/a/01.mp3', ['TIT2' => 'Sunshine', 'TPE1' => 'Beam & Yanou', 'TALB' => 'A']);
        $this->mp3('library/a/02.mp3', ['TIT2' => 'Solo', 'TPE1' => 'Yanou', 'TALB' => 'A']);

        // lokale MusicBrainz-Daten: das Duo mit zwei Mitgliedern, dazu ein gleichnamiger anderer „Beam“
        $member = fn ($id, $group) => ['target-type' => 'artist', 'type' => 'member of band', 'direction' => 'forward', 'artist' => ['id' => $group]];
        $artists = [
            ['id' => self::DUO, 'name' => 'Beam & Yanou', 'type' => 'Group', 'relations' => []],
            ['id' => self::BEAM, 'name' => 'Beam', 'type' => 'Person', 'relations' => [$member(self::BEAM, self::DUO)]],
            ['id' => self::BEAM_OTHER, 'name' => 'BEAM', 'type' => 'Person', 'disambiguation' => 'US rapper', 'relations' => []],
            ['id' => self::YANOU, 'name' => 'Yanou', 'type' => 'Person', 'relations' => [$member(self::YANOU, self::DUO)]],
        ];
        $this->loadMusicBrainz($artists, [['id' => 'rg', 'artist-credit' => [['artist' => ['id' => self::BEAM_OTHER]]]]]);
        Http::fake(['*' => Http::response(['artists' => null])]);   // andere Dienste: nichts gefunden – geprüft wird hier MusicBrainz
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        $this->tearDownMusicBrainzDumps();
        parent::tearDown();
    }

    public function test_group_members_are_checked_by_id_and_kept_with_the_group(): void
    {
        app(Scanner::class)->scan('library');

        // Prüfen: das Duo, dann die Mitglieder genau über ihre ID (nicht der gleichnamige Rapper)
        $page = Livewire::test(ArtistCombos::class)->call('check');
        $this->assertSame([], NameCheck::pending());
        $beam = NameCheck::memberResults([self::BEAM])[self::BEAM]['musicbrainz'];
        $this->assertSame(['ok', 'Beam', 'Person', 0], [$beam['status'], $beam['found'], $beam['type'], $beam['releases']], 'nicht BEAM mit der Veröffentlichung');

        $asked = Http::recorded()->count();
        $page->call('refresh')->assertSee('Mitglieder laut MusicBrainz')->assertSee('Fazit: Bleibt ganz + Mitglieder')->assertSee('Gruppe aus Beam und Yanou');

        $this->assertSame($asked, Http::recorded()->count(), 'die Seite liest nur Gespeichertes – keine Abfrage beim Anzeigen');
        $this->assertSame(['' => 2, 'kombis' => 1], array_intersect_key(ArtistTools::counts(), ['' => 1, 'kombis' => 1]));

        $page->call('keepWithMembers', 'Beam & Yanou', ['Beam', 'Yanou'])->assertSee('Mitglieder verknüpft: Beam · Yanou – 1 Lied angepasst.');
        $sunshine = Track::where('title', 'Sunshine')->sole();
        $this->assertSame(['Beam & Yanou', 'Beam & Yanou; Beam; Yanou'], [$sunshine->artist, $sunshine->artists], 'Variante B');
        $this->assertSame(['Beam', 'Beam & Yanou', 'Yanou'], DB::table('track_artist')->join('artists', 'artists.id', '=', 'track_artist.artist_id')
            ->where('track_id', $sunshine->id)->where('role', 'artist')->orderBy('name')->pluck('name')->all(), 'auch bei den Mitgliedern');
        $this->assertTrue(Artist::where('name', 'Beam & Yanou')->value('confirmed'));
        $this->assertSame([], app(ArtistCredits::class)->open());
        $page->set('tab', 'entschieden')->assertSee('bleibt ganz + Mitglieder:');
        $this->assertSame(0, ArtistTools::counts()['kombis'], 'Zähler neu gerechnet, weil sich etwas geändert hat');

        // nächster Durchlauf: Prüfungen, die keine offene Kombi mehr braucht, werden entfernt
        $this->assertGreaterThan(0, DB::table('name_checks')->count());
        $page->set('tab', 'offen')->call('check');
        $this->assertSame(0, DB::table('name_checks')->count());

        // Flyout: Mitglied von – Mitglieder (eindeutiger Name bzw. Gruppe)
        Livewire::test(ArtistList::class)->call('open', Artist::where('name', 'Yanou')->value('id'))->assertSee('Mitglied von')->assertSee('Beam &amp; Yanou', false);
        Livewire::test(ArtistList::class)->call('open', Artist::where('name', 'Beam & Yanou')->value('id'))->assertSee('Mitglieder');
        Livewire::test(ArtistList::class)->call('open', Artist::where('name', 'Beam')->value('id'))->assertDontSee('Mitglied von', 'Beam ist mehrdeutig (BEAM)');
    }

    public function test_members_also_reach_songs_read_later_and_undo_takes_everything_back(): void
    {
        app(Scanner::class)->scan('library');
        $credits = app(ArtistCredits::class);
        $credits->keepWithMembers('Beam & Yanou', ['Beam', 'Yanou']);

        // ein neues Lied der Gruppe kommt nach der Entscheidung dazu
        $this->mp3('library/b/99.mp3', ['TIT2' => 'Neu', 'TPE1' => 'Beam & Yanou', 'TALB' => 'B']);
        app(Scanner::class)->scan('library');
        $neu = Track::where('title', 'Neu')->sole();
        $this->assertSame('Beam & Yanou; Beam; Yanou', $neu->artists);
        $this->assertSame(['Beam', 'Beam & Yanou', 'Yanou'], DB::table('track_artist')->join('artists', 'artists.id', '=', 'track_artist.artist_id')
            ->where('track_id', $neu->id)->where('role', 'artist')->orderBy('name')->pluck('name')->all());

        // aufheben: Bestätigung (kam von der Entscheidung) und Mitglieder sind weg, die Kombi ist wieder offen
        $this->assertSame(2, app(ArtistCredits::class)->undo('Beam & Yanou'));
        $this->assertNull($neu->refresh()->artists);
        $this->assertFalse((bool) Artist::where('name', 'Beam & Yanou')->value('confirmed'));
        $this->assertContains('Beam & Yanou', array_column(app(ArtistCredits::class)->open(), 'name'));
    }
}
