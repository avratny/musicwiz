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

    /** @return array{broken:bool, uncertain:bool, lossless:bool, bitrate:int, bits:int, rate:int, duration:float, label:string} */
    public static function of(string $path): array
    {
        $t = Track::where('path', $path)->first();
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $lossless = in_array($ext, self::LOSSLESS, true);
        // fehlende Werte im Index sind "unbekannt", nicht 0 – sonst gälte die Datei als defekt und verlöre immer
        $indexed = $t?->duration !== null ? (float) $t->duration : null;
        $bitrate = $t?->bitrate !== null ? (int) $t->bitrate : null;
        // Bittiefe/Abtastrate stehen nicht im Index – nur für den Vergleich direkt aus der Datei lesen
        $info = @(new getID3)->analyze($path);
        $bits = (int) ($info['audio']['bits_per_sample'] ?? 0);
        $rate = (int) ($info['audio']['sample_rate'] ?? 0);
        $fresh = (float) ($info['playtime_seconds'] ?? 0);
        $duration = $indexed !== null && $indexed >= 0.1 ? $indexed : $fresh;
        $bitrate ??= (int) ($info['bitrate'] ?? 0);
        $broken = $duration < 0.1;
        // laut Index intakt, jetzt aber nicht lesbar: eher ein Aussetzer des Shares als eine kaputte Datei
        $uncertain = $indexed !== null && $indexed >= 0.1 && $fresh < 0.1;
        $kbit = (int) round($bitrate / 1000);
        $label = $broken ? 'defekt' : strtoupper($ext).($lossless
            ? ($bits ? ' '.$bits.' Bit' : '').($rate ? '/'.rtrim(rtrim(number_format($rate / 1000, 1, ',', ''), '0'), ',').' kHz' : '')
            : ($kbit ? ' '.$kbit.' kbit/s' : '')).($broken ? '' : ', '.self::time($duration));

        return compact('broken', 'uncertain', 'lossless', 'bitrate', 'bits', 'rate', 'duration', 'label');
    }

    /**
     * Enthalten beide Dateien dasselbe Lied? Interpret + Titel aus den Dateien (falls bei beiden vorhanden) und der
     * akustische Fingerabdruck. Im Zweifel: nein.
     *
     * @return array{0: bool, 1: string} [dasselbe Lied?, Grund falls nicht]
     */
    public static function sameSong(string $a, string $b): array
    {
        // frisch aus den Dateien: die Quelle hat ihre neuen Tags evtl. gerade erst in derselben Aktion bekommen
        $readable = array_values(array_filter([$a, $b], fn ($p) => TagTool::canWrite($p)));
        $tags = rescue(fn () => app(TagTool::class)->read($readable), [], false);
        $key = function (string $path) use ($tags): ?string {
            $t = ($tags[$path]['ok'] ?? false) ? $tags[$path]['tags'] : null;
            if (! $t || trim((string) ($t['artist'] ?? '')) === '' || trim((string) ($t['title'] ?? '')) === '') {
                return null;
            }

            return CleanupChecks::songKey(new Track(['artist' => $t['artist'], 'title' => $t['title']]));
        };
        [$ka, $kb] = [$key($a), $key($b)];
        if ($ka !== null && $kb !== null && $ka !== $kb) {
            return [false, 'laut Tags ein anderes Lied'];
        }
        $similarity = app(Fingerprint::class)->similarity($a, $b);
        if ($similarity === null) {
            return [false, 'Klang nicht vergleichbar'];
        }
        if ($similarity < Fingerprint::SAME) {
            return [false, 'klingt anders ('.round($similarity * 100).' % Übereinstimmung)'];
        }

        return [true, ''];
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

        if ($s['uncertain'] || $t['uncertain']) {
            return $result('both', 'Datei gerade nicht lesbar – nichts wird aussortiert, beide bleiben, diese mit „(2)“');
        }
        if ($t['broken'] && ! $s['broken']) {
            return $result('source', 'vorhandene Datei ist defekt – wird ersetzt');
        }
        if ($s['broken']) {
            return $result('target', 'diese Datei ist defekt – wird aussortiert');
        }
        if (abs($s['duration'] - $t['duration']) > self::LENGTH_TOLERANCE) {
            return $result('both', 'Längen weichen ab (andere Fassung oder abgeschnitten?) – beide bleiben, diese mit „(2)“');
        }
        // nur aussortieren, wenn es nachweislich dasselbe Lied ist – gleiche Länge allein reicht nicht
        [$same, $why] = self::sameSong($source, $target);
        if (! $same) {
            return $result('both', $why.' – beide bleiben, diese mit „(2)“');
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
