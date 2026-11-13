<?php

namespace App\Jobs;

use App\Support\Activity;
use App\Support\TextRepairAi;
use App\Support\TextRepairs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sonstiges › Encoding-Fehler: alle Werte mit verlorenen Zeichen, die noch keine Vorschläge haben, bei Claude anfragen –
 * paketweise; „Anhalten“ wirkt nach dem laufenden Wert. Fehlgeschlagene werden in diesem Durchlauf nicht wiederholt.
 */
class AskClaudeTextRepairs implements ShouldQueue
{
    use Queueable;

    public const KEY = 'zeichen';

    private const BATCH = 10;

    private const STOP = 'zeichen:stop';

    public int $timeout = 0;

    /** @param  list<string>  $asked  in diesem Durchlauf schon gefragt (Hash des Werts) */
    public function __construct(public array $asked = []) {}

    public static function start(): bool
    {
        if (Activity::running(self::KEY) || app(TextRepairs::class)->unasked()->isEmpty()) {
            return false;
        }
        Cache::forget(self::STOP);
        Activity::start(self::KEY, 'Encoding-Fehler: Claude fragen', app(TextRepairs::class)->unasked()->count(), 'startet …');
        self::dispatch();

        return true;
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    public function handle(TextRepairs $repairs, TextRepairAi $ai): void
    {
        $state = Activity::current(self::KEY);
        $done = $state['running'] ? $state['done'] : 0;
        $pending = $repairs->unasked()->reject(fn ($p) => in_array($p['hash'], $this->asked, true))->values();
        $total = $done + $pending->count();
        $failed = 0;
        foreach ($pending->take(self::BATCH) as $p) {
            if (Cache::get(self::STOP)) {
                break;
            }
            Activity::progress(self::KEY, $done, $total, null, $p['value']);
            try {
                $ai->suggest($p['value'], $p['fields'], $repairs->context($p['value']));
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
            $this->asked[] = $p['hash'];
            $done++;
        }
        Activity::progress(self::KEY, $done, $total);
        $left = $repairs->unasked()->reject(fn ($p) => in_array($p['hash'], $this->asked, true))->isNotEmpty();
        if (Cache::pull(self::STOP)) {
            Activity::finish(self::KEY, 'Claude-Anfragen angehalten – '.$done.' von '.$total.' Werten');
        } elseif ($left) {
            self::dispatch($this->asked);
        } else {
            Activity::finish(self::KEY, 'Claude hat Vorschläge für '.$done.' '.($done === 1 ? 'Wert' : 'Werte').' gemacht'.($failed ? ' ('.$failed.' fehlgeschlagen)' : ''));
        }
    }
}
