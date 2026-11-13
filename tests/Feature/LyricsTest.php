<?php

namespace Tests\Feature;

use App\Jobs\FetchLyrics;
use App\Jobs\WriteChanges;
use App\Livewire\MiscPage;
use App\Models\LyricsLookup;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Lyrics;
use App\Support\OpenCounts;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class LyricsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    private const SYNCED = "[00:00.50] Waterloo, I was defeated\n[00:01.20] you won the war";

    private const PLAIN = "Waterloo, I was defeated\nyou won the war";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        config(['musicwiz.lookup_rates.lrclib' => 6000]);
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

    /** LRCLIB nachgestellt: genauer Treffer nur für „Waterloo“, Suche für „SOS“, nichts für „Stille“ */
    private function fakeLrclib(?string $existing = null): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            if (str_contains($request->url(), '/api/get')) {
                return ($q['track_name'] ?? '') === 'Waterloo'
                    ? Http::response(['id' => 1, 'trackName' => 'Waterloo', 'artistName' => 'ABBA', 'albumName' => 'Gold', 'duration' => 2, 'instrumental' => false,
                        'plainLyrics' => self::PLAIN, 'syncedLyrics' => self::SYNCED])
                    : Http::response(['code' => 404], 404);
            }
            if (($q['track_name'] ?? '') === 'SOS') {
                return Http::response([
                    ['id' => 7, 'trackName' => 'SOS (Live)', 'artistName' => 'ABBA', 'albumName' => 'Live', 'duration' => 200, 'instrumental' => false, 'plainLyrics' => 'Live-Text', 'syncedLyrics' => null],
                    ['id' => 8, 'trackName' => 'SOS', 'artistName' => 'ABBA', 'albumName' => 'ABBA', 'duration' => 3, 'instrumental' => false, 'plainLyrics' => 'Where are those happy days', 'syncedLyrics' => '[00:00.10] Where are those happy days'],
                    ['id' => 9, 'trackName' => 'SOS', 'artistName' => 'ABBA', 'albumName' => 'x', 'duration' => 2, 'instrumental' => true, 'plainLyrics' => null, 'syncedLyrics' => null],
                ]);
            }

            return Http::response([]);
        });
    }

    public function test_reads_and_writes_lyrics_in_flac_and_mp3(): void
    {
        $this->flac('inbox/Gold/01.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'LYRICS' => self::PLAIN]);
        $this->mp3('inbox/Gold/02.mp3', ['TIT2' => 'SOS', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'USLT' => "Where are\nthose happy days"]);
        app(Scanner::class)->scan('inbox');
        $this->assertSame([self::PLAIN, "Where are\nthose happy days"], [$this->track('Waterloo')->lyrics, $this->track('SOS')->lyrics]);

        app(TagEditor::class)->update($this->track('Waterloo'), ['lyrics' => self::SYNCED."\r\n"]);
        app(TagEditor::class)->update($this->track('SOS'), ['lyrics' => '[00:00.10] Where are those happy days']);
        WriteChanges::start();

        $this->assertSame(0, TagChange::count(), 'alles geschrieben');
        app(Scanner::class)->scan('inbox');   // nachgelesen: steht so in den Dateien
        $this->assertSame([self::SYNCED, '[00:00.10] Where are those happy days'], [$this->track('Waterloo')->lyrics, $this->track('SOS')->lyrics]);
    }

    public function test_exact_matches_go_in_one_batch_the_rest_is_chosen(): void
    {
        $this->flac('inbox/Gold/01.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold']);
        $this->flac('inbox/Gold/02.flac', ['TITLE' => 'SOS', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold']);
        $this->flac('inbox/Gold/03.flac', ['TITLE' => 'Stille', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold']);
        $this->flac('inbox/Gold/04.flac', ['TITLE' => 'Fernando', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'LYRICS' => '[00:01.00] schon synchron']);
        app(Scanner::class)->scan('inbox');
        $this->fakeLrclib();

        $this->assertSame(3, Lyrics::pending()->count(), 'synchronisierte werden nicht abgefragt');
        FetchLyrics::start();

        $this->assertSame(['Waterloo' => 'exact', 'SOS' => 'choice', 'Stille' => 'none'], LyricsLookup::with('track')->get()->mapWithKeys(fn ($l) => [$l->track->title => $l->status])->all());
        $found = app(Lyrics::class)->findings();
        $this->assertSame([1, 1, 1], [$found['exact']->count(), $found['choice']->count(), $found['none']->count()]);
        // Kandidaten: passende Länge und synchronisiert zuerst, instrumental fällt weg
        $this->assertSame([8, 7], array_column($found['choice']->first()['candidates'], 'id'));
        $this->assertSame(2, app(OpenCounts::class)->misc()['songtexte']);
        $this->assertSame(0, Lyrics::pending()->count(), 'abgefragt – erst nach '.Lyrics::RETRY_DAYS.' Tagen wieder');

        $page = Livewire::test(MiscPage::class)->set('view', 'songtexte')->assertSee('Waterloo')->assertSee('synchronisiert');
        $page->call('takeAllLyrics')->assertSee('1 Songtext übernommen');
        $this->assertSame(self::SYNCED, $this->track('Waterloo')->lyrics);
        $change = TagChange::where('field', 'lyrics')->sole();
        $this->assertSame(['manual', 'Songtext von LRCLIB (synchronisiert)'], [$change->source, $change->reason]);

        $page->set('lyricsTab', 'choice')->assertSee('SOS (Live)')->call('takeLyrics', $this->track('SOS')->id, 8, true);
        $this->assertSame('Where are those happy days', $this->track('SOS')->lyrics, 'nur rein');
        $this->assertSame(0, app(Lyrics::class)->findings()['choice']->count());
    }

    public function test_plain_text_is_replaced_by_an_exact_synced_one_in_the_batch_otherwise_chosen(): void
    {
        $this->flac('inbox/Gold/01.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'LYRICS' => 'alter reiner Text']);
        app(Scanner::class)->scan('inbox');
        $this->fakeLrclib();
        app(Lyrics::class)->fetch($this->track('Waterloo'));
        $this->assertSame(1, app(Lyrics::class)->findings()['exact']->count(), 'rein → synchronisiert im Batch');

        // nur reiner Treffer, der vom vorhandenen abweicht: zur Auswahl
        LyricsLookup::query()->update(['exact' => array_merge(LyricsLookup::first()->exact, ['synced' => null])]);
        $found = app(Lyrics::class)->findings();
        $this->assertSame([0, 1], [$found['exact']->count(), $found['choice']->count()]);

        Livewire::test(MiscPage::class)->set('view', 'songtexte')->set('lyricsTab', 'choice')->call('dismissLyrics', $this->track('Waterloo')->id);
        $this->assertSame(0, app(Lyrics::class)->count());
    }

    public function test_writing_page_and_change_log_show_only_a_summary(): void
    {
        $this->flac('inbox/Gold/01.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold']);
        app(Scanner::class)->scan('inbox');
        app(TagEditor::class)->update($this->track('Waterloo'), ['lyrics' => self::SYNCED]);

        $this->get('/schreiben')->assertOk()->assertSee('synchronisiert, 2 Zeilen')->assertDontSee('you won the war');
        $this->get('/aenderungen')->assertOk()->assertSee('Songtext: synchronisiert, 2 Zeilen')->assertDontSee('you won the war');
    }
}
