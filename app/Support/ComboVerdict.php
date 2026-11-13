<?php

namespace App\Support;

/**
 * Fazit für eine Kombi (Interpreten › Kombis): Trennen oder Bleibt ganz – aus der Namensprüfung der Dienste und den
 * eigenen Liedern der Teile. Feste, nachvollziehbare Regeln, sicherste zuerst:
 * 0. MusicBrainz führt den ganzen Namen als Gruppe und alle Teile sind ihre Mitglieder → Bleibt ganz + Mitglieder
 *    (ARTISTS = Gruppe; Mitglieder – das Lied findet sich auch bei den Mitgliedern)
 * 1. MusicBrainz führt den ganzen Namen als Gruppe (Band, Orchester, Chor) → Bleibt ganz
 * 2. MusicBrainz oder Discogs führen den ganzen Namen als eigenen Interpreten → Bleibt ganz (festes Projekt, auch wenn
 *    die Mitglieder einzeln bekannt sind – wie bei „Beam & Yanou“; für bloße Zusammenarbeiten legt Discogs keine Seite an)
 * 3. ein Teil ist nirgends verlässlich bekannt → Bleibt ganz (eher ein Bandname wie „His Comets“)
 * 4. alle Teile bekannt und MusicBrainz und Discogs kennen den ganzen Namen nachweislich nicht → Trennen
 *    (ohne Antwort von MusicBrainz zum ganzen Namen nie Trennen – dann unklar)
 * Spotify und Last.fm zählen nicht als verlässlich (kennen fast jeden Namen); Last.fm-Hörer stehen als Hinweis dabei.
 */
class ComboVerdict
{
    private const GOOD = ['exakt', 'gross_klein', 'zeichen'];

    private const GROUPS = ['Group', 'Orchestra', 'Choir'];

    /**
     * @param  array{name: string, parts: list<string>, known: array<string, array{alone: int, others: int}>}  $combo
     * @param  array<string, array<string, array{status: string, match: ?string, type: ?string}>>  $checks  Name => Dienst => Ergebnis
     * @param  array{group?: ?array}  $combo  group: NameCheck::group() – Mitglieder laut MusicBrainz
     * @return array{action: ?string, confidence: string, reasons: list<string>} action: members, split, whole oder null (unklar)
     */
    public static function for(array $combo, array $checks): array
    {
        $whole = $checks[$combo['name']] ?? [];
        $checked = $whole || array_intersect_key($checks, array_flip($combo['parts']));
        $mbMissing = array_filter([$combo['name'], ...$combo['parts']], fn ($n) => ($checks[$n]['musicbrainz']['status'] ?? null) === 'fehler' || ! isset($checks[$n]['musicbrainz']));
        $result = function (?string $action, string $confidence, array $reasons) use ($mbMissing, $checked) {
            if ($mbMissing && $checked) {
                $reasons[] = 'MusicBrainz fehlt noch';
                $confidence = $confidence === 'sicher' ? 'wahrscheinlich' : $confidence;
            }

            return ['action' => $action, 'confidence' => $confidence, 'reasons' => $reasons];
        };

        if (! $checked) {
            return ArtistCredits::allAlone($combo)
                ? ['action' => 'split', 'confidence' => 'wahrscheinlich', 'reasons' => ['jeder Teil hat eigene Lieder', 'Dienste noch nicht gefragt']]
                : ['action' => null, 'confidence' => 'unklar', 'reasons' => ['Dienste noch nicht gefragt']];
        }

        // 1. MusicBrainz: der ganze Name ist eine Gruppe
        $good = fn (?array $r) => $r && $r['status'] === 'ok' && in_array($r['match'], self::GOOD, true);
        $mb = $whole['musicbrainz'] ?? null;
        $hint = self::listeners($combo, $checks);
        $group = $combo['group'] ?? null;
        if ($group && $group['parts'] && count($group['parts']) === count($combo['parts'])) {
            return ['action' => 'members', 'confidence' => 'sicher', 'reasons' => [
                'MusicBrainz: Gruppe aus '.self::names($group).' – bleibt ganz, die Mitglieder werden mit verknüpft', ...$hint]];
        }
        if ($good($mb) && in_array($mb['type'], self::GROUPS, true)) {
            if ($group) {
                return ['action' => 'whole', 'confidence' => 'sicher', 'reasons' => ['MusicBrainz führt „'.$combo['name'].'“ als Gruppe aus '.self::names($group)
                    .' – nicht alle Teile sind Mitglieder', ...$hint]];
            }

            return ['action' => 'whole', 'confidence' => 'sicher', 'reasons' => ['MusicBrainz führt „'.$combo['name'].'“ als eigene Gruppe', ...$hint]];
        }

        // 2. MusicBrainz oder Discogs führen den ganzen Namen als eigenen Interpreten
        if ($good($mb) || $good($whole['discogs'] ?? null)) {
            $who = $good($mb) ? 'MusicBrainz' : 'Discogs';

            return $result('whole', $good($mb) ? 'sicher' : 'wahrscheinlich', [$who.' führt „'.$combo['name'].'“ als eigenen Interpreten – festes Projekt', ...$hint]);
        }

        // 3. ein Teil ist nirgends verlässlich bekannt (und hat keine eigenen Lieder)
        $partInfo = [];
        foreach ($combo['parts'] as $part) {
            $partInfo[$part] = self::reliable($checks[$part] ?? [], $combo['known'][$part] ?? null);
        }
        $wholeSources = self::sources($whole);
        foreach ($partInfo as $part => $info) {
            if (! $info['known'] && $info['checked']) {
                return $result('whole', $wholeSources ? 'sicher' : 'wahrscheinlich',
                    ['„'.$part.'“ kennt kein Dienst verlässlich'.($wholeSources ? ', den ganzen Namen '.implode(', ', $wholeSources) : ''), ...$hint]);
            }
        }

        // 4. alle Teile bekannt: Trennen nur, wenn MusicBrainz und Discogs den ganzen Namen nachweislich nicht führen
        if (array_filter($partInfo, fn ($i) => $i['known']) === $partInfo) {
            $why = array_map(fn ($part) => '„'.$part.'“: '.$partInfo[$part]['why'], array_keys($partInfo));
            if (($mb['status'] ?? null) !== 'nicht_gefunden') {
                return ['action' => null, 'confidence' => 'unklar', 'reasons' => ['alle Teile bekannt, aber MusicBrainz hat zum ganzen Namen noch nicht geantwortet – erneut „Dienste fragen“', ...$hint]];
            }
            if (in_array($whole['discogs']['status'] ?? null, ['ok', 'fehler'], true)) {
                return ['action' => null, 'confidence' => 'unklar', 'reasons' => ['alle Teile bekannt, Discogs zum ganzen Namen unklar', ...$hint]];
            }

            // gleichnamige Teile: „bekannt“ kann ein anderer sein
            $ambiguous = array_values(array_filter($combo['parts'], fn ($p) => ($checks[$p]['musicbrainz']['namesakes'] ?? 0) > 1 && ! ($combo['known'][$p]['alone'] ?? 0)));
            $note = $ambiguous ? ['mehrdeutig: '.implode(', ', array_map(fn ($p) => '„'.$p.'“ ('.$checks[$p]['musicbrainz']['namesakes'].' heißen so)', $ambiguous))] : [];

            return $result('split', $ambiguous ? 'wahrscheinlich' : 'sicher', ['weder MusicBrainz noch Discogs führen „'.$combo['name'].'“ – eine Zusammenarbeit', ...$why, ...$note, ...$hint]);
        }

        return $result(null, 'unklar', ['noch nicht alle Teile geprüft']);
    }

    /** „Beam und Yanou“ (aktuelle Mitglieder, ehemalige in Klammern) */
    private static function names(array $group): string
    {
        $now = array_column(array_filter($group['members'], fn ($m) => ! $m['ended']), 'name');
        $former = array_column(array_filter($group['members'], fn ($m) => $m['ended']), 'name');
        $list = $now ? (count($now) > 1 ? implode(', ', array_slice($now, 0, -1)).' und '.end($now) : $now[0]) : '';

        return trim($list.($former ? ' (ehemals '.implode(', ', $former).')' : ''));
    }

    /** Hinweis: Hörer laut Last.fm für den ganzen Namen und die Teile („Last.fm-Hörer: ganz 12.000 · Beam 30.000“) */
    private static function listeners(array $combo, array $checks): array
    {
        $n = fn ($name) => ($checks[$name]['lastfm']['status'] ?? null) === 'ok' ? $checks[$name]['lastfm']['listeners'] ?? null : null;
        $parts = array_filter(array_map(fn ($p) => $n($p) === null ? null : $p.' '.number_format($n($p), 0, ',', '.'), $combo['parts']));
        if ($n($combo['name']) === null && ! $parts) {
            return [];
        }

        return ['Last.fm-Hörer: ganz '.($n($combo['name']) === null ? '–' : number_format($n($combo['name']), 0, ',', '.')).($parts ? ' · '.implode(' · ', $parts) : '')];
    }

    /** Dienste, die den Namen so kennen (ohne Spotify, das fast alles findet) */
    private static function sources(array $checks): array
    {
        $labels = ['musicbrainz' => 'MusicBrainz', 'discogs' => 'Discogs', 'audiodb' => 'TheAudioDB'];

        return array_values(array_map(fn ($s) => $labels[$s], array_keys(array_filter(array_intersect_key($checks, $labels),
            fn ($r) => $r['status'] === 'ok' && in_array($r['match'], self::GOOD, true)))));
    }

    /** Ist der Teil verlässlich bekannt? MusicBrainz, zwei andere Dienste oder eigene Lieder */
    private static function reliable(array $checks, ?array $local): array
    {
        $sources = self::sources($checks);
        $own = $local ? $local['alone'] + $local['others'] : 0;
        $known = $own > 0 || in_array('MusicBrainz', $sources, true) || count($sources) >= 2;
        $why = $own > 0 ? $own.' eigene Lieder' : implode(', ', $sources);
        // geprüft: mindestens zwei Dienste haben geantwortet (nicht Fehler)
        $checked = count(array_filter($checks, fn ($r) => in_array($r['status'], ['ok', 'nicht_gefunden'], true))) >= 2;

        return ['known' => $known, 'checked' => $checked, 'why' => $why];
    }
}
