<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistSplit;
use Illuminate\Support\Facades\Schema;

/**
 * Regeln für Interpreten: automatisch getrennt wird bei ";", feat./ft./featuring und vs./versus,
 * nie bei "&", ",", "und", "and" – außer die Kombi ist als Zusammenarbeit gemerkt (ArtistSplit, „Trennen“ im Aufräumen).
 * Namen aus dem Künstlerverzeichnis werden nie zerlegt (z. B. eine Band mit "vs" im Namen).
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
        $splits = self::splitRules();
        foreach (preg_split('/\s*;\s*/u', $text) as $part) {
            foreach (preg_split(self::SEPARATOR, $part) as $name) {
                $name = self::trimClosing(trim(strtr($name, $keep)));
                // gemerkte Zusammenarbeit („Yanou & Liz“ → Yanou, Liz)
                foreach ($splits[ArtistSplit::keyOf($name)] ?? [$name] as $one) {
                    if ($one !== '' && ! in_array($one, $out, true)) {
                        $out[] = $one;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Schließende Klammer vom Trennen abschneiden („Yanou (feat. Liz)“ → „Liz)“), aber nur ohne passende öffnende –
     * „Was (Not Was)“ bleibt ganz (sonst wird daraus beim Korrigieren „Was (Not Was))“, bei jedem Lauf eine mehr).
     */
    private static function trimClosing(string $name): string
    {
        while (preg_match('/[)\]]$/u', $name, $m)) {
            if (substr_count($name, $m[0] === ')' ? '(' : '[') >= substr_count($name, $m[0])) {
                break;
            }
            $name = rtrim(substr($name, 0, -1));
        }

        return $name;
    }

    /** @var array<string, list<string>>|null Kleinschreibung der Kombi => Teile */
    private static ?array $splits = null;

    /** @return array<string, list<string>> */
    private static function splitRules(): array
    {
        if (self::$splits === null) {
            self::$splits = [];
            try {
                if (Schema::hasTable('artist_splits')) {
                    foreach (ArtistSplit::all(['name_key', 'parts']) as $s) {
                        self::$splits[$s->name_key] = array_values(array_filter(array_map('trim', (array) $s->parts)));
                    }
                }
            } catch (\Throwable) {
                self::$splits = [];
            }
        }

        return self::$splits;
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

    /**
     * Stand der Regeln, die das Trennen bestimmen (geschützte Namen mit Trenner, gemerkte Zusammenarbeiten) – frisch aus
     * der Datenbank. Schlüssel für Namensliste und Profile: andere Änderungen im Verzeichnis rechnen sie nicht neu.
     */
    public static function rulesVersion(): string
    {
        self::flushProtected();

        return md5(serialize([self::protectedNames(), self::splitRules()]));
    }

    public static function flushProtected(): void
    {
        self::$protected = null;
        self::$splits = null;
    }

    /** Vorschlag zum Trennen einer Kombi: an &, und, and, x und Komma. */
    public static function suggestSplit(string $name): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\s+(?:&|und|and|x)\s+|,\s*/iu', $name) ?: [])));
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

    /**
     * "Nachname, Vorname" bzw. "Name, The": genau ein Komma, ohne weitere Kombi-Zeichen, und eine Seite ist ein einzelnes
     * Wort ("Berry, Chuck", "Lee Hooker, John") – mehrere Wörter auf beiden Seiten sind zwei Personen
     * ("Gerhard Polt, Gisela Schneeberger").
     */
    public static function isInverted(string $name): bool
    {
        $parts = explode(', ', $name);

        return count($parts) === 2 && ! str_contains($parts[1], ',') && ! preg_match('/\s(&|und|and|x)\s/iu', $name)
            && ! preg_match(self::SEPARATOR, ' '.$name) && mb_strlen(trim($parts[1])) > 0 && substr_count(trim($parts[1]), ' ') <= 1
            && (! str_contains(trim($parts[0]), ' ') || ! str_contains(trim($parts[1]), ' '));
    }

    /** Artikel, die in der Sortierschreibweise hinten stehen („Crystals, The“, „Toten Hosen, Die“) */
    public const ARTICLES = ['the', 'die', 'der', 'das', 'les', 'le', 'la', 'los', 'las', 'il', 'el', 'de', 'het', 'a', 'an'];

    /**
     * Umgedrehten Namen richtig herum: „Crystals, The“ → „The Crystals“; mit $persons auch „Berry, Chuck“ → „Chuck Berry“.
     * null = kein umgedrehter Name bzw. im gewählten Umfang nicht umstellen.
     */
    public static function uninvert(string $name, bool $persons = false): ?string
    {
        $name = trim($name);
        if (! self::isInverted($name)) {
            return null;
        }
        [$rest, $front] = array_map('trim', explode(', ', $name, 2));
        if (! $persons && ! in_array(mb_strtolower($front), self::ARTICLES, true)) {
            return null;
        }

        return $front.' '.$rest;
    }

    /** Kombi, die nicht automatisch getrennt wird, aber zur Entscheidung vorgeschlagen werden kann. */
    public static function looksCombined(string $name): bool
    {
        if (self::isInverted($name)) {
            return false; // "Crystals, The" / "Berry, Chuck": umgedrehter Name, kein zweiter Künstler
        }

        return (bool) preg_match('/\s(&|und|and|x)\s|,\s/iu', $name);
    }

    /**
     * Typografische Satzzeichen (z. B. von MusicBrainz) durch die üblichen ersetzen: „‐“ → „-“, „’“ → „'“, „…“ → „...“.
     * Sie sehen gleich aus, sind aber andere Zeichen – in Tags und Ordnernamen sollen die normalen stehen.
     */
    public static function plainPunctuation(string $name): string
    {
        return strtr($name, ["\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2026}" => '...', "\u{00A0}" => ' ']);
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
