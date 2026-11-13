<?php

namespace Tests\Feature;

use App\Jobs\MeasureFingerprints;
use App\Jobs\WriteChanges;
use App\Livewire\ChangeLog;
use App\Livewire\MiscPage;
use App\Models\FilePlan;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Duplicates;
use App\Support\Fingerprints;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class FingerprintsTest extends TestCase
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

    /** 20 s Rauschen – gleicher $seed = gleiche Aufnahme (auch als MP3), anderer $seed = andere */
    private function noise(string $rel, int $seed, array $tags): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        $kind = str_ends_with($path, '.mp3') ? 'mp3' : 'flac';
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'anoisesrc=d=20:c=pink:seed='.$seed, '-ac', '1',
            ...($kind === 'mp3' ? ['-b:a', '128k'] : []), $path])->throw();
        $this->tag($path, $kind, $tags);

        return $path;
    }

    private function scan(): void
    {
        foreach (['library', 'inbox', 'blackhole'] as $location) {
            app(Scanner::class)->scan($location);
        }
    }

    public function test_fingerprints_are_measured_on_request_and_written_to_the_tag(): void
    {
        $this->noise('library/A/01.flac', 1, ['TITLE' => 'Eins', 'ARTIST' => 'A', 'ALBUM' => 'A']);
        $this->noise('blackhole/B/01.mp3', 2, ['TIT2' => 'Zwei', 'TPE1' => 'B', 'TALB' => 'B']);
        $this->scan();

        $page = Livewire::test(MiscPage::class)->set('view', 'fingerabdruck')->assertSee('0 von 1 Lied mit Fingerabdruck')
            ->call('measureFingerprints', 'library')->call('measureFingerprints', 'blackhole');

        $lib = Track::where('location', 'library')->sole();
        $black = Track::where('location', 'blackhole')->sole();
        $this->assertNotNull($lib->fingerprint);
        $this->assertNotNull($black->fingerprint);
        $this->assertSame(['fingerprint', 'system', Fingerprints::REASON], [TagChange::sole()->field, TagChange::sole()->source, TagChange::sole()->reason], 'Lib: als Tag-Änderung');
        $this->assertSame(0, TagChange::where('track_id', $black->id)->count(), 'Blackhole: nur in der Datenbank');
        $page->assertSee('1 von 1 Lied mit Fingerabdruck');
        // in der Änderungsliste nur auf Wunsch
        Livewire::test(ChangeLog::class)->assertDontSee(Fingerprints::REASON)->set('field', 'fingerprint')->assertSee(Fingerprints::REASON);

        WriteChanges::start();

        $this->assertSame(0, TagChange::count());
        $this->assertSame([$lib->fingerprint], $lib->refresh()->tags['ACOUSTID_FINGERPRINT'] ?? null, 'im Tag wie bei Picard');
        // neu eingelesen (Datei geändert): der Fingerabdruck kommt aus dem Tag
        touch($lib->path, time() + 10);
        app(Scanner::class)->scan('library');
        $this->assertSame($lib->fingerprint, Track::find($lib->id)->fingerprint);
    }

    public function test_a_fingerprint_from_picard_is_taken_and_inbox_songs_get_one_after_reading(): void
    {
        $picard = $this->noise('library/A/01.mp3', 1, ['TIT2' => 'Eins', 'TPE1' => 'A', 'TALB' => 'A', 'TXXX:Acoustid Fingerprint' => 'AQAAPICARDFINGERPRINT1234567890']);
        app(Scanner::class)->scan('library');
        $this->assertSame('AQAAPICARDFINGERPRINT1234567890', Track::where('path', $picard)->value('fingerprint'));
        $this->assertSame(0, TagChange::count(), 'übernommen, nichts zu schreiben');

        config(['musicwiz.fingerprint_inbox' => true]);
        $this->noise('inbox/Neu/01.flac', 2, ['TITLE' => 'Neu', 'ARTIST' => 'B', 'ALBUM' => 'Neu']);
        app(Scanner::class)->scan('inbox');
        $this->assertNotNull(Track::where('location', 'inbox')->value('fingerprint'));
        $this->assertFalse(MeasureFingerprints::start(['library']), 'nichts mehr offen');
    }

    public function test_the_same_recording_replaces_across_albums_and_different_ones_are_no_duplicates(): void
    {
        // dasselbe „Intro“ (gleiche Aufnahme): FLAC im Album, MP3 auf der Best-of
        $this->noise('library/X/Album/01.flac', 1, ['TITLE' => 'Intro', 'ARTIST' => 'X', 'ALBUM' => 'Album', 'TRACKNUMBER' => '1']);
        $this->noise('library/X/Best Of/01.mp3', 1, ['TIT2' => 'Intro', 'TPE1' => 'X', 'TALB' => 'Best Of', 'TRCK' => '1']);
        // ein anderes „Interlude“ gleicher Länge auf zwei Alben
        $this->noise('library/X/Album/02.flac', 3, ['TITLE' => 'Interlude', 'ARTIST' => 'X', 'ALBUM' => 'Album', 'TRACKNUMBER' => '2']);
        $this->noise('library/X/Best Of/02.mp3', 4, ['TIT2' => 'Interlude', 'TPE1' => 'X', 'TALB' => 'Best Of', 'TRCK' => '2']);
        $this->scan();
        // ohne Fingerabdruck: allgemeiner Titel – kein Ersetzen über Albumgrenzen
        $this->assertTrue(Duplicates::generic('Interlude 2'));
        $this->assertCount(0, app(Duplicates::class)->findings());

        MeasureFingerprints::start(['library']);

        $findings = app(Duplicates::class)->findings();
        $this->assertCount(1, $findings);
        $replaced = collect($findings->first()['members'])->firstWhere('role', 'replaced');
        $this->assertSame(['Intro', true], [$replaced['track']->title, $replaced['verified']], 'akustisch geprüft');
        $interludes = Track::where('title', 'Interlude')->orderBy('id')->pluck('id')->all();
        $this->assertTrue(DB::table('duplicate_exemption_pairs')->where('track_a', $interludes[0])->where('track_b', $interludes[1])->exists(),
            'die verschiedenen „Interlude“ sind als kein Duplikat ausgenommen');
        // „Alle vormerken“ ersetzt Geprüftes
        app(Duplicates::class)->apply();
        $this->assertSame('Intro', FilePlan::sole()->track->title);
    }
}
