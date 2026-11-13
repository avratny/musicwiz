<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Support\Activity;
use App\Support\Lookup\MusicBrainz;
use App\Support\Lookup\NameCheck;
use App\Support\MusicBrainzData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\MakesMusicBrainzDumps;
use Tests\TestCase;

class MusicBrainzDataTest extends TestCase
{
    use MakesMusicBrainzDumps, RefreshDatabase;

    private const HALEY = '11111111-1111-1111-1111-111111111111';

    private const COMETS = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dump('20261111-001001');
        config(['musicwiz.lookup_rates' => array_fill_keys(['musicbrainz', 'spotify', 'discogs', 'audiodb'], 1_000_000)]);
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicBrainzDumps();
        parent::tearDown();
    }

    /** Dumps wie bei MusicBrainz: LATEST, je Stand artist.tar.xz und release-group.tar.xz (mbdump/…, TIMESTAMP) */
    private function dump(string $stand, array $extraArtists = []): void
    {
        $artists = [
            ['id' => self::HALEY, 'name' => 'Bill Haley', 'sort-name' => 'Haley, Bill', 'type' => 'Person', 'country' => 'US', 'disambiguation' => '',
                'life-span' => ['begin' => '1925-07-06', 'end' => '1981-02-09'], 'aliases' => [['name' => 'William John Clinton Haley, Jr.', 'type' => 'Legal name']],
                'genres' => [['name' => 'rockabilly', 'count' => 3], ['name' => 'rock and roll', 'count' => 5]],
                'relations' => [['target-type' => 'artist', 'type' => 'member of band', 'direction' => 'forward', 'ended' => true, 'artist' => ['id' => self::COMETS]]]],
            ['id' => self::COMETS, 'name' => 'Bill Haley and His Comets', 'sort-name' => 'Haley, Bill and His Comets', 'type' => 'Group', 'country' => 'US',
                'disambiguation' => '', 'life-span' => ['begin' => '1952'], 'aliases' => [['name' => 'Bill Haley & His Comets', 'type' => 'Search hint']],
                'genres' => [], 'relations' => [
                    ['target-type' => 'url', 'type' => 'free streaming', 'url' => ['resource' => 'https://open.spotify.com/artist/5ByvvdeiOqyfsGQh5lYmT5']],
                    ['target-type' => 'url', 'type' => 'discogs', 'url' => ['resource' => 'https://www.discogs.com/artist/253385']],
                    ['target-type' => 'url', 'type' => 'wikidata', 'url' => ['resource' => 'https://www.wikidata.org/wiki/Q467406']],
                    ['target-type' => 'artist', 'type' => 'member of band', 'direction' => 'backward', 'ended' => true, 'artist' => ['id' => self::HALEY]],
                ]],
            ...$extraArtists,
        ];
        $groups = [
            ['id' => 'rg1', 'title' => 'Rock Around the Clock', 'artist-credit' => [['name' => 'Bill Haley & His Comets', 'artist' => ['id' => self::COMETS]]]],
            ['id' => 'rg2', 'title' => 'Shake, Rattle and Roll', 'artist-credit' => [['name' => 'Bill Haley', 'joinphrase' => ' & ', 'artist' => ['id' => self::HALEY]], ['name' => 'His Comets', 'artist' => ['id' => self::COMETS]]]],
            ['id' => 'rg3', 'title' => 'Live', 'artist-credit' => [['name' => 'Bill Haley and His Comets', 'artist' => ['id' => self::COMETS]]]],
        ];
        $this->musicBrainzDump($artists, $groups, $stand);
    }

    public function test_dumps_are_imported_and_searched_locally_without_asking_musicbrainz(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response(['artists' => []])]);

        $message = app(MusicBrainzData::class)->update();

        $this->assertSame('MusicBrainz-Daten geladen: 2 Interpreten (Stand 20261111-001001).', $message);
        $this->assertSame(['dump' => '20261111-001001', 'artists' => 2, 'names' => 4],
            array_intersect_key(app(MusicBrainzData::class)->status(), array_flip(['dump', 'artists', 'names'])));
        $this->assertFalse(\Schema::hasTable('mb_artists_neu'), 'Zwischentabellen ausgetauscht');

        $mb = app(MusicBrainz::class);
        $comets = $mb->search('Bill Haley & His Comets', 1)[0];
        $this->assertSame(['Bill Haley and His Comets', 'Group', 95, 3], [$comets['name'], $comets['data']['type'], $comets['score'], $comets['data']['releases']],
            'über die Schreibweise; Veröffentlichungen: rg1–rg3');
        $this->assertSame(['spotify' => ['5ByvvdeiOqyfsGQh5lYmT5'], 'discogs' => ['253385']], $comets['links']);
        $haley = $mb->search('Bill Haley', 1)[0];
        $this->assertSame(['Bill Haley', 'Person', 1, ['rock and roll', 'rockabilly']], [$haley['name'], $haley['data']['type'], $haley['data']['releases'], $haley['data']['genres']]);
        $this->assertSame(3, $mb->releaseCount(self::COMETS));
        $this->assertSame('Bill Haley', $mb->artist(self::HALEY)['name']);
        Http::assertNothingSent();

        // lokal nichts gefunden: dann doch die Schnittstelle
        $this->assertSame([], $mb->search('Ganz Unbekannt', 1));
        Http::assertSentCount(1);

        // Namensprüfung nutzt es mit
        app(NameCheck::class)->check(NameCheck::NAME, 'Bill Haley And His Comets');
        $this->assertSame(['ok', 'Group', 3], array_values(array_intersect_key(NameCheck::results(['Bill Haley And His Comets'])['Bill Haley And His Comets']['musicbrainz'], array_flip(['status', 'type', 'releases']))));
    }

    public function test_same_stand_is_skipped_newer_replaces_and_a_broken_dump_keeps_the_old_data(): void
    {
        $data = app(MusicBrainzData::class);
        $data->update();
        $this->assertStringContainsString('sind aktuell', app(MusicBrainzData::class)->update());

        $this->dump('20261114-001001', [['id' => '33333333-3333-3333-3333-333333333333', 'name' => 'Danny', 'type' => 'Person', 'aliases' => [], 'relations' => []]]);
        app(MusicBrainzData::class)->update();
        $this->assertSame(3, DB::table('mb_artists')->count());
        $this->assertSame('20261114-001001', app(MusicBrainzData::class)->status()['dump']);

        file_put_contents($this->musicBrainzDir.'/LATEST', "20261118-001001\n");   // gibt es nicht
        try {
            app(MusicBrainzData::class)->update();
            $this->fail('Fehler erwartet');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('abgebrochen', $e->getMessage());
        }
        $this->assertSame(3, DB::table('mb_artists')->count(), 'alter Stand bleibt');
        $this->assertFalse(\Schema::hasTable('mb_artists_neu'));
    }

    public function test_settings_page_loads_in_the_background_and_shows_the_stand(): void
    {
        $page = Livewire::test(SettingsPage::class)->set('tab', 'musicbrainz')->assertSee('Noch nicht geladen')->assertSee('Jetzt laden');

        $page->call('updateMusicBrainz');   // Warteschlange in Tests: sofort

        $this->assertStringContainsString('2 Interpreten', Activity::current('musicbrainz')['message']);
        $page->call('musicBrainzFinished')->assertSee('20261111-001001')->assertSee('Jetzt aktualisieren');
    }

    public function test_namesakes_are_counted_locally_and_stored_by_the_name_check(): void
    {
        $this->dump('20261111-001001', [
            ['id' => '44444444-4444-4444-4444-444444444444', 'name' => 'Bill Haley', 'type' => 'Person', 'country' => 'GB', 'disambiguation' => 'UK folk singer', 'aliases' => [], 'relations' => []],
            ['id' => '55555555-5555-5555-5555-555555555555', 'name' => 'Billy H.', 'type' => 'Person', 'aliases' => [['name' => 'Bill Haley', 'type' => 'Search hint']], 'relations' => []],
        ]);
        app(MusicBrainzData::class)->update();

        $this->assertSame(2, app(MusicBrainz::class)->namesakes('Bill Haley'), 'nur Namen, keine Schreibweisen');
        $this->assertSame(1, app(MusicBrainz::class)->namesakes('Bill Haley and His Comets'));

        Http::fake(['*' => Http::response([], 404)]);
        app(NameCheck::class)->check(NameCheck::NAME, 'Bill Haley');
        $this->assertSame(2, NameCheck::results(['Bill Haley'])['Bill Haley']['musicbrainz']['namesakes']);
    }

    public function test_members_are_imported_once_from_both_sides(): void
    {
        app(MusicBrainzData::class)->update();
        $data = app(MusicBrainzData::class);

        $this->assertSame(1, DB::table('mb_members')->count(), 'steht bei Gruppe und Person – einmal gespeichert');
        $this->assertSame([['mbid' => self::HALEY, 'name' => 'Bill Haley', 'type' => 'Person', 'ended' => true]], $data->members(self::COMETS));
        $this->assertSame('Bill Haley and His Comets', $data->memberOf(self::HALEY)[0]['name']);
        $this->assertSame('Bill Haley', app(MusicBrainz::class)->search('Bill Haley & His Comets', 1)[0]['members'][0]['name']);
    }
}
