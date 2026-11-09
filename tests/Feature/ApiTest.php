<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Operation;
use App\Models\PendingChange;
use App\Models\Track;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Schnittstelle: lesen und vormerken – nie in die Dateien schreiben. */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/api-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        foreach (['01 - Waterloo' => 'Gold', '02 - SOS' => 'Gold', '03 - Fernando' => 'Gold (Remaster)'] as $file => $album) {
            $path = $this->base.'/library/artists/A/ABBA/Gold (1992)/'.$file.'.mp3';
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => $album, 'year' => '1992',
                'track' => substr($file, 1, 1), 'title' => substr($file, 5), 'comment' => 'LIB']]]);
        }
        app(Scanner::class)->scan('library');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function fileAlbum(string $file): string
    {
        $path = $this->base.'/library/artists/A/ABBA/Gold (1992)/'.$file.'.mp3';

        return app(TagTool::class)->read([$path])[$path]['tags']['album'];
    }

    public function test_read_endpoints(): void
    {
        $this->getJson('/api')->assertOk()->assertJsonPath('counts.tracks', 3)->assertJsonStructure(['endpoints', 'tag_keys']);
        $this->getJson('/api/stats')->assertOk()->assertJsonPath('albums', 1);
        $this->getJson('/api/checks')->assertOk()->assertJsonFragment(['key' => 'album_mixed']);

        $album = Album::sole();
        $this->getJson('/api/albums?q=Gold')->assertOk()->assertJsonPath('albums.0.dir', 'library/artists/A/ABBA/Gold (1992)');
        $this->getJson('/api/albums/'.$album->id)->assertOk()->assertJsonCount(3, 'tracks')
            ->assertJsonPath('tracks.0.path', 'library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3')
            ->assertJsonPath('tracks.0.tags.title', 'Waterloo');
        $this->getJson('/api/tracks?q=Fernando')->assertOk()->assertJsonCount(1, 'tracks');
        $this->getJson('/api/tracks')->assertStatus(422);
        $this->getJson('/api/checks/gibtsnicht')->assertNotFound();

        $f = $this->getJson('/api/checks/album_mixed?fresh=1')->assertOk()->assertJsonPath('total', 1)->json('findings.0');
        $this->assertSame('library/artists/A/ABBA/Gold (1992)/03 - Fernando.mp3', $f['actions'][0]['path'], 'Pfade relativ');
    }

    public function test_stage_and_discard_never_touch_files(): void
    {
        // Behebung eines Funds vormerken
        $id = $this->getJson('/api/checks/album_mixed?fresh=1')->json('findings.0.id');
        $this->postJson('/api/checks/album_mixed/stage', ['ids' => [$id, 'a999']])->assertOk()
            ->assertJsonPath('staged_findings', 1)->assertJsonPath('staged_tracks', 1)->assertJsonPath('missing', ['a999']);
        $this->assertSame('Gold', Track::where('title', 'Fernando')->value('album'), 'Zwilling geändert');
        $this->assertSame('Gold (Remaster)', $this->fileAlbum('03 - Fernando'), 'Datei unverändert');

        // eigene Tag-Änderungen per Pfad bzw. ID
        $sos = Track::where('title', 'SOS')->value('id');
        $this->postJson('/api/pending/stage', ['label' => 'Analyse', 'changes' => [
            ['path' => 'library/artists/A/ABBA/Gold (1992)/01 - Waterloo.mp3', 'tags' => ['genres' => ['Pop']]],
            ['track_id' => $sos, 'tags' => ['year' => '1993']],
            ['track_id' => $sos, 'tags' => ['path' => '/etc/passwd']],
            ['path' => 'gibt/es/nicht.mp3', 'tags' => ['year' => '1']],
        ]])->assertOk()->assertJsonPath('staged', 2)->assertJsonCount(2, 'errors');
        $this->assertSame(3, PendingChange::count());
        $this->getJson('/api/pending')->assertOk()->assertJsonPath('totals.tracks', 3);
        $this->assertNull(Operation::first(), 'keine Aktion, nichts geschrieben');

        // verwerfen
        $this->postJson('/api/pending/discard', ['track_ids' => [$sos]])->assertOk()->assertJsonPath('discarded', 1);
        $this->assertSame('1992', Track::find($sos)->year);
        $this->postJson('/api/pending/discard', ['all' => true])->assertOk()->assertJsonPath('discarded', 2);
        $this->assertSame(0, PendingChange::count());
        $this->assertSame('Gold (Remaster)', Track::where('title', 'Fernando')->value('album'));
    }

    public function test_status_logs_and_history_detail(): void
    {
        $this->getJson('/api/status')->assertOk()
            ->assertJsonStructure(['scan', 'operations', 'queues', 'failed_jobs' => ['count', 'latest'], 'cleanup', 'dirs' => ['base' => ['writable', 'free_gb']], 'env' => ['memory_limit']])
            ->assertJsonPath('dirs.base.exists', true);

        // Protokoll: neueste zuerst, Zugangsdaten geschwärzt
        app(Settings::class)->set(['discogs_token' => 'geheim-token-123']);
        File::ensureDirectoryExists(storage_path('app/logs'));
        $log = storage_path('app/logs/musicwiz-test-'.bin2hex(random_bytes(3)).'.log');
        file_put_contents($log, "[2026-11-09 10:00:00] production.INFO: alles gut\n[2026-11-09 10:01:00] production.ERROR: Discogs geheim-token-123 abgelehnt\n#0 /app/x.php(1): y()\n");
        try {
            $entries = $this->getJson('/api/logs?level=error&entries=5')->assertOk()->json('entries');
            $mine = collect($entries)->firstWhere('file', basename($log));
            $this->assertSame('Discogs *** abgelehnt', $mine['message']);
            $this->assertSame(['#0 /app/x.php(1): y()'], $mine['trace']);
            $this->assertFalse(collect($entries)->contains(fn ($e) => $e['message'] === 'alles gut'), 'unter der Stufe ausgeblendet');
        } finally {
            @unlink($log);
        }

        // Verlauf einer Aktion mit Einträgen
        $op = Operation::create(['type' => 'pending', 'description' => 'Test', 'status' => 'done', 'total' => 2, 'done' => 1, 'failed' => 1]);
        $op->entries()->create(['kind' => 'move', 'old_path' => $this->base.'/library/a.mp3', 'new_path' => $this->base.'/library/b.mp3']);
        $op->entries()->create(['kind' => 'info', 'old_path' => $this->base.'/library/c.mp3', 'error' => 'übersprungen – Ziel belegt']);
        $this->getJson('/api/history/'.$op->id)->assertOk()->assertJsonCount(2, 'entries')->assertJsonPath('entries.0.new_path', 'library/b.mp3');
        $this->getJson('/api/history/'.$op->id.'?errors=1')->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.error', 'übersprungen – Ziel belegt');
    }
}
