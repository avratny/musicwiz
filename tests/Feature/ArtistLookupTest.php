<?php

namespace Tests\Feature;

use App\Jobs\FetchPreviews;
use App\Jobs\LookupArtist;
use App\Jobs\LookupArtists;
use App\Livewire\ArtistList;
use App\Models\Artist;
use App\Models\ArtistImage;
use App\Models\ArtistSource;
use App\Models\TagChange;
use App\Support\Activity;
use App\Support\Lookup\ArtistLookup;
use App\Support\Lookup\ArtistPreviews;
use App\Support\Lookup\Client;
use App\Support\Lookup\LastFm;
use App\Support\Lookup\LookupGroups;
use App\Support\Lookup\MusicBrainz;
use App\Support\Lookup\NameCheck;
use App\Support\Lookup\Throttled;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\FakesLookupServices;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

/** Abgleich mit MusicBrainz, Spotify, Discogs, TheAudioDB, Fanart.tv – Antworten simuliert (keine echten Anfragen). */
class ArtistLookupTest extends TestCase
{
    use FakesLookupServices, MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        config(['musicwiz.lookup_rates' => array_fill_keys(['musicbrainz', 'spotify', 'discogs', 'audiodb', 'fanart', 'images'], 1_000_000)]);
        Cache::flush();
        app(Settings::class)->set(['spotify_client_id' => 'id', 'spotify_client_secret' => 'secret', 'discogs_token' => 'tok', 'fanart_key' => 'fk', 'audiodb_key' => 'ak']);
        $this->mp3('library/a-ha/Hits/01.mp3', ['TIT2' => 'Take On Me', 'TPE1' => 'a-ha', 'TALB' => 'Hits']);
        $this->mp3('blackhole/Nur/Hier/01.mp3', ['TIT2' => 'Nur im Blackhole', 'TPE1' => 'Blackhole-Band']);
        app(Scanner::class)->scan('library');
        app(Scanner::class)->scan('blackhole');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_all_services_are_queried_linked_and_stored_as_one_group(): void
    {
        $this->fakeServices();
        $artist = Artist::where('name', 'a-ha')->first();

        $status = app(ArtistLookup::class)->run($artist);

        $this->assertSame(['musicbrainz' => 'ok', 'spotify' => 'ok', 'discogs' => 'ok', 'audiodb' => 'ok', 'fanart' => 'ok', 'lastfm' => 'uebersprungen'], $status, 'Last.fm ohne Schlüssel');
        $this->assertSame(['audiodb', 'discogs', 'fanart', 'musicbrainz', 'spotify'], ArtistSource::orderBy('service')->pluck('service')->all(), 'je Dienst ein Treffer (Aha Shake passt nicht)');
        $this->assertSame([self::MBID], ArtistSource::pluck('mbid')->unique()->values()->all(), 'Spotify/Discogs über MusicBrainz-Links verknüpft');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'musicbrainz.org') && $r->header('User-Agent')[0] === Client::USER_AGENT);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.spotify.com/v1/search'), 'kein Namenssuchen, wenn verknüpft');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.spotify.com/v1/artists'), 'ohne lokale MusicBrainz-Daten nicht eindeutig: Spotify gefragt');

        $this->assertSame(4, ArtistImage::count(), 'je Dienst die gelieferten Bilder');
        $this->assertSame(4, ArtistImage::whereNotNull('preview')->count(), 'Vorschauen lokal');
        $this->assertNotNull($artist->fresh()->looked_up_at);

        $groups = app(LookupGroups::class)->for($artist);
        $this->assertCount(1, $groups);
        $this->assertSame(['a-ha' => ['Spotify', 'Discogs', 'TheAudioDB'], 'a‐ha' => ['MusicBrainz', 'Fanart.tv']], $groups[0]['spellings'], 'häufigste Schreibweise zuerst');
        $this->assertArrayHasKey('Aha', $groups[0]['aliases']);
        $this->assertArrayNotHasKey('Morten Harket Band', $groups[0]['aliases'], 'juristischer Name ist keine Schreibweise');
    }

    public function test_choosing_a_group_stores_the_ids_and_changes_no_tags(): void
    {
        $this->fakeServices();
        $artist = Artist::where('name', 'a-ha')->first();
        app(ArtistLookup::class)->run($artist);
        $key = app(LookupGroups::class)->for($artist)[0]['key'];

        Livewire::test(ArtistList::class)->call('open', $artist->id)->assertSee('Diesen nehmen')->assertSee('= Name, nur andere Zeichen')->assertSee('Schreibweise bestätigen')
            ->call('chooseGroup', $key)->assertSee('IDs hinterlegt')->assertSee('✓ gewählt');

        $artist->refresh();
        $this->assertSame([self::MBID, '37757', '2jzc5TC5TVFLXQlBNiIUzE'], [$artist->mbid, $artist->discogs_id, $artist->spotify_id]);
        $this->assertTrue($artist->confirmed, 'Treffer gewählt = Schreibweise bestätigt');
        $this->assertSame(5, ArtistSource::where('chosen', true)->count());
        $this->assertSame(0, TagChange::count());
    }

    public function test_adopting_spelling_and_image_from_the_lookup(): void
    {
        $this->fakeServices();
        File::ensureDirectoryExists($this->base.'/_artists');
        config(['musicwiz.artist_images' => $this->base.'/_artists']);
        $artist = Artist::where('name', 'a-ha')->first();
        app(ArtistLookup::class)->run($artist);
        $page = Livewire::test(ArtistList::class)->call('open', $artist->id);

        $page->call('adoptAlias', 'Aha')->assertSee('„Aha“ als Schreibweise übernommen');
        $page->call('useImage', ArtistImage::whereHas('source', fn ($q) => $q->where('service', 'spotify'))->value('id'))->assertSee('Bild übernommen');

        $this->assertSame('a-ha.jpg', $artist->fresh()->image);
        $this->assertContains('Aha', $artist->fresh()->aliases->pluck('alias')->all());
        Http::assertSent(fn ($r) => $r->url() === 'https://i.scdn.co/big.jpg', 'Original geladen');
    }

    public function test_relookup_from_the_flyout_shows_the_new_results(): void
    {
        $this->fakeServices();
        $artist = Artist::where('name', 'a-ha')->first();

        // Queue in Tests: der Auftrag läuft sofort – das Flyout zeichnet sich danach mit den Ergebnissen neu
        Livewire::test(ArtistList::class)->call('open', $artist->id)->assertSee('Noch nicht abgeglichen')
            ->call('relookup')->call('$refresh')->assertSee('Abgleich aktualisiert')->assertSee('Diesen nehmen')->assertSet('awaiting', null);

        $this->assertInstanceOf(Carbon::class, $artist->fresh()->looked_up_at);
    }

    public function test_rate_limit_answers_are_respected(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response('', 503, ['Retry-After' => '120'])]);

        try {
            app(Client::class)->get('musicbrainz', 'https://musicbrainz.org/ws/2/artist', []);
            $this->fail('erwartet: Sperre');
        } catch (Throttled $e) {
            $this->assertStringContainsString('bremst', $e->getMessage());
        }
        $this->assertNotNull(Client::blockedUntil('musicbrainz'));
        Http::assertSentCount(1);

        // gesperrt: gar nicht mehr fragen, Abgleich der anderen Dienste läuft weiter
        $artist = Artist::where('name', 'a-ha')->first();
        $status = app(ArtistLookup::class)->run($artist);
        $this->assertSame('gesperrt', $status['musicbrainz']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'musicbrainz.org/ws/2/artist?'));

        // nachgeholt wird erst, wenn die Sperre vorbei ist – bis dahin wartet der Abgleich darauf
        $this->assertFalse(LookupArtists::pending()->whereKey($artist->id)->exists());
        $this->assertSame(Client::blockedUntil('musicbrainz'), LookupArtists::waitingUntil());
        Cache::forget('lookup:blocked:musicbrainz');
        $this->assertTrue(LookupArtists::pending()->whereKey($artist->id)->exists());
        $this->assertNull(LookupArtists::waitingUntil());
    }

    public function test_a_service_without_answer_fails_alone_and_is_paused_when_it_keeps_hanging(): void
    {
        // Spotify antwortet nicht (Zeitüberschreitung) – vor den übrigen Antworten, damit es gilt
        Http::fake(['api.spotify.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $this->fakeServices();
        $artist = Artist::where('name', 'a-ha')->first();

        $status = app(ArtistLookup::class)->run($artist);
        $this->assertSame(['fehler', 'ok', 'ok'], [$status['spotify'], $status['musicbrainz'], $status['discogs']], 'nur Spotify betroffen');
        $this->assertNotNull($artist->fresh()->looked_up_at, 'Interpret erledigt, kommt nicht sofort wieder');
        $this->assertSame('spotify antwortet nicht (Zeitüberschreitung)', DB::table('artist_lookups')->where('service', 'spotify')->value('message'));
        $this->assertNull(Client::blockedUntil('spotify'), 'eine hängende Abfrage: noch nicht aussetzen');

        // zweite Zeitüberschreitung hintereinander: Spotify kurz aussetzen, das wird nachgeholt
        $artist->update(['looked_up_at' => null]);
        app(Client::class)->fresh(fn () => app(ArtistLookup::class)->run($artist));
        $this->assertNotNull(Client::blockedUntil('spotify'));
        $this->assertSame('gesperrt', app(ArtistLookup::class)->run($artist)['spotify']);
    }

    public function test_errors_are_retried_after_the_retry_days_not_the_refresh_days(): void
    {
        $artist = Artist::where('name', 'a-ha')->first();
        app(ArtistLookup::class)->run($artist);
        $this->assertFalse(LookupArtists::pending()->whereKey($artist->id)->exists());

        DB::table('artist_lookups')->where('artist_id', $artist->id)->where('service', 'audiodb')->update(['status' => 'fehler', 'fetched_at' => now()->subDays(29)]);
        $this->assertFalse(LookupArtists::pending()->whereKey($artist->id)->exists());
        DB::table('artist_lookups')->where('artist_id', $artist->id)->where('service', 'audiodb')->update(['fetched_at' => now()->subDays(31)]);
        $this->assertTrue(LookupArtists::pending()->whereKey($artist->id)->exists());
    }

    public function test_background_lookup_covers_lib_and_inbox_not_blackhole_and_shows_progress(): void
    {
        $this->fakeServices();
        $this->assertSame(['a-ha'], LookupArtists::pending()->pluck('name')->all(), 'Blackhole-Band nur im Blackhole');

        $this->assertTrue(LookupArtists::start()); // Queue in Tests: sofort

        $done = Activity::current('lookup');
        $this->assertFalse($done['running']);
        $this->assertStringContainsString('Abgleich fertig – 1 Interpreten', $done['message']);
        $this->assertSame(0, LookupArtists::pending()->count());
        $this->assertFalse(LookupArtists::start(), 'nichts mehr offen');
    }

    public function test_answers_are_cached_and_shared_only_relookup_asks_again(): void
    {
        // vor den übrigen Antworten: „i.discogs.com/*“ (Bilder) passt sonst auch auf api.discogs.com
        Http::fake([
            'musicbrainz.org/ws/2/release-group*' => Http::response(['release-group-count' => 42, 'release-groups' => []]),
            'api.discogs.com/artists/37757/releases*' => Http::response(['pagination' => ['items' => 310], 'releases' => []]),
            'api.discogs.com/database/search*' => Http::response(['results' => [['id' => 37757, 'title' => 'a-ha']]]),
        ]);
        $this->fakeServices();
        $artist = Artist::where('name', 'a-ha')->sole();
        $requests = fn () => Http::recorded(fn ($r) => ! str_contains($r->url(), 'accounts.spotify.com') && ! preg_match('~i\.scdn|i\.discogs|r2\.theaudiodb|assets\.fanart~', $r->url()))->count();

        app(ArtistLookup::class)->run($artist);
        $first = $requests();
        $this->assertSame(['MusicBrainz' => 42, 'Discogs' => 310], app(LookupGroups::class)->for($artist)[0]['releases'], 'Veröffentlichungen gespeichert');

        // zweiter Abgleich und Namensprüfung desselben Namens: alles aus dem Zwischenspeicher
        app(ArtistLookup::class)->run($artist->fresh());
        app(NameCheck::class)->check(NameCheck::NAME, 'a-ha');
        $this->assertSame(['ok', 42], [NameCheck::results(['a-ha'])['a-ha']['musicbrainz']['status'], NameCheck::results(['a-ha'])['a-ha']['musicbrainz']['releases']]);
        $this->assertSame(310, NameCheck::results(['a-ha'])['a-ha']['discogs']['releases']);
        $before = $requests();
        $this->assertSame(3, $before - $first, 'nur die Namenssuchen, die der Abgleich nicht gebraucht hatte (Spotify, Discogs, TheAudioDB)');

        app(ArtistLookup::class)->run($artist->fresh());
        $this->assertSame($before, $requests(), 'dritter Abgleich: keine einzige Abfrage');

        // „Neu abgleichen“: fragt die Dienste wirklich
        LookupArtist::dispatch($artist->id);
        $this->assertGreaterThanOrEqual($first, $requests() - $before);

        // Fehler werden nicht gespeichert
        Http::fake(['musicbrainz.org/ws/2/label*' => Http::response('', 500)]);
        $this->assertSame(500, app(Client::class)->get('musicbrainz', 'https://musicbrainz.org/ws/2/label', ['query' => 'x'])->status());
        $this->assertSame(0, DB::table('service_cache')->where('url', 'like', '%/label?%')->count());
    }

    public function test_expired_answers_are_removed_after_scanning(): void
    {
        $row = ['service' => 'musicbrainz', 'url' => 'x', 'status' => 200, 'body' => '{}'];
        DB::table('service_cache')->insert([
            ['key' => str_repeat('a', 40), 'fetched_at' => now()->subDays(91)] + $row,
            ['key' => str_repeat('b', 40), 'fetched_at' => now()->subDays(10)] + $row,
        ]);

        app(Scanner::class)->scan('library');

        $this->assertSame([str_repeat('b', 40)], DB::table('service_cache')->pluck('key')->all(), 'älter als die Frist: weg');
    }

    public function test_musicbrainz_prefers_the_hit_with_the_searched_name_over_a_higher_score(): void
    {
        Http::fake([
            'musicbrainz.org/ws/2/artist/person*' => Http::response(['id' => 'person', 'name' => 'Bill Haley', 'type' => 'Person']),
            'musicbrainz.org/ws/2/artist*' => Http::response(['artists' => [
                ['id' => 'group', 'name' => 'Bill Haley and His Comets', 'type' => 'Group', 'score' => 100, 'aliases' => [['name' => 'Bill Haley & His Comets']]],
                ['id' => 'person', 'name' => 'Bill Haley', 'type' => 'Person', 'score' => 79],
            ]]),
        ]);

        $hits = app(MusicBrainz::class)->search('Bill Haley', 1);

        $this->assertSame(['Bill Haley', 'Person'], [$hits[0]['name'], $hits[0]['data']['type']], 'nicht die Gruppe mit dem höheren Score');
    }

    public function test_lastfm_adds_listeners_tags_and_bio_over_the_musicbrainz_id(): void
    {
        app(Settings::class)->set(['lastfm_api_key' => 'lfm']);
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['artist' => ['name' => 'a-ha', 'mbid' => self::MBID, 'url' => 'https://www.last.fm/music/a-ha',
            'stats' => ['listeners' => '2345678', 'playcount' => '98765432'], 'tags' => ['tag' => [['name' => 'synthpop'], ['name' => 'seen live']]],
            'bio' => ['summary' => 'Norwegische Band. <a href="https://www.last.fm/music/a-ha">Read more on Last.fm</a>']]])]);
        $this->fakeServices();
        $artist = Artist::where('name', 'a-ha')->sole();

        $this->assertSame('ok', app(ArtistLookup::class)->run($artist)['lastfm']);

        $source = ArtistSource::where('service', 'lastfm')->sole();
        $this->assertSame([self::MBID, 'verknuepft', 2345678, ['synthpop', 'seen live'], 'Norwegische Band.'],
            [$source->mbid, $source->match, $source->data['listeners'], $source->data['genres'], $source->data['biography']]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ws.audioscrobbler.com') && str_contains($r->url(), 'mbid='.self::MBID));
        $groups = app(LookupGroups::class)->for($artist);
        $this->assertCount(1, $groups, 'über die MusicBrainz-ID in derselben Gruppe');
        $this->assertSame(2345678, $groups[0]['listeners']);
    }

    public function test_artists_looked_up_before_a_new_service_existed_are_pending_again(): void
    {
        $artist = Artist::where('name', 'a-ha')->sole();
        $artist->forceFill(['looked_up_at' => now()])->save();
        foreach (array_diff(array_keys(ArtistSource::SERVICES), ['lastfm']) as $service) {
            \DB::table('artist_lookups')->insert(['artist_id' => $artist->id, 'service' => $service, 'status' => 'ok', 'fetched_at' => now()]);
        }
        $this->assertTrue(LookupArtists::pending()->whereKey($artist->id)->exists(), 'Last.fm fehlt noch');

        \DB::table('artist_lookups')->insert(['artist_id' => $artist->id, 'service' => 'lastfm', 'status' => 'uebersprungen', 'fetched_at' => now()]);
        $this->assertFalse(LookupArtists::pending()->whereKey($artist->id)->exists());
    }

    public function test_lastfm_unknown_name_is_not_found_not_an_error(): void
    {
        app(Settings::class)->set(['lastfm_api_key' => 'lfm']);
        Http::fake(['ws.audioscrobbler.com/*' => Http::response(['error' => 6, 'message' => 'The artist you supplied could not be found'], 400)]);

        $this->assertNull(app(LastFm::class)->byName('Gibt Es Nicht'));
    }

    public function test_namesakes_without_local_data_come_from_the_search(): void
    {
        Http::fake(['musicbrainz.org/ws/2/artist*' => Http::response(['artists' => [
            ['id' => 'a', 'name' => 'Beam', 'score' => 100], ['id' => 'b', 'name' => 'BEAM', 'score' => 100], ['id' => 'c', 'name' => 'Beam & Yanou', 'score' => 70],
        ]])]);

        $this->assertSame(2, app(MusicBrainz::class)->namesakes('Beam'));
        $this->assertSame(2, app(MusicBrainz::class)->namesakes('Beam'));
        Http::assertSentCount(1);
    }

    public function test_previews_load_in_the_background_and_on_demand(): void
    {
        $this->fakeServices();
        $artist = Artist::where('name', 'a-ha')->sole();

        Queue::fake();
        app(ArtistLookup::class)->run($artist);
        Queue::assertPushedOn('images', FetchPreviews::class, fn ($job) => $job->artistId === $artist->id);
        $this->assertSame(0, ArtistImage::whereNotNull('preview')->count(), 'der Abgleich selbst lädt keine Bilder');

        // im Flyout angezeigt, bevor der Hintergrund so weit ist: sofort nachgeladen
        $image = ArtistImage::first();
        $this->get(route('artist.preview', $image))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertNotNull($image->fresh()->preview);

        // der Hintergrund-Auftrag holt den Rest
        (new FetchPreviews($artist->id))->handle(app(ArtistPreviews::class));
        $this->assertSame(ArtistImage::count(), ArtistImage::whereNotNull('preview')->count());
    }
}
