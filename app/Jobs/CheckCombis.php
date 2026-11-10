<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Support\ArtistDirectory;
use App\Support\CombiCheck;
use App\Support\NameConfirmation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Kombi-Prüfung für alle Kombi-Namen (Verzeichnis und noch nicht erfasste aus dem Bestand): Act oder Zusammenarbeit? In kleinen Häppchen,
 * die sich selbst weiterreichen (MusicBrainz erlaubt etwa eine Abfrage pro Sekunde) – Dateiaktionen kommen dazwischen dran.
 */
class CheckCombis implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 5;

    public const STATUS = 'combi-check.status';

    private const LIST = 'combi-check.list';

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('research');
    }

    /** @param  bool  $fresh  auch schon geprüfte Einträge neu prüfen */
    public static function start(bool $fresh = false): bool
    {
        if ((self::status()['running'] ?? false)) {
            return false;
        }
        $check = app(CombiCheck::class);
        $names = array_values(array_filter(self::names(), fn ($n) => $fresh || ! $check->cached($n)));
        if (! $names) {
            return false;
        }
        Cache::put(self::LIST, ['names' => $names, 'fresh' => $fresh], now()->addDay());
        Cache::put(self::STATUS, ['running' => true, 'done' => 0, 'total' => count($names), 'started_at' => now()->toIso8601String(), 'touched_at' => now()->toIso8601String()], now()->addDays(2));
        self::dispatch();

        return true;
    }

    /**
     * Kombi-Namen zum Prüfen: Einträge im Verzeichnis und noch nicht erfasste Namen aus dem Bestand (häufigste zuerst).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $directory = app(ArtistDirectory::class);
        $counts = $directory->namesInCollection();
        arsort($counts);
        $open = array_filter(array_map('strval', array_keys($counts)), fn ($n) => CombiCheck::applies($n) && in_array($directory->check($n)['status'], ['new', 'similar'], true));
        $entries = Artist::orderBy('name')->pluck('name')->filter(fn ($n) => CombiCheck::applies($n))->all();

        return array_values(array_unique([...$entries, ...$open]));
    }

    public static function status(): array
    {
        $s = Cache::get(self::STATUS, []);
        if (($s['running'] ?? false) && isset($s['touched_at']) && now()->diffInMinutes($s['touched_at'], true) > 10) {
            $s['running'] = false; // Worker neu gestartet: Lauf gilt als beendet
        }

        return $s;
    }

    public function handle(CombiCheck $check): void
    {
        $list = Cache::get(self::LIST, ['names' => [], 'fresh' => false]);
        $chunk = array_splice($list['names'], 0, self::CHUNK);
        foreach ($chunk as $name) {
            rescue(fn () => $check->check($name, $list['fresh']), report: false);
            $s = Cache::get(self::STATUS, []);
            Cache::put(self::STATUS, array_merge($s, ['done' => ($s['done'] ?? 0) + 1, 'current' => $name, 'touched_at' => now()->toIso8601String()]), now()->addDays(2));
        }
        Cache::put(self::LIST, $list, now()->addDay());
        if ($list['names']) {
            self::dispatch();
        } else {
            Cache::put(self::STATUS, array_merge(Cache::get(self::STATUS, []), ['running' => false, 'current' => null, 'finished_at' => now()->toIso8601String()]), now()->addDays(2));
            rescue(fn () => app(NameConfirmation::class)->evaluateAll(), report: false);
        }
    }
}
