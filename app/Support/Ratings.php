<?php

namespace App\Support;

/**
 * Bewertungen: 1–5 Sterne (null = keine), wie in 0.3.x. In der Datei: MP3 POPM wie MediaMonkey/Windows (1★=1, 2★=64,
 * 3★=128, 4★=196, 5★=255), FLAC/OGG und M4A RATING 0–100 (20 je Stern; manche Programme schreiben 1–5), WMA
 * WM/SharedUserRating (1, 25, 50, 75, 99). Bewertet wird nur in der Datenbank (wie jede Tag-Änderung).
 */
class Ratings
{
    /** Absender des eigenen POPM-Frames (wie 0.3.x) – wird beim Lesen bevorzugt */
    public const POPM_EMAIL = 'no@email';

    /** Sterne aus den rohen Tags der Datei (null = keine Bewertung) */
    public static function fromRawTags(array $raw): ?int
    {
        $upper = array_change_key_case($raw, CASE_UPPER);
        // MP3: „POPM:<email>“ mit Wert „<email>: <0–255> (gespielt n)“ – eigener Frame zuerst
        $popm = array_filter($upper, fn ($key) => str_starts_with($key, 'POPM'), ARRAY_FILTER_USE_KEY);
        uksort($popm, fn ($a, $b) => ($b === 'POPM:'.strtoupper(self::POPM_EMAIL)) <=> ($a === 'POPM:'.strtoupper(self::POPM_EMAIL)));
        foreach ($popm as $values) {
            foreach ((array) $values as $v) {
                if (preg_match('/:\s*(\d+)\s*(\(|$)/', (string) $v, $m)) {
                    return self::fromPopm((int) $m[1]);
                }
            }
        }
        foreach (['RATING', '----:COM.APPLE.ITUNES:RATING'] as $key) {
            foreach ((array) ($upper[$key] ?? []) as $v) {
                if (is_numeric($v = trim((string) $v))) {
                    return self::clamp((float) $v <= 5 ? (float) $v : (float) $v / 20);
                }
            }
        }
        foreach ((array) ($upper['WM/SHAREDUSERRATING'] ?? []) as $v) {
            if (is_numeric($v = trim((string) $v))) {
                return (int) $v <= 0 ? null : self::clamp(1 + (float) $v / 25);
            }
        }

        return null;
    }

    public static function fromPopm(int $value): ?int
    {
        return match (true) {
            $value <= 0 => null,
            $value < 32 => 1,
            $value < 96 => 2,
            $value < 160 => 3,
            $value < 224 => 4,
            default => 5,
        };
    }

    /** 0 bzw. leer = keine Bewertung, sonst 1–5 */
    public static function clamp(mixed $stars): ?int
    {
        $n = is_numeric($stars) ? (int) round((float) $stars) : 0;

        return $n <= 0 ? null : min($n, 5);
    }
}
