<?php

namespace App\Support;

use App\Jobs\PrefetchArtistLookup;

/**
 * „Kombi mit &, und, and, Komma“: Vorschlag je Name aus dem, was schon da ist (Verzeichnis, Vorladen von MusicBrainz) –
 * ohne neue Abfragen.
 *  - Ein Künstler: genau ein MusicBrainz-Künstler mit genau diesem Namen hat eure Lieder („Simon & Garfunkel“).
 *  - Trennen: alle Teile stehen im Verzeichnis bzw. sind einzeln eindeutig bei MusicBrainz („Yanou & Liz“).
 * Alles andere bleibt ohne Vorschlag (von Hand entscheiden).
 */
class CombinedSuggestions
{
    public function __construct(private ArtistDirectory $directory, private ArtistQuickMatch $quick) {}

    /**
     * @param  list<string>  $names
     * @return array<string, array{kind: string, reason: string, parts: list<string>, hit: ?array}> nur Namen mit Vorschlag
     */
    public function for(array $names): array
    {
        $parts = [];
        foreach ($names as $name) {
            $p = array_values(array_unique(ArtistNames::suggestSplit($name)));
            if (count($p) >= 2) {
                $parts[$name] = $p;
            }
        }
        $results = PrefetchArtistLookup::results(array_values(array_unique([...$names, ...array_merge([], ...array_values($parts))])));
        $combis = app(CombiCheck::class)->cachedMany($names);
        $out = [];
        foreach ($names as $name) {
            [$kind, , $hit] = $this->quick->classify($name, $results[$name] ?? null);
            $combi = $combis[$name] ?? null;
            // laut Deezer/Spotify/MusicBrainz getrennte Künstler eingetragen → trennen (mit deren Schreibweise)
            if (($combi['verdict'] ?? null) === 'collab' && count($combi['parts']) >= 2) {
                $out[$name] = ['kind' => 'split', 'parts' => $combi['parts'], 'hit' => null, 'reason' => 'Zusammenarbeit laut Diensten – '.$combi['reason']];

                continue;
            }
            // ein Act: MusicBrainz kennt den Namen mit euren Liedern UND die Dienste tragen ihn als einen Künstler ein
            // (der MusicBrainz-Eintrag allein reicht nicht – dort haben auch Zusammenarbeiten wie „Beam & Yanou“ einen)
            if ($kind === 'sure' && ($combi['verdict'] ?? null) === 'act') {
                $out[$name] = ['kind' => 'artist', 'parts' => [], 'hit' => $hit,
                    'reason' => 'MusicBrainz kennt „'.$hit['name'].'“'.(! empty($hit['type']) ? ' ('.$hit['type'].')' : '').' mit eurem Lied „'.$hit['songs'][0].'“'
                        .(($combi['verdict'] ?? null) === 'act' ? ' · Act laut Diensten' : '')];

                continue;
            }
            if (! isset($parts[$name])) {
                continue;
            }
            $why = [];
            foreach ($parts[$name] as $part) {
                if (in_array($this->directory->check($part)['status'], ['ok', 'variant'], true)) {
                    $why[] = 'Verzeichnis';
                } elseif ($this->quick->classify($part, $results[$part] ?? null)[0] === 'sure') {
                    $why[] = 'MusicBrainz';
                } else {
                    continue 2; // ein Teil unbekannt: kein Vorschlag
                }
            }
            $out[$name] = ['kind' => 'split', 'parts' => $parts[$name], 'hit' => null,
                'reason' => 'alle Teile bekannt ('.implode(', ', array_unique($why)).')'];
        }

        return $out;
    }
}
