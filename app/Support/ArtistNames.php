<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use Illuminate\Support\Facades\Schema;

/**
 * Regeln für Interpreten: automatisch getrennt wird bei ";", feat./ft./featuring und vs./versus,
 * nie bei "&", ",", "und", "and". Namen aus dem Künstlerverzeichnis werden nie zerlegt (z. B. eine Band mit "vs" im Namen).
 */
class ArtistNames
{
    /** Trenner: feat./ft./featuring (auch in Klammern) und vs./vs/versus */
    private const SEPARATOR = '/\s*[\(\[]?\s*\b(?:feat\.?|ft\.|ft\b|featuring|vs\.?|versus)\s+/iu';

    /** Geschützte Namen (aus dem Künstlerverzeichnis), die selbst einen Trenner enthalten */
    private static ?array $protected = null;

    /** @return list<string> */
    public static function split(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }
        // geschützte Namen vor dem Trennen durch Platzhalter ersetzen
        $keep = [];
        foreach (self::protectedNames() as $i => $name) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu';
            $text = preg_replace_callback($pattern, function ($m) use (&$keep, $i) {
                $keep["\x01{$i}\x01"] = $m[0];

                return "\x01{$i}\x01";
            }, $text);
        }
        $out = [];
        foreach (preg_split('/\s*;\s*/u', $text) as $part) {
            foreach (preg_split(self::SEPARATOR, $part) as $name) {
                $name = trim(strtr($name, $keep), " \t)]");
                if ($name !== '' && ! in_array($name, $out, true)) {
                    $out[] = $name;
                }
            }
        }

        return $out;
    }

    /** Namen aus dem Künstlerverzeichnis, die einen Trenner enthalten – längste zuerst. */
    private static function protectedNames(): array
    {
        if (self::$protected === null) {
            self::$protected = [];
            try {
                if (Schema::hasTable('artists')) {
                    $names = array_merge(Artist::pluck('name')->all(), ArtistAlias::pluck('alias')->all());
                    $names = array_filter($names, fn ($n) => preg_match('/;/', $n) || preg_match(self::SEPARATOR, $n));
                    usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                    self::$protected = array_values($names);
                }
            } catch (\Throwable) {
                self::$protected = [];
            }
        }

        return self::$protected;
    }

    public static function flushProtected(): void
    {
        self::$protected = null;
    }

    /**
     * Steht im Namen eine Zusammenarbeit? (feat./ft./vs./;, &/und/and/x/Komma, pres./pts./presents/introducing/meets/with/aka, + und / mit Leerzeichen)
     * Solche Namen taugen nicht als Schreibweise eines einzelnen Künstlers.
     */
    public static function looksCollaboration(string $name): bool
    {
        if (self::isInverted($name)) {
            return false; // "Berry, Chuck" / "Beatles, The": umgedrehter Name, keine Zusammenarbeit
        }

        return preg_match(self::SEPARATOR, ' '.$name) || str_contains($name, ';') || self::looksCombined($name)
            || preg_match('/\s(pres\.?|prés\.?|pts\.?|presents?|introducing|intro\.|meets|with|aka|a\.k\.a\.|\+|\/)\s/iu', $name);
    }

    /** "Nachname, Vorname" bzw. "Name, The": genau ein Komma, ohne weitere Kombi-Zeichen. */
    public static function isInverted(string $name): bool
    {
        $parts = explode(', ', $name);

        return count($parts) === 2 && ! str_contains($parts[1], ',') && ! preg_match('/\s(&|und|and|x)\s/iu', $name)
            && ! preg_match(self::SEPARATOR, ' '.$name) && mb_strlen(trim($parts[1])) > 0 && substr_count(trim($parts[1]), ' ') <= 1;
    }

    /** Kombi, die nicht automatisch getrennt wird, aber zur Entscheidung vorgeschlagen werden kann. */
    public static function looksCombined(string $name): bool
    {
        return (bool) preg_match('/\s(&|und|and|x)\s|,\s/iu', $name);
    }

    /** Vergleichsschlüssel: klein, ohne Punkte, Bindestriche, Leerzeichen und Akzente, &/and/und vereinheitlicht. */
    public static function compareKey(?string $name): string
    {
        $s = mb_strtolower(trim((string) $name));
        $s = preg_replace('/\s+(and|und|&|\+|n|\'n\')\s+/u', ' & ', $s);
        $s = str_replace('&', 'and', $s);
        $s = self::stripAccents($s);
        $s = preg_replace('/^the\s+|,\s*the$/u', '', $s);

        return preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
    }

    public static function stripAccents(string $s): string
    {
        $map = ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ß' => 'ss'];
        $s = strtr($s, $map);
        // Transliterator anzulegen ist rund 20× teurer als ihn zu benutzen – läuft pro Track und Prüfung mehrfach
        static $t = null;
        if ($t === null && class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII') ?: false;
        }
        if ($t) {
            return $t->transliterate($s);
        }

        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }

    /** Discogs-Zusätze wie "Adam (2)" entfernen. */
    public static function stripDiscogsSuffix(string $name): string
    {
        return trim(preg_replace('/\s+\(\d+\)$/', '', $name));
    }
}
