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

    /** Alle Lib-Dateien prüfen, die noch kein gültiges Ergebnis haben. */
    public function run(): array
    {
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
        $this->status(['running' => true, 'done' => 0, 'total' => count($todo), 'errors' => 0, 'started_at' => now()->toIso8601String(), 'finished_at' => null]);
        $throttle = max(0, (int) $this->settings->get('scan_throttle_ms')) * 1000;
        $errors = 0;
        foreach ($todo as $i => $path) {
            clearstatcache(true, $path);
            $stat = @stat($path);
            if (! $stat) {
                continue; // inzwischen verschoben/gelöscht – der nächste Scan bereinigt den Index
            }
            $error = $this->check($path);
            $errors += $error ? 1 : 0;
            IntegrityResult::updateOrCreate(['path' => $path], ['mtime' => $stat['mtime'], 'size' => $stat['size'], 'ok' => $error === null, 'error' => $error]);
            if ($i % 10 === 0) {
                $this->status(['done' => $i + 1, 'errors' => $errors, 'current' => basename(dirname($path))]);
            }
            if ($throttle) {
                usleep($throttle);
            }
        }
        // Ergebnisse zu Dateien, die es nicht mehr gibt, aufräumen
        IntegrityResult::whereNotIn('path', Track::where('location', 'library')->select('path'))->delete();
        $this->status(['running' => false, 'done' => count($todo), 'errors' => $errors, 'finished_at' => now()->toIso8601String()]);

        return ['checked' => count($todo), 'errors' => $errors];
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
