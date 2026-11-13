<?php

namespace App\Support;

use App\Models\Genre;
use App\Models\GenreIgnore;
use App\Models\GenreSynonym;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Genrebaum: Import, Pfade und die globale Zuordnung eines Genre-Werts (aus Tags oder Diensten) zu einem Knoten –
 * über Name und Synonyme, Groß/klein und Zeichen egal. Name schlägt Synonym; passt ein Synonym auf mehrere Knoten,
 * gewinnt der speziellere (tiefere).
 */
class GenreTree
{
    /** @var array<int, array{name: string, level: int, parent: ?int}>|null */
    private ?array $nodes = null;

    /** @var array<string, int>|null Vergleichsschlüssel => Genre-ID */
    private ?array $index = null;

    /** @var array<string, true>|null Vergleichsschlüssel zu breiter Werte */
    private ?array $ignored = null;

    /** @var list<array{0: int, 1: string, 2: string, 3: list<string>}>|null Namen und Synonyme mit Schlüssel und Wörtern (für suggest) */
    private ?array $names = null;

    /**
     * Startstand aus der CSV (Hauptgenre, Familie, Style, Ebene, Beatport-Genre, Discogs-Genre, Synonyme). Zeilen, deren
     * Hauptgenre in Wahrheit eine Familie ist („Ambient, Ambient, Dark Ambient“), werden der richtigen Familie
     * zugeordnet; doppelte Familien zusammengeführt.
     */
    public function importCsv(string $file): int
    {
        $handle = fopen($file, 'r');
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), fgetcsv($handle, escape: '\\'));
        $rows = [];
        while (($r = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($r) >= count($header)) {
                $rows[] = array_combine($header, array_slice($r, 0, count($header)));
            }
        }
        fclose($handle);

        $mains = array_values(array_unique(array_column(array_filter($rows, fn ($r) => $r['Ebene'] === '1'), 'Hauptgenre')));
        // Familie → Hauptgenre (aus den korrekten Zeilen), um falsch eingehängte Zeilen umzuhängen
        $familyMain = [];
        foreach ($rows as $r) {
            if (in_array($r['Hauptgenre'], $mains, true) && $r['Familie'] !== '') {
                $familyMain[$r['Familie']] ??= $r['Hauptgenre'];
            }
        }
        $ids = [];
        $position = 0;
        $node = function (?int $parent, string $name, int $level, array $r) use (&$ids, &$position): int {
            $key = ($parent ?? 0).'|'.mb_strtolower($name);
            if (! isset($ids[$key])) {
                $ids[$key] = Genre::create(['parent_id' => $parent, 'name' => $name, 'level' => $level, 'position' => $position++])->id;
            }
            $genre = Genre::find($ids[$key]);
            $genre->update(['beatport' => $genre->beatport ?: (trim($r['Beatport-Genre'] ?? '') ?: null), 'discogs' => $genre->discogs ?: (trim($r['Discogs-Genre'] ?? '') ?: null)]);
            foreach (explode(';', (string) ($r['Synonyme / Schreibweisen'] ?? '')) as $synonym) {
                $synonym = trim(preg_replace('/\s*\(als [^)]*\)/u', '', $synonym)); // „Electronica (als Sammelbegriff)“ → „Electronica“
                if ($synonym !== '' && mb_strtolower($synonym) !== mb_strtolower($name)) {
                    GenreSynonym::firstOrCreate(['genre_id' => $ids[$key], 'synonym' => mb_substr($synonym, 0, 128)]);
                }
            }

            return $ids[$key];
        };

        DB::transaction(function () use ($rows, $mains, $familyMain, $node) {
            foreach ($rows as $r) {
                $main = in_array($r['Hauptgenre'], $mains, true) ? $r['Hauptgenre'] : ($familyMain[$r['Familie'] ?: $r['Hauptgenre']] ?? null);
                if ($main === null) {
                    continue;
                }
                $mainId = $node(null, $main, 1, (int) $r['Ebene'] === 1 ? $r : []);
                // Ebene 2: Familie – oder, wenn die Familie leer ist, der Style direkt unter dem Hauptgenre
                $family = $r['Familie'] !== '' ? $r['Familie'] : ($r['Style'] !== '' && (int) $r['Ebene'] === 2 ? $r['Style'] : '');
                if ($family === '') {
                    continue;
                }
                $familyId = $node($mainId, $family, 2, (int) $r['Ebene'] === 2 ? $r : []);
                if ((int) $r['Ebene'] === 3 && $r['Style'] !== '') {
                    $node($familyId, $r['Style'], 3, $r);
                }
            }
        });
        $this->forget();

        return Genre::count();
    }

    /** Vergleichsschlüssel: klein, ohne Akzente, nur Buchstaben/Ziffern, „&/and/und“ gleich – „Hip-Hop“ = „hip hop“ */
    public static function key(string $value): string
    {
        $s = mb_strtolower(trim($value));
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s);
        $s = preg_replace('/\s+(and|und|n)\s+/u', '&', $s);

        return preg_replace('/[^\p{L}\p{N}&]+/u', '', $s) ?? '';
    }

    /** Einzelwerte aus einem Genre-Feld: nur „;“ trennt (so liest der Tag-Leser mehrere Genres) – „Folk, World, & Country“ bleibt eins */
    public static function split(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(';', (string) $value)), fn ($v) => $v !== ''));
    }

    /** Knoten zu einem Wert oder null (nicht zugeordnet – oder zu breit) */
    public function match(string $value): ?int
    {
        $this->load();

        return $this->isIgnored($value) ? null : ($this->index[self::key($value)] ?? null);
    }

    /** Zu breiter Wert („Rock/Pop“)? – wird aus den Liedern entfernt */
    public function isIgnored(string $value): bool
    {
        $this->ignored ??= array_fill_keys(GenreIgnore::pluck('value')->map(fn ($v) => self::key($v))->all(), true);

        return isset($this->ignored[self::key($value)]);
    }

    /**
     * Vorschläge für einen nicht zugeordneten Wert: fast gleiche Schreibweise, Enthaltensein („Soundtrack“ in
     * „Soundtrack & Bühne“), gemeinsame Wörter („Rock 'n' Roll / Rockabilly“ → Rockabilly).
     *
     * @return list<array{id: int, path: string, reason: string}>
     */
    public function suggest(string $value, int $limit = 3): array
    {
        $this->load();
        $key = self::key($value);
        $words = self::words($value);
        if ($key === '') {
            return [];
        }
        // einmal je Stand vorbereiten – bei 300 Werten auf der Seite sonst 300-mal alles neu
        if ($this->names === null) {
            $this->names = [];
            $all = [...array_map(fn ($id, $n) => [$id, $n['name']], array_keys($this->nodes), $this->nodes),
                ...GenreSynonym::get(['genre_id', 'synonym'])->map(fn ($s) => [$s->genre_id, $s->synonym])->all()];
            foreach ($all as [$id, $name]) {
                if (($k = self::key($name)) !== '') {
                    $this->names[] = [$id, $name, $k, self::words($name)];
                }
            }
        }
        $scores = [];
        foreach ($this->names as [$id, $name, $k, $theirs]) {
            $score = 0;
            $reason = '';
            $common = array_intersect($words, $theirs);
            $len = max(strlen($k), strlen($key));
            if (strlen($k) >= 5 && strlen($key) >= 5 && levenshtein($k, $key) <= ($len >= 10 ? 2 : 1)) {
                [$score, $reason] = [90, 'fast gleich geschrieben'];
            } elseif ($theirs && $common && count($common) === count($theirs)) {
                // das Genre steckt ganz im Wert („Rockabilly“ in „Rock 'n' Roll / Rockabilly“)
                [$score, $reason] = [60 + (int) (30 * count($theirs) / max(1, count($words))), '„'.$name.'“ steckt darin'];
            } elseif ($words && $common && count($common) === count($words)) {
                // der Wert steckt ganz im Genre („Soundtrack“ in „Soundtrack & Bühne“)
                [$score, $reason] = [60 + (int) (30 * count($words) / max(1, count($theirs))), 'Teil von „'.$name.'“'];
            } elseif ($common) {
                [$score, $reason] = [30 + (int) (30 * count($common) / max(count($words), count($theirs))), 'gemeinsames Wort „'.implode(', ', $common).'“'];
            }
            // bei gleicher Güte: spezifischer (tiefer) zuerst
            if ($score && $score + ($this->nodes[$id]['level'] ?? 0) > ($scores[$id]['score'] ?? 0)) {
                $scores[$id] = ['id' => $id, 'score' => $score + ($this->nodes[$id]['level'] ?? 0), 'reason' => $reason];
            }
        }
        usort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($s) => ['id' => $s['id'], 'path' => $this->path($s['id']), 'reason' => $s['reason']], array_slice($scores, 0, $limit));
    }

    /** Wörter ohne Füllwörter – auch einzelne Buchstaben zählen („K-Pop“ ist nicht „Pop“) */
    private static function words(string $value): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(\Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => ! in_array($w, ['and', 'und', 'n', 'the', 'der', 'die', 'das', 'music', 'musik'], true))));
    }

    /** „Electronic › Trance › Uplifting Trance“ */
    public function path(int $id): string
    {
        $this->load();
        $parts = [];
        for ($n = $this->nodes[$id] ?? null; $n; $n = $n['parent'] ? ($this->nodes[$n['parent']] ?? null) : null) {
            array_unshift($parts, $n['name']);
        }

        return implode(' › ', $parts);
    }

    /** Hauptgenre (Ebene 1) eines Knotens */
    public function root(int $id): ?int
    {
        $this->load();
        while (isset($this->nodes[$id]) && $this->nodes[$id]['parent']) {
            $id = $this->nodes[$id]['parent'];
        }

        return isset($this->nodes[$id]) ? $id : null;
    }

    /**
     * Neues Genre anlegen – unter $parentId (null = Hauptgenre), höchstens drei Ebenen.
     *
     * @throws InvalidArgumentException mit Meldung für die Oberfläche
     */
    public function create(string $name, ?int $parentId): Genre
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $parent = $parentId ? Genre::find($parentId) : null;
        $problem = match (true) {
            $name === '' => 'Bitte einen Namen eingeben.',
            $parentId && ! $parent => 'Übergeordnetes Genre nicht gefunden.',
            $parent && $parent->level >= 3 => 'Unter einem Style gibt es keine weitere Ebene.',
            Genre::where('parent_id', $parent?->id)->where('name', $name)->exists() => '„'.$name.'“ gibt es dort schon.',
            default => null,
        };
        if ($problem) {
            throw new InvalidArgumentException($problem);
        }
        $genre = Genre::create(['parent_id' => $parent?->id, 'name' => $name, 'level' => $parent ? $parent->level + 1 : 1,
            'position' => (int) Genre::where('parent_id', $parent?->id)->max('position') + 1]);
        $this->forget();

        return $genre;
    }

    public function forget(): void
    {
        $this->nodes = $this->index = $this->ignored = $this->names = null;
    }

    private function load(): void
    {
        if ($this->index !== null) {
            return;
        }
        $this->nodes = Genre::get(['id', 'name', 'level', 'parent_id'])
            ->mapWithKeys(fn ($g) => [$g->id => ['name' => $g->name, 'level' => (int) $g->level, 'parent' => $g->parent_id]])->all();
        $index = [];
        // Synonyme zuerst (bei mehreren Treffern der tiefere), dann Namen – Name schlägt Synonym
        $synonyms = GenreSynonym::get(['genre_id', 'synonym'])->sortBy(fn ($s) => $this->nodes[$s->genre_id]['level'] ?? 0);
        foreach ($synonyms as $s) {
            $index[self::key($s->synonym)] = $s->genre_id;
        }
        foreach (collect($this->nodes)->sortByDesc('level') as $id => $n) {
            $index[self::key($n['name'])] = $id;
        }
        unset($index['']);
        $this->index = $index;
    }
}
