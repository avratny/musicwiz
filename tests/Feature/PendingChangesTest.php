<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Livewire\Artists;
use App\Livewire\Cleanup;
use App\Livewire\PendingPage;
use App\Models\Album;
use App\Models\Artist;
use App\Models\HistoryEntry;
use App\Models\Operation;
use App\Models\PendingChange;
use App\Models\Track;
use App\Support\AlbumPlanner;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\PendingChanges;
use App\Support\Ratings;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Digitaler Zwilling: Aufräumen und Verzeichnis merken nur vor, „Änderungen schreiben“ schreibt einmal und verschiebt einmal. */
class PendingChangesTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/pending-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function mp3(string $rel, array $tags): string
    {
        $path = $this->base.'/library/artists/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags + ['comment' => 'LIB']]]);

        return $path;
    }

    private function fileTags(string $path): array
    {
        return app(TagTool::class)->read([$path])[$path]['tags'];
    }

    /** Album „Gold“ mit uneinheitlichem Albumnamen, schon am Regelort */
    private function gold(): array
    {
        $a = $this->mp3('A/ABBA/Gold (1992)/01 - Waterloo.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'Waterloo']);
        $b = $this->mp3('A/ABBA/Gold (1992)/02 - SOS.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '2', 'title' => 'SOS']);
        $c = $this->mp3('A/ABBA/Gold (1992)/03 - Fernando.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold (Remaster)', 'year' => '1992', 'track' => '3', 'title' => 'Fernando']);
        app(Scanner::class)->scan('library');

        return [$a, $b, $c];
    }

    public function test_apply_only_stages_and_write_puts_it_into_files(): void
    {
        [, , $c] = $this->gold();
        Cache::put(CleanupChecks::FOCUS, 'album_mixed');
        app(CleanupChecks::class)->refreshAll(); // Stand berechnet (macht sonst der Hintergrund-Job)
        Queue::fake();
        $page = Livewire::test(Cleanup::class)->set('check', 'album_mixed')->assertSee('Gold (Remaster)');
        $finding = $page->viewData('findings')[0];
        $page->call('applyOne', $finding['id'])
            ->assertViewHas('total', 0) // sofort weg
            ->assertSee('Änderungen schreiben (1)');

        $this->assertSame('Gold (Remaster)', $this->fileTags($c)['album'], 'Datei unverändert');
        $this->assertSame('Gold', Track::where('path', $c)->value('album'), 'Zwilling geändert');
        $this->assertSame(['album' => 'Gold (Remaster)'], PendingChange::sole()->tags_before);
        $this->assertNull(Operation::first(), 'keine Aktion, kein Worker');

        // eigene Seite „Änderungen schreiben“: Übersicht je Album, Schreiben per Klick
        Queue::fake([]);
        app()->forgetInstance('queue');
        Livewire::test(PendingPage::class)
            ->assertSee('ABBA – Gold')->assertSee('Gold (Remaster)')->assertSee('→ Gold')->assertSee('Alle schreiben (1)')
            ->call('write')
            ->assertSee('Nichts vorgemerkt');
        $this->assertSame('done', Operation::latest('id')->first()->status, (string) Operation::latest('id')->first()->error);
        $this->assertSame('Gold', $this->fileTags($c)['album']);
        $this->assertSame(0, PendingChange::count());
    }

    public function test_misplaced_is_staged_and_written_once_with_cover_then_undone(): void
    {
        $old = $this->mp3('A/ABBA/Gold/1-01  Waterloo.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'Waterloo']);
        file_put_contents(dirname($old).'/cover.jpg', 'bild');
        app(Scanner::class)->scan('library');

        $f = app(CleanupChecks::class)->findings('misplaced');
        app(PendingChanges::class)->stage($f[0]['actions'], 'Ablage');
        $this->assertSame([], app()->make(CleanupChecks::class)->findings('misplaced'), 'vorgemerkt: kein Fund mehr');
        $this->assertFileExists($old, 'noch am alten Ort');

        $op = OperationRunner::dispatch('pending', 'Schreiben', app(PendingChanges::class)->actions()['actions']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $new = $this->base.'/library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3';
        $this->assertFileExists($new);
        $this->assertFileExists(dirname($new).'/cover.jpg', 'Cover zieht mit');
        $this->assertSame($new, Track::sole()->path, 'neuer Pfad im Zwilling');
        $this->assertSame(0, PendingChange::count());

        OperationRunner::dispatchUndo($op->fresh());
        $this->assertFileExists($old);
        $this->assertSame($old, Track::sole()->path);
    }

    public function test_several_stagings_write_tags_once_and_move_once(): void
    {
        $path = $this->mp3('C/Crystals, The/Da Doo Ron Ron (1963)/01 - Da Doo Ron Ron.mp3', ['artist' => 'crystals, the', 'albumartist' => 'crystals, the', 'album' => 'Da Doo Ron Ron', 'year' => '1963', 'track' => '1', 'title' => 'Da Doo Ron Ron']);
        app(Scanner::class)->scan('library');
        $pending = app(PendingChanges::class);
        // erst Schreibweise (Groß/klein), dann umdrehen
        $pending->stage([['type' => 'apply', 'path' => $path, 'tags' => ['artist' => 'Crystals, The', 'albumartist' => 'Crystals, The']]], 'Schreibweise');
        $pending->stage(app()->make(CleanupChecks::class)->findings('inverted')[0]['actions'], 'Umgedrehte Namen');
        $this->assertSame(['artist' => 'crystals, the', 'albumartist' => 'crystals, the'], PendingChange::sole()->tags_before, 'ursprünglicher Dateistand');

        $op = OperationRunner::dispatch('pending', 'Schreiben', $pending->actions()['actions']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $this->assertSame(1, HistoryEntry::where('operation_id', $op->id)->where('kind', 'tags')->count(), 'einmal Tags');
        $this->assertSame(1, HistoryEntry::where('operation_id', $op->id)->where('kind', 'move')->where('new_path', 'like', '%.mp3')->count(), 'einmal verschoben');
        $new = Track::sole();
        $this->assertSame('The Crystals', $new->artist);
        $this->assertStringContainsString('/T/The Crystals/Da Doo Ron Ron (1963)/', $new->path);
        $this->assertSame('The Crystals', $this->fileTags($new->path)['artist']);
    }

    public function test_discard_restores_twin_and_findings(): void
    {
        [, , $c] = $this->gold();
        app(PendingChanges::class)->stage(app(CleanupChecks::class)->findings('album_mixed')[0]['actions'], 'Album');
        $album = Track::where('path', $c)->value('album_id');
        Carbon::setTestNow(now()->addMinute());
        $this->assertSame(1, app(PendingChanges::class)->discard([$album]));
        $this->assertSame('Gold (Remaster)', Track::where('path', $c)->value('album'));
        $this->assertCount(1, app()->make(CleanupChecks::class)->findings('album_mixed'));
    }

    public function test_pending_page_discards_per_album_and_in_sidebar(): void
    {
        [, , $c] = $this->gold();
        app(PendingChanges::class)->stage([['type' => 'apply', 'path' => $c, 'tags' => ['album' => 'Gold']]], 'Album');
        $this->get(route('cleanup'))->assertSee('Änderungen schreiben');
        $album = Track::where('path', $c)->value('album_id');
        Livewire::test(PendingPage::class)->call('discard', $album)->assertSee('verworfen');
        $this->assertSame('Gold (Remaster)', Track::where('path', $c)->value('album'));
    }

    public function test_mixed_finding_is_not_stageable(): void
    {
        $this->assertFalse(PendingChanges::stageable([['type' => 'trash', 'path' => '/a'], ['type' => 'apply', 'path' => '/b', 'target' => '/c']]));
        $this->assertFalse(PendingChanges::stageable([['type' => 'apply', 'path' => '/b', 'embed_from' => '/x']]), 'Cover braucht die Datei');
        $this->assertTrue(PendingChanges::stageable([['type' => 'apply', 'path' => '/b', 'tags' => ['album' => 'X'], 'target' => '/c'], ['type' => 'album_files', 'from_dir' => '/a', 'to_dir' => '/b']]));
    }

    public function test_scan_keeps_twin_and_follows_file_changes(): void
    {
        [$a, , $c] = $this->gold();
        app(PendingChanges::class)->stage(app(CleanupChecks::class)->findings('album_mixed')[0]['actions'], 'Album');
        // Datei extern geändert (anderes Programm) → Zwilling gewinnt, Dateistand nachgeführt
        app(TagTool::class)->write([['path' => $c, 'tags' => ['album' => 'Gold (Deluxe)']]]);
        touch($c, time() + 5);
        app(Scanner::class)->scan('library');
        $this->assertSame('Gold', Track::where('path', $c)->value('album'));
        $this->assertSame(['album' => 'Gold (Deluxe)'], PendingChange::sole()->tags_before);
        app(PendingChanges::class)->discard();
        $this->assertSame('Gold (Deluxe)', Track::where('path', $c)->value('album'), 'Verwerfen = Dateistand');

        // Datei gelöscht → Vormerkung weg
        app(PendingChanges::class)->stage([['type' => 'apply', 'path' => $a, 'tags' => ['title' => 'Waterloo!']]], 'Titel');
        unlink($a);
        app(Scanner::class)->scan('library');
        $this->assertSame(0, PendingChange::count());
    }

    public function test_failed_write_keeps_pending(): void
    {
        [, , $c] = $this->gold();
        app(PendingChanges::class)->stage(app(CleanupChecks::class)->findings('album_mixed')[0]['actions'], 'Album');
        $actions = app(PendingChanges::class)->actions()['actions'];
        rename($c, $c.'.weg'); // Datei kurz weg
        OperationRunner::dispatch('pending', 'Schreiben', $actions);
        $this->assertSame(1, PendingChange::count());
    }

    public function test_staging_while_writing_keeps_the_newer_change(): void
    {
        [, , $c] = $this->gold();
        $pending = app(PendingChanges::class);
        $pending->stage([['type' => 'apply', 'path' => $c, 'tags' => ['album' => 'Gold']]], 'Album');
        $actions = $pending->actions()['actions']; // Vorschau berechnet …
        $pending->stage([['type' => 'apply', 'path' => $c, 'tags' => ['year' => '1993']]], 'Jahr'); // … und danach Neues vorgemerkt
        $op = OperationRunner::dispatch('pending', 'Schreiben', $actions);

        // veraltete Aktion: Datei übersprungen, nichts Halbes geschrieben – alles bleibt vorgemerkt
        $this->assertSame('Gold (Remaster)', $this->fileTags($c)['album']);
        $this->assertSame(['album' => 'Gold (Remaster)', 'year' => '1992'], PendingChange::sole()->tags_before);
        $this->assertSame(1, HistoryEntry::where('operation_id', $op->id)->where('kind', 'info')->count());
        // nächstes Schreiben mit frischen Aktionen schreibt beides
        OperationRunner::dispatch('pending', 'Schreiben', $pending->actions()['actions']);
        $track = Track::where('title', 'Fernando')->sole();
        $this->assertSame(['Gold', '1993'], [$this->fileTags($track->path)['album'], $this->fileTags($track->path)['year']]);
        $this->assertSame(0, PendingChange::count());
    }

    public function test_discarded_meanwhile_is_not_written(): void
    {
        [, , $c] = $this->gold();
        $pending = app(PendingChanges::class);
        $pending->stage([['type' => 'apply', 'path' => $c, 'tags' => ['album' => 'Gold']]], 'Album');
        $actions = $pending->actions()['actions']; // Vorschau offen …
        $pending->discard(); // … woanders verworfen
        OperationRunner::dispatch('pending', 'Schreiben', $actions);
        $this->assertSame('Gold (Remaster)', $this->fileTags($c)['album'], 'verworfene Änderung nicht geschrieben');
    }

    public function test_occupied_target_is_never_overwritten(): void
    {
        $a = $this->mp3('A/ABBA/Gold/1-01  Waterloo.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'Waterloo']);
        // am Regelort liegt schon eine andere Datei
        $other = $this->base.'/library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3';
        File::ensureDirectoryExists(dirname($other));
        file_put_contents($other, 'andere Datei');
        app(Scanner::class)->scan('library');
        app(PendingChanges::class)->stage([['type' => 'apply', 'path' => $a, 'tags' => ['title' => 'Waterloo'], 'target' => $other]], 'Ablage');

        OperationRunner::dispatch('pending', 'Schreiben', app(PendingChanges::class)->actions()['actions']);
        $this->assertSame('andere Datei', file_get_contents($other), 'nichts überschrieben');
        $this->assertFileExists($a, 'bleibt am alten Ort');
    }

    public function test_rating_on_staged_track_keeps_twin(): void
    {
        [, , $c] = $this->gold();
        app(PendingChanges::class)->stage([['type' => 'apply', 'path' => $c, 'tags' => ['album' => 'Gold']]], 'Album');
        app(Ratings::class)->track(Track::where('path', $c)->sole(), 4);
        $this->assertSame('Gold', Track::where('path', $c)->value('album'));
        $this->assertSame(1, PendingChange::count());
    }

    public function test_album_editor_stages_too_and_write_takes_everything(): void
    {
        [, , $c] = $this->gold();
        app(PendingChanges::class)->stage([['type' => 'apply', 'path' => $c, 'tags' => ['album' => 'Gold']]], 'Album');
        $album = Album::where('dir', dirname($c))->sole();
        Queue::fake();
        Livewire::test(AlbumEditor::class, ['album' => $album])
            ->assertSee('vorgemerkte Änderungen')
            ->set('fields.year', '1993')->call('preview', false)->call('confirm')
            ->assertSee('3 Titel vorgemerkt');

        $this->assertNull(Operation::first(), 'Editor schreibt nicht sofort');
        $this->assertSame('1992', $this->fileTags($c)['year'], 'Datei unverändert');
        $this->assertSame('1993', Track::where('path', $c)->value('year'), 'Zwilling geändert');
        $before = PendingChange::where('track_id', Track::where('path', $c)->value('id'))->sole()->tags_before;
        $this->assertSame(['Gold (Remaster)', '1992'], [$before['album'], $before['year']], 'Dateistand gemerkt');

        Queue::fake([]);
        app()->forgetInstance('queue');
        Livewire::test(AlbumEditor::class, ['album' => Album::where('dir', dirname($c))->sole()])->call('writePendingAlbum');
        $this->assertSame('done', Operation::latest('id')->first()->status, (string) Operation::latest('id')->first()->error);
        // Ordner ergibt sich aus den fertigen Tags: Jahr 1993 → „Gold (1993)“
        $new = $this->base.'/library/artists/A/ABBA/Gold (1993)/03 - Fernando.mp3';
        $this->assertFileExists($new);
        $this->assertSame('Gold', $this->fileTags($new)['album'], 'vorgemerkter Albumname mitgeschrieben');
        $this->assertSame('1993', $this->fileTags($new)['year']);
        $this->assertSame(0, PendingChange::count());
    }

    public function test_album_editor_reverting_a_field_drops_its_staging(): void
    {
        [, , $c] = $this->gold();
        app(PendingChanges::class)->stage([['type' => 'apply', 'path' => $c, 'tags' => ['year' => '1993']]], 'Jahr');
        $album = Album::where('dir', dirname($c))->sole();
        Livewire::test(AlbumEditor::class, ['album' => $album])->set('fields.year', '1992')->call('preview', false)->call('confirm');
        $this->assertSame('1992', Track::where('path', $c)->value('year'));
        $this->assertSame([], PendingChange::pluck('tags_before')->flatMap(fn ($t) => array_keys($t))->filter(fn ($k) => $k === 'year')->all());
    }

    public function test_album_editor_stages_new_cover_and_write_embeds_it(): void
    {
        [$a, $b] = $this->gold();
        $img = imagecreatetruecolor(40, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        $jpg = $this->base.'/neu.jpg';
        imagejpeg($img, $jpg);
        $album = Album::where('dir', dirname($a))->sole();
        $probe = $this->base.'/probe.jpg';

        Livewire::test(AlbumEditor::class, ['album' => $album])->set('newCover', $jpg)->call('preview', false)->call('confirm');
        $this->assertFalse(app(TagTool::class)->extractCover($a, $probe), 'noch nicht eingebettet');
        $this->assertFileDoesNotExist(dirname($a).'/cover.jpg');
        $this->assertSame(3, PendingChange::whereNotNull('cover')->count());
        Livewire::test(PendingPage::class)->assertSee('neues Cover');

        $op = OperationRunner::dispatch('pending', 'Schreiben', app(PendingChanges::class)->actions()['actions'], ['on_conflict' => 'skip']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $this->assertTrue(app(TagTool::class)->extractCover($a, $probe));
        $this->assertTrue(app(TagTool::class)->extractCover($b, $probe));
        $this->assertFileExists(dirname($a).'/cover.jpg');
        $this->assertSame(0, PendingChange::count());
        $this->assertSame([], Storage::files('pending-covers'), 'aufgehobenes Cover aufgeräumt');
    }

    public function test_inbox_editor_still_writes_immediately(): void
    {
        $path = $this->base.'/inbox/ABBA/Gold/01 Waterloo.mp3';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'ABBA', 'album' => 'Gold', 'title' => 'Waterloo', 'track' => '1']]]);
        app(Scanner::class)->scan('inbox');
        Livewire::test(AlbumEditor::class, ['album' => Album::where('location', 'inbox')->sole()])
            ->set('fields.year', '1992')->call('preview', false)->call('confirm');
        $this->assertSame('1992', $this->fileTags($path)['year']);
        $this->assertSame(0, PendingChange::count());
    }

    public function test_artist_decision_stages_all_affected_titles(): void
    {
        $a = $this->mp3('D/DJ Bobo/Pray (1999)/01 - Pray.mp3', ['artist' => 'DJ Bobo', 'albumartist' => 'DJ Bobo', 'album' => 'Pray', 'year' => '1999', 'track' => '1', 'title' => 'Pray']);
        $this->mp3('D/DJ BoBo/Planet Colors (2001)/01 - Hard to Say.mp3', ['artist' => 'DJ BoBo', 'albumartist' => 'DJ BoBo', 'album' => 'Planet Colors', 'year' => '2001', 'track' => '1', 'title' => 'Hard to Say']);
        app(Scanner::class)->scan('library');
        $bobo = Artist::create(['name' => 'DJ BoBo']);

        Livewire::test(Artists::class)->call('select', $bobo->id)->set('newAlias', 'DJ Bobo')->call('addAlias');
        // nur Groß/klein – Verzeichnis erkennt „DJ Bobo“ als Schreibweise von „DJ BoBo“ und merkt die Tags gleich vor
        $this->assertSame('DJ BoBo', Track::where('path', $a)->value('artist'));
        $this->assertSame('DJ Bobo', $this->fileTags($a)['artist'], 'Datei unverändert');

        $op = OperationRunner::dispatch('pending', 'Schreiben', app(PendingChanges::class)->actions()['actions']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $new = Track::where('title', 'Pray')->sole();
        $this->assertStringContainsString('/D/DJ BoBo/Pray (1999)/', $new->path, 'Ordner folgt dem Namen');
        $this->assertSame('DJ BoBo', $this->fileTags($new->path)['artist']);
    }

    public function test_export_lists_changes_moves_and_planned_actions(): void
    {
        $old = $this->mp3('A/ABBA/Gold/1-01  Waterloo.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'Waterloo']);
        app(Scanner::class)->scan('library');
        app(PendingChanges::class)->stage([['type' => 'apply', 'path' => $old, 'tags' => ['genres' => ['Pop']]]], 'Genre');

        $data = app(PendingChanges::class)->export();
        $this->assertSame(['tracks' => 1, 'albums' => 1, 'actions' => 2], $data['totals']);
        $track = $data['albums'][0]['tracks'][0];
        $this->assertSame('library/artists/A/ABBA/Gold/1-01  Waterloo.mp3', $track['path'], 'relativ zum Musikordner');
        $this->assertSame(['from' => [], 'to' => ['Pop']], $track['changes']['genres']);
        $this->assertSame('library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3', $track['move']['to'], 'Ziel aus den fertigen Tags');
        $this->assertSame(['Genre'], $track['labels']);
        $this->assertSame(['ABBA', '1992', 'Waterloo'], [$track['tags']['artist'], $track['tags']['year'], $track['tags']['title']], 'Felder zum Prüfen');
        $this->assertSame(['apply', 'album_files'], array_column($data['planned_actions'], 'type'), 'Titel zuerst, dann Cover & Extras des Albums');
        $this->assertSame($track['move']['to'], $data['planned_actions'][0]['target']);

        Livewire::test(PendingPage::class)->assertSee('Als JSON exportieren')
            ->call('export')->assertFileDownloaded();
    }

    /** Sampler, Datei frei unter library/sampler/… */
    private function sampler(string $rel, array $tags): string
    {
        $path = $this->base.'/library/sampler/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags + ['comment' => 'LIB COMPILATION', 'albumartist' => 'Various Artists', 'compilation' => true]]]);

        return $path;
    }

    public function test_sampler_with_year_per_track_goes_to_one_folder(): void
    {
        // Jahr der Original-Single je Titel: Ordner bekommt das Album-Jahr (keine Mehrheit → das neueste), Tags bleiben
        $a = $this->sampler('Dream Dance 12/1-1 ATB - Don\'t Stop.mp3', ['artist' => 'ATB', 'album' => 'Dream Dance 12', 'year' => '1999', 'disc' => '1', 'track' => '1', 'title' => 'Don\'t Stop']);
        $this->sampler('Dream Dance 12/1-2 Peaches - All Over.mp3', ['artist' => 'Peaches', 'album' => 'Dream Dance 12', 'year' => '', 'disc' => '1', 'track' => '2', 'title' => 'All Over']);
        $c = $this->sampler('Dream Dance 12/1-3 Moby - Run On.mp3', ['artist' => 'Moby', 'album' => 'Dream Dance 12', 'year' => '1992', 'disc' => '1', 'track' => '3', 'title' => 'Run On']);
        app(Scanner::class)->scan('library');
        $this->assertSame([], app(CleanupChecks::class)->findings('album_mixed'), 'verschiedene Jahre sind kein Fund');

        app(PendingChanges::class)->stage([['type' => 'apply', 'path' => $a, 'tags' => ['genres' => ['Trance']]]], 'Genre');
        $op = OperationRunner::dispatch('pending', 'Schreiben', app(PendingChanges::class)->actions()['actions'], ['on_conflict' => 'skip']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        $dir = $this->base.'/library/sampler/D/Dream Dance 12 (1999)';
        $this->assertSame([$dir], Track::pluck('dir')->unique()->values()->all(), 'ein Ordner für das ganze Album');
        $this->assertSame('1992', $this->fileTags($dir.'/03 - Moby - Run On.mp3')['year'], 'Jahr im Tag bleibt');
        $this->assertFileDoesNotExist($c);
    }

    public function test_two_files_with_the_same_target_stay_where_they_are(): void
    {
        $a = $this->mp3('A/ABBA/Gold/1-01 Waterloo.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'Waterloo']);
        $b = $this->mp3('A/ABBA/Gold/1-01 Waterloo (3).mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '1', 'title' => 'Waterloo']);
        $c = $this->mp3('A/ABBA/Gold/1-02 SOS.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '2', 'title' => 'SOS']);
        app(Scanner::class)->scan('library');

        $f = app(CleanupChecks::class)->findings('misplaced')[0];
        $this->assertStringContainsString('2 Datei(en) hätten dasselbe Ziel', $f['detail']);
        $this->assertStringContainsString('würde auf 2 Ordner verteilt', $f['detail'], 'SOS allein umziehen hieße: Album zerrissen');
        $this->assertSame([], $f['actions'], 'nichts verschieben, bis die Duplikate entschieden sind');
        foreach ([$a, $b, $c] as $p) {
            $this->assertFileExists($p);
        }
    }

    public function test_year_with_date_keeps_only_the_year(): void
    {
        $a = $this->mp3('A/ABBA/Gold (1992)/01 - Waterloo.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992-09-21', 'track' => '1', 'title' => 'Waterloo']);
        $this->mp3('A/ABBA/Gold (1992)/02 - SOS.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'year' => '1992', 'track' => '2', 'title' => 'SOS']);
        app(Scanner::class)->scan('library');
        $this->assertSame('1992-09-21', Track::where('path', $a)->value('year'), 'Datum wird so eingelesen');

        $f = collect(app(CleanupChecks::class)->findings('year_date'))->sole();
        $this->assertSame('1 Titel: „1992-09-21“ → 1992', $f['detail']);
        $this->assertSame([['type' => 'apply', 'path' => $a, 'tags' => ['year' => '1992']]], $f['actions']);
        $this->assertTrue(PendingChanges::stageable($f['actions']));

        // Album-Editor: eingegebenes bzw. nachgeschlagenes Datum → nur das Jahr
        $plan = app(AlbumPlanner::class)->plan(Album::where('dir', dirname($a))->sole(), ['year' => '1992-09-21'], [], false);
        $this->assertSame(['1992'], array_values(array_unique(array_column(array_column($plan['rows'], 'changed'), 'year'))), 'nur der Titel mit Datum ändert sich, auf 1992');
    }

    public function test_editor_keeps_unlisted_genres_when_genre_field_is_empty(): void
    {
        $a = $this->mp3('D/Die singenden Weinhauer/Badner Luft (1970)/01 - Badner Luft.mp3', ['artist' => 'Die singenden Weinhauer', 'albumartist' => 'Die singenden Weinhauer',
            'album' => 'Badner Luft', 'year' => '1970', 'track' => '1', 'title' => 'Badner Luft', 'genres' => ['Schrammeln', 'Austria']]);
        app(Scanner::class)->scan('library');
        $page = Livewire::test(AlbumEditor::class, ['album' => Album::where('dir', dirname($a))->sole()])->assertSet('fields.genres', []);
        $page->set('fields.year', '1971')->call('preview', false)->call('confirm');

        $pc = PendingChange::sole();
        $this->assertArrayNotHasKey('genres', $pc->tags_before, 'Genres nicht angefasst');
        $this->assertSame(['Schrammeln', 'Austria'], Track::where('path', $a)->sole()->genres);
    }

    public function test_stage_for_many_names_does_not_overflow_sqlite(): void
    {
        // viele Künstler mit allen MusicBrainz-Schreibweisen auf einmal („Alle Vorschläge übernehmen“)
        $a = $this->mp3('L/Lexy & K-Paul/Lexy (2000)/01 - Freak.mp3', ['artist' => 'Lexy & K-Paul', 'albumartist' => 'Lexy & K-Paul', 'album' => 'Lexy', 'year' => '2000', 'track' => '1', 'title' => 'Freak']);
        app(Scanner::class)->scan('library');
        $names = array_map(fn ($i) => 'Name '.$i, range(1, 400));
        $names[] = 'Lexy & K-Paul';

        $this->assertSame(0, app(PendingChanges::class)->stageForNames($names, 'Test'), 'läuft durch (nichts zu ändern)');
    }
}
