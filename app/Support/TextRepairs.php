<?php

namespace App\Support;

use App\Models\TagChange;
use App\Models\TextRepair;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Normalizer;

/**
 * Encoding-Fehler in den Tags (Inbox und Lib) – je kaputtem Wert ein Eintrag, egal in wie vielen Liedern und Feldern:
 * - falsch dekodiert („KÃ¶nig“): UTF-8 als Windows-1252 gelesen – lässt sich sicher zurückrechnen
 * - unsichtbare Zeichen (Null-Breite, Steuerzeichen, weiches Trennzeichen) – entfernen
 * - zerlegte Umlaute (NFD, „o“ + Trema) – zusammensetzen
 * - verlorene Zeichen („�“, „?“ mitten im Wort) – nicht zurückzurechnen: Vorschläge von Claude (TextRepairAi) oder
 *   eigene Schreibweise
 * Dazu Schreibweise prüfen (Titel, Album, Interpreten – was TextCleanup nicht sicher entscheiden kann), mit Vorschlag
 * und „Passt so“:
 * - Klammer offen („(Album Version9“)
 * - Punkt am Ende bei Titel und Album („Sea Cruise.“) – nicht bei Abkürzungen („Jr.“, „Vol.“, „E.I.“)
 * - Bindestrich mit Leerzeichen nur davor („Played -A-Live“)
 * Übernommen wird als Tag-Änderung (nur in der Datenbank).
 */
class TextRepairs
{
    /** geprüfte Felder */
    public const FIELDS = ['title', 'artist', 'artists', 'album', 'albumartist', 'albumartists', 'genre'];

    public const KINDS = ['lost' => 'Zeichen verloren', 'mojibake' => 'falsch dekodiert', 'invisible' => 'unsichtbare Zeichen', 'nfd' => 'zerlegte Umlaute',
        'bracket' => 'Klammer offen', 'dot' => 'Punkt am Ende', 'hyphen' => 'Bindestrich einseitig'];

    /** Arten zum Prüfen der Schreibweise (der Rest sind Encoding-Fehler) */
    public const REVIEW = ['bracket', 'dot', 'hyphen'];

    /** Felder, in denen die Schreibweise geprüft wird (Punkt am Ende nur bei Titel und Album) */
    private const REVIEW_FIELDS = ['title' => true, 'album' => true, 'artist' => false, 'albumartist' => false];

    /** Abkürzungen, deren Punkt am Ende bleibt */
    private const ABBREVIATIONS = ['jr', 'sr', 'mr', 'mrs', 'ms', 'dr', 'st', 'no', 'nr', 'vol', 'pt', 'co', 'inc', 'ltd', 'bros', 'feat', 'ft', 'vs', 'ca', 'op', 'orig', 'etc', 'usw', 'bzw'];

    private const HYPHEN = '/(?<=\S) -(?=[\p{L}\p{N}])/u';

    /** typische Folgen, wenn UTF-8 als Windows-1252 gelesen wurde: Ã¶, ÃŸ, Â°, â€™ … */
    private const MOJIBAKE = '/[ÃÂ][\x{00A0}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}-\x{203A}\x{20AC}\x{2122}]|â€/u';

    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}\x{0000}-\x{001F}\x{007F}]/u';

    private const LOST = '/\x{FFFD}|\p{L}\?\p{L}/u';

    /** @return list<string> Arten des Fehlers (Schlüssel aus KINDS) – Schreibweise nur mit Feld aus REVIEW_FIELDS */
    public static function kinds(string $value, ?string $field = null): array
    {
        $kinds = [];
        if (preg_match(self::LOST, $value)) {
            $kinds[] = 'lost';
        }
        if (preg_match(self::MOJIBAKE, $value) && self::undoMojibake($value) !== $value) {
            $kinds[] = 'mojibake';
        }
        if (preg_match(self::INVISIBLE, $value)) {
            $kinds[] = 'invisible';
        }
        if (class_exists(Normalizer::class) && ! Normalizer::isNormalized($value, Normalizer::FORM_C)) {
            $kinds[] = 'nfd';
        }
        if (! isset(self::REVIEW_FIELDS[$field])) {
            return $kinds;
        }
        if (substr_count($value, '(') !== substr_count($value, ')') || substr_count($value, '[') !== substr_count($value, ']')) {
            $kinds[] = 'bracket';
        }
        if (self::REVIEW_FIELDS[$field] && self::needlessDot($value)) {
            $kinds[] = 'dot';
        }
        if (preg_match(self::HYPHEN, $value)) {
            $kinds[] = 'hyphen';
        }

        return $kinds;
    }

    /** Punkt am Ende, der keine Abkürzung („Jr.“) und keine Initialen („E.I.“) abschließt */
    private static function needlessDot(string $value): bool
    {
        if (! preg_match('/(?:^|\s)(\S+?)\.$/u', $value, $m) || str_ends_with($value, '..')) {
            return false;
        }

        return ! preg_match('/^(\p{L}\.)*\p{L}$/u', $m[1]) && ! in_array(mb_strtolower($m[1]), self::ABBREVIATIONS, true);
    }

    /**
     * Vorschlag zur Schreibweise (zum Prüfen, nicht sicher): Punkt am Ende weg, „9“ am Ende der offenen Klammer ist „)“
     * (Umschalt vergessen), sonst schließen; einseitiger Bindestrich verbindet („Played -A-Live“ → „Played-A-Live“).
     */
    public static function reviewFix(string $value): ?string
    {
        $fixed = $value;
        if (self::needlessDot($fixed)) {
            $fixed = mb_substr($fixed, 0, -1);
        }
        foreach (['(' => ')', '[' => ']'] as $open => $close) {
            if (substr_count($fixed, $open) === substr_count($fixed, $close) + 1 && mb_strrpos($fixed, $open) > (int) mb_strrpos($fixed, $close)) {
                $fixed = preg_replace('/9$/u', '', $fixed).$close;
            }
        }
        $fixed = preg_replace(self::HYPHEN, '-', $fixed);

        return $fixed === $value ? null : $fixed;
    }

    /** Wert als HTML, die kaputten Stellen markiert (unsichtbare Zeichen als „U+200B“ sichtbar gemacht) */
    public static function highlight(string $value): HtmlString
    {
        $mojibake = substr(self::MOJIBAKE, 1, -2);
        $invisible = substr(self::INVISIBLE, 1, -2);
        $parts = preg_split('/(\x{FFFD}|(?<=\p{L})\?(?=\p{L})|'.$mojibake.'|'.$invisible.')/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        $html = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $html .= e($part);
            } elseif (preg_match(self::INVISIBLE, $part)) {
                $html .= '<mark class="rounded bg-amber-500/25 px-0.5 text-[10px] text-amber-200">U+'.strtoupper(dechex(mb_ord($part))).'</mark>';
            } else {
                $html .= '<mark class="rounded bg-red-500/30 px-0.5 text-red-200">'.e($part).'</mark>';
            }
        }

        return new HtmlString($html);
    }

    /** sichere Reparatur ohne Raten – null, wenn danach noch verlorene Zeichen bleiben oder sich nichts ändert */
    public static function ruleFix(string $value): ?string
    {
        $fixed = trim(preg_replace('/\s+/u', ' ', preg_replace(self::INVISIBLE, ' ', $value)));
        $fixed = self::undoMojibake($fixed);
        if (class_exists(Normalizer::class)) {
            $fixed = Normalizer::normalize($fixed, Normalizer::FORM_C) ?: $fixed;
        }

        return $fixed === $value || preg_match(self::LOST, $fixed) ? null : $fixed;
    }

    /** als Windows-1252 gelesenes UTF-8 zurückrechnen (bis zu zweimal – manches ist doppelt kaputt) */
    private static function undoMojibake(string $value): string
    {
        for ($i = 0; $i < 2 && preg_match(self::MOJIBAKE, $value); $i++) {
            $bytes = @mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
            // nur, wenn dabei gültiges UTF-8 herauskommt und nichts verloren geht („?“ für nicht darstellbare Zeichen)
            if (! is_string($bytes) || ! mb_check_encoding($bytes, 'UTF-8') || substr_count($bytes, '?') !== substr_count($value, '?') || $bytes === $value) {
                break;
            }
            $value = $bytes;
        }

        return $value;
    }

    /**
     * Kaputte Werte – zwischengespeichert, bis sich an den Liedern etwas ändert.
     *
     * @return Collection<int, array{value: string, hash: string, kinds: list<string>, fields: array<string, int>, tracks: int, rule: ?string}>
     */
    public function problems(string $search = ''): Collection
    {
        // Änderungen in derselben Sekunde ändern updated_at nicht sichtbar – die letzte Tag-Änderung schon
        $state = implode('|', [Track::count(), Track::max('updated_at'), TagChange::max('id'), TagChange::count()]);
        $all = Cache::remember('text-repairs:'.md5($state), now()->addDay(), function () {
            $out = [];
            foreach (self::FIELDS as $field) {
                $values = DB::table('tracks')->whereIn('location', SplitAlbums::LOCATIONS)->whereNotNull($field)
                    ->groupByRaw('BINARY '.$field)->selectRaw('MIN('.$field.') AS v, COUNT(*) AS n')->get();
                foreach ($values as $row) {
                    $value = (string) $row->v;
                    if (! ($kinds = self::kinds($value, $field))) {
                        continue;
                    }
                    $out[$value] ??= ['value' => $value, 'hash' => sha1($value), 'kinds' => [], 'fields' => [], 'tracks' => 0, 'rule' => null, 'safe' => false];
                    $out[$value]['kinds'] = array_values(array_unique([...$out[$value]['kinds'], ...$kinds]));
                    $out[$value]['fields'][$field] = (int) $row->n;
                    $out[$value]['tracks'] += (int) $row->n;
                }
            }

            foreach ($out as $value => $p) {
                $safe = self::ruleFix($value);
                $out[$value]['safe'] = $safe !== null;
                $out[$value]['rule'] = $safe ?? (array_intersect($p['kinds'], self::REVIEW) ? self::reviewFix($value) : null);
            }

            // verlorene Zeichen zuerst (brauchen Entscheidung), dann nach Zahl der Lieder
            return collect(array_values($out))->sortBy([fn ($a, $b) => in_array('lost', $b['kinds'], true) <=> in_array('lost', $a['kinds'], true),
                fn ($a, $b) => $b['tracks'] <=> $a['tracks']])->values()->all();
        });
        // „Passt so“: nur noch Encoding-Fehler zählen
        $accepted = TextRepair::whereNotNull('accepted_at')->pluck('hash')->flip();
        $list = collect($all)->reject(fn ($p) => isset($accepted[$p['hash']]) && ! array_diff($p['kinds'], self::REVIEW));
        if (trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $list = $list->filter(fn ($p) => str_contains(mb_strtolower($p['value']), $needle));
        }

        return $list->values();
    }

    public function count(): int
    {
        return $this->problems()->count();
    }

    /** Werte, für die nur Claude (oder der Nutzer) helfen kann und die noch keine Vorschläge haben */
    public function unasked(): Collection
    {
        $asked = TextRepair::whereNotNull('suggestions')->pluck('hash')->flip();

        return $this->problems()->filter(fn ($p) => $p['rule'] === null && array_diff($p['kinds'], self::REVIEW) && ! isset($asked[$p['hash']]))->values();
    }

    /** Schreibweise „passt so“ – der Wert wird beim Prüfen nicht mehr angezeigt */
    public function accept(string $value): void
    {
        TextRepair::updateOrCreate(['hash' => sha1($value)], ['value' => $value, 'accepted_at' => now()]);
    }

    /** Zusammenhang für Claude: bis zu drei Lieder mit diesem Wert */
    public function context(string $value): array
    {
        $tracks = Track::whereIn('location', SplitAlbums::LOCATIONS)
            ->where(fn ($q) => collect(self::FIELDS)->each(fn ($f) => $q->orWhereRaw('BINARY '.$f.' = ?', [$value])))
            ->limit(3)->get(['title', 'artist', 'album', 'albumartist', 'year', 'genre', 'filename', 'dir', 'location']);

        return $tracks->map(fn ($t) => array_filter([
            'Titel' => $t->title, 'Interpret' => $t->artist, 'Album' => $t->album, 'Album-Interpret' => $t->albumartist, 'Jahr' => $t->year,
            'Genre' => $t->genre, 'Ordner' => ltrim(mb_substr($t->dir, mb_strlen(Locations::root($t->location))), '/'), 'Datei' => $t->filename,
        ]))->all();
    }

    /**
     * Schreibweise übernehmen: in allen Liedern (Inbox und Lib) und Feldern, die genau diesen Wert haben.
     *
     * @return int Zahl der geänderten Lieder
     */
    public function apply(string $value, string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            throw new InvalidArgumentException('Bitte eine Schreibweise angeben.');
        }
        if (preg_match(self::LOST, $text)) {
            throw new InvalidArgumentException('Die Schreibweise enthält noch ein verlorenes Zeichen („�“ oder „?“ im Wort).');
        }
        $editor = app(TagEditor::class);
        $reason = (array_diff(self::kinds($value), self::REVIEW) ? 'Encoding repariert' : 'Schreibweise korrigiert').': „'.$value.'“ → „'.$text.'“';
        $changed = [];
        foreach (self::FIELDS as $field) {
            foreach (Track::whereIn('location', SplitAlbums::LOCATIONS)->whereRaw('BINARY '.$field.' = ?', [$value])->get() as $track) {
                if ($editor->update($track, [$field => $text], TagEditor::MANUAL, $reason)) {
                    $changed[$track->id] = true;
                }
            }
        }

        return count($changed);
    }
}
