<?php

namespace Tests\Feature;

use App\Jobs\WriteChanges;
use App\Livewire\AlbumList;
use App\Livewire\TrackTable;
use App\Models\FilePlan;
use App\Models\Track;
use App\Support\IncompleteAlbums;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class IncompleteAlbumsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        config(['musicwiz.lookup_rates' => array_fill_keys(['musicbrainz', 'deezer', 'itunes'], 1_000_000)]);
        foreach ([1 => 'Eins', 2 => 'Zwei', 4 => 'Vier'] as $n => $title) {
            $this->mp3("library/ABBA/Hits/0$n.mp3", ['TIT2' => $title, 'TPE1' => 'ABBA', 'TALB' => 'Hits', 'TRCK' => "$n/4"]);
        }
        // gleiches Album, allein im Ordner (darf verschoben werden)
        $this->mp3('inbox/Einzeln/03.mp3', ['TIT2' => 'Drei', 'TPE1' => 'ABBA', 'TALB' => 'Hits', 'TRCK' => '3']);
        // Teil eines anderen Albums (nur kopieren) und im Blackhole
        $this->mp3('library/ABBA/Best/01.mp3', ['TIT2' => 'Drei (Remaster)', 'TPE1' => 'ABBA', 'TALB' => 'Best Of', 'TRCK' => '1']);
        $this->mp3('library/ABBA/Best/02.mp3', ['TIT2' => 'Fünf', 'TPE1' => 'ABBA', 'TALB' => 'Best Of', 'TRCK' => '2']);
        $this->mp3('blackhole/X/01.mp3', ['TIT2' => 'Drei', 'TPE1' => 'ABBA feat. Cher', 'TALB' => 'X']);
        // Titelanzahl falsch: 2 von 3, es fehlt nur die letzte
        $this->mp3('library/Cher/Kurz/01.mp3', ['TIT2' => 'K1', 'TPE1' => 'Cher', 'TALB' => 'Kurz', 'TRCK' => '1/3']);
        $this->mp3('library/Cher/Kurz/02.mp3', ['TIT2' => 'K2', 'TPE1' => 'Cher', 'TALB' => 'Kurz', 'TRCK' => '2/3']);
        foreach (['library', 'inbox', 'blackhole'] as $location) {
            app(Scanner::class)->scan($location);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function hash(string $album): string
    {
        return Track::where('location', 'library')->where('album', $album)->value('album_hash');
    }

    private function fakeTracklist(): void
    {
        Http::fake([
            'musicbrainz.org/*' => Http::response(['releases' => []]),
            'itunes.apple.com/*' => Http::response(['results' => []]),
            'api.deezer.com/search/album*' => Http::response(['data' => [['id' => 7, 'title' => 'Hits', 'artist' => ['name' => 'ABBA']], ['id' => 8, 'title' => 'Hits (Live)', 'artist' => ['name' => 'ABBA']]]]),
            'api.deezer.com/album/7/tracks*' => Http::response(['data' => array_map(fn ($n, $t) => ['disk_number' => 1, 'track_position' => $n, 'title' => $t, 'artist' => ['name' => 'ABBA'], 'duration' => 3],
                [1, 2, 3, 4], ['Eins', 'Zwei', 'Drei', 'Vier'])]),
            'api.deezer.com/album/8/tracks*' => Http::response(['data' => [['disk_number' => 1, 'track_position' => 1, 'title' => 'Ganz anders', 'artist' => ['name' => 'ABBA']]]]),
        ]);
    }

    public function test_finds_gaps_and_wrong_totals(): void
    {
        $found = app(IncompleteAlbums::class)->findings();
        $this->assertEqualsCanonicalizing([$this->hash('Hits'), $this->hash('Kurz')], $found->keys()->all());
        $this->assertSame([3], $found[$this->hash('Hits')]['discs'][1]['missing']);

        Livewire::test(AlbumList::class)->set('check', 'unvollstaendig')->assertSee('3 von 4 – es fehlt 3')
            ->call('toggle', $this->hash('Kurz'))->assertSee('vielleicht ist die Titelanzahl falsch')
            ->call('fixTotals', $this->hash('Kurz'))->assertSee('bei 2 Liedern');
        $this->assertFalse(app(IncompleteAlbums::class)->findings()->has($this->hash('Kurz')));
    }

    public function test_local_candidate_without_internet_and_moving_a_lone_track(): void
    {
        $hash = $this->hash('Hits');
        $details = app(IncompleteAlbums::class)->details($hash);
        $this->assertSame(['Drei'], array_map(fn ($c) => $c['track']->title, $details['slots'][0]['candidates']));
        $this->assertTrue($details['slots'][0]['candidates'][0]['alone']);

        Livewire::test(AlbumList::class)->set('check', 'unvollstaendig')->call('toggle', $hash)->assertSee('gleiches Album, Nr. 3')
            ->call('planTrack', Track::where('title', 'Drei')->where('location', 'inbox')->value('id'), $hash, 1, 3, 'move')->assertSee('vorgemerkt');
        $plan = FilePlan::sole();
        $this->assertSame(['move', 'Hits', 3, 4], [$plan->action, $plan->values['album'], $plan->values['track'], $plan->values['track_total']]);
        $this->assertFalse(app(IncompleteAlbums::class)->findings()->has($hash), 'vorgemerkt zählt als vorhanden');
        $this->assertSame('inbox', Track::where('title', 'Drei')->where('location', 'inbox')->value('location'), 'nur vorgemerkt');
        Livewire::test(TrackTable::class, ['slug' => 'lib'])->assertSee('vorgemerkt')->assertSee('wird verschoben aus Inbox');
    }

    public function test_a_lone_track_moves_into_one_album_and_is_copied_into_others(): void
    {
        $drei = Track::where('title', 'Drei')->where('location', 'inbox')->sole();
        $hits = $this->hash('Hits');
        $kurz = $this->hash('Kurz');
        Livewire::test(AlbumList::class)->set('check', 'unvollstaendig')->call('toggle', $hits)->call('planTrack', $drei->id, $hits, 1, 3, 'move')
            ->call('toggle', $kurz)->call('planTrack', $drei->id, $kurz, 1, 3, 'move')->assertSee('hierher kommt eine Kopie');
        $this->assertSame(['move', 'copy'], [FilePlan::where('target_album_hash', $hits)->value('action'), FilePlan::where('target_album_hash', $kurz)->value('action')]);
        // auch zwei „verschieben“ (älterer Stand): beim Schreiben wird das zweite zur Kopie
        FilePlan::where('target_album_hash', $kurz)->update(['action' => 'move']);

        WriteChanges::start();

        $this->assertSame(['01.mp3', '02.mp3', '03 - Drei.mp3', '04.mp3'], Track::where('album_hash', $hits)->orderBy('filename')->pluck('filename')->all());
        $this->assertSame(3, Track::where('album_hash', $kurz)->count());
        $this->assertSame($hits, $drei->refresh()->album_hash, 'verschoben ins erste Album, nicht wieder herausgeholt');
        $this->assertSame(0, FilePlan::count());
    }

    public function test_tracklist_names_the_missing_title_and_never_tears_an_album(): void
    {
        $this->fakeTracklist();
        $hash = $this->hash('Hits');
        Livewire::test(AlbumList::class)->set('check', 'unvollstaendig')->call('fetchTracklist', $hash)
            ->assertSee('passende gewählt')->assertSee('Deezer – „Hits“')->assertSee('passt zu 3 von 3');

        $slot = app(IncompleteAlbums::class)->details($hash)['slots'][0];
        $this->assertSame(['Drei', 'ABBA'], [$slot['title'], $slot['artist']]);
        $byTitle = collect($slot['candidates'])->keyBy(fn ($c) => $c['track']->location.'|'.$c['track']->title);
        $this->assertEqualsCanonicalizing(['inbox|Drei', 'library|Drei (Remaster)', 'blackhole|Drei'], $byTitle->keys()->all());
        $this->assertFalse($byTitle['library|Drei (Remaster)']['alone'], 'gehört zu „Best Of“');
        $this->assertTrue($byTitle['blackhole|Drei']['blackhole']);

        $remaster = $byTitle['library|Drei (Remaster)']['track'];
        $this->assertThrows(fn () => app(IncompleteAlbums::class)->plan($remaster->id, $hash, 1, 3, 'move'), InvalidArgumentException::class);
        $this->assertThrows(fn () => app(IncompleteAlbums::class)->plan($byTitle['blackhole|Drei']['track']->id, $hash, 1, 3, 'copy'), InvalidArgumentException::class);
        app(IncompleteAlbums::class)->plan($remaster->id, $hash, 1, 3, 'copy');
        $this->assertSame('copy', FilePlan::sole()->action);
        $this->assertSame(2, Track::where('album', 'Best Of')->count(), '„Best Of“ bleibt ganz');

        // Blackhole: erst in die Inbox
        Livewire::test(AlbumList::class)->call('toInbox', $byTitle['blackhole|Drei']['track']->id);
        $this->assertSame('inbox', $byTitle['blackhole|Drei']['track']->fresh()->move_to);
    }

    public function test_exempt_and_matching_helpers(): void
    {
        $hash = $this->hash('Hits');
        Livewire::test(AlbumList::class)->set('check', 'unvollstaendig')->call('exempt', $hash, true)->assertSee('gewollt unvollständig');
        $this->assertFalse(app(IncompleteAlbums::class)->findings()->has($hash));
        $this->assertTrue(app(IncompleteAlbums::class)->findings(true)->has($hash));
        app(IncompleteAlbums::class)->exempt($hash, false);
        $this->assertTrue(app(IncompleteAlbums::class)->findings()->has($hash));

        $this->assertTrue(IncompleteAlbums::sameTitle('Summer Moved On (Radio Edit)', 'Summer Moved On - Radio Edit'));
        $this->assertTrue(IncompleteAlbums::sameTitle('Drei (Remaster)', 'Drei'));
        $this->assertFalse(IncompleteAlbums::sameTitle('Zwei', 'Drei'));
        $this->assertTrue(IncompleteAlbums::sameArtist('Cher feat. Eros Ramazzotti', 'Cher'));
        $this->assertFalse(IncompleteAlbums::sameArtist('ABBA', 'Cher'));
    }
}
