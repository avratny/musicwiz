<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * Lautheit für ReplayGain 2.0 (EBU R128 über ffmpeg, Bezug −18 LUFS). Jede Datei wird einmal komplett dekodiert;
 * die Album-Lautheit wird aus den Titeln nach Länge gewichtet zusammengesetzt (kein zweiter Durchlauf).
 */
class Loudness
{
    public const REFERENCE = -18.0;

    /** @return array{lufs: float, peak: float}|null peak linear (1.0 = 0 dBFS); null, wenn nicht messbar */
    public function measure(string $path): ?array
    {
        $result = Process::timeout(900)->run(['ffmpeg', '-hide_banner', '-nostats', '-i', $path, '-vn', '-af', 'ebur128=peak=true', '-f', 'null', '-']);

        return $result->successful() ? self::parse($result->errorOutput()) : null;
    }

    /** Zusammenfassung von ffmpeg ebur128 auswerten („I: -9.3 LUFS“, „Peak: 0.5 dBFS“). */
    public static function parse(string $log): ?array
    {
        $at = strrpos($log, 'Summary:');
        $summary = $at === false ? $log : substr($log, $at);
        if (! preg_match('/I:\s+(-?[\d.]+|-inf)\s+LUFS/', $summary, $i) || $i[1] === '-inf' || (float) $i[1] <= -70) {
            return null; // Stille bzw. nicht messbar
        }
        $peakDb = preg_match('/Peak:\s+(-?[\d.]+|-inf)\s+dBFS/', $summary, $p) && $p[1] !== '-inf' ? (float) $p[1] : -120.0;

        return ['lufs' => (float) $i[1], 'peak' => 10 ** ($peakDb / 20)];
    }

    /**
     * Tags für alle Titel eines Albums.
     *
     * @param  array<string, array{lufs: float, peak: float, duration: float}>  $measured  Pfad => Messung
     * @return array<string, array<string, string>> Pfad => rg_*-Tags
     */
    public static function tags(array $measured): array
    {
        $weight = array_sum(array_map(fn ($m) => max(1.0, $m['duration']), $measured));
        $energy = array_sum(array_map(fn ($m) => max(1.0, $m['duration']) * 10 ** ($m['lufs'] / 10), $measured));
        $albumLufs = 10 * log10($energy / $weight);
        $albumPeak = max(array_column($measured, 'peak'));
        $out = [];
        foreach ($measured as $path => $m) {
            $out[$path] = [
                'rg_track_gain' => self::gain($m['lufs']),
                'rg_track_peak' => sprintf('%.6f', $m['peak']),
                'rg_album_gain' => self::gain($albumLufs),
                'rg_album_peak' => sprintf('%.6f', $albumPeak),
            ];
        }

        return $out;
    }

    private static function gain(float $lufs): string
    {
        return sprintf('%.2f dB', self::REFERENCE - $lufs);
    }
}
