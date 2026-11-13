<?php

namespace App\Jobs;

use App\Models\FilePlan;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Activity;
use App\Support\FileMover;
use App\Support\Sorter;
use App\Support\TagWriter;
use App\Support\TextCleanup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Schreiben: je Album erst die offenen Tag-Änderungen in die Dateien (TagWriter), dann sortieren (Sorter, FileMover) –
 * verschoben wird nur, wenn die Tags des Albums vollständig geschrieben sind. Alle bzw. nur bestimmte Alben, paketweise;
 * „Anhalten“ wirkt nach dem laufenden Paket. Fehler bleiben offen und stehen auf der Seite „Schreiben“.
 */
class WriteChanges implements ShouldQueue
{
    use Queueable;

    public const KEY = 'write';

    /** Fehler des letzten Durchlaufs (Pfad bzw. Ordner => Grund) für die Seite */
    public const FAILED = 'write:failed';

    private const BATCH = 10;

    private const STOP = 'write:stop';

    public int $timeout = 0;

    /**
     * @param  list<string>  $albums  zu bearbeitende Alben (beim Start festgelegt)
     */
    public function __construct(public array $albums, public int $done = 0, public int $tags = 0, public int $moved = 0, public int $failed = 0) {}

    /** @param  list<string>|null  $only  nur diese Alben (null = alle mit offenen Änderungen bzw. Datei-Aktionen) */
    public static function start(?array $only = null): bool
    {
        $albums = self::albums($only);
        if (Activity::running(self::KEY) || ! $albums) {
            return false;
        }
        Cache::forget(self::STOP);
        Cache::forget(self::FAILED);
        Activity::start(self::KEY, 'Schreiben', count($albums), 'startet …');
        self::dispatch($albums);

        return true;
    }

    /** Alben mit schreibbaren Tag-Änderungen oder Datei-Aktionen (nicht blockiert), nach Ordner sortiert */
    public static function albums(?array $only = null): array
    {
        // blockierte Alben haben keine Aktionen (alles „bleibt“), bei Einzeltiteln nur die blockierten Lieder
        $sorts = app(Sorter::class)->overview()->filter(fn ($s) => (bool) $s['counts'])->keys();
        $hashes = TagWriter::writable()->distinct()->pluck('album_hash')->merge($sorts)->unique()
            ->when($only !== null, fn ($c) => $c->intersect($only));

        return Track::whereIn('album_hash', $hashes)->selectRaw('album_hash, MIN(album_dir) AS dir')->groupBy('album_hash')->orderBy('dir')
            ->pluck('album_hash')->all();
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish(self::KEY, 'Schreiben: Fehler – '.mb_substr((string) $e?->getMessage(), 0, 150).' (Erledigtes bleibt, erneut starten setzt fort)');
    }

    public function handle(TagWriter $writer, Sorter $sorter, FileMover $mover): void
    {
        $total = count($this->albums);
        // abgebrochene Datei-Aktionen eines früheren Laufs abgleichen
        if ($this->done === 0) {
            $this->fail($mover->recover());
        }
        if (! Cache::get(self::STOP)) {
            foreach (array_slice($this->albums, $this->done, self::BATCH) as $hash) {
                Activity::progress(self::KEY, $this->done, $total, null, Track::where('album_hash', $hash)->value('album') ?? '');
                try {
                    $this->album($hash, $writer, $sorter, $mover);
                } catch (Throwable $e) {
                    report($e);
                    $this->fail([$hash => $e->getMessage()]);
                }
                $this->done++;
            }
        }
        Activity::progress(self::KEY, $this->done, $total);
        $summary = $this->tags.' '.($this->tags === 1 ? 'Lied' : 'Lieder').' getaggt, '.$this->moved.' '.($this->moved === 1 ? 'Datei' : 'Dateien').' verschoben'
            .($this->failed ? ', '.$this->failed.' mit Fehler' : '');
        if (Cache::pull(self::STOP)) {
            Activity::finish(self::KEY, 'Schreiben angehalten – '.$summary);
        } elseif ($this->done < $total) {
            self::dispatch($this->albums, $this->done, $this->tags, $this->moved, $this->failed);
        } else {
            Activity::finish(self::KEY, 'Schreiben fertig: '.$summary);
        }
    }

    /** ein Album: Tags (auch der Lieder, die hineinkommen), dann sortieren, dann die Album-Werte der neuen Lieder */
    private function album(string $hash, TagWriter $writer, Sorter $sorter, FileMover $mover): void
    {
        $incoming = FilePlan::where('target_album_hash', $hash)->pluck('track_id');
        // vor dem Schreiben nochmals die Schreibweise prüfen (als Tag-Änderung – wird gleich mitgeschrieben)
        app(TextCleanup::class)->apply(Track::where('album_hash', $hash)->pluck('id')->merge($incoming)->all());
        $ids = TagWriter::writable()->where(fn ($q) => $q->where('album_hash', $hash)->orWhereIn('id', $incoming))->pluck('id')->all();
        $result = $writer->write($ids);
        $this->tags += $result['written'];
        $this->fail($result['failed']);

        $plan = $sorter->plan($hash);
        if (! $plan['actions']) {
            return;
        }
        // nur verschieben, wenn die Tags vollständig in den Dateien stehen (sonst stünde Altes am neuen Ort)
        $open = TagChange::whereIn('track_id', TagWriter::writable()->where('album_hash', $hash)->where(fn ($q) => $q->whereNull('move_to')->orWhere('move_to', '!=', 'trash'))->select('id'))->exists();
        if ($open) {
            $this->fail([$plan['items'][0]['track']->dir => 'Tags nicht vollständig geschrieben – nicht verschoben']);

            return;
        }
        $moved = $mover->run($plan);
        $this->moved += $moved['moved'];
        $this->fail($moved['failed']);
        if ($moved['new']) {
            $result = $writer->write($moved['new']);
            $this->fail($result['failed']);
        }
    }

    private function fail(array $failed): void
    {
        if ($failed) {
            $this->failed += count($failed);
            Cache::put(self::FAILED, [...Cache::get(self::FAILED, []), ...$failed], now()->addDay());
        }
    }
}
