<?php

namespace Tests\Feature;

use App\Livewire\YouTubeDownload;
use App\Models\Album;
use App\Models\HistoryEntry;
use App\Models\Operation;
use App\Support\OperationRunner;
use App\Support\Settings;
use App\Support\YouTube;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class YouTubeDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/yt-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        File::ensureDirectoryExists($this->base.'/inbox');

        // Ersatz für yt-dlp: legt MP3s unter -P an und meldet sie wie das echte Werkzeug.
        // Links mit "list=" sind Playlists mit 3 Titeln; --max-downloads und --download-archive werden beachtet.
        $mp3 = $this->base.'/frame.mp3';
        file_put_contents($mp3, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        $fake = $this->base.'/yt-dlp';
        file_put_contents($fake, strtr(<<<'SH'
            #!/bin/sh
            max=""; archive=""
            while [ "$#" -gt 0 ]; do
              case "$1" in -P) dir="$2"; shift ;; --max-downloads) max="$2"; shift ;; --download-archive) archive="$2"; shift ;; --) url="$2" ;; esac
              shift
            done
            case "$url" in *fail*) [ -f "BASE/healed" ] || { echo "ERROR: Video unavailable" >&2; exit 1; } ;; esac
            name=Song; case "$url" in *fail*) name=Other ;; esac
            n=1; case "$url" in *list=*) n=3 ;; esac
            mkdir -p "$dir/Einzeltitel"
            count=0
            for i in $(seq 1 $n); do
              id="$name$i"
              if [ -n "$archive" ] && [ -f "$archive" ] && grep -qx "$id" "$archive"; then continue; fi
              if [ -n "$max" ] && [ "$count" -ge "$max" ]; then exit 101; fi
              file="$dir/Einzeltitel/Artist - $name.mp3"; [ "$n" -gt 1 ] && file="$dir/Einzeltitel/Artist - $name $i.mp3"
              echo "MWPROG $i|$n| 50.0%|$name"
              cp "MP3" "$file"
              echo "MWFILE $file"
              [ -n "$archive" ] && echo "$id" >> "$archive"
              count=$((count + 1))
            done
            [ -n "$max" ] && [ "$count" -ge "$max" ] && exit 101
            exit 0
            SH, ['BASE' => $this->base, 'MP3' => $mp3]));
        chmod($fake, 0755);
        config(['musicwiz.ytdlp' => $fake]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_download_lands_in_inbox_and_undo_moves_to_trash(): void
    {
        Livewire::test(YouTubeDownload::class)
            ->set('urls', "https://www.youtube.com/watch?v=abc\n")
            ->call('download')
            ->assertSee('Einzeltitel/Artist - Song.mp3');

        $file = $this->base.'/inbox/YouTube/Einzeltitel/Artist - Song.mp3';
        $this->assertFileExists($file);
        $op = Operation::where('type', 'download')->sole();
        $this->assertSame('done', $op->status);
        $this->assertSame(1, HistoryEntry::where('kind', 'download')->where('new_path', $file)->count());
        $this->assertSame(1, Album::where('location', 'inbox')->count(), 'Download erscheint direkt in der Inbox');

        OperationRunner::dispatchUndo($op);
        $this->assertFileDoesNotExist($file);
        $this->assertFileExists($this->base.'/_papierkorb/inbox/YouTube/Einzeltitel/Artist - Song.mp3');
        $this->assertSame(0, Album::where('location', 'inbox')->count());
    }

    public function test_already_present_file_is_not_claimed(): void
    {
        $dir = $this->base.'/inbox/YouTube';
        app(YouTube::class)->download('https://youtu.be/abc', $dir);
        $again = app(YouTube::class)->download('https://youtu.be/abc', $dir);
        $this->assertSame([], $again['files'], 'vorhandene Datei gehört nicht zum neuen Download (Rückgängig würde sie sonst wegräumen)');
        $this->assertNotNull($again['error']);
    }

    public function test_failure_and_invalid_links(): void
    {
        Livewire::test(YouTubeDownload::class)
            ->set('urls', 'https://example.com/video')
            ->call('download')
            ->assertSee('Kein YouTube-Link');
        $this->assertSame(0, Operation::count());

        OperationRunner::dispatch('download', 'x', [['type' => 'download', 'url' => 'https://youtu.be/fail', 'dir' => $this->base.'/inbox/YouTube']]);
        $op = Operation::sole();
        $this->assertSame('failed', $op->status);
        $this->assertStringContainsString('Video unavailable', $op->error);
    }

    public function test_retry_failed_download_once(): void
    {
        $dir = $this->base.'/inbox/YouTube';
        OperationRunner::dispatch('download', 'YouTube: 2 Links herunterladen', [
            ['type' => 'download', 'url' => 'https://youtu.be/ok', 'dir' => $dir],
            ['type' => 'download', 'url' => 'https://youtu.be/fail', 'dir' => $dir],
        ]);
        $failed = Operation::sole();
        $this->assertSame('failed', $failed->status);

        touch($this->base.'/healed');
        $page = Livewire::test(YouTubeDownload::class)->assertSee('Erneut versuchen')->call('retry', $failed->id);
        $retry = Operation::latest('id')->first();
        $this->assertSame($failed->id, $retry->payload['options']['retry_of']);
        $this->assertSame('done', $retry->status);
        $this->assertSame(['https://youtu.be/fail'], array_column($retry->payload['actions'], 'url'), 'nur der fehlgeschlagene Link');

        $page->call('retry', $failed->id)->assertDontSee('Erneut versuchen');
        $this->assertSame(2, Operation::count(), 'kein zweiter Versuch für denselben Download');
    }

    public function test_daily_limit_defers_rest_of_playlist_to_tomorrow(): void
    {
        app(Settings::class)->set(['yt_daily_limit' => 2]);
        Livewire::test(YouTubeDownload::class)->set('urls', 'https://www.youtube.com/playlist?list=PL1')->call('download');

        $first = Operation::where('type', 'download')->orderBy('id')->first();
        $this->assertSame('done', $first->status);
        $this->assertSame(2, HistoryEntry::where('kind', 'download')->count(), 'nur so viele wie die Tagesgrenze erlaubt');
        $this->assertSame(2, YouTube::countToday());

        $next = Operation::where('type', 'download')->latest('id')->first();
        $this->assertNotSame($first->id, $next->id);
        $this->assertTrue($next->isPlanned(), 'Rest ist für morgen eingeplant');
        $this->assertStringStartsWith('Fortsetzung: ', $next->description);
        $this->assertSame($first->payload['actions'][0]['archive'], $next->payload['actions'][0]['archive']);

        // Grenze aufheben und sofort starten: nur der fehlende dritte Titel wird geladen
        app(Settings::class)->set(['yt_daily_limit' => 0]);
        Livewire::test(YouTubeDownload::class)->assertSee('Jetzt starten')->call('startNow', $next->id);
        $next->refresh();
        $this->assertSame('done', $next->status);
        $this->assertSame(['Artist - Song 3.mp3'], $next->entries()->where('kind', 'download')->pluck('new_path')->map(fn ($p) => basename($p))->all());
        $this->assertSame(2, Operation::where('type', 'download')->count(), 'keine weitere Fortsetzung');
    }

    public function test_exact_limit_on_single_video_needs_no_continuation(): void
    {
        app(Settings::class)->set(['yt_daily_limit' => 1]);
        OperationRunner::dispatch('download', 'x', [['type' => 'download', 'url' => 'https://youtu.be/abc', 'dir' => $this->base.'/inbox/YouTube']]);
        $this->assertSame('done', Operation::sole()->status);

        // Grenze erreicht: der nächste Link wartet bis morgen, statt zu laden
        OperationRunner::dispatch('download', 'y', [['type' => 'download', 'url' => 'https://youtu.be/fail', 'dir' => $this->base.'/inbox/YouTube']]);
        $this->assertTrue(Operation::latest('id')->first()->isPlanned());
        $this->assertFileDoesNotExist($this->base.'/inbox/YouTube/Einzeltitel/Artist - Other.mp3');
    }

    public function test_limits_are_saved_and_used(): void
    {
        Livewire::test(YouTubeDownload::class)
            ->set('limits.sleep_min', 20)->set('limits.sleep_max', 10)->call('saveLimits')->assertHasErrors('limits.sleep_max')
            ->set('limits', ['sleep_min' => 2, 'sleep_max' => 4, 'sleep_requests' => 0, 'rate_mb' => 1.5, 'daily_limit' => 30])
            ->call('saveLimits')->assertHasNoErrors()->assertSee('Gespeichert');

        $cmd = implode(' ', app(YouTube::class)->command('https://youtu.be/abc', '/x'));
        $this->assertStringContainsString('--sleep-interval 2 --max-sleep-interval 4', $cmd);
        $this->assertStringContainsString('--limit-rate 1536K', $cmd);
        $this->assertStringNotContainsString('--sleep-requests', $cmd, '0 = aus');
    }

    public function test_delete_history_keeps_files(): void
    {
        Livewire::test(YouTubeDownload::class)->set('urls', 'https://youtu.be/abc')->call('download');
        $op = Operation::sole();
        $file = $op->entries()->where('kind', 'download')->value('new_path');
        OperationRunner::dispatchUndo($op);
        $running = Operation::create(['type' => 'download', 'description' => 'läuft', 'status' => 'running', 'payload' => ['actions' => []], 'total' => 1]);

        Livewire::test(YouTubeDownload::class)->call('clearHistory');
        $this->assertSame([$running->id], Operation::pluck('id')->all(), 'Download samt Rückgängig weg, laufender bleibt');
        $this->assertSame(0, HistoryEntry::count());
        $this->assertFileExists(str_replace('/inbox/', '/_papierkorb/inbox/', $file), 'Dateien bleiben, wo sie sind');
    }
}
