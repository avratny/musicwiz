<?php

namespace Tests\Feature;

use App\Jobs\WriteChanges;
use App\Livewire\MiscPage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\PathTemplate;
use App\Support\Scanner;
use App\Support\TagEditor;
use App\Support\TextCleanup;
use App\Support\TextRepairs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

/** Schreibweise: sicher Bereinigtes automatisch (Einlesen, Bearbeiten, Schreiben), Unsicheres zum Prüfen, Datei- und Ordnernamen */
class TextCleanupTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_rules(): void
    {
        $cases = [
            'Never  Alone ' => 'Never Alone',
            'She Moves (La La La ) (Original Radio Edit)' => 'She Moves (La La La) (Original Radio Edit)',
            'Song(Remix)' => 'Song (Remix)',
            'Girl(s)' => 'Girl(s)',
            'Harder? !' => 'Harder?!',
            'Hello , World .' => 'Hello, World.',
            'Would You ...?' => 'Would You ...?',
            'Boy´s Of Summer' => "Boy's Of Summer",
            'Bro’Sis' => 'Bro’Sis',
            'High Life- Hits à la Chart' => 'High Life - Hits à la Chart',
            "Infinity (1990's- Time for the Guru)" => "Infinity (1990's - Time for the Guru)",
            'Wishing You Were Here (Radio- / Videoversion)' => 'Wishing You Were Here (Radio- / Videoversion)',
            'Radio- und Videoversion' => 'Radio- und Videoversion',
            'Jay-Z' => 'Jay-Z',
            'Played -A-Live' => 'Played -A-Live',
            'A - B' => 'A - B',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, TextCleanup::clean($in), $in);
        }
    }

    public function test_reading_cleans_as_system_change_and_edits_are_cleaned_too(): void
    {
        $this->mp3('library/A/01.mp3', ['TIT2' => 'Never  Alone', 'TPE1' => 'Junia', 'TALB' => 'High Life- Hits']);
        $this->mp3('library/A/02.mp3', ['TIT2' => 'Who´s The Other Woman', 'TPE1' => 'Junia', 'TALB' => 'High Life- Hits']);
        app(Scanner::class)->scan('library');

        $this->assertSame(['Never Alone', 'High Life - Hits'], [Track::where('filename', '01.mp3')->value('title'), Track::where('filename', '01.mp3')->value('album')]);
        $this->assertSame("Who's The Other Woman", Track::where('filename', '02.mp3')->value('title'));
        $change = TagChange::where('field', 'title')->where('old_value', 'Never  Alone')->sole();
        $this->assertSame([TagEditor::SYSTEM, 'Schreibweise bereinigt: „Never  Alone“ → „Never Alone“'], [$change->source, $change->reason]);
        $this->assertSame(4, TagChange::where('reason', 'like', 'Schreibweise bereinigt%')->count());

        $track = Track::where('filename', '01.mp3')->first();
        app(TagEditor::class)->update($track, ['title' => 'Song(Radio Edit)  ']);
        $this->assertSame('Song (Radio Edit)', $track->fresh()->title, 'auch von Hand Eingegebenes');

        app(Scanner::class)->scan('library');
        $this->assertSame(4, TagChange::where('reason', 'like', 'Schreibweise bereinigt%')->count(), 'nichts doppelt');
    }

    public function test_writing_checks_again_before(): void
    {
        $this->flac('library/A/01.flac', ['TITLE' => 'Eins', 'ARTIST' => 'Junia', 'ALBUM' => 'A']);
        app(Scanner::class)->scan('library');
        $track = Track::first();
        // an der Bereinigung vorbei (z. B. aus einer älteren Fassung)
        DB::table('tracks')->where('id', $track->id)->update(['title' => 'Eins  (Remix )']);
        app(TagEditor::class)->update($track->fresh(), ['genre' => 'Pop']);

        WriteChanges::start();

        $this->assertSame('Eins (Remix)', $track->fresh()->title);
        $this->assertNotNull(TagChange::history()->where('field', 'title')->value('written_at'), 'gleich mitgeschrieben');
    }

    public function test_doubtful_spellings_are_shown_with_a_suggestion_or_accepted(): void
    {
        $this->mp3('library/A/01.mp3', ['TIT2' => 'H.I.V. (Album Version9', 'TPE1' => 'Junia', 'TALB' => 'The Collection']);
        $this->mp3('library/A/02.mp3', ['TIT2' => 'Sea Cruise.', 'TPE1' => 'Junia', 'TALB' => 'The Collection']);
        $this->mp3('library/A/03.mp3', ['TIT2' => 'Played -A-Live', 'TPE1' => 'Junia', 'TALB' => 'The Collection']);
        $this->mp3('library/A/04.mp3', ['TIT2' => 'E.I.', 'TPE1' => 'Oli P.', 'TALB' => 'The Collection']);
        $this->mp3('library/A/05.mp3', ['TIT2' => 'Mr. Vain Jr.', 'TPE1' => 'Junia', 'TALB' => 'The Collection']);
        app(Scanner::class)->scan('library');

        $repairs = app(TextRepairs::class);
        $found = $repairs->problems()->mapWithKeys(fn ($p) => [$p['value'] => [$p['kinds'], $p['rule']]])->sortKeys()->all();
        $this->assertSame([
            'H.I.V. (Album Version9' => [['bracket'], 'H.I.V. (Album Version)'],
            'Played -A-Live' => [['hyphen'], 'Played-A-Live'],
            'Sea Cruise.' => [['dot'], 'Sea Cruise'],
        ], $found, 'Abkürzungen, Initialen und Interpreten mit Punkt bleiben');
        $this->assertSame(0, $repairs->unasked()->count(), 'nicht an Claude');

        $hash = sha1('Played -A-Live');
        Livewire::test(MiscPage::class)->assertSee('Bindestrich einseitig')->call('accept', $hash)->assertSee('passt so');
        Livewire::test(MiscPage::class)->call('take', sha1('Sea Cruise.'), 'Sea Cruise');
        $this->assertSame(['H.I.V. (Album Version9'], $repairs->problems()->pluck('value')->all());
        $this->assertSame('Schreibweise korrigiert: „Sea Cruise.“ → „Sea Cruise“', TagChange::where('new_value', 'Sea Cruise')->value('reason'));
    }

    public function test_file_and_folder_names(): void
    {
        $paths = new PathTemplate;
        $render = fn (array $tags) => $paths->render('{album}/{nr} - {titel}.{ext}', $paths->values(new Track(['path' => '/x/Datei.flac', 'filename' => 'Datei.flac', 'location' => 'library', 'track' => 1] + $tags)));

        $this->assertSame('Hits 90 - Die Superhits/01 - Re-Mix.flac', $render(['album' => 'Hits 90: Die Superhits', 'title' => 'Re:Mix']));
        $this->assertSame('Time Koma/01 - Wishing (Radio - Videoversion).flac', $render(['album' => 'Time / Koma', 'title' => 'Wishing (Radio- / Videoversion)']));
        $this->assertSame('Ruiniert…/01 - Would You….flac', $render(['album' => 'Ruiniert...', 'title' => 'Would You...?']));
        $this->assertSame('A/01 - Sea Cruise.flac', $render(['album' => 'A', 'title' => 'Sea Cruise.']));
        $this->assertSame('20 Top Hits 5-2003/01 - Radio Video Version.flac', $render(['album' => '20 Top Hits 5/2003', 'title' => 'Radio / Video Version']));
        $this->assertSame('A/01 - E.I.flac', $render(['album' => 'A', 'title' => 'E.I.']));
    }
}
