<?php

namespace Tests\Feature;

use App\Jobs\MeasureReplayGain;
use App\Livewire\MiscPage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Activity;
use App\Support\ReplayGain;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class ReplayGainTest extends TestCase
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

    /** FLAC mit hörbarem Ton (Sinus) – laut bzw. leise */
    private function tone(string $rel, array $fields, float $volume, int $seconds = 3): void
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration='.$seconds, '-af', 'volume='.$volume, $path])->throw();
        $this->tag($path, 'flac', $fields);
    }

    private function track(string $title): Track
    {
        return Track::where('title', $title)->firstOrFail();
    }

    public function test_reads_existing_values_and_parses_ffmpeg(): void
    {
        $this->flac('library/A/01.flac', ['TITLE' => 'Hat', 'ALBUM' => 'A', 'REPLAYGAIN_TRACK_GAIN' => '-6.62 dB', 'REPLAYGAIN_ALBUM_GAIN' => '-7.00 dB']);
        $this->mp3('library/B/01.mp3', ['TIT2' => 'Hat MP3', 'TALB' => 'B', 'TXXX:replaygain_track_gain' => '-1.00 dB', 'TXXX:replaygain_album_gain' => '-2.00 dB']);
        app(Scanner::class)->scan('library');

        $this->assertSame(['-6.62 dB', '-7.00 dB'], [$this->track('Hat')->rg_track_gain, $this->track('Hat')->rg_album_gain]);
        $this->assertSame('-1.00 dB', $this->track('Hat MP3')->rg_track_gain);
        $this->assertSame(0, app(ReplayGain::class)->count());
        $this->assertSame(['lufs' => -23.5, 'peak' => 1.0], ReplayGain::parse("…\nSummary:\n  Integrated loudness:\n    I:         -23.5 LUFS\n  True peak:\n    Peak:        0.0 dBFS"));
        $this->assertNull(ReplayGain::parse("Summary:\n    I:         -inf LUFS"));
    }

    public function test_measures_albums_in_the_background_and_sets_values(): void
    {
        $this->tone('inbox/Album/01.flac', ['TITLE' => 'Laut', 'ALBUM' => 'Album'], 1.0);
        $this->tone('inbox/Album/02.flac', ['TITLE' => 'Leise', 'ALBUM' => 'Album'], 0.1);
        $this->tone('inbox/Einzeln/a.flac', ['TITLE' => 'Single A'], 0.5);
        $this->tone('inbox/Einzeln/b.flac', ['TITLE' => 'Single B', 'ALBUM' => 'Irgendwas'], 0.1);
        $this->flac('inbox/Still/01.flac', ['TITLE' => 'Stille', 'ALBUM' => 'Still']);
        app(Scanner::class)->scan('inbox');
        $this->assertSame(3, app(ReplayGain::class)->count());

        Queue::fake();
        Livewire::test(MiscPage::class)->set('view', 'replaygain')->assertSee('ReplayGain fehlt')->assertSee('Einzeltitel')->call('measure', null);
        Queue::assertPushed(MeasureReplayGain::class);
        (new MeasureReplayGain)->handle(app(ReplayGain::class));

        $laut = $this->track('Laut');
        $leise = $this->track('Leise');
        $this->assertNotNull($laut->loudness);
        $this->assertLessThan((float) $leise->rg_track_gain, (float) $laut->rg_track_gain, 'lautes Lied wird leiser gemacht');
        $this->assertSame($laut->rg_album_gain, $leise->rg_album_gain, 'ein Album-Wert');
        $this->assertNotSame($this->track('Single A')->rg_album_gain, $this->track('Single B')->rg_album_gain, 'Einzeltitel je für sich');
        $this->assertSame($this->track('Single A')->rg_track_gain, $this->track('Single A')->rg_album_gain);
        $this->assertNull($this->track('Stille')->rg_track_gain, 'Stille ist nicht messbar');
        $this->assertSame('system', TagChange::where('track_id', $laut->id)->where('field', 'rg_track_gain')->value('source'));
        $this->assertStringContainsString('nicht messbar: Stille', Activity::current('replaygain')['message'] ?? '');

        // erneut einlesen: Messung und Werte bleiben
        app(Scanner::class)->scan('inbox');
        $this->assertSame($laut->loudness, $this->track('Laut')->loudness);
        $this->assertSame($laut->rg_track_gain, $this->track('Laut')->rg_track_gain);
    }
}
