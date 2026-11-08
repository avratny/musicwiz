<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Akustischer Fingerabdruck (Chromaprint über ffmpeg): erkennt, ob zwei Dateien dasselbe Lied enthalten –
 * unabhängig von Format, Bitrate und Tags. Wird nur gebraucht, bevor eine Fassung aussortiert wird.
 */
class Fingerprint
{
    /** Ab diesem Anteil übereinstimmender Bits gilt es als dasselbe Lied (verschiedene Lieder liegen um 0,5–0,6). */
    public const SAME = 0.8;

    /** nur der Anfang zählt – reicht zum Erkennen und spart Zeit */
    private const SECONDS = 120;

    /** größte Verschiebung beim Vergleich (ein Wert ≈ 0,124 s), z. B. für etwas längeres Intro bzw. Stille am Anfang */
    private const MAX_OFFSET = 80;

    /** @return list<int>|null null, wenn nicht berechenbar (z. B. defekte Datei) */
    public function of(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (! $stat) {
            return null;
        }

        return Cache::remember('fingerprint:'.md5($path.'|'.$stat['mtime'].'|'.$stat['size']), now()->addDays(30), function () use ($path) {
            $result = Process::timeout(120)->run(['ffmpeg', '-loglevel', 'error', '-i', $path, '-t', (string) self::SECONDS, '-f', 'chromaprint', '-fp_format', 'raw', '-']);
            $raw = $result->output();
            if (! $result->successful() || strlen($raw) < 4 * 16) {
                return null; // zu kurz oder nicht lesbar – kein Abdruck (wird nicht gecacht, siehe unten)
            }

            return array_values(unpack('V*', substr($raw, 0, intdiv(strlen($raw), 4) * 4)));
        }) ?: null;
    }

    /** Abdruck im AcoustID-Format (komprimiert, Base64 – wie fpcalc); null, wenn nicht berechenbar. */
    public function acoustid(string $path): ?string
    {
        $result = Process::timeout(120)->run(['ffmpeg', '-loglevel', 'error', '-i', $path, '-t', (string) self::SECONDS, '-f', 'chromaprint', '-fp_format', 'base64', '-']);
        $fp = trim($result->output());

        return $result->successful() && $fp !== '' ? $fp : null;
    }

    /** Ähnlichkeit 0–1 (Anteil gleicher Bits bei bester Verschiebung); null, wenn ein Abdruck fehlt. */
    public function similarity(string $a, string $b): ?float
    {
        $fa = $this->of($a);
        $fb = $this->of($b);

        return $fa && $fb ? self::compare($fa, $fb) : null;
    }

    /** @param  list<int>  $a  @param  list<int>  $b */
    public static function compare(array $a, array $b): float
    {
        static $bits = null;
        $bits ??= array_map(fn ($i) => substr_count(decbin($i), '1'), range(0, 255));
        $na = count($a);
        $nb = count($b);
        // mindestens die Hälfte des kürzeren Abdrucks muss sich überlappen
        $minOverlap = max(1, intdiv(min($na, $nb), 2));
        $best = 0.0;
        for ($offset = -self::MAX_OFFSET; $offset <= self::MAX_OFFSET; $offset++) {
            $from = max(0, -$offset);
            $to = min($na, $nb - $offset);
            if ($to - $from < $minOverlap) {
                continue;
            }
            $diff = 0;
            for ($i = $from; $i < $to; $i++) {
                $x = $a[$i] ^ $b[$i + $offset];
                $diff += $bits[$x & 255] + $bits[($x >> 8) & 255] + $bits[($x >> 16) & 255] + $bits[($x >> 24) & 255];
            }
            $best = max($best, 1 - $diff / (32 * ($to - $from)));
        }

        return $best;
    }
}
