<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Support\Activity;
use App\Support\ClearArtists;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Interpreten › Eindeutig / Wahrscheinlich / Schreibweise › Übernehmen: im Hintergrund (ggf. umbenennen,) bestätigen,
 * IDs und Genres übernehmen, Bilder laden – auf der Warteschlange des Abgleichs (Bilder-Tempo gemeinsam). Jeder wird
 * vorher erneut geprüft und nur übernommen, wenn er noch in derselben Liste steht. „Anhalten“ nach dem laufenden.
 */
class ConfirmClearArtists implements ShouldQueue
{
    use Queueable;

    public const KEY = 'eindeutig';

    private const STOP = 'eindeutig:stop';

    public int $timeout = 0;

    /** @param  list<int>  $ids */
    public function __construct(public array $ids, public string $level = 'eindeutig') {}

    public static function start(array $ids, string $level = 'eindeutig'): bool
    {
        if (Activity::running(self::KEY) || ! $ids) {
            return false;
        }
        Cache::forget(self::STOP);
        $labels = ['eindeutig' => 'Eindeutige Interpreten übernehmen', 'wahrscheinlich' => 'Wahrscheinliche Interpreten übernehmen', 'schreibweise' => 'Schreibweisen übernehmen'];
        Activity::start(self::KEY, $labels[$level] ?? $labels['eindeutig'], count($ids), 'startet …');
        self::dispatch(array_values(array_map('intval', $ids)), $level)->onQueue('lookup');

        return true;
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    public function handle(ClearArtists $clear): void
    {
        $done = $taken = 0;
        foreach (Artist::whereIn('id', $this->ids)->orderBy('name')->get() as $artist) {
            if (Cache::get(self::STOP)) {
                break;
            }
            Activity::progress(self::KEY, $done, count($this->ids), null, $artist->name);
            try {
                $taken += $clear->confirm($artist, $this->level) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
            $done++;
        }
        $stopped = Cache::pull(self::STOP);
        ClearArtists::forget();   // Umbenennungen und Bestätigungen ändern auch andere Listen (Ähnlich, Kombis)
        Activity::finish(self::KEY, ($stopped ? 'Angehalten – ' : '').$taken.' '.($taken === 1 ? 'Interpret' : 'Interpreten').' übernommen'
            .($done > $taken ? ' ('.($done - $taken).' inzwischen nicht mehr in der Liste)' : ''));
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish(self::KEY, 'Übernehmen abgebrochen – '.mb_substr((string) $e?->getMessage(), 0, 150));
    }
}
