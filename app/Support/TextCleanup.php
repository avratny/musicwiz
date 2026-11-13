<?php

namespace App\Support;

use App\Models\Track;

/**
 * Schreibweise von Titel, Album und Interpreten bereinigen – immer und ohne Rückfrage (sicher):
 * - mehrfache Leerzeichen zu einem, keine am Rand
 * - keine Leerzeichen innen an Klammern („(La La La )“ → „(La La La)“), eins davor, wenn es fehlt („Song(Remix)“ →
 *   „Song (Remix)“ – nicht bei kurzem Inhalt wie „Girl(s)“)
 * - kein Leerzeichen vor , ; ! ? und einzelnem Punkt („Harder? !“ → „Harder?!“)
 * - Akzent statt Apostroph (´ `) → ' („Boy´s“ → „Boy's“); ’ bleibt (so schreibt es das Künstlerverzeichnis)
 * - Bindestrich als Trenner mit Leerzeichen nur dahinter → „ - “ („High Life- Hits“ → „High Life - Hits“); Bindestriche
 *   im Wort („Jay-Z“) und der Ergänzungsstrich („Radio- und Videoversion“, „Radio- / Video“) bleiben
 * Greift beim Bearbeiten (TagEditor), nach dem Einlesen und vor dem Schreiben (apply) – als System-Änderung mit Grund.
 * Was sich nicht sicher entscheiden lässt, steht zur Prüfung unter Sonstiges (TextRepairs).
 */
class TextCleanup
{
    public const FIELDS = ['title', 'album', 'artist', 'albumartist', 'artists', 'albumartists'];

    /** Wörter nach einem Ergänzungsstrich („Radio- und Videoversion“) */
    private const AFTER_HYPHEN = '(?:und|oder|bis|bzw\.?|sowie|and|or|&|\/|,)(?:\s|$)';

    public static function clean(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        $value = preg_replace(['/([\(\[]) /u', '/ ([\)\]])/u'], '$1', $value);
        $value = preg_replace('/(?<=[\p{L}\p{N}])([\(\[])(?=[^\(\)\[\]]{3,}[\)\]])/u', ' $1', $value);
        $value = preg_replace('/ (?=[,;!?]|\.(?!\.))/u', '', $value);
        $value = str_replace(['´', '`'], "'", $value);
        $value = preg_replace('/(?<=[^\s-])- (?!'.self::AFTER_HYPHEN.')/u', ' - ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Alle Lieder (Inbox, Lib) bzw. diese IDs bereinigen.
     *
     * @return int Zahl der geänderten Lieder
     */
    public function apply(?array $ids = null): int
    {
        $tracks = fn () => Track::whereNotIn('location', Locations::IGNORED)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
        $map = [];
        foreach (self::FIELDS as $field) {
            // binär: „Never  Alone“ und „Never Alone“ sind verschiedene Werte
            foreach ($tracks()->whereNotNull($field)->selectRaw('DISTINCT CAST('.$field.' AS BINARY) AS v')->pluck('v') as $value) {
                $clean = TagEditor::normalize($field, (string) $value);
                if ($clean !== $value) {
                    $map[$field][$value] = $clean;
                }
            }
        }
        $editor = app(TagEditor::class);
        $changed = [];
        foreach ($map as $field => $values) {
            foreach (array_chunk(array_keys($values), 500) as $chunk) {
                foreach ($tracks()->whereIn($field, $chunk)->get() as $track) {
                    $old = (string) $track->getAttribute($field);
                    if (isset($values[$old]) && $editor->update($track, [$field => $values[$old]], TagEditor::SYSTEM, 'Schreibweise bereinigt: „'.$old.'“ → „'.$values[$old].'“')) {
                        $changed[$track->id] = true;
                    }
                }
            }
        }

        return count($changed);
    }
}
