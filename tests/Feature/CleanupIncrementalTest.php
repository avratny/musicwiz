<?php

namespace Tests\Feature;

use App\Jobs\RefreshCleanup;
use App\Jobs\RunOperation;
use App\Livewire\Cleanup;
use App\Livewire\SettingsPage;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistNames;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Aufräumen schrittweise: nach Änderungen an einzelnen Alben nur diese neu prüfen – Ergebnis wie beim kompletten Neurechnen. */
class CleanupIncrementalTest extends TestCase
{
    use RefreshDatabase;

    private function album(string $artist, string $title, array $tracks, array $albumAttrs = []): Album
    {
        $dir = "/music/library/artists/{$artist[0]}/$artist/$title";
        $album = Album::create(['location' => 'library', 'dir' => $dir, 'title' => $title, 'category' => 'LIB', 'albumartist' => $artist, 'folder_artist' => $artist, 'track_count' => count($tracks)] + $albumAttrs);
        foreach ($tracks as $n => $t) {
            Track::create($t + ['album_id' => $album->id, 'location' => 'library', 'path' => "$dir/$n.mp3", 'dir' => $dir, 'filename' => "$n.mp3", 'ext' => 'mp3', 'writable' => true,
                'artist' => $artist, 'albumartist' => $artist, 'album' => $title, 'track' => (string) ($n + 1), 'title' => "T$n", 'comment' => 'LIB',
                'artists' => [], 'albumartists' => [], 'genres' => ['Pop'], 'duration' => 200 + $n]);
        }

        return $album;
    }

    /** Funde je Album-Prüfung, nach ID sortiert (Reihenfolge ist bei Komplett und schrittweise gleichgültig) */
    private function localFindings(CleanupChecks $checks): array
    {
        $out = [];
        foreach (CleanupChecks::ALBUM_LOCAL as $key) {
            $out[$key] = collect($checks->findings($key))->sortBy('id')->values()->all();
        }

        return $out;
    }

    public function test_incremental_equals_full_recompute(): void
    {
        app(Settings::class)->set(['base' => '/music']);
        $a = $this->album('ABBA', 'Gold', [[], [], ['album' => 'Gold (Remaster)'], ['track' => '']]); // uneinheitlich + Nummer fehlt
        $b = $this->album('Yanou', 'Sunrise', [['comment' => 'BLACKHOLE'], ['artist' => 'Yanou feat. Liz']]); // Kommentar, Kombi
        $this->album('ATB', 'Movin Melodies', [[], [], []]);
        app(CleanupChecks::class)->refreshAll();

        // ein Album ändern (ABBA vereinheitlichen) und ein neues hinzufügen; eine Bewertung woanders
        Carbon::setTestNow(now()->addMinute());
        Track::where('album_id', $a->id)->update(['album' => 'Gold', 'track' => '9']);
        $this->album('Scooter', 'Hyper', [['album' => 'Hyper Hyper'], []]);
        Track::where('album_id', $b->id)->update(['rating' => 5]);

        $checks = app()->make(CleanupChecks::class);
        $changed = (fn () => $this->changedSinceLast())->call($checks);
        $this->assertNotNull($changed, 'schrittweise möglich');
        $this->assertEqualsCanonicalizing([$a->id, Album::where('title', 'Hyper')->value('id')], $changed[1], 'nur geänderte und neue Alben – die Bewertung zählt nicht');
        $this->assertSame('done', $checks->refreshAll());
        $incremental = $this->localFindings(app()->make(CleanupChecks::class));

        // Vergleich: alles komplett neu
        Cache::flush();
        $full = $this->localFindings(app()->make(CleanupChecks::class));
        $this->assertSame($full, $incremental);
        foreach (['album_mixed', 'misplaced', 'comment', 'combined'] as $key) {
            $this->assertNotEmpty($full[$key], "Testdaten haben Funde für $key");
        }
        $this->assertSame(['Scooter – Hyper'], array_column($full['album_mixed'], 'title'), 'ABBA ist behoben, Scooter neu');
    }

    public function test_settings_change_recomputes_everything(): void
    {
        app(Settings::class)->set(['base' => '/music']);
        $this->album('ABBA', 'Gold', [[], []]);
        app(CleanupChecks::class)->refreshAll();
        app(Settings::class)->set(['no_album' => 'Ohne Album']);

        $this->assertNull((fn () => $this->changedSinceLast())->call(app()->make(CleanupChecks::class)), 'Einstellungen geändert: alles neu');
    }

    public function test_operations_no_longer_refresh_in_advance(): void
    {
        Queue::fake();
        $op = OperationRunner::dispatch('cleanup', 'Test', []);
        (new RunOperation($op->id))->handle(app(OperationRunner::class));
        Queue::assertNotPushed(RefreshCleanup::class);
    }

    public function test_inverted_names_follow_setting(): void
    {
        app(Settings::class)->set(['base' => '/music']);
        $this->album('Crystals, The', 'Da Doo Ron Ron', [['artists' => ['Crystals, The']], ['artist' => 'Crystals, The feat. Darlene Love']]);
        $this->album('Berry, Chuck', 'Maybellene', [[]]);
        $this->album('Earth, Wind & Fire', 'September', [[]]);
        $this->assertSame('The Crystals', ArtistNames::uninvert('Crystals, The'));
        $this->assertSame('Die Toten Hosen', ArtistNames::uninvert('Toten Hosen, Die'));
        $this->assertNull(ArtistNames::uninvert('Berry, Chuck'), 'Person nur mit Einstellung „persons“');
        $this->assertSame('Chuck Berry', ArtistNames::uninvert('Berry, Chuck', true));

        // Standard: nur Artikel
        $f = collect(app()->make(CleanupChecks::class)->findings('inverted'))->keyBy('title');
        $this->assertSame(['Crystals, The – Da Doo Ron Ron'], $f->keys()->all());
        $crystals = $f['Crystals, The – Da Doo Ron Ron'];
        $this->assertSame(['field' => 'Interpret', 'from' => 'Crystals, The', 'count' => 4, 'to' => 'The Crystals'], $crystals['changes'][0]);
        $tags = collect($crystals['actions'])->pluck('tags')->all();
        $this->assertSame(['artist' => 'The Crystals', 'artists' => ['The Crystals'], 'albumartist' => 'The Crystals'], $tags[0]);
        $this->assertSame('The Crystals feat. Darlene Love', $tags[1]['artist']);

        // auch Personen
        app(Settings::class)->set(['invert_names' => 'persons']);
        $this->assertCount(2, app()->make(CleanupChecks::class)->findings('inverted'));

        // aus
        app(Settings::class)->set(['invert_names' => 'off']);
        $this->assertSame([], app()->make(CleanupChecks::class)->findings('inverted'));
        Livewire::test(SettingsPage::class)->assertSee('Umgedrehte Namen');
    }

    public function test_inverted_directory_entries_are_renamed(): void
    {
        $images = storage_path('framework/testing/inv-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($images);
        file_put_contents($images.'/Coasters, The.jpg', 'bild');
        app(Settings::class)->set(['base' => '/music', 'artist_images' => $images, 'trash' => $images.'/_papierkorb']);
        $coasters = Artist::create(['name' => 'Coasters, The', 'image' => 'Coasters, The.jpg']);
        Artist::create(['name' => 'Crystals, The', 'mbid' => 'crystals']);
        $right = Artist::create(['name' => 'The Crystals']);
        $this->album('Coasters, The', 'Yakety Yak', [[]]);

        // schon vor dem Umbenennen gilt die richtig herum gedrehte Form (kein Hin und Her mit „Schreibweisen“)
        $this->assertSame(['status' => 'variant', 'canonical' => 'The Coasters'], app(ArtistDirectory::class)->check('Coasters, The'));
        $page = Livewire::test(Cleanup::class)->set('check', 'inverted')
            ->assertSee('Im Verzeichnis umbenennen (2)')
            ->call('renameInvertedArtists')
            ->assertSee('„Coasters, The“ → „The Coasters“');

        $coasters->refresh();
        $this->assertSame('The Coasters', $coasters->name);
        $this->assertSame('The Coasters.jpg', $coasters->image);
        $this->assertFileExists($images.'/The Coasters.jpg');
        $this->assertSame(['Coasters, The'], $coasters->aliases()->pluck('alias')->all());
        // „The Crystals“ gab es schon: zusammengeführt, ID übernommen
        $this->assertFalse(Artist::where('name', 'Crystals, The')->exists());
        $this->assertSame('crystals', $right->fresh()->mbid);
        $this->assertSame(['status' => 'variant', 'canonical' => 'The Coasters'], app(ArtistDirectory::class)->check('Coasters, The'));
        $page->assertDontSee('Im Verzeichnis umbenennen');
        File::deleteDirectory($images);
    }

    public function test_inverted_and_spelling_never_ping_pong(): void
    {
        app(Settings::class)->set(['base' => '/music']);
        Artist::create(['name' => 'Coasters, The']); // Verzeichnis noch umgedreht
        $album = $this->album('Coasters, The', 'Yakety Yak', [[], []]);

        $checks = fn () => app()->make(CleanupChecks::class);
        $inverted = $checks()->findings('inverted');
        $this->assertSame('The Coasters', $inverted[0]['actions'][0]['tags']['artist']);
        // „Uneinheitliche Schreibweisen“ will dasselbe – nicht zurück auf „Coasters, The“
        foreach ($checks()->findings('spelling') as $f) {
            foreach ($f['actions'] as $a) {
                $this->assertStringNotContainsString('Coasters, The', json_encode($a['tags'] ?? [], JSON_UNESCAPED_UNICODE));
            }
        }

        // „Richtig herum schreiben“ angewendet → keine der beiden Prüfungen meldet noch etwas
        Carbon::setTestNow(now()->addMinute());
        Track::where('album_id', $album->id)->update(['artist' => 'The Coasters', 'albumartist' => 'The Coasters']);
        $this->assertSame([], $checks()->findings('inverted'));
        $this->assertSame([], $checks()->findings('spelling'));
        $this->assertSame(['status' => 'ok', 'canonical' => 'The Coasters'], app(ArtistDirectory::class)->check('The Coasters'));
        // Ordner richtet sich nach „The Coasters“
        $target = collect($checks()->findings('misplaced'))->flatMap(fn ($f) => $f['actions'])->pluck('target')->filter()->first();
        $this->assertStringContainsString('/T/The Coasters/', (string) $target);

        // Einstellung „aus“: Verzeichnis-Schreibweise gilt wieder
        app(Settings::class)->set(['invert_names' => 'off']);
        app()->forgetInstance(ArtistDirectory::class);
        $this->assertSame('Coasters, The', app(ArtistDirectory::class)->check('The Coasters')['canonical']);
    }
}
