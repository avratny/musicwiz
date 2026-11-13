<?php

namespace App\Support;

/**
 * Fazit für eine Gruppe in Interpreten › Ähnlich – aus der Zuordnung zu MusicBrainz (lokale Daten):
 * 1. alle derselbe Eintrag → Zusammenführen, als Name die Schreibweise laut MusicBrainz (sicher; wahrscheinlich, wenn
 *    nicht alle eindeutig zugeordnet sind)
 * 2. alle verschiedene Einträge → Verschieden (sicher)
 * 3. gemischt → Zusammenführen, wer zu einem anderen Eintrag gehört, ist abgewählt (wahrscheinlich)
 * 4. nichts zugeordnet: nur Groß/klein anders → Zusammenführen (wahrscheinlich), sonst unklar
 */
class SimilarVerdict
{
    /**
     * @param  list<array{id: int, name: string, mbid: ?string, candidates: int}>  $members  stärkster zuerst
     * @param  array<string, object>  $info  MusicBrainz-Kurzinfo je Eintrag
     * @return array{action: ?string, confidence: string, reasons: list<string>, target: ?int, name: ?string, uncheck: list<int>}
     */
    public static function for(array $members, int $level, array $info, bool $available): array
    {
        $result = fn (?string $action, string $confidence, array $reasons, ?int $target = null, ?string $name = null, array $uncheck = []) => compact('action', 'confidence', 'reasons', 'target', 'name', 'uncheck');
        $known = array_values(array_filter($members, fn ($m) => $m['mbid'] && isset($info[$m['mbid']])));
        $entities = array_values(array_unique(array_column($known, 'mbid')));

        if (! $known) {
            $why = ! $available ? 'MusicBrainz-Daten nicht geladen (Einstellungen › MusicBrainz)'
                : (array_filter($members, fn ($m) => $m['candidates'] > 1) ? 'bei MusicBrainz mehrdeutig' : 'MusicBrainz kennt die Namen nicht');

            return $level === ArtistMatcher::CASE
                ? $result('merge', 'wahrscheinlich', ['nur Groß/klein anders', $why])
                : $result(null, 'unklar', [$why]);
        }

        if (count($entities) === 1) {
            [$target, $name] = self::target($members, $info[$entities[0]]->name);
            $unknown = count($members) - count($known);

            return $result('merge', $unknown ? 'wahrscheinlich' : 'sicher', [
                ($unknown ? 'MusicBrainz: ' : 'alle derselbe MusicBrainz-Eintrag: ').self::describe($info[$entities[0]]),
                ...($unknown ? [$unknown === 1 ? 'einer nicht eindeutig zugeordnet' : $unknown.' nicht eindeutig zugeordnet'] : []),
            ], $target, $name);
        }

        if (count($known) === count($members) && count($entities) === count($members)) {
            return $result('distinct', 'sicher', ['MusicBrainz kennt verschiedene Interpreten: '.implode(' · ', array_map(fn ($m) => '„'.$m['name'].'“ = '.self::describe($info[$m['mbid']]), $members))]);
        }

        // gemischt: der Eintrag des stärksten Zugeordneten zählt, die anderen werden abgewählt
        $main = $known[0]['mbid'];
        $others = array_values(array_filter($known, fn ($m) => $m['mbid'] !== $main));
        $mainMembers = array_values(array_filter($members, fn ($m) => ! in_array($m, $others, true)));
        [$target, $name] = self::target($mainMembers, $info[$main]->name);

        return $result('merge', 'wahrscheinlich', [
            'MusicBrainz: '.self::describe($info[$main]),
            'anderer Interpret (abgewählt): '.implode(' · ', array_map(fn ($m) => '„'.$m['name'].'“ = '.self::describe($info[$m['mbid']]), $others)),
        ], $target, $name, array_column($others, 'id'));
    }

    /** Ziel: wer schon so heißt wie bei MusicBrainz – sonst der stärkste, mit der Schreibweise von MusicBrainz als Name */
    private static function target(array $members, string $mbName): array
    {
        foreach ($members as $m) {
            if ($m['name'] === $mbName) {
                return [$m['id'], null];
            }
        }

        return [$members[0]['id'], $mbName];
    }

    /** „Diddy (Person, US, Rapper)“ */
    public static function describe(object $e): string
    {
        $types = ['Person' => 'Person', 'Group' => 'Gruppe', 'Orchestra' => 'Orchester', 'Choir' => 'Chor', 'Character' => 'Figur', 'Other' => 'Sonstiges'];
        $details = array_filter([$types[$e->type] ?? $e->type, $e->country, $e->disambiguation]);

        return '„'.$e->name.'“'.($details ? ' ('.implode(', ', $details).')' : '');
    }
}
