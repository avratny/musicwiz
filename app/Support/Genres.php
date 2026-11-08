<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\GenreMapping;
use App\Models\Track;

/**
 * Feste Genre-Liste: flach, Unterstile (z. B. bei Electronic) schreiben das Hauptgenre mit.
 */
class Genres
{
    private ?array $byName = null;

    /** @return array<string, ?string> Name => Hauptgenre (null bei Hauptgenres) */
    public function map(): array
    {
        if ($this->byName === null) {
            $all = Genre::with('parent')->get();
            $this->byName = [];
            foreach ($all as $g) {
                $this->byName[$g->name] = $g->parent?->name;
            }
        }

        return $this->byName;
    }

    /** Für Auswahllisten: [['name'=>..., 'label'=>..., 'parent'=>...], ...] in Listenreihenfolge. */
    public function options(): array
    {
        $out = [];
        foreach (Genre::whereNull('parent_id')->orderBy('position')->orderBy('name')->with('children')->get() as $main) {
            $out[] = ['name' => $main->name, 'label' => $main->name, 'parent' => null];
            foreach ($main->children as $child) {
                $out[] = ['name' => $child->name, 'label' => $main->name.' › '.$child->name, 'parent' => $main->name];
            }
        }

        return $out;
    }

    public function isListed(string $name): bool
    {
        return array_key_exists($name, $this->map());
    }

    /** Auswahl (höchstens zwei) in GENRE-Werte übersetzen: Unterstil -> [Hauptgenre, Unterstil]. */
    public function expand(array $selection): array
    {
        $map = $this->map();
        $out = [];
        foreach (array_slice(array_values(array_filter($selection)), 0, 2) as $name) {
            if (($map[$name] ?? null) !== null) {
                $out[] = $map[$name];
            }
            $out[] = $name;
        }

        return array_values(array_unique($out));
    }

    /** GENRE-Werte einer Datei zurück in die Auswahl übersetzen (Hauptgenre entfällt, wenn Unterstil dabei ist). */
    public function selection(array $genres): array
    {
        $map = $this->map();
        $listed = array_values(array_filter($genres, fn ($g) => array_key_exists($g, $map)));
        $parentsCovered = array_filter(array_map(fn ($g) => $map[$g], $listed));

        return array_values(array_slice(array_diff($listed, $parentsCovered), 0, 2)) ?: array_slice($listed, 0, 2);
    }

    /**
     * Standard-Genre eines Künstlers aus dem Bestand: häufigstes Listen-Genre seiner Lieder
     * (Unterstil vor Hauptgenre, fremde Werte über die Zuordnungstabelle).
     *
     * @return array{genre:string, count:int, total:int}|null
     */
    public function suggestForArtist(Artist $artist): ?array
    {
        $names = array_merge([$artist->name], $artist->aliases()->pluck('alias')->all());
        $keys = array_map([ArtistNames::class, 'compareKey'], $names);
        $base = $artist->baseName();
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($base, 0, 4)).'%';
        $counts = [];
        $total = 0;
        $tracks = Track::where('location', 'library')->where(fn ($q) => $q->where('artist', 'like', $like)->orWhere('albumartist', 'like', $like)->orWhere('artists', 'like', $like))
            ->get(['artist', 'artists', 'albumartist', 'albumartists', 'genres']);
        foreach ($tracks as $t) {
            // gleichnamige Künstler: nur Lieder, die dieser Person zugeordnet sind
            $candidates = $artist->base_name
                ? [...($t->artists ?? []), ...($t->albumartists ?? [])]
                : [...($t->artists ?: ArtistNames::split($t->artist)), ...($t->albumartists ?: ArtistNames::split($t->albumartist))];
            $hit = $artist->base_name
                ? in_array($artist->name, $candidates, true)
                : (bool) array_intersect(array_map([ArtistNames::class, 'compareKey'], $candidates), $keys);
            if (! $hit) {
                continue;
            }
            $total++;
            foreach ($this->suggest($t->genres ?? []) as $g) {
                $counts[$g] = ($counts[$g] ?? 0) + 1;
            }
        }
        if (! $counts) {
            return null;
        }
        arsort($counts);

        return ['genre' => (string) array_key_first($counts), 'count' => reset($counts), 'total' => $total];
    }

    /**
     * Genre-Angaben von MusicBrainz/Discogs auf die eigene Liste abbilden (Groß/klein egal, fremde Werte über die
     * Zuordnungstabelle). Bestes Listen-Genre = höchstes Gewicht, bei Gleichstand der Unterstil.
     *
     * @param  array<string, int|float>  $weights  Wert => Gewicht (z. B. Stimmen bzw. Anzahl Releases)
     * @return array{genre: ?string, tied: list<string>, mapped: array<string, string>, unmapped: list<string>}
     */
    public function fromExternal(array $weights): array
    {
        $listed = [];
        foreach (array_keys($this->map()) as $name) {
            $listed[mb_strtolower($name)] = $name;
        }
        $mappings = [];
        foreach (GenreMapping::whereNotNull('target')->pluck('target', 'source') as $src => $target) {
            $mappings[mb_strtolower($src)] = $target;
        }
        $scores = [];
        $mapped = [];
        $unmapped = [];
        arsort($weights);
        foreach ($weights as $value => $w) {
            $k = mb_strtolower(trim((string) $value));
            $target = $listed[$k] ?? $mappings[$k] ?? null;
            if ($target === null || ! $this->isListed($target)) {
                $unmapped[] = (string) $value;

                continue;
            }
            $mapped[(string) $value] = $target;
            $scores[$target] = ($scores[$target] ?? 0) + $w;
        }
        uksort($scores, fn ($a, $b) => [$scores[$b], $this->map()[$b] !== null] <=> [$scores[$a], $this->map()[$a] !== null]);
        $best = array_key_first($scores);
        // gleichauf (gleiches Gewicht, gleiche Ebene): nicht still einen wählen, sondern alle nennen
        $tied = $best === null ? [] : array_keys(array_filter($scores, fn ($s, $g) => $s === $scores[$best]
            && ($this->map()[$g] !== null) === ($this->map()[$best] !== null), ARRAY_FILTER_USE_BOTH));

        return ['genre' => $best, 'tied' => count($tied) > 1 ? $tied : [], 'mapped' => $mapped, 'unmapped' => $unmapped];
    }

    /** Vorschlag aus der Zuordnungstabelle für beliebige Genre-Werte. */
    public function suggest(array $values): array
    {
        $mappings = GenreMapping::whereIn('source', $values)->whereNotNull('target')->pluck('target', 'source');
        $out = [];
        foreach ($values as $v) {
            if ($this->isListed($v)) {
                $out[] = $v;
            } elseif (isset($mappings[$v])) {
                $out[] = $mappings[$v];
            }
        }

        return $this->selection($this->expand(array_values(array_unique($out))));
    }
}
