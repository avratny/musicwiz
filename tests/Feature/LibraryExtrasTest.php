<?php

namespace Tests\Feature;

use App\Jobs\InboxWatchJob;
use App\Jobs\ScanJob;
use App\Livewire\AlbumEditor;
use App\Livewire\Cleanup;
use App\Livewire\Statistics;
use App\Models\Album;
use App\Models\Operation;
use App\Models\Track;
use App\Support\CleanupChecks;
use App\Support\Fingerprint;
use App\Support\InboxWatch;
use App\Support\Integrity;
use App\Support\Loudness;
use App\Support\Lyrics;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Album unvollständig, ReplayGain, Songtexte, Audiodaten prüfen, Inbox beobachten, Titelliste, AcoustID, Statistik.
 * Braucht ffmpeg und python3 mit mutagen (läuft im Container: ./dev.sh test).
 */
class LibraryExtrasTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/extras-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base);
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** Echter Ton (Sinus), damit Lautheit und Dekodieren etwas zu tun haben. */
    private function tone(string $rel, array $tags, float $volume = 0.5, int $seconds = 3): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', "sine=frequency=440:duration=$seconds", '-af', "volume=$volume",
            ...($ext === 'mp3' ? ['-b:a', '128k'] : []), $path])->throw();
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags]]);

        return $path;
    }

    public function test_incomplete_album(): void
    {
        foreach ([1, 2, 4] as $n) {
            $this->tone("library/artists/A/ABBA/Gold (1992)/0$n - T$n.mp3", ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => (string) $n, 'tracktotal' => '5', 'title' => "T$n", 'comment' => 'LIB'], 0.5, 1);
        }
        app(Scanner::class)->scan('library');

        $f = app(CleanupChecks::class)->findings('incomplete');
        $this->assertCount(1, $f);
        $this->assertSame('3 von 5 – es fehlen 3, 5', $f[0]['detail']);
        $this->assertSame([], $f[0]['actions']);
    }

    public function test_tagtool_reads_and_writes_replaygain_and_lyrics(): void
    {
        foreach (['mp3', 'flac'] as $ext) {
            $path = $this->tone("library/artists/A/ABBA/Gold/01 - SOS.$ext", ['artist' => 'ABBA', 'title' => 'SOS'], 0.5, 1);
            $lrc = "[00:01.00] So when you're near me\n[00:03.00] darling can't you hear me";
            $result = app(TagTool::class)->write([['path' => $path, 'tags' => ['rg_track_gain' => '-6.62 dB', 'rg_album_peak' => '0.988000', 'lyrics' => $lrc]]]);
            $this->assertTrue($result[$path]['ok'], $result[$path]['error'] ?? '');
            $tags = app(TagTool::class)->read([$path])[$path]['tags'];
            $this->assertSame('-6.62 dB', $tags['rg_track_gain'], $ext);
            $this->assertSame('0.988000', $tags['rg_album_peak'], $ext);
            $this->assertSame('', $tags['rg_track_peak'], $ext);
            $this->assertSame($lrc, $tags['lyrics'], $ext);
            $this->assertSame('ABBA', $tags['artist'], $ext);
        }
        app(Scanner::class)->scan('library');
        $this->assertSame(['-6.62 dB'], Track::pluck('replaygain')->unique()->values()->all());
        $this->assertSame([true], Track::pluck('has_lyrics')->unique()->values()->all());
    }

    public function test_loudness_parse_and_album_gain(): void
    {
        $log = "[Parsed_ebur128_0 @ 0x1] Summary:\n\n  Integrated loudness:\n    I:         -12.0 LUFS\n    Threshold: -22.0 LUFS\n\n  True peak:\n    Peak:        -1.0 dBFS\n";
        $m = Loudness::parse($log);
        $this->assertSame(-12.0, $m['lufs']);
        $this->assertEqualsWithDelta(0.891, $m['peak'], 0.001);
        $this->assertNull(Loudness::parse("Summary:\n    I:         -inf LUFS\n"));

        $tags = Loudness::tags(['/a' => ['lufs' => -12.0, 'peak' => 0.5, 'duration' => 100], '/b' => ['lufs' => -12.0, 'peak' => 0.9, 'duration' => 100]]);
        $this->assertSame('-6.00 dB', $tags['/a']['rg_track_gain']);
        $this->assertSame('-6.00 dB', $tags['/b']['rg_album_gain']); // gleich laut: Album = Titel
        $this->assertSame('0.900000', $tags['/a']['rg_album_peak']);
    }

    public function test_replaygain_check_measures_writes_and_undoes(): void
    {
        $a = $this->tone('library/artists/A/ABBA/Gold (1992)/01 - Loud.flac', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'Loud', 'comment' => 'LIB'], 0.8);
        $b = $this->tone('library/artists/A/ABBA/Gold (1992)/02 - Quiet.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '2', 'title' => 'Quiet', 'comment' => 'LIB'], 0.1);
        app(Scanner::class)->scan('library');

        $f = app(CleanupChecks::class)->findings('replaygain');
        $this->assertCount(1, $f);
        $this->assertSame('replaygain', $f[0]['actions'][0]['type']);
        $op = OperationRunner::dispatch('cleanup', 'ReplayGain', $f[0]['actions']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        $tags = app(TagTool::class)->read([$a, $b]);
        $gainA = (float) $tags[$a]['tags']['rg_track_gain'];
        $gainB = (float) $tags[$b]['tags']['rg_track_gain'];
        $this->assertGreaterThan($gainA, $gainB, 'leiser Titel bekommt mehr Verstärkung');
        $this->assertSame($tags[$a]['tags']['rg_album_gain'], $tags[$b]['tags']['rg_album_gain']);
        $this->assertSame('ABBA', $tags[$b]['tags']['artist']);
        $this->assertSame([], app(CleanupChecks::class)->findings('replaygain'));

        OperationRunner::dispatchUndo($op->fresh());
        $this->assertSame('', app(TagTool::class)->read([$a])[$a]['tags']['rg_track_gain']);
    }

    public function test_editor_shows_measures_and_removes_replaygain(): void
    {
        $a = $this->tone('library/artists/A/ABBA/Gold (1992)/01 - Loud.flac', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'Loud', 'comment' => 'LIB'], 0.8);
        $this->tone('library/artists/A/ABBA/Gold (1992)/02 - Quiet.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '2', 'title' => 'Quiet', 'comment' => 'LIB'], 0.1);
        app(Scanner::class)->scan('library');
        $album = Album::firstOrFail();

        $editor = Livewire::test(AlbumEditor::class, ['album' => $album])
            ->assertSee('ReplayGain')->assertSee('messen')->assertDontSee('entfernen')
            ->set('rows.0.title', 'Ungespeichert')
            ->call('measureReplaygain')
            ->call('checkOperation');
        $rows = $editor->get('rows');
        $this->assertNotNull($rows[0]['rg']);
        $this->assertNotNull($rows[1]['rg_album']);
        $this->assertSame('Ungespeichert', $rows[0]['title'], 'Messen lädt nur die Werte nach, Änderungen im Editor bleiben');
        $editor->assertSee('neu messen')->assertSee('entfernen');
        $this->assertMatchesRegularExpression('/ReplayGain: Album −?\d+,\d+ dB/u', $editor->html());
        $this->assertSame('replaygain', Operation::latest('id')->first()->type);

        $editor->call('removeReplaygain')->call('checkOperation');
        $this->assertNull($editor->get('rows')[0]['rg']);
        $this->assertSame('', app(TagTool::class)->read([$a])[$a]['tags']['rg_album_gain']);
        $this->assertNull(Track::where('path', $a)->value('replaygain'));
    }

    public function test_lyrics_fetched_written_and_missing_remembered(): void
    {
        Http::fake([
            'lrclib.net/api/get?*track_name=SOS*' => Http::response(['syncedLyrics' => '[00:01.00] Where are those happy days', 'plainLyrics' => 'Where are those happy days', 'instrumental' => false]),
            'lrclib.net/*' => Http::response(['message' => 'not found'], 404),
        ]);
        $sos = $this->tone('library/artists/A/ABBA/Gold/01 - SOS.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'track' => '1', 'title' => 'SOS', 'comment' => 'LIB'], 0.5, 1);
        $other = $this->tone('library/artists/A/ABBA/Gold/02 - Unbekannt.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'track' => '2', 'title' => 'Unbekannt', 'comment' => 'LIB'], 0.5, 1);
        app(Scanner::class)->scan('library');

        $f = app(CleanupChecks::class)->findings('lyrics');
        $this->assertCount(2, $f[0]['actions']);
        $op = OperationRunner::dispatch('cleanup', 'Songtexte', $f[0]['actions']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        $this->assertSame('[00:01.00] Where are those happy days', app(TagTool::class)->read([$sos])[$sos]['tags']['lyrics']);
        $this->assertTrue(Lyrics::knownMissing($other));
        $this->assertSame(1, $op->entries()->where('kind', 'info')->count());
        $this->assertSame([], app(CleanupChecks::class)->findings('lyrics'), 'SOS hat jetzt Text, der andere wird 30 Tage nicht vorgeschlagen');
    }

    public function test_integrity_finds_damaged_audio(): void
    {
        $good = $this->tone('library/artists/A/ABBA/Gold/01 - Gut.flac', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'track' => '1', 'title' => 'Gut', 'comment' => 'LIB'], 0.5, 5);
        $bad = $this->tone('library/artists/A/ABBA/Gold/02 - Kaputt.flac', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'track' => '2', 'title' => 'Kaputt', 'comment' => 'LIB'], 0.5, 5);
        // Audiodaten in der Mitte beschädigen (Länge und Tags bleiben – das normale Einlesen merkt nichts)
        $data = file_get_contents($bad);
        $mid = intdiv(strlen($data), 2);
        file_put_contents($bad, substr($data, 0, $mid).str_repeat("\x55", 4000).substr($data, $mid + 4000));
        app(Scanner::class)->scan('library');
        $this->assertSame([], app(CleanupChecks::class)->findings('integrity'), 'vor der Prüfung keine Funde');

        $result = app(Integrity::class)->run();
        $this->assertSame(['checked' => 2, 'errors' => 1], $result);
        $f = app(CleanupChecks::class)->findings('integrity');
        $this->assertCount(1, $f);
        $this->assertSame([['type' => 'trash', 'path' => $bad, 'reason' => 'Audiodaten beschädigt']], $f[0]['actions']);

        $this->assertSame(['checked' => 0, 'errors' => 0], app(Integrity::class)->run(), 'zweiter Lauf: nichts Neues');
        $this->assertNull(app(Integrity::class)->check($good));

        Livewire::test(Cleanup::class)->set('check', 'integrity')->assertSee('Audiodaten prüfen')->assertSee('Kaputt');
    }

    public function test_inbox_watch_scans_only_after_changes(): void
    {
        Queue::fake();
        $watch = app(InboxWatch::class);
        $this->assertFalse($watch->tick(), 'aus: Beobachter hört auf');

        app(Settings::class)->set(['inbox_watch' => true]);
        $watch = app(InboxWatch::class); // im Betrieb pro Durchgang neu (Job)
        File::ensureDirectoryExists($this->base.'/inbox');
        $this->assertTrue($watch->tick());
        Queue::assertNotPushed(ScanJob::class); // erster Durchgang merkt sich nur den Stand
        $this->assertTrue($watch->tick());
        Queue::assertNotPushed(ScanJob::class);

        $this->tone('inbox/Neu/01.mp3', ['artist' => 'ABBA', 'title' => 'Neu'], 0.5, 1);
        $this->assertTrue($watch->tick());
        Queue::assertPushed(ScanJob::class, fn ($job) => $job->location === 'inbox');

        $watch->ensure();
        Queue::assertNotPushed(InboxWatchJob::class); // läuft schon (Lebenszeichen) – kein zweiter
        Cache::forget('inbox-watch:alive'); // z. B. nach Absturz abgelaufen
        $watch->ensure();
        $watch->ensure();
        Queue::assertPushedTimes(InboxWatchJob::class, 1);
    }

    public function test_tracklist_matching(): void
    {
        $rows = [
            ['disc' => '1', 'track' => '1', 'title' => 'waterloo'],
            ['disc' => '1', 'track' => '2', 'title' => 'SOS'],
            ['disc' => '', 'track' => '', 'title' => 'Mamma Mia'],
        ];
        $tl = ['release' => 'Gold', 'url' => 'u', 'tracks' => [
            ['disc' => 1, 'track' => 1, 'title' => 'Waterloo', 'artist' => 'ABBA', 'seconds' => 168.0],
            ['disc' => 1, 'track' => 2, 'title' => 'S.O.S.', 'artist' => 'ABBA', 'seconds' => 200.0],
            ['disc' => 1, 'track' => 3, 'title' => 'Mamma Mia', 'artist' => 'ABBA', 'seconds' => 210.0],
            ['disc' => 1, 'track' => 4, 'title' => 'Fernando', 'artist' => 'ABBA', 'seconds' => 250.0],
        ]];
        $m = AlbumEditor::matchTracklist($rows, $tl);
        $this->assertSame([0 => 'Waterloo', 1 => 'S.O.S.'], $m['titles']);
        $this->assertSame([2 => ['disc' => '1', 'track' => '3']], $m['numbers']);
        $this->assertSame(['4. Fernando'], $m['missing']);
        $this->assertSame(4, $m['count']);
    }

    public function test_acoustid_suggestions_in_editor(): void
    {
        app(Settings::class)->set(['acoustid_key' => 'test-key']);
        $this->app->instance(Fingerprint::class, new class extends Fingerprint
        {
            public function acoustid(string $path): ?string
            {
                return 'AQAAfake';
            }
        });
        Http::fake(['api.acoustid.org/*' => Http::response(['status' => 'ok', 'fingerprints' => [
            ['index' => 0, 'results' => [['score' => 0.94, 'id' => 'x', 'recordings' => [['title' => 'Dancing Queen', 'artists' => [['name' => 'ABBA']], 'releasegroups' => [['title' => 'Arrival', 'type' => 'Album']]]]]]],
        ]])]);
        $this->tone('inbox/Unbekannt/Track01.mp3', ['title' => '', 'artist' => ''], 0.5, 1);
        app(Scanner::class)->scan('inbox');

        Livewire::test(AlbumEditor::class, ['album' => Album::firstOrFail()])
            ->assertSee('Am Fingerabdruck erkennen')
            ->call('identifyTracks')
            ->assertSet('acoustid.0.title', 'Dancing Queen')
            ->assertSee('ABBA – Dancing Queen (94 %) übernehmen')
            ->call('useAcoustid', 0)
            ->assertSet('rows.0.title', 'Dancing Queen')
            ->assertSet('rows.0.artist', 'ABBA')
            ->assertSet('fields.album', 'Arrival');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'acoustid') && $r['client'] === 'test-key' && $r['fingerprint.0'] === 'AQAAfake');
    }

    public function test_statistics_page(): void
    {
        $this->tone('library/artists/A/ABBA/Gold (1992)/01 - SOS.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'SOS', 'genres' => ['Pop'], 'comment' => 'LIB'], 0.5, 1);
        app(Scanner::class)->scan('library');

        Livewire::test(Statistics::class)->assertOk()
            ->assertSee('Statistik')->assertSee('1990er')->assertSee('Kandidaten für FLAC')->assertSee('ABBA · 1');
        $this->get(route('statistics'))->assertOk();
    }
}
