<?php

namespace App\Support;

/**
 * Zwei Texte vergleichen (Schreiben: Dateipfade, Tags): gleicher Anfang und gleiches Ende, dazwischen das Geänderte –
 * als HTML mit Markierung (rot = fällt weg, grün = kommt dazu).
 */
class TextDiff
{
    public const REMOVED = 'bg-red-500/25 text-red-200 line-through decoration-red-300/70';

    public const ADDED = 'bg-emerald-500/25 text-emerald-200';

    /** @return array{0: int, 1: int} Länge des gleichen Anfangs und des gleichen Endes (Zeichen, überlappen nicht) */
    public static function bounds(string $a, string $b): array
    {
        [$a, $b] = [mb_str_split($a), mb_str_split($b)];
        $max = min(count($a), count($b));
        for ($pre = 0; $pre < $max && $a[$pre] === $b[$pre]; $pre++);
        for ($suf = 0; $suf < $max - $pre && $a[count($a) - 1 - $suf] === $b[count($b) - 1 - $suf]; $suf++);

        return [$pre, $suf];
    }

    /**
     * Text mit markiertem Teil (ohne $bounds: alles hell). Leerzeichen im markierten Teil als „␣“, ein leerer markierter
     * Teil als „‸“ (hier fehlt etwas bzw. kam etwas weg).
     */
    public static function html(string $text, ?array $bounds, string $mark): string
    {
        if (! $bounds) {
            return '<span class="text-zinc-200">'.e($text).'</span>';
        }
        $chars = mb_str_split($text);
        [$pre, $suf] = $bounds;
        $middle = implode('', array_slice($chars, $pre, count($chars) - $pre - $suf));

        return '<span class="text-zinc-400">'.e(implode('', array_slice($chars, 0, $pre))).'</span>'
            .($middle !== '' ? '<span class="rounded-sm px-px '.$mark.'">'.e(str_replace(' ', '␣', $middle)).'</span>' : '<span class="text-zinc-500">‸</span>')
            .'<span class="text-zinc-400">'.e(implode('', array_slice($chars, count($chars) - $suf))).'</span>';
    }
}
