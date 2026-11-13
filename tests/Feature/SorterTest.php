<?php

namespace Tests\Feature;

use App\Jobs\WriteChanges;
use App\Livewire\WritePage;
use App\Models\FileOperation;
use App\Models\FilePlan;
use App\Models\FolderImage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Duplicates;
use App\Support\FileMover;
use App\Support\Scanner;
use App\Support\Sorter;
use App\Support\TagEditor;
use App\Support\TextDiff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class SorterTest extends TestCase
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

    private function scan(): void
    {
        foreach (['inbox', 'library', 'blackhole'] as $location) {
            app(Scanner::class)->scan($location);
        }
    }

    private function track(string $title): Track
    {
        return Track::where('title', $title)->sole();
    }

    private function categorize(string $category, ?string $dirLike = null): void
    {
        foreach (Track::when($dirLike, fn ($q) => $q->where('dir', 'like', '%'.$dirLike.'%'))->whereIn('location', ['inbox', 'library'])->get() as $t) {
            app(TagEditor::class)->update($t, ['category' => $category]);
        }
    }

    private function rel(Track $t): string
    {
        return str_replace($this->base.'/', '', $t->refresh()->path);
    }

    /** jede Gruppe einzeln vormerken (wie per Klick – ersetzt auch ohne Fingerabdruck) */
    private function planEach(): void
    {
        foreach (app(Duplicates::class)->findings()->keys() as $key) {
            app(Duplicates::class)->apply($key);
        }
    }

    public function test_inbox_album_moves_to_its_rule_place_with_tags_pictures_and_history(): void
    {
        $this->mp3('inbox/Neu/ABBA Gold/1.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '1', 'TDRC' => '1992']);
        $this->mp3('inbox/Neu/ABBA Gold/2.mp3', ['TIT2' => 'SOS', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '2', 'TDRC' => '1992']);
        copy($this->jpeg(), $this->base.'/inbox/Neu/ABBA Gold/folder.jpg');
        file_put_contents($this->base.'/inbox/Neu/ABBA Gold/booklet.pdf', 'pdf');
        file_put_contents($this->base.'/inbox/Neu/ABBA Gold/.DS_Store', 'x');
        $this->scan();
        $id = $this->track('Waterloo')->id;
        $this->categorize('LIB');
        app(TagEditor::class)->update($this->track('SOS'), ['title' => 'S.O.S.']);

        $page = Livewire::test(WritePage::class)->assertSee('→ Lib · artists/A/ABBA/Gold (1992)')->assertSee('2 verschieben')->assertSee('2 Dateien: von → nach');
        // je Datei: von wo nach wo
        $files = app(Sorter::class)->overview()->first()['files'];
        $this->assertSame([['inbox', 'Neu/ABBA Gold/1.mp3', 'library', 'artists/A/ABBA/Gold (1992)'], ['inbox', 'Neu/ABBA Gold/2.mp3', 'library', 'artists/A/ABBA/Gold (1992)']],
            array_map(fn ($f) => [$f['from']['location'], $f['from']['path'], $f['to']['location'], $f['to']['dir']], $files));
        $page->assertSee('Neu/ABBA Gold/')->assertSee('1.mp3')->assertSee($files[0]['to']['name'])->call('write', null);

        $waterloo = Track::find($id);
        $this->assertSame(['library', 'library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3'], [$waterloo->location, $this->rel($waterloo)]);
        $this->assertSame('library/artists/A/ABBA/Gold (1992)/02 - S.O.S.mp3', $this->rel($this->track('S.O.S.')));
        $this->assertFileExists($this->base.'/library/artists/A/ABBA/Gold (1992)/folder.jpg');
        $this->assertFileExists($this->base.'/library/artists/A/ABBA/Gold (1992)/booklet.pdf');
        $this->assertDirectoryDoesNotExist($this->base.'/inbox/Neu');   // leer geworden (samt .DS_Store) → weg
        $this->assertDirectoryExists($this->base.'/inbox');              // nie die Wurzel
        $this->assertSame('LIB', $waterloo->tags['TXXX:MUSICWIZ_CATEGORY'][0]);
        $this->assertSame(0, TagChange::count());
        $this->assertSame(3, TagChange::history()->where('track_id', '>', 0)->count());
        $this->assertSame($this->base.'/library/artists/A/ABBA/Gold (1992)/folder.jpg', FolderImage::sole()->path);
        $this->assertSame(2, FileOperation::where('action', 'move')->count());
        // neu einlesen ändert nichts: dieselben Lieder am neuen Ort
        $this->scan();
        $this->assertSame([$id], Track::where('title', 'Waterloo')->pluck('id')->all());
        $this->assertSame(0, app(Sorter::class)->plan($waterloo->album_hash)['actions']);

        // ein Lied neu benannt: wandert im selben Ordner – kein „verteilt“
        app(TagEditor::class)->update($waterloo->refresh(), ['title' => 'Waterloo (Live)']);
        $plan = app(Sorter::class)->plan($waterloo->album_hash);
        $this->assertSame([1, null], [$plan['actions'], $plan['blocked']]);
        // Album-Tag nur in Groß/klein gemischt: der Ordner behält seine Schreibweise
        app(TagEditor::class)->update($this->track('S.O.S.'), ['album' => 'GOLD']);
        $plan = app(Sorter::class)->plan($waterloo->album_hash);
        $this->assertSame([1, null], [$plan['actions'], $plan['blocked']]);
        $this->assertStringContainsString('/Gold (1992)/', collect($plan['items'])->firstWhere('kind', 'move')['to']);
    }

    public function test_an_album_never_gets_split_and_nothing_is_overwritten(): void
    {
        $this->mp3('inbox/Mix/1.mp3', ['TIT2' => 'Eins', 'TPE1' => 'ABBA', 'TPE2' => 'ABBA', 'TALB' => 'Hits', 'TRCK' => '1']);
        $this->mp3('inbox/Mix/2.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Cher', 'TPE2' => 'Cher', 'TALB' => 'Hits', 'TRCK' => '2']);
        $this->mp3('inbox/Belegt/1.mp3', ['TIT2' => 'Drei', 'TPE1' => 'Cher', 'TALB' => 'Believe', 'TRCK' => '1']);
        $this->mp3('inbox/Belegt/2.mp3', ['TIT2' => 'Vier', 'TPE1' => 'Cher', 'TALB' => 'Believe', 'TRCK' => '2']);
        $this->scan();
        $this->mp3('library/artists/C/Cher/Believe/01 - Drei.mp3', ['TIT2' => 'fremd']);   // liegt schon da, nicht eingelesen
        $this->categorize('LIB');

        Livewire::test(WritePage::class)->assertSee('bleibt liegen: Album würde auf mehrere Ordner verteilt')->assertSee('ist schon belegt')
            // warum: je Ziel-Ordner die Lieder und der Tag, der sich unterscheidet
            ->assertSee('Warum? 2 Ordner – unterschiedlich: Album-Interpret (sonst Interpret)')
            ->assertSee('→ Lib · artists/A/ABBA/Hits')->assertSee('→ Lib · artists/C/Cher/Hits')
            ->call('write', null);

        foreach (['Eins' => 'inbox/Mix/1.mp3', 'Zwei' => 'inbox/Mix/2.mp3', 'Drei' => 'inbox/Belegt/1.mp3', 'Vier' => 'inbox/Belegt/2.mp3'] as $title => $path) {
            $this->assertSame($path, $this->rel($this->track($title)));
        }
        $this->assertSame('LIB', $this->track('Eins')->tags['TXXX:MUSICWIZ_CATEGORY'][0]);   // Tags trotzdem geschrieben
    }

    public function test_single_tracks_move_one_by_one_and_marks_go_to_trash_and_back_to_the_inbox(): void
    {
        $this->mp3('inbox/Cher/Kein Album/Believe.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher']);
        $this->mp3('inbox/Cher/Kein Album/Strong.mp3', ['TIT2' => 'Strong Enough', 'TPE1' => 'Cher']);
        $this->mp3('inbox/Cher/Kein Album/Weg.mp3', ['TIT2' => 'Weg', 'TPE1' => 'Cher']);
        $this->mp3('blackhole/Alt/Song.mp3', ['TIT2' => 'Zurück', 'TPE1' => 'Wer']);
        $this->scan();
        $this->mp3('library/artists/C/Cher/Kein Album/Strong Enough.mp3', ['TIT2' => 'fremd']);
        $this->categorize('BLACKHOLE');
        Track::where('title', 'Weg')->update(['move_to' => 'trash']);
        Track::where('title', 'Zurück')->update(['move_to' => 'inbox']);
        app(TagEditor::class)->update($this->track('Strong Enough'), ['category' => 'LIB']);

        WriteChanges::start();

        $this->assertSame('blackhole/artists/C/Cher/Kein Album/Believe.mp3', $this->rel($this->track('Believe')));
        $this->assertSame('inbox/Cher/Kein Album/Strong.mp3', $this->rel($this->track('Strong Enough')));   // Ziel belegt → bleibt
        $this->assertSame(['trash', 'trash/inbox/Cher/Kein Album/Weg.mp3'], [$this->track('Weg')->location, $this->rel($this->track('Weg'))]);
        $this->assertSame(['inbox', 'inbox/Alt/Song.mp3', null], [$this->track('Zurück')->location, $this->rel($this->track('Zurück')), $this->track('Zurück')->move_to]);
    }

    public function test_better_versions_from_duplicates_replace_the_worse_one_in_the_album(): void
    {
        $this->mp3('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '1', 'TDRC' => '1992', 'TXXX:MUSICWIZ_CATEGORY' => 'LIB']);
        $this->mp3('library/artists/A/ABBA/Gold (1992)/02 - SOS.mp3', ['TIT2' => 'SOS', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '2', 'TDRC' => '1992', 'TXXX:MUSICWIZ_CATEGORY' => 'LIB']);
        $this->flac('inbox/Kein Album/Waterloo.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA']);
        $this->flac('inbox/Best/03.flac', ['TITLE' => 'SOS', 'ARTIST' => 'ABBA', 'ALBUM' => 'Best', 'TRACKNUMBER' => '3']);
        $this->mp3('inbox/Best/04.mp3', ['TIT2' => 'Fernando', 'TPE1' => 'ABBA', 'TALB' => 'Best', 'TRCK' => '4']);
        $this->scan();
        $sos = Track::where('title', 'SOS')->where('ext', 'flac')->sole();
        $this->planEach();
        $this->assertSame(2, FilePlan::count());

        WriteChanges::start();

        $album = Track::where('album_dir', $this->base.'/library/artists/A/ABBA/Gold (1992)')->orderBy('track')->get();
        $this->assertSame(['flac', 'flac'], $album->pluck('ext')->all());
        $this->assertSame(['01 - Waterloo.flac', '02 - SOS.flac'], $album->pluck('filename')->all());
        $this->assertSame(['Gold', 'LIB', 1], [$album[0]->tags['ALBUM'][0], $album[0]->category, $album[0]->track]);
        $this->assertSame(2, Track::where('location', 'trash')->where('ext', 'mp3')->count());
        // die Kopie: das Original bleibt in seinem Album
        $this->assertSame('inbox/Best/03.flac', $this->rel($sos));
        $this->assertSame(0, FilePlan::count());
        $this->assertSame(0, TagChange::count());
    }

    public function test_the_replaced_song_stays_when_its_better_version_does_not_arrive(): void
    {
        $this->mp3('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '1', 'TDRC' => '1992', 'TXXX:MUSICWIZ_CATEGORY' => 'LIB']);
        $this->mp3('library/artists/A/ABBA/Gold (1992)/02 - SOS.mp3', ['TIT2' => 'SOS', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '2', 'TDRC' => '1992', 'TXXX:MUSICWIZ_CATEGORY' => 'LIB']);
        $loose = $this->flac('inbox/Kein Album/Waterloo.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA']);
        $this->flac('inbox/Best/03.flac', ['TITLE' => 'SOS', 'ARTIST' => 'ABBA', 'ALBUM' => 'Best', 'TRACKNUMBER' => '3']);
        $this->scan();
        $this->planEach();
        $waterloo = Track::where('title', 'Waterloo')->where('ext', 'mp3')->sole();
        unlink($loose);   // die bessere Fassung ist weg, bevor geschrieben wird

        WriteChanges::start();

        // Waterloo (MP3) bleibt im Album, die Vormerkung bleibt offen; SOS wurde ersetzt
        $this->assertSame('library', $waterloo->refresh()->location);
        $this->assertFileExists($waterloo->path);
        $this->assertSame(1, FilePlan::where('replaces_track_id', $waterloo->id)->count());
        $album = Track::where('location', 'library')->orderBy('track')->get();
        $this->assertSame(['mp3', 'flac'], $album->pluck('ext')->all());
        $this->assertSame(1, Track::where('location', 'trash')->count());
        $this->assertArrayHasKey($loose, Cache::get(WriteChanges::FAILED));
    }

    public function test_a_better_version_with_the_same_file_name_takes_the_place_of_the_old_one(): void
    {
        $this->flac('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'TRACKNUMBER' => '1', 'DATE' => '1992', 'MUSICWIZ_CATEGORY' => 'LIB']);
        $this->flac('inbox/Kein Album/Waterloo.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA'], 48000);
        $this->scan();
        $old = Track::where('location', 'library')->sole();
        $better = Track::where('location', 'inbox')->sole();
        $this->planEach();
        $this->assertSame(1, FilePlan::count());

        WriteChanges::start();

        $album = Track::where('location', 'library')->get();
        $this->assertSame([$better->id], $album->pluck('id')->all());
        $this->assertSame('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.flac', $this->rel($better));
        $this->assertSame(48000, $better->sample_rate);
        $this->assertSame('trash', $old->refresh()->location);
        $this->assertSame(['01 - Waterloo.flac'], array_values(array_diff(scandir($this->base.'/library/artists/A/ABBA/Gold (1992)'), ['.', '..'])));
        $this->assertSame(0, FilePlan::count());
    }

    public function test_a_better_version_gets_the_album_as_it_is_when_writing_not_when_planned(): void
    {
        $this->mp3('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '1', 'TDRC' => '1992', 'TXXX:MUSICWIZ_CATEGORY' => 'LIB']);
        $this->mp3('library/artists/A/ABBA/Gold (1992)/02 - SOS.mp3', ['TIT2' => 'SOS', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '2', 'TDRC' => '1992', 'TXXX:MUSICWIZ_CATEGORY' => 'LIB']);
        $this->flac('inbox/Best/03.flac', ['TITLE' => 'SOS', 'ARTIST' => 'ABBA', 'ALBUM' => 'Best', 'TRACKNUMBER' => '3']);
        $this->scan();
        $this->planEach();
        // nach dem Vormerken umbenannt (z. B. über „Serien“)
        foreach (Track::where('location', 'library')->get() as $t) {
            app(TagEditor::class)->update($t, ['album' => 'Gold (Remaster)']);
        }

        WriteChanges::start();

        $album = Track::where('location', 'library')->orderBy('track')->get();
        $this->assertSame(['Gold (Remaster)', 'Gold (Remaster)'], $album->pluck('album')->all());
        $this->assertSame(['mp3', 'flac'], $album->pluck('ext')->all());
        $this->assertSame([$this->base.'/library/artists/A/ABBA/Gold (Remaster) (1992)'], $album->pluck('dir')->unique()->values()->all());
    }

    public function test_moving_between_shares_copies_safely_and_never_leaves_half_files(): void
    {
        config(['musicwiz.copy_moves' => true]);   // wie zwischen zwei Shares
        $src = $this->mp3('inbox/Neu/Gold/1.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '1', 'TDRC' => '1992', 'TXXX:MUSICWIZ_CATEGORY' => 'LIB']);
        $this->scan();
        $track = $this->track('Waterloo');
        $target = $this->base.'/library/artists/A/ABBA/Gold (1992)';

        // Ziel nicht beschreibbar: nichts passiert, keine Hilfsdatei
        File::ensureDirectoryExists($target);
        chmod($target, 0555);
        WriteChanges::start();
        chmod($target, 0755);
        $this->assertSame($src, $track->refresh()->path);
        $this->assertFileExists($src);
        $this->assertSame([], array_values(array_diff(scandir($target), ['.', '..'])));
        $this->assertSame(0, FileOperation::where('status', 'pending')->count());

        // Original lässt sich nicht löschen: die Kopie wird wieder entfernt, das Original bleibt
        chmod(dirname($src), 0555);
        WriteChanges::start();
        chmod(dirname($src), 0755);
        $this->assertSame($src, $track->refresh()->path);
        $this->assertFileExists($src);
        $this->assertSame([], array_values(array_diff(scandir($target), ['.', '..'])));

        WriteChanges::start();
        $this->assertSame('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3', $this->rel($track));
        $this->assertFileDoesNotExist($src);
        $this->assertSame(['01 - Waterloo.mp3'], array_values(array_diff(scandir($target), ['.', '..'])));
        $this->assertSame('done', FileOperation::where('track_id', $track->id)->where('action', 'move')->value('status'));
    }

    public function test_an_aborted_move_is_finished_or_undone_without_losing_the_history(): void
    {
        $moved = $this->mp3('inbox/A/1.mp3', ['TIT2' => 'Eins', 'TPE1' => 'ABBA', 'TALB' => 'A']);
        $stayed = $this->mp3('inbox/A/2.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'ABBA', 'TALB' => 'A']);
        $both = $this->mp3('inbox/A/3.mp3', ['TIT2' => 'Drei', 'TPE1' => 'ABBA', 'TALB' => 'A']);
        $this->scan();
        $eins = $this->track('Eins');
        $drei = $this->track('Drei')->id;
        app(TagEditor::class)->update($eins, ['genre' => 'Pop']);
        // Absturz mitten im Schreiben: Datei schon am Ziel, Eintrag noch nicht
        $to = $this->base.'/library/ABBA/A/01 - Eins.mp3';
        File::ensureDirectoryExists(dirname($to));
        rename($moved, $to);
        FileOperation::create(['track_id' => $eins->id, 'action' => 'move', 'from_path' => $moved, 'to_path' => $to, 'location' => 'library', 'status' => 'pending']);
        // Absturz, bevor etwas passiert ist
        FileOperation::create(['track_id' => $this->track('Zwei')->id, 'action' => 'move', 'from_path' => $stayed, 'to_path' => $this->base.'/library/ABBA/A/02 - Zwei.mp3', 'location' => 'library', 'status' => 'pending']);
        // halbe Kopie ohne Eintrag
        copy($stayed, $this->base.'/library/ABBA/A/09 - Kopie.mp3');
        FileOperation::create(['action' => 'copy', 'from_path' => $stayed, 'to_path' => $this->base.'/library/ABBA/A/09 - Kopie.mp3', 'location' => 'library', 'status' => 'pending']);
        // an beiden Orten
        copy($both, $this->base.'/library/ABBA/A/03 - Drei.mp3');
        FileOperation::create(['track_id' => $drei, 'action' => 'move', 'from_path' => $both, 'to_path' => $this->base.'/library/ABBA/A/03 - Drei.mp3', 'location' => 'library', 'status' => 'pending']);

        $problems = app(FileMover::class)->recover();
        $this->scan();

        $eins->refresh();
        $this->assertSame(['library', $to, 'Pop'], [$eins->location, $eins->path, $eins->genre], 'gleiche ID, Änderung bleibt');
        $this->assertSame(1, TagChange::where('track_id', $eins->id)->count());
        $this->assertSame($stayed, $this->track('Zwei')->path);
        $this->assertFileDoesNotExist($this->base.'/library/ABBA/A/09 - Kopie.mp3');
        $this->assertSame([$this->base.'/library/ABBA/A/03 - Drei.mp3'], array_keys($problems));
        // bleibt offen und wird gemeldet, bis eine der beiden Dateien weg ist
        $this->assertSame([$drei], FileOperation::where('status', 'pending')->pluck('track_id')->all());
        $this->assertSame(2, Track::where('title', 'Drei')->count(), 'die Kopie am Ziel wurde beim Einlesen erfasst');
        unlink($both);
        $this->assertSame([], app(FileMover::class)->recover());
        $this->assertSame($this->base.'/library/ABBA/A/03 - Drei.mp3', Track::find($drei)->path, 'der Eintrag mit Verlauf bleibt');
        $this->assertSame(1, Track::where('title', 'Drei')->count());
    }

    public function test_a_better_version_left_under_its_temporary_name_is_put_in_place(): void
    {
        $this->mp3('inbox/A/1.mp3', ['TIT2' => 'Eins', 'TPE1' => 'ABBA', 'TALB' => 'A']);
        $this->scan();
        $track = $this->track('Eins');
        // Absturz nach „unter Hilfsnamen ins Album“ und „altes Lied in den Papierkorb“, vor dem Umbenennen
        $swap = $this->base.'/library/ABBA/A/.musicwiz-swap.01 - Eins.mp3';
        File::ensureDirectoryExists(dirname($swap));
        app(FileMover::class)->move($track, $swap, 'library');

        app(FileMover::class)->recover();

        $this->assertSame($this->base.'/library/ABBA/A/01 - Eins.mp3', $track->refresh()->path);
        $this->assertFileExists($track->path);
    }

    public function test_only_case_differs_renames_through_a_temporary_name(): void
    {
        $this->mp3('library/artists/A/ABBA/gold (1992)/01 - waterloo.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '1', 'TDRC' => '1992', 'TXXX:MUSICWIZ_CATEGORY' => 'LIB']);
        $this->scan();

        WriteChanges::start();

        $this->assertSame('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3', $this->rel($this->track('Waterloo')));
        $this->assertContains('01 - Waterloo.mp3', scandir($this->base.'/library/artists/A/ABBA/Gold (1992)'));
        $this->assertContains('Gold (1992)', scandir($this->base.'/library/artists/A/ABBA'));   // auch der Ordner
    }

    public function test_file_list_marks_exactly_what_changes(): void
    {
        // „/“ im Titel: „Started-Sweet“ → „Started Sweet“ – nur der Bindestrich ist anders
        $this->assertSame([33, 17], TextDiff::bounds('P/Pink/04 - Get the Party Started-Sweet Dreams.flac', 'P/Pink/04 - Get the Party Started Sweet Dreams.flac'));
        $this->assertSame([5, 4], TextDiff::bounds('a/b/cd.mp3', 'a/b/c.mp3'), 'weggefallen: nur das „d“');
        $this->assertSame([3, 0], TextDiff::bounds('abc', 'abc'));
    }
}
