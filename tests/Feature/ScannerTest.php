<?php

namespace Tests\Feature;

use App\Livewire\MiscPage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Activity;
use App\Support\ArtistCredits;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class ScannerTest extends TestCase
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

    public function test_reads_core_fields_and_all_raw_tags_of_mp3_and_flac(): void
    {
        $mp3 = $this->mp3('library/ABBA/Gold/CD1/01 Waterloo.mp3', [
            'TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TPE2' => 'ABBA', 'TRCK' => '1/19', 'TPOS' => '1/2',
            'TDRC' => '1992', 'TCON' => 'Pop', 'COMM' => 'LIB', 'TCMP' => '1', 'TXXX:EIGENES FELD' => 'mein Wert', 'APIC' => $this->jpeg(),
        ]);
        $flac = $this->flac('library/ABBA/Gold/CD2/01 Thank You.flac', [
            'TITLE' => 'Thank You for the Music', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'TRACKNUMBER' => '1', 'DISCNUMBER' => '2',
            'DATE' => '1992-09-21', 'GENRE' => ['Pop', 'Disco'], 'MUSICBRAINZ_ALBUMID' => 'abc-123',
        ]);

        $stats = app(Scanner::class)->scan('library');

        $this->assertSame(['files' => 2, 'read' => 2, 'removed' => 0, 'errors' => 0, 'kept_missing' => 0, 'folders' => 0, 'moved' => 0, 'relocated' => 0, 'merged' => 0], $stats);
        $a = Track::where('path', $mp3)->first();
        $this->assertSame(['library', 'Waterloo', 'ABBA', 'Gold', 'ABBA', 1, 19, 1, 2, '1992', 'Pop', 'LIB', true, true, 'MP3'],
            [$a->location, $a->title, $a->artist, $a->album, $a->albumartist, $a->track, $a->track_total, $a->disc, $a->disc_total, $a->year, $a->genre, $a->comment, $a->compilation, $a->has_cover, $a->format]);
        $this->assertSame(['mein Wert'], $a->tags['TXXX:EIGENES FELD'], 'alle Felder roh, auch eigene');
        $this->assertStringStartsWith('[Front: image/jpeg', $a->tags['APIC:'][0], 'Bilder nur als Beschreibung');

        $b = Track::where('path', $flac)->first();
        $this->assertSame(['Thank You for the Music', 2, '1992-09-21', 'Pop; Disco', 'FLAC'], [$b->title, $b->disc, $b->year, $b->genre, $b->format]);
        $this->assertSame(['abc-123'], $b->tags['MUSICBRAINZ_ALBUMID']);
        $this->assertSame($a->album_hash, $b->album_hash, 'CD1 und CD2 sind ein Album');
        $this->assertSame($this->base.'/library/ABBA/Gold', $a->album_dir);
        $this->assertFalse(Activity::running('scan'));
    }

    public function test_several_artists_in_one_tag_become_one_name_and_wait_as_a_combo(): void
    {
        $this->flac('library/Italo/01.flac', ['TITLE' => 'Felicita', 'ARTIST' => ['Al Bano', 'Romina Power'], 'ALBUMARTIST' => ['Al Bano', 'Romina Power'], 'ALBUM' => 'Italo']);
        $this->flac('library/Italo/02.flac', ['TITLE' => 'California Love', 'ARTIST' => ['2Pac', 'Dr. Dre', 'Roger Troutman'], 'ARTISTS' => ['2Pac', 'Dr. Dre'], 'ALBUM' => 'Italo']);
        $this->mp3('library/Italo/03.mp3', ['TIT2' => 'Live Is Life', 'TPE1' => ['Hermes House Band', 'DJ Ötzi'], 'TALB' => 'Italo']);
        $this->flac('library/Italo/04.flac', ['TITLE' => 'September', 'ARTIST' => ['Earth', 'Wind', 'Fire'], 'ALBUM' => 'Italo']);
        $this->flac('library/Italo/05.flac', ['TITLE' => 'Get Down On It', 'ARTIST' => ['Kool', 'Gang, The'], 'ALBUM' => 'Italo']);

        app(Scanner::class)->scan('library');

        $felicita = Track::where('title', 'Felicita')->sole();
        $this->assertSame(['Al Bano & Romina Power', null, 'Al Bano & Romina Power', null],
            [$felicita->artist, $felicita->artists, $felicita->albumartist, $felicita->albumartists], 'getrennt wird erst nach Entscheidung');
        $love = Track::where('title', 'California Love')->sole();
        $this->assertSame(['2Pac, Dr. Dre & Roger Troutman', '2Pac; Dr. Dre'], [$love->artist, $love->artists], 'ARTISTS aus der Datei bleibt');
        $this->assertSame('Hermes House Band & DJ Ötzi', Track::where('title', 'Live Is Life')->value('artist'), 'auch ID3');
        $this->assertSame('Earth, Wind & Fire', Track::where('title', 'September')->value('artist'), 'falsch zerlegt: wieder ganz');
        $this->assertSame('Kool & The Gang', Track::where('title', 'Get Down On It')->value('artist'), 'Teil in Sortierform');

        $open = array_column(app(ArtistCredits::class)->open(), 'parts', 'name');
        $this->assertSame(['Al Bano', 'Romina Power'], $open['Al Bano & Romina Power'] ?? null);
        $this->assertSame(['Hermes House Band', 'DJ Ötzi'], $open['Hermes House Band & DJ Ötzi'] ?? null);
    }

    public function test_reads_only_new_or_changed_files_and_removes_deleted_ones(): void
    {
        $one = $this->mp3('library/A/01.mp3', ['TIT2' => 'Eins']);
        $two = $this->mp3('library/A/02.mp3', ['TIT2' => 'Zwei']);
        app(Scanner::class)->scan('library');

        $this->assertSame(0, app(Scanner::class)->scan('library')['read'], 'nichts geändert, nichts gelesen');

        $this->tag($one, 'mp3', ['TIT2' => 'Eins neu']);
        touch($one, time() + 5);
        unlink($two);
        $stats = app(Scanner::class)->scan('library');

        $this->assertSame([1, 1], [$stats['read'], $stats['removed']]);
        $this->assertSame(['Eins neu'], Track::pluck('title')->all());
    }

    public function test_empty_folders_are_removed_after_reading(): void
    {
        $this->mp3('library/A/Album/01.mp3', ['TIT2' => 'Eins']);
        File::ensureDirectoryExists($this->base.'/library/Leer/Noch leerer');
        File::ensureDirectoryExists($this->base.'/library/Nur System');
        file_put_contents($this->base.'/library/Nur System/.DS_Store', 'x');
        file_put_contents($this->base.'/library/Nur System/Thumbs.db', 'x');
        File::ensureDirectoryExists($this->base.'/library/Bild');
        file_put_contents($this->base.'/library/Bild/cover.jpg', 'x');
        File::ensureDirectoryExists($this->base.'/library/Versteckt/.sync');

        $stats = app(Scanner::class)->scan('library');

        $this->assertSame(3, $stats['folders']);
        $this->assertDirectoryDoesNotExist($this->base.'/library/Leer');
        $this->assertDirectoryDoesNotExist($this->base.'/library/Nur System');
        $this->assertDirectoryExists($this->base.'/library/Bild');
        $this->assertDirectoryExists($this->base.'/library/Versteckt');
        $this->assertDirectoryExists($this->base.'/library/A/Album');
        $this->assertStringContainsString('3 leere Ordner gelöscht', Activity::current('scan')['message']);

        // Bereich leer: die Wurzel bleibt
        File::deleteDirectory($this->base.'/library/A');
        app(Scanner::class)->scan('library');
        $this->assertDirectoryExists($this->base.'/library');
    }

    public function test_nothing_is_removed_when_the_folder_is_unreachable_or_most_files_are_missing(): void
    {
        foreach (range(1, 30) as $i) {
            $this->mp3("library/A/$i.mp3", ['TIT2' => "Lied $i"]);
        }
        app(Scanner::class)->scan('library');

        File::deleteDirectory($this->base.'/library');
        $stats = app(Scanner::class)->scan('library');
        $this->assertSame(0, $stats['removed']);
        $this->assertSame(30, Track::count(), 'Share nicht eingehängt: nichts entfernen');
        $this->assertStringContainsString('nicht erreichbar', Activity::current('scan')['message']);

        $this->mp3('library/A/1.mp3', ['TIT2' => 'Lied 1']);
        $stats = app(Scanner::class)->scan('library');
        $this->assertSame([29, 0], [$stats['kept_missing'], $stats['removed']], 'auffällig viele fehlen: nicht entfernen');
    }

    public function test_a_file_moved_to_another_area_outside_keeps_its_entry(): void
    {
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'ABBA', 'TALB' => 'Hits']);
        $this->mp3('inbox/Hits/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'ABBA', 'TALB' => 'Hits']);
        app(Scanner::class)->scan('inbox');
        $track = Track::where('title', 'Eins')->first();
        app(TagEditor::class)->update($track, ['genre' => 'Pop']);
        $track->update(['fingerprint' => 'AQAA-test', 'loudness' => -9.5]);
        $oldAlbum = $track->album_hash;
        DB::table('album_exemptions')->insert(['album_hash' => $oldAlbum, 'check' => 'incomplete']);

        // auf dem NAS verschoben (anderes Share – hier: anderer Bereich), danach die Lib eingelesen
        File::moveDirectory($this->base.'/inbox/Hits', $this->base.'/library/Hits');
        $stats = app(Scanner::class)->scan('library');

        $this->assertSame(2, $stats['relocated']);
        $this->assertSame(2, Track::count(), 'kein zweiter Eintrag');
        $track->refresh();
        $this->assertSame(['library', $this->base.'/library/Hits/01.mp3', 'Pop', 'AQAA-test', -9.5],
            [$track->location, $track->path, $track->genre, $track->fingerprint, (float) $track->loudness], 'gleiche ID, Änderungen, Fingerabdruck und Lautheit bleiben');
        $this->assertTrue(TagChange::where('track_id', $track->id)->where('field', 'genre')->exists());
        $this->assertSame($track->album_hash, DB::table('album_exemptions')->value('album_hash'), 'Ausnahmen des Albums ziehen mit');
        $this->assertStringContainsString('2 aus anderen Bereichen übernommen', Activity::current('scan')['message']);

        $this->assertSame(0, app(Scanner::class)->scan('inbox')['removed'], 'in der Inbox fehlt nichts mehr');
    }

    public function test_stale_entries_are_merged_into_the_entry_in_the_other_area(): void
    {
        foreach (range(1, 25) as $i) {
            $this->mp3("inbox/Hits/$i.mp3", ['TIT2' => "Lied $i", 'TPE1' => 'Bj?rn']);
        }
        app(Scanner::class)->scan('inbox');
        $old = Track::where('title', 'Lied 1')->first();
        app(TagEditor::class)->update($old, ['artist' => 'Björn']);
        $old->update(['fingerprint' => 'AQAA-test']);
        DB::table('lyrics_lookups')->insert(['track_id' => $old->id, 'status' => 'none', 'fetched_at' => now()]);

        // früher: Lib-Eintrag neu angelegt, solange die Inbox-Datei noch da war – danach fehlt sie in der Inbox
        File::copyDirectory($this->base.'/inbox/Hits', $this->base.'/library/Hits');
        app(Scanner::class)->scan('library');
        File::deleteDirectory($this->base.'/inbox/Hits');
        $this->assertSame(50, Track::count());

        $stats = app(Scanner::class)->scan('inbox');

        $this->assertSame([25, 0, 0], [$stats['merged'], $stats['kept_missing'], $stats['removed']], 'kein „auffällig viele fehlen“');
        $this->assertSame(['library'], Track::distinct()->pluck('location')->all());
        $twin = Track::where('title', 'Lied 1')->first();
        $this->assertSame(['Björn', 'AQAA-test'], [$twin->artist, $twin->fingerprint], 'Änderung von Hand und Fingerabdruck übernommen');
        $this->assertSame('manual', TagChange::where('track_id', $twin->id)->where('field', 'artist')->value('source'));
        $this->assertSame($twin->id, DB::table('lyrics_lookups')->value('track_id'));
        $this->assertStringContainsString('25 veraltete mit dem Eintrag im anderen Bereich zusammengeführt', Activity::current('scan')['message']);
    }

    public function test_a_copy_in_another_area_is_a_new_entry(): void
    {
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Eins']);
        app(Scanner::class)->scan('inbox');
        File::copyDirectory($this->base.'/inbox/Hits', $this->base.'/library/Hits');
        $this->assertSame(0, app(Scanner::class)->scan('library')['relocated'], 'Original noch da');
        $this->assertSame(['inbox', 'library'], Track::orderBy('location')->pluck('location')->all());

        // nicht eindeutig: zwei verschwundene mit gleichem Namen und gleicher Größe
        $this->mp3('inbox/A/02.mp3', ['TIT2' => 'Zwei']);
        $this->mp3('inbox/B/02.mp3', ['TIT2' => 'Zwei']);
        app(Scanner::class)->scan('inbox');
        File::moveDirectory($this->base.'/inbox/A', $this->base.'/library/A');
        File::deleteDirectory($this->base.'/inbox/B');
        $this->assertSame(0, app(Scanner::class)->scan('library')['relocated']);
    }

    public function test_fields_edited_in_the_database_survive_a_rescan_of_the_changed_file(): void
    {
        $path = $this->mp3('library/A/01.mp3', ['TIT2' => 'Alt', 'TPE1' => 'ABBA']);
        app(Scanner::class)->scan('library');
        app(TagEditor::class)->update(Track::first(), ['title' => 'In der DB geändert']);

        $this->tag($path, 'mp3', ['TPE1' => 'Abba (Datei)']);
        touch($path, time() + 5);
        app(Scanner::class)->scan('library');

        $t = Track::first();
        $this->assertSame('In der DB geändert', $t->title, 'bearbeitetes Feld bleibt');
        $this->assertSame('Abba (Datei)', $t->artist, 'unbearbeitetes Feld kommt aus der Datei');
        $this->assertSame(['Alt'], $t->tags['TIT2'], 'rohe Tags zeigen den Stand der Datei');
    }

    public function test_disc_folders_side_by_side_are_one_album(): void
    {
        $this->flac("inbox/Various Artists/Dance Club '99, Disc 1/01.flac", ['TITLE' => 'Eins', 'DISCNUMBER' => '1']);
        $this->flac("inbox/Various Artists/Dance Club '99, disc 2/01.flac", ['TITLE' => 'Zwei', 'DISCNUMBER' => '2']);
        $this->flac('inbox/Various Artists/Hits CD1/01.flac', ['TITLE' => 'Hits 1']);
        $this->flac('inbox/Various Artists/Hits (CD 2)/01.flac', ['TITLE' => 'Hits 2']);
        $this->flac('inbox/Various Artists/Disco 2/01.flac', ['TITLE' => 'Kein CD-Ordner']);
        app(Scanner::class)->scan('inbox');

        $hash = fn ($title) => Track::where('title', $title)->value('album_hash');
        $this->assertSame($hash('Eins'), $hash('Zwei'), 'Disc 1 / disc 2 (Groß/klein egal)');
        $this->assertSame($hash('Hits 1'), $hash('Hits 2'));
        $this->assertSame($this->base."/inbox/Various Artists/Dance Club '99", Track::where('title', 'Eins')->value('album_dir'));
        $this->assertSame(3, Track::distinct()->count('album_hash'), '„Disco 2“ bleibt ein eigenes Album');
    }

    public function test_unreadable_file_is_recorded_with_an_error(): void
    {
        File::ensureDirectoryExists($this->base.'/inbox/X');
        file_put_contents($this->base.'/inbox/X/kaputt.flac', 'keine Audiodaten');

        $stats = app(Scanner::class)->scan('inbox');

        $this->assertSame(1, $stats['errors']);
        $this->assertNotNull(Track::first()->error);
        $this->assertSame('inbox', Track::first()->location);
    }

    public function test_a_known_song_that_is_briefly_unreadable_keeps_its_tags_and_is_read_again(): void
    {
        $path = $this->mp3('inbox/X/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'ABBA', 'TALB' => 'Hits']);
        app(Scanner::class)->scan('inbox');
        $id = Track::sole()->id;
        $this->assertSame(1, DB::table('track_artist')->where('track_id', $id)->count());

        // gerade beim Speichern erwischt: kaputt, anderer Zeitstempel
        $good = file_get_contents($path);
        file_put_contents($path, 'halb geschrieben');
        touch($path, time() + 10);
        $stats = app(Scanner::class)->scan('inbox');

        $track = Track::sole();
        $this->assertSame([1, $id], [$stats['errors'], $track->id]);
        $this->assertNotNull($track->error);
        $this->assertSame(['Eins', 'ABBA', 'Hits'], [$track->title, $track->artist, $track->album]);
        $this->assertSame(1, DB::table('track_artist')->where('track_id', $id)->count());

        // wieder in Ordnung, gleicher Zeitstempel: wird trotzdem neu gelesen
        file_put_contents($path, $good);
        touch($path, time() + 10);
        $stats = app(Scanner::class)->scan('inbox');
        $this->assertSame([1, 0], [$stats['read'], $stats['errors']]);
        $this->assertNull(Track::sole()->error);
    }

    public function test_unreadable_files_are_listed_and_can_be_removed_from_the_database(): void
    {
        File::ensureDirectoryExists($this->base.'/inbox/X');
        file_put_contents($this->base.'/inbox/X/kaputt.flac', 'keine Audiodaten');
        $this->mp3('inbox/X/01.mp3', ['TIT2' => 'Eins']);
        app(Scanner::class)->scan('inbox');

        Livewire::test(MiscPage::class)->set('view', 'nicht_lesbar')->assertSee('kaputt.flac')->assertDontSee('01.mp3')
            ->call('removeErrors')->assertSee('1 Eintrag entfernt')->assertSee('Alle Dateien sind lesbar.');
        $this->assertSame(['Eins'], Track::pluck('title')->all());
        $this->assertFileExists($this->base.'/inbox/X/kaputt.flac', 'die Datei bleibt');
    }

    public function test_only_mp3_and_flac_are_read(): void
    {
        $this->mp3('inbox/X/01.mp3', ['TIT2' => 'Eins']);
        $this->flac('inbox/X/02.flac', ['TITLE' => 'Zwei']);
        file_put_contents($this->base.'/inbox/X/03.m4a', 'aac');
        file_put_contents($this->base.'/inbox/X/04.ogg', 'ogg');

        $stats = app(Scanner::class)->scan('inbox');

        $this->assertSame([2, 0], [$stats['files'], $stats['errors']]);
        $this->assertSame(['flac', 'mp3'], Track::orderBy('ext')->pluck('ext')->all());
    }

    public function test_a_folder_renamed_outside_keeps_its_songs_history_and_checks(): void
    {
        $this->mp3('library/Bjork - Homogenic/01.mp3', ['TIT2' => 'Hunter', 'TPE1' => 'Björk', 'TALB' => 'Homogenic']);
        $this->mp3('library/Bjork - Homogenic/02.mp3', ['TIT2' => 'Jóga', 'TPE1' => 'Björk', 'TALB' => 'Homogenic']);
        app(Scanner::class)->scan('library');
        $hunter = Track::where('title', 'Hunter')->sole();
        $this->assertNotNull($hunter->file_id);
        app(TagEditor::class)->update($hunter, ['year' => '1997']);
        DB::table('genre_exemptions')->insert(['unit' => $hunter->album_hash, 'check' => 'mixed']);

        rename($this->base.'/library/Bjork - Homogenic', $this->base.'/library/Björk - Homogenic (1997)');
        $stats = app(Scanner::class)->scan('library');

        $this->assertSame([2, 0, 0], [$stats['moved'], $stats['removed'], $stats['read']]);
        $moved = Track::find($hunter->id);
        $this->assertSame($this->base.'/library/Björk - Homogenic (1997)/01.mp3', $moved->path);
        $this->assertSame('1997', $moved->year);
        $this->assertSame(1, TagChange::where('track_id', $hunter->id)->count());
        $this->assertSame(2, Track::count());
        $this->assertSame($moved->album_hash, DB::table('genre_exemptions')->value('unit'), 'Ausnahmen wandern mit');
    }
}
