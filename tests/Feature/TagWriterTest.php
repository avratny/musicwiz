<?php

namespace Tests\Feature;

use App\Livewire\WritePage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\CoverStore;
use App\Support\Scanner;
use App\Support\TagEditor;
use App\Support\TagWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class TagWriterTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    /** alle Felder, die beim Schreiben vorkommen */
    private const VALUES = [
        'title' => 'Waterloo (Remaster)', 'artist' => 'ABBA feat. Björn', 'artists' => 'ABBA; Björn', 'album' => 'Gold',
        'albumartist' => 'ABBA', 'albumartists' => 'ABBA', 'artistsort' => 'ABBA feat. Björn', 'albumartistsort' => 'ABBA',
        'track' => 3, 'track_total' => 19, 'disc' => 1, 'disc_total' => 2, 'year' => '1992', 'releasedate' => '1992-09-21',
        'mb_albumid' => '0a1b2c3d-0000-4000-8000-000000000001', 'genre' => 'Pop; Europop', 'comment' => 'Neu',
        'compilation' => true, 'rating' => 4, 'category' => 'LIB COMPILATION',
        'rg_track_gain' => '-6.62 dB', 'rg_track_peak' => '0.988', 'rg_album_gain' => '-7.01 dB', 'rg_album_peak' => '1.000',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        $this->mp3('inbox/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold (alt)', 'TRCK' => '1/10', 'COMM' => 'Alt', 'POPM' => 64]);
        $this->flac('inbox/ABBA/Gold/02.flac', ['TITLE' => 'SOS', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold (alt)', 'TRACKNUMBER' => '2/10', 'ALBUM ARTIST' => 'Abba']);
        app(Scanner::class)->scan('inbox');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function track(string $file): Track
    {
        return Track::where('filename', $file)->sole();
    }

    public function test_writes_every_field_into_mp3_and_flac_and_keeps_the_history(): void
    {
        foreach (['01.mp3', '02.flac'] as $file) {
            app(TagEditor::class)->update($this->track($file), self::VALUES);
        }
        $open = TagChange::count();
        $this->assertGreaterThan(40, $open);

        $result = app(TagWriter::class)->write(Track::pluck('id')->all());

        $this->assertSame(['written' => 2, 'failed' => []], $result);
        $this->assertSame(0, TagChange::count());
        $this->assertSame($open, TagChange::history()->whereNotNull('written_at')->count());
        // neu eingelesen: alles steht so in der Datei, nichts mehr offen – und dasselbe Lied (gleiche ID)
        Track::query()->update(['mtime' => 0]);
        app(Scanner::class)->scan('inbox');
        foreach (['01.mp3', '02.flac'] as $file) {
            $track = $this->track($file);
            foreach (self::VALUES as $field => $value) {
                $this->assertEquals($value, $track->{$field}, $file.': '.$field);
            }
        }
        $raw = $this->track('02.flac')->tags;
        $this->assertSame(['ABBA'], $raw['ALBUM ARTIST']);       // Zweitschreibweise mitgepflegt
        $this->assertSame(['Pop', 'Europop'], $raw['GENRE']);      // Listen als mehrere Werte
        $this->assertSame(['80'], $raw['RATING']);
        $this->assertStringContainsString('196', $this->track('01.mp3')->tags['POPM:no@email'][0]);
        $this->assertSame(0, Track::where('filename', 'like', '%musicwiz-write%')->count());
        $this->assertSame([], glob($this->base.'/inbox/ABBA/Gold/.*musicwiz-write'));
    }

    public function test_only_changed_fields_are_written_and_new_changes_build_on_the_file(): void
    {
        $track = $this->track('01.mp3');
        app(TagEditor::class)->update($track, ['title' => 'Waterloo!', 'track_total' => 12]);
        app(TagWriter::class)->write([$track->id]);

        $track = $this->track('01.mp3');
        $this->assertSame(['Waterloo!', 1, 12, 'Gold (alt)', 'Alt', 2], [$track->title, $track->track, $track->track_total, $track->album, $track->comment, $track->rating]);
        $this->assertSame(['1/12'], $track->tags['TRCK']);

        // neue Änderung: Ausgangsstand ist jetzt die Datei – zurücksetzen führt dorthin, nicht zum alten Titel
        app(TagEditor::class)->update($track, ['title' => 'Anders']);
        $this->assertSame('Waterloo!', TagChange::sole()->old_value);
        app(TagEditor::class)->revert($track->refresh(), ['title']);
        $this->assertSame('Waterloo!', $track->refresh()->title);
        $this->assertSame(0, TagChange::count());
        $this->assertSame(2, TagChange::history()->count());
    }

    public function test_small_changes_go_into_the_file_and_growth_goes_through_a_checked_copy(): void
    {
        $path = $this->base.'/inbox/ABBA/Gold/01.mp3';
        $stale = $this->base.'/inbox/ABBA/Gold/.02.flac.musicwiz-write';
        file_put_contents($stale, 'Rest eines abgebrochenen Laufs');
        [$inode, $size] = [fileinode($path), filesize($path)];

        app(TagEditor::class)->update($this->track('01.mp3'), ['title' => 'Waterloo!']);
        app(TagWriter::class)->write([$this->track('01.mp3')->id]);
        clearstatcache();
        // passt in den Reserveplatz: dieselbe Datei, gleich groß
        $this->assertSame([$inode, $size], [fileinode($path), filesize($path)]);
        $this->assertFileDoesNotExist($stale);

        // viel mehr Text: die Datei muss wachsen → über die geprüfte Kopie
        app(TagEditor::class)->update($this->track('01.mp3'), ['comment' => str_repeat('Lang ', 4000)]);
        $this->assertSame(['written' => 1, 'failed' => []], app(TagWriter::class)->write([$this->track('01.mp3')->id]));
        clearstatcache();
        $this->assertNotSame($inode, fileinode($path));
        $this->assertGreaterThan($size + 15000, filesize($path));
        $this->assertSame(['Waterloo!', 2.09], [$this->track('01.mp3')->title, round($this->track('01.mp3')->duration, 2)]);
        $this->assertSame([], glob($this->base.'/inbox/ABBA/Gold/.*musicwiz-write'));
    }

    public function test_writes_a_new_cover(): void
    {
        $image = app(CoverStore::class)->store((string) file_get_contents($this->jpeg('neu.jpg', 500)));
        $hash = $this->track('02.flac')->album_hash;
        app(CoverStore::class)->apply($hash, $image);
        $this->assertFalse($this->track('02.flac')->has_cover);

        app(TagWriter::class)->write(Track::pluck('id')->all());

        $this->assertSame(0, TagChange::count());
        $this->assertTrue($this->track('02.flac')->has_cover);
        $this->assertSame(500, $this->track('01.mp3')->cover_width);
    }

    public function test_unreadable_files_stay_open_and_the_page_writes_in_the_background(): void
    {
        $broken = $this->base.'/inbox/ABBA/Gold/03.mp3';
        file_put_contents($broken, 'kein mp3');
        app(Scanner::class)->scan('inbox');
        foreach (Track::all() as $track) {
            Track::whereKey($track->id)->update(['error' => $track->filename === '03.mp3' ? 'kaputt' : null]);
            app(TagEditor::class)->update($track->refresh(), ['album' => 'Gold']);
        }

        Livewire::test(WritePage::class)
            ->assertSee('Gold')->assertSee('3 Lieder')->assertSee('1 nicht schreibbar')
            ->call('write', null);

        $this->assertSame([$this->track('03.mp3')->id], TagChange::pluck('track_id')->all());
        $this->assertSame('Gold', $this->track('02.flac')->tags['ALBUM'][0]);
        $this->assertSame(1, TagWriter::pending()->count());
        $this->assertSame(0, TagWriter::writable()->count());
    }

    public function test_page_shows_per_song_what_changes_in_the_tags(): void
    {
        app(TagEditor::class)->update($this->track('01.mp3'), ['title' => 'Waterloo!']);
        app(TagEditor::class)->update($this->track('01.mp3'), ['title' => 'Waterloo (Live)']);   // zählt: Datei → jetziger Wert
        app(TagEditor::class)->update($this->track('02.flac'), ['albumartist' => '']);

        $changes = app(TagWriter::class)->albums(collect())['albums'][0]['changes'];
        $this->assertSame([['1. Waterloo (Live)', 'Titel', 'Waterloo', 'Waterloo (Live)', [8, 0]], ['2. SOS', 'Album-Interpret', 'Abba', '', null]],
            array_map(fn ($c) => [$c['track'].'. '.$c['title'], $c['fields'][0]['label'], $c['fields'][0]['old'], $c['fields'][0]['new'], $c['fields'][0]['diff']], $changes));

        Livewire::test(WritePage::class)->assertSee('Tags: 2 Lieder – bisher → neu')->assertSeeHtml('␣(Live)');
    }
}
