<?php

namespace App\Support;

/**
 * Kaputte Zeichen in Tags erkennen und Korrekturen vorschlagen.
 *
 *  - mojibake    UTF-8 als Latin-1/Windows-1252 gelesen ("KÃ¶nig") – verlustfrei zurückrechenbar (sicher)
 *  - invisible   Steuerzeichen, unsichtbare Zeichen, geschützte/doppelte Leerzeichen (sicher)
 *  - nfd         Unicode-Form wie vom Mac ("u" + "¨" statt "ü") – sieht gleich aus, vergleicht anders (sicher)
 *  - codepage    falsche Codepage, meist Kyrillisch aus alten Tags ("Ìàøèíà" statt "Машина") – nur Vorschlag
 *  - lost        Ersatzzeichen bzw. "?" mitten im Wort – Information verloren, nur von Hand / ähnliche Namen
 */
class TextRepair
{
    public const LABELS = [
        'mojibake' => 'UTF-8 falsch gelesen',
        'invisible' => 'unsichtbare Zeichen',
        'nfd' => 'Unicode-Form (Mac)',
        'codepage' => 'falsche Codepage',
        'lost' => 'Zeichen verloren',
    ];

    /** sicher automatisch behebbar (eindeutig, verlustfrei) */
    public const SAFE = ['mojibake', 'invisible', 'nfd'];

    /** Codepages, die als Vorschlag angeboten werden: Kennung => Anzeigename */
    private const CODEPAGES = ['Windows-1251' => 'Kyrillisch (Windows-1251)', 'Windows-1253' => 'Griechisch (Windows-1253)', 'Windows-1250' => 'Mitteleuropäisch (Windows-1250)'];

    /** Windows-1252: Zeichen in 0x80–0x9F, die nicht Latin-1 entsprechen */
    private const CP1252 = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87,
        0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91,
        0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98,
        0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /**
     * null = alles in Ordnung
     *
     * @return array{problems: list<string>, safe: ?string, cleaned: string, suggestions: list<array{value:string, label:string}>}|null
     */
    public static function analyze(?string $text): ?array
    {
        $text = (string) $text;
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8')) {
            return $text === '' ? null : ['problems' => ['lost'], 'safe' => null, 'cleaned' => $text, 'suggestions' => []];
        }
        $problems = [];
        $value = $text;

        // Mojibake zuerst: falsch gelesene Bytes können wie Steuerzeichen aussehen ("Ã\u{0083}Â¶")
        $fixed = self::fixMojibake($value);
        if ($fixed !== null) {
            $problems[] = 'mojibake';
            $value = $fixed;
        }
        $clean = self::stripInvisible($value);
        if ($clean !== $value) {
            $problems[] = 'invisible';
            $value = $clean;
        }
        if (class_exists(\Normalizer::class) && ! \Normalizer::isNormalized($value, \Normalizer::FORM_C)) {
            $problems[] = 'nfd';
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        $suggestions = [];
        if (self::looksLikeWrongCodepage($value)) {
            $problems[] = 'codepage';
            $suggestions = self::codepageCandidates($value);
        }
        if (preg_match('/\x{FFFD}|\p{L}\?+\p{L}/u', $value)) {
            $problems[] = 'lost';
        }
        if (! $problems) {
            return null;
        }

        return [
            'problems' => $problems,
            // nur wenn alles sicher behebbar ist – sonst entscheidet der Assistent über den ganzen Wert
            'safe' => ! array_diff($problems, self::SAFE) ? $value : null,
            // Wert nach den sicheren Korrekturen (Ausgangspunkt im Assistenten)
            'cleaned' => $value,
            'suggestions' => $suggestions,
        ];
    }

    /** Steuer- und unsichtbare Zeichen entfernen, Sonder-Leerzeichen vereinheitlichen, doppelte/äußere Leerzeichen weg. */
    public static function stripInvisible(string $s): string
    {
        $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $s);
        $s = preg_replace('/[\x{0009}\x{000A}\x{000D}\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $s);

        return trim(preg_replace('/ {2,}/', ' ', $s));
    }

    /** "KÃ¶nig" → "König" (auch doppelt kaputt); null, wenn es nicht nach Mojibake aussieht bzw. nicht aufgeht. */
    public static function fixMojibake(string $s): ?string
    {
        $pattern = '/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}\x{2014}\x{2018}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}]/u';
        $changed = false;
        for ($round = 0; $round < 2 && preg_match($pattern, $s); $round++) {
            $bytes = self::toSingleBytes($s);
            if ($bytes === null || ! mb_check_encoding($bytes, 'UTF-8') || $bytes === $s) {
                break;
            }
            $s = $bytes;
            $changed = true;
        }

        return $changed ? $s : null;
    }

    /** Text als Windows-1252-Bytes (jedes Zeichen ein Byte); null, wenn ein Zeichen nicht hineinpasst. */
    private static function toSingleBytes(string $s): ?string
    {
        $out = '';
        foreach (mb_str_split($s) as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp <= 0xFF) {
                $out .= chr($cp); // Latin-1 (auch rohe Steuerzeichen 0x80–0x9F, wenn als ISO-8859-1 gelesen)
            } elseif (isset(self::CP1252[$cp])) {
                $out .= chr(self::CP1252[$cp]);
            } else {
                return null;
            }
        }

        return $out;
    }

    /**
     * Überwiegend Buchstaben aus À–ÿ, kaum normale Buchstaben dazwischen: so sieht Kyrillisch/Griechisch aus,
     * das als Latin-1 gelesen wurde. Echte Namen wie "Björk" oder "Beyoncé" fallen nicht darunter.
     */
    private static function looksLikeWrongCodepage(string $s): bool
    {
        preg_match_all('/\p{L}/u', $s, $letters);
        $letters = $letters[0];
        $high = count(array_filter($letters, fn ($l) => mb_ord($l) >= 0xC0 && mb_ord($l) <= 0xFF && $l !== '×' && $l !== '÷'));

        return $high >= 3 && $high >= 0.6 * count($letters) && self::toSingleBytes($s) !== null;
    }

    /** Grundalphabet und Vokale je Schrift – zum Bewerten, ob ein Vorschlag nach echter Sprache aussieht */
    private const SCRIPTS = [
        'Windows-1251' => ['/[\x{0410}-\x{044F}\x{0401}\x{0451}]/u', '/[аеёиоуыэюяАЕЁИОУЫЭЮЯ]/u'],
        'Windows-1253' => ['/[\x{0391}-\x{03A9}\x{03AC}-\x{03AF}\x{03B1}-\x{03C9}\x{03CC}-\x{03CE}]/u', '/[αεηιουωάέήίόύώΑΕΗΙΟΥΩΆΈΉΊΌΎΏ]/u'],
        'Windows-1250' => ['/[A-Za-zÁáČčĎďÉéĚěÍíŇňÓóŘřŠšŤťÚúŮůÝýŽžĄąĆćĘęŁłŃńŚśŹźŻżÖöÜüŐőŰű]/u', '/[aeiouyAEIOUYáéěíóúůýÁÉĚÍÓÚŮÝąęĄĘöüőűÖÜŐŰ]/u'],
    ];

    /** @return list<array{value:string, label:string}> plausibelster Vorschlag zuerst */
    private static function codepageCandidates(string $s): array
    {
        $bytes = self::toSingleBytes($s);
        $scored = [];
        foreach (self::CODEPAGES as $cp => $label) {
            $candidate = @iconv($cp, 'UTF-8', $bytes); // iconv: mbstring kennt nicht alle Windows-Codepages
            if (! $candidate || $candidate === $s) {
                continue;
            }
            [$alphabet, $vowels] = self::SCRIPTS[$cp];
            $letters = preg_match_all('/\p{L}/u', $candidate);
            if (! $letters) {
                continue;
            }
            // Anteil Buchstaben aus dem Grundalphabet; echte Wörter haben etwa 25–65 % Vokale
            $basic = preg_match_all($alphabet, $candidate) / $letters;
            $vowelShare = preg_match_all($vowels, $candidate) / $letters;
            $score = $basic * ($vowelShare >= 0.2 && $vowelShare <= 0.7 ? 1 : 0.5);
            if ($score >= 0.85) {
                $scored[] = ['value' => $candidate, 'label' => $label, 'score' => $score];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($c) => ['value' => $c['value'], 'label' => $c['label']], $scored);
    }

    /** Für die Anzeige: unsichtbare Zeichen und Sonder-Leerzeichen sichtbar machen (⟨U+00A0⟩), Leerzeichen am Rand als ␣ */
    public static function visible(string $s): string
    {
        $s = preg_replace_callback('/[\x{0000}-\x{001F}\x{007F}-\x{00A0}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{2060}\x{3000}\x{FEFF}]/u',
            fn ($m) => '⟨U+'.strtoupper(str_pad(dechex(mb_ord($m[0])), 4, '0', STR_PAD_LEFT)).'⟩', $s);
        $s = preg_replace('/^ | $/', '␣', $s);

        return preg_replace('/ {2,}/', '␣␣', $s);
    }

    /** geprüfte Tag-Felder; Interpreten-Felder teilen sich eine Entscheidung (derselbe Name steht oft in mehreren) */
    public const FIELDS = ['title' => 'title', 'artist' => 'artist', 'artists' => 'artist', 'albumartist' => 'artist', 'albumartists' => 'artist', 'album' => 'album'];

    public const GROUP_LABELS = ['artist' => 'Interpret', 'album' => 'Album', 'title' => 'Titel'];

    /** Schlüssel einer Entscheidung (Feldgruppe + Originalwert) – auch für „so lassen“ (Aufräum-Ausnahme) */
    public static function key(string $field, string $value): string
    {
        return (self::FIELDS[$field] ?? $field).'|'.$value;
    }

    /**
     * Auffällige Werte eines Titels.
     *
     * @param  array<string, mixed>  $tags  Tags im Tag-Tool-Format (Track::tags())
     * @param  array<string, mixed>  $ignored  Schlüssel, die als „so lassen“ markiert sind
     * @return list<array{field:string, value:string, analysis:array}>
     */
    public static function issues(array $tags, array $ignored = []): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $field) {
            foreach ((array) ($tags[$field] ?? []) as $value) {
                $value = (string) $value;
                if ($value !== '' && ! isset($ignored[self::key($field, $value)]) && ($analysis = self::analyze($value))) {
                    $out[] = ['field' => $field, 'value' => $value, 'analysis' => $analysis];
                }
            }
        }

        return $out;
    }

    /**
     * Tags mit ersetzten Werten (nur geänderte Felder).
     *
     * @param  array<string, mixed>  $tags
     * @param  callable(string $field, string $value): ?string  $replace  neuer Wert oder null (bleibt)
     * @return array<string, mixed>
     */
    public static function replaced(array $tags, callable $replace): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $field) {
            if (! isset($tags[$field]) || $tags[$field] === '' || $tags[$field] === []) {
                continue;
            }
            $old = $tags[$field];
            $new = is_array($old)
                ? array_map(fn ($v) => $replace($field, (string) $v) ?? $v, $old)
                : ($replace($field, (string) $old) ?? $old);
            if ($new !== $old) {
                $out[$field] = $new;
            }
        }

        return $out;
    }

    /**
     * Für verlorene Zeichen: gleich lange Werte aus der Sammlung, die an allen lesbaren Stellen übereinstimmen
     * ("M?tley Cr?e" → "Mötley Crüe").
     *
     * @param  iterable<string>  $known
     * @return list<string>
     */
    public static function similarKnown(string $broken, iterable $known, int $limit = 5): array
    {
        $chars = mb_str_split($broken);
        $regex = '/^'.implode('', array_map(fn ($c) => $c === '?' || $c === "\u{FFFD}" ? '[^\s?\x{FFFD}]' : preg_quote($c, '/'), $chars)).'$/iu';
        $out = [];
        foreach ($known as $k) {
            if ($k !== $broken && mb_strlen($k) === count($chars) && preg_match($regex, $k) && ! self::analyze($k)) {
                $out[$k] = true;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return array_keys($out);
    }
}
