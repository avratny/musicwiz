<?php

namespace App\Jobs;

use App\Support\Activity;
use App\Support\Lookup\NameCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Kombis bei den Diensten prüfen (Interpreten › Kombis): den ganzen Namen und jeden Teil – paketweise auf der Warteschlange
 * des Abgleichs, damit die Ratenbegrenzung der Dienste gemeinsam gilt. „Anhalten“ wirkt nach dem laufenden Namen.
 */
class CheckComboNames implements ShouldQueue
{
    use Queueable;

    public const KEY = 'combos';

    private const BATCH = 20;

    private const STOP = 'combos:stop';

    public int $timeout = 0;

    /** @param  list<string>  $asked  in diesem Durchlauf schon gefragt (bei Fehlern nicht noch einmal) */
    public function __construct(public array $asked = []) {}

    public static function start(): bool
    {
        if (Activity::running(self::KEY)) {
            return false;
        }
        if (! NameCheck::pending()) {
            NameCheck::prune();   // nichts zu fragen – aber nicht mehr Gebrauchtes trotzdem aufräumen

            return false;
        }
        Cache::forget(self::STOP);
        Activity::start(self::KEY, 'Kombis bei den Diensten prüfen', count(NameCheck::pending()), 'startet …');
        self::dispatch()->onQueue('lookup');

        return true;
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    public function handle(NameCheck $check): void
    {
        $state = Activity::current(self::KEY);
        $done = $state['running'] ? $state['done'] : 0;
        $pending = NameCheck::pending($this->asked);
        $total = $done + count($pending);
        foreach (array_slice($pending, 0, self::BATCH) as $item) {
            if (Cache::get(self::STOP)) {
                break;
            }
            Activity::progress(self::KEY, $done, $total, null, $item['type'] === NameCheck::MBID ? 'Mitglied '.$item['subject'] : $item['subject']);
            try {
                $check->check($item['type'], $item['subject']);
            } catch (Throwable $e) {
                report($e);
            }
            $this->asked[] = NameCheck::key($item['type'], $item['subject']);
            $done++;
        }
        Activity::progress(self::KEY, $done, $total);
        if (Cache::pull(self::STOP)) {
            Activity::finish(self::KEY, 'Prüfung angehalten – '.$done.' von '.$total.' Namen');
        } elseif (NameCheck::pending($this->asked)) {
            self::dispatch($this->asked)->onQueue('lookup');   // nächstes Paket (u. a. die Mitglieder gerade erkannter Gruppen)
        } else {
            NameCheck::prune();
            Activity::finish(self::KEY, 'Kombis geprüft – '.$done.' Namen');
        }
    }

    public function failed(?Throwable $e): void
    {
        Activity::finish(self::KEY, 'Prüfung abgebrochen – '.mb_substr((string) $e?->getMessage(), 0, 150));
    }
}
