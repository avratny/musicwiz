<?php

namespace App\Support;

use App\Models\IntegrityResult;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Audiodaten prüfen: jede Datei einmal komplett dekodieren (ffmpeg). Findet beschädigte Stellen (CRC-Fehler, abgeschnittene
 * Frames), die beim normalen Einlesen nicht auffallen. Inkrementell: geprüft wird nur, was neu ist oder sich geändert hat.
 */
class Integrity
{
    public const STATUS_KEY = 'integrity.status';

    public function __construct(private Settings $settings) {}

    /** @return string|null Fehlermeldung oder null (in Ordnung) */
    public function check(string $path): ?string
    {
        $result = Process::timeout(900)->run(['ffmpeg', '-hide_banner', '-nostats', '-v', 'error', '-i', $path, '-vn', '-f', 'null', '-']);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $result->errorOutput()))));
        if ($result->successful() && ! $lines) {
            return null;
        }

        return implode("\n", array_slice($lines, 0, 3)) ?: 'ffmpeg-Fehler '.$result->exitCode();
    }

    /** Liste der noch zu prüfenden Dateien (zwischen den Häppchen) */
    private const TODO_KEY = 'integrity.todo';

    /** höchstens so lange je Häppchen, dann kommen andere Jobs dran */
    private const CHUNK_SECONDS = 20;

    /** Alle Lib-Dateien prüfen, die noch kein gültiges Ergebnis haben (am Stück – für Befehle und Tests). */
    public function run(): array
    {
        $first = true;
        while (! $this->runChunk($first)) {
            $first = false;
        }
        $status = $this->status();

        return ['checked' => (int) ($status['done'] ?? 0), 'errors' => (int) ($status['errors'] ?? 0)];
    }

    /**
     * Ein Häppchen prüfen (höchstens CHUNK_SECONDS). Beim ersten Häppchen wird ermittelt, was zu prüfen ist.
     *
     * @return bool fertig?
     */
    public function runChunk(bool $start): bool
    {
        if ($start) {
            $known = IntegrityResult::get(['path', 'mtime', 'size'])->keyBy('path');
            $todo = [];
            // mit der Datei vergleichen, nicht mit dem Index (der kann bis zum nächsten Scan veraltet sein)
            foreach (Track::where('location', 'library')->orderBy('path')->select(['path'])->cursor() as $t) {
                $r = $known[$t->path] ?? null;
                $stat = $r ? @stat($t->path) : null;
                if (! $r || ($stat && ($r->mtime !== $stat['mtime'] || $r->size !== $stat['size']))) {
                    $todo[] = $t->path;
                }
            }
            Cache::put(self::TODO_KEY, $todo, now()->addDays(2));
            $this->status(['running' => true, 'done' => 0, 'total' => count($todo), 'errors' => 0, 'error' => null, 'started_at' => now()->toIso8601String(), 'finished_at' => null]);
        }
        $todo = (array) Cache::get(self::TODO_KEY, []);
        $status = $this->status();
        $done = (int) ($status['done'] ?? 0);
        $errors = (int) ($status['errors'] ?? 0);
        $throttle = max(0, (int) $this->settings->get('scan_throttle_ms')) * 1000;
        $until = microtime(true) + self::CHUNK_SECONDS;
        while ($todo && microtime(true) < $until) {
            $path = array_shift($todo);
            clearstatcache(true, $path);
            $stat = @stat($path);
            if ($stat) { // sonst inzwischen verschoben/gelöscht – der nächste Scan bereinigt den Index
                $error = $this->check($path);
                // während der Prüfung verschoben (Dateiaktionen laufen im eigenen Worker gleichzeitig): kein Fehler der Datei
                if ($error !== null && ! is_file($path)) {
                    $done++;

                    continue;
                }
                $errors += $error ? 1 : 0;
                IntegrityResult::updateOrCreate(['path' => $path], ['mtime' => $stat['mtime'], 'size' => $stat['size'], 'ok' => $error === null, 'error' => $error]);
            }
            $done++;
            if ($throttle) {
                usleep($throttle);
            }
        }
        Cache::put(self::TODO_KEY, $todo, now()->addDays(2));
        $this->status(['done' => $done, 'errors' => $errors, 'current' => isset($path) ? basename(dirname($path)) : null]);
        if ($todo) {
            return false;
        }
        // Ergebnisse zu Dateien, die es nicht mehr gibt, aufräumen
        IntegrityResult::whereNotIn('path', Track::where('location', 'library')->select('path'))->delete();
        Cache::forget(self::TODO_KEY);
        $this->status(['running' => false, 'done' => $done, 'errors' => $errors, 'finished_at' => now()->toIso8601String()]);

        return true;
    }

    public function status(?array $update = null): array
    {
        $status = Cache::get(self::STATUS_KEY, []);
        if ($update !== null) {
            $status = array_merge($status, $update);
            Cache::forever(self::STATUS_KEY, $status);
        }

        return $status;
    }
}
