<?php

namespace App\Support;

use App\Models\Track;
use getID3;

/**
 * Vergleicht zwei Fassungen desselben Liedes (z. B. bei belegtem Ziel):
 * intakt vor defekt, verlustfrei vor verlustbehaftet, bei FLAC Bittiefe/Abtastrate, bei MP3 & Co. die Bitrate.
 * Weichen die Längen deutlich ab, wird nicht entschieden ("unklar") – dann bleiben beide.
 */
class Quality
{
    private const LOSSLESS = ['flac', 'wav', 'aiff', 'alac'];

    /** Längenunterschied (s), ab dem es vermutlich eine andere Fassung oder ein abgeschnittener Rip ist. */
    public const LENGTH_TOLERANCE = 3.0;

    /** @return array{broken:bool, lossless:bool, bitrate:int, bits:int, rate:int, duration:float, label:string} */
    public static function of(string $path): array
    {
        $t = Track::where('path', $path)->first();
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $lossless = in_array($ext, self::LOSSLESS, true);
        $duration = $t ? (float) $t->duration : null;
        $bitrate = $t ? (int) $t->bitrate : null;
        $bits = 0;
        $rate = 0;
        // Bittiefe/Abtastrate stehen nicht im Index – nur für den Vergleich direkt aus der Datei lesen
        $info = @(new getID3)->analyze($path);
        $bits = (int) ($info['audio']['bits_per_sample'] ?? 0);
        $rate = (int) ($info['audio']['sample_rate'] ?? 0);
        $duration ??= (float) ($info['playtime_seconds'] ?? 0);
        $bitrate ??= (int) ($info['bitrate'] ?? 0);
        $broken = $duration < 0.1;
        $kbit = (int) round($bitrate / 1000);
        $label = $broken ? 'defekt' : strtoupper($ext).($lossless
            ? ($bits ? ' '.$bits.' Bit' : '').($rate ? '/'.rtrim(rtrim(number_format($rate / 1000, 1, ',', ''), '0'), ',').' kHz' : '')
            : ($kbit ? ' '.$kbit.' kbit/s' : '')).($broken ? '' : ', '.self::time($duration));

        return compact('broken', 'lossless', 'bitrate', 'bits', 'rate', 'duration', 'label');
    }

    private static function time(float $s): string
    {
        $s = (int) round($s);

        return intdiv($s, 60).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{winner:string, reason:string, identical:bool} winner: source | target | both
     */
    public static function compare(string $source, string $target): array
    {
        if (FileOps::sameFile($source, $target)) {
            return ['winner' => 'source', 'reason' => 'dieselbe Datei – wird nur umbenannt', 'identical' => true];
        }
        if (@filesize($source) === @filesize($target) && md5_file($source) === md5_file($target)) {
            return ['winner' => 'target', 'reason' => 'identische Kopie – wird aussortiert', 'identical' => true];
        }
        $s = self::of($source);
        $t = self::of($target);
        $vs = ' ('.$s['label'].' vs. '.$t['label'].')';
        $result = fn (string $winner, string $reason) => ['winner' => $winner, 'reason' => $reason.$vs, 'identical' => false];

        if ($t['broken'] && ! $s['broken']) {
            return $result('source', 'vorhandene Datei ist defekt – wird ersetzt');
        }
        if ($s['broken']) {
            return $result('target', 'diese Datei ist defekt – wird aussortiert');
        }
        if (abs($s['duration'] - $t['duration']) > self::LENGTH_TOLERANCE) {
            return $result('both', 'Längen weichen ab (andere Fassung oder abgeschnitten?) – beide bleiben, diese mit „(2)“');
        }
        if ($s['lossless'] !== $t['lossless']) {
            return $s['lossless']
                ? $result('source', 'verlustfrei – ersetzt die vorhandene')
                : $result('target', 'vorhandene ist verlustfrei – diese wird aussortiert');
        }
        if ($s['lossless']) {
            // FLAC: Bitrate sagt nur etwas über die Kompression – entscheidend sind Bittiefe und Abtastrate
            $sq = [$s['bits'], $s['rate']];
            $tq = [$t['bits'], $t['rate']];
            if ($sq > $tq) {
                return $result('source', 'höhere Auflösung – ersetzt die vorhandene');
            }

            return $result('target', ($sq < $tq ? 'vorhandene hat höhere Auflösung' : 'gleichwertig').' – vorhandene bleibt, diese wird aussortiert');
        }
        if ($s['bitrate'] > $t['bitrate'] * 1.1) {
            return $result('source', 'höhere Bitrate – ersetzt die vorhandene');
        }

        return $result('target', 'gleichwertig – vorhandene bleibt, diese wird aussortiert');
    }
}
