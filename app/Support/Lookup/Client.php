<?php

namespace App\Support\Lookup;

use App\Support\Activity;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * HTTP-Abfragen an die Dienste mit festem Tempo je Dienst (gemeinsam für alle Prozesse, über den Cache) und Sperren:
 * 429/503 → so lange warten, wie der Dienst sagt; längere Sperren werden gemerkt, dann wird der Dienst nicht gefragt.
 * Keine Antwort (Zeitüberschreitung) → Fehler für diese Abfrage; zweimal hintereinander → Dienst kurz aussetzen.
 * Antworten (erfolgreich oder „gibt es nicht“) werden gespeichert und bis zur Frist für einen neuen Abgleich
 * wiederverwendet – egal, wer fragt (Abgleich, Namensprüfung …). Nur fresh() fragt trotzdem neu („Neu abgleichen“).
 * Ein Client je Anfrage bzw. Auftrag (scoped) – alle Dienste teilen ihn, also auch den Zustand von fresh().
 */
class Client
{
    public const USER_AGENT = 'MusicWiz/0.4 ( https://github.com/avratny/musicwiz )';

    /** so viele Zeitüberschreitungen hintereinander, dann den Dienst für TIMEOUT_PAUSE Sekunden aussetzen */
    private const TIMEOUTS_BEFORE_PAUSE = 2;

    private const TIMEOUT_PAUSE = 120;

    private bool $fresh = false;

    /** Innerhalb von $fn nicht aus dem Zwischenspeicher lesen (die Antworten werden trotzdem gespeichert) */
    public function fresh(callable $fn): mixed
    {
        $before = $this->fresh;
        $this->fresh = true;
        try {
            return $fn();
        } finally {
            $this->fresh = $before;
        }
    }

    /** @throws Throttled */
    public function get(string $service, string $url, array $query = [], array $headers = [], ?string $token = null): Response
    {
        ksort($query);
        $key = sha1($service.' '.$url.'?'.http_build_query($query));
        if (! $this->fresh && ($hit = DB::table('service_cache')->where('key', $key)
            ->where('fetched_at', '>', now()->subDays((int) config('musicwiz.lookup_refresh_days')))->first())) {
            return new Response(new Psr7Response((int) $hit->status, ['Content-Type' => 'application/json'], $hit->body));
        }
        $response = $this->request($service, $url, $query, $headers, $token);
        if ($response->successful() || $response->status() === 404) {
            DB::table('service_cache')->updateOrInsert(['key' => $key], ['service' => $service, 'url' => $url.'?'.http_build_query($query),
                'status' => $response->status(), 'body' => $response->body(), 'fetched_at' => now()]);
        }

        return $response;
    }

    /** Abgelaufene Antworten löschen (werden ohnehin nicht mehr gelesen) – nach dem Einlesen und nach dem Abgleich */
    public static function prune(): int
    {
        return DB::table('service_cache')->where('fetched_at', '<=', now()->subDays((int) config('musicwiz.lookup_refresh_days')))->delete();
    }

    private function request(string $service, string $url, array $query, array $headers, ?string $token): Response
    {
        for ($attempt = 0; ; $attempt++) {
            if ($until = self::blockedUntil($service)) {
                throw new Throttled($service, $until);
            }
            $this->pace($service);
            $request = Http::connectTimeout(5)->timeout(20)->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'] + $headers);
            try {
                $response = ($token ? $request->withToken($token) : $request)->get($url, $query);
            } catch (ConnectionException $e) {
                // keine Antwort: einmal kann eine einzelne Abfrage hängen – zweimal hintereinander, dann den Dienst kurz
                // aussetzen, statt bei jedem Interpreten 20 s zu warten (das Ausgesetzte wird nachgeholt)
                if (Cache::increment('lookup:timeouts:'.$service) >= self::TIMEOUTS_BEFORE_PAUSE) {
                    Cache::put('lookup:blocked:'.$service, time() + self::TIMEOUT_PAUSE, self::TIMEOUT_PAUSE);
                    Cache::forget('lookup:timeouts:'.$service);
                    Log::info('Dienst antwortet nicht – ausgesetzt', ['service' => $service, 'seconds' => self::TIMEOUT_PAUSE]);
                }
                throw new RuntimeException($service.' antwortet nicht (Zeitüberschreitung)', 0, $e);
            }
            Cache::forget('lookup:timeouts:'.$service);
            if (! in_array($response->status(), [429, 503], true)) {
                return $response;
            }
            $after = max(1, (int) ($response->header('Retry-After') ?: (2 ** min($attempt, 6))));
            // sichtbar machen: im Protokoll und als Hinweis beim laufenden Abgleich
            Log::info('Dienst bremst', ['service' => $service, 'status' => $response->status(), 'retry_after' => $after, 'attempt' => $attempt + 1]);
            if (Activity::running('lookup')) {
                Activity::progress('lookup', Activity::current('lookup')['done'], null, null, $service.' bremst – warte '.$after.' s');
            }
            if ($attempt < 3 && $after <= 30) {
                sleep($after);

                continue;
            }
            Cache::put('lookup:blocked:'.$service, time() + $after, $after);
        }
    }

    /** Sperre eines Dienstes (Zeitpunkt) oder null */
    public static function blockedUntil(string $service): ?int
    {
        $until = (int) Cache::get('lookup:blocked:'.$service, 0);

        return $until > time() ? $until : null;
    }

    /** Abstand zwischen zwei Abfragen desselben Dienstes einhalten */
    public function pace(string $service): void
    {
        $perMinute = max(1, (int) config('musicwiz.lookup_rates.'.$service, 60));
        $gap = 60 / $perMinute;
        Cache::lock('lookup:pace:'.$service, 15)->block(30, function () use ($service, $gap) {
            $wait = $gap - (microtime(true) - (float) Cache::get('lookup:last:'.$service, 0));
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            Cache::put('lookup:last:'.$service, microtime(true), 3600);
        });
    }
}
