<?php

namespace App\Support;

use App\Models\GenreExemption;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sonstiges › Genre (Inbox und Lib): Genre fehlt, Genre weicht vom Interpreten ab, Album uneinheitlich.
 *
 * Einheiten: Interpreten-Album (ein Interpret auf mehr als der Hälfte der Lieder) – geprüft und gesetzt je Album;
 * Sampler (kein solcher Interpret) – je Lied, am eigenen Interpreten gemessen (Gäste zählen nicht); Einzeltitel
 * (einziges Lied seines Albums oder ohne Album-Tag) – je Lied.
 *
 * Genre des Interpreten: manuell zugeordnete Genres, sonst Dienste (artist_genre ohne „tags“) und die Tags seiner
 * übrigen Interpreten-Alben (ohne das geprüfte – sonst bestätigt sich ein Album selbst; Sampler zählen nicht).
 * Verglichen wird auf Hauptgenre-Ebene: Ein Hauptgenre zählt zum Interpreten ab dem Anteil aus Einstellungen ›
 * Sonstiges. Werte, die keinem Genre im Baum zugeordnet sind, gelten nicht als abweichend.
 *
 * Vorschläge: zuerst, was auf den übrigen Alben des Interpreten steht; sonst das speziellste Genre plus sein
 * Hauptgenre („Synthpop; Pop“). Übernommen wird nur in der Datenbank (TagEditor), „Passt so“ merkt die Einheit vor.
 */
class GenreChecks
{
    public const CHECKS = ['missing' => 'Genre fehlt', 'mismatch' => 'Genre weicht ab', 'mixed' => 'Album uneinheitlich'];

    public const KINDS = ['album' => 'Album', 'sampler' => 'Sampler', 'single' => 'Einzeltitel'];

    /** Prüfungen mit „Passt so“ */
    public const EXEMPTIBLE = ['mismatch', 'mixed'];

    private const REASONS = ['missing' => 'Genre fehlte', 'mismatch' => 'Genre passte nicht zum Interpreten', 'mixed' => 'Genre im Album angeglichen'];

    /** @var array<string, array>|null Schlüssel => Einheit */
    private ?array $units = null;

    /** @var array<int, array{manual: array<int, int>, services: array<int, int>}> */
    private array $nodes = [];

    /** @var array<int, array<string, array<string, int>>> Interpret => Album-Hash => Genre-Wert => Lieder */
    private array $albumTags = [];

    private array $profiles = [];

    private array $roots = [];

    /** Anteil, ab dem ein Hauptgenre zum Interpreten zählt (Einstellungen › Sonstiges) */
    private float $share = 0.15;

    public function __construct(private GenreTree $tree) {}

    public function forget(): void
    {
        $this->units = null;
        $this->nodes = $this->albumTags = $this->profiles = $this->roots = [];
        $this->tree->forget();
    }

    public function count(string $check): int
    {
        return $this->findings($check)->count();
    }

    /**
     * Befunde einer Prüfung, nach Ordner sortiert – oder die mit „Passt so“ ausgenommenen.
     *
     * @return Collection<string, array> Schlüssel der Einheit => Befund
     */
    public function findings(string $check, string $search = '', bool $exempted = false): Collection
    {
        $skip = in_array($check, self::EXEMPTIBLE, true) ? GenreExemption::where('check', $check)->pluck('unit')->flip() : collect();
        $needle = mb_strtolower(trim($search));
        $out = collect();
        foreach ($this->units() as $key => $unit) {
            if (isset($skip[$key]) !== $exempted || ($needle !== '' && ! str_contains($unit['search'], $needle))) {
                continue;
            }
            if ($finding = $this->check($check, $unit)) {
                $out[$key] = $finding;
            }
        }

        return $out->sortBy(fn ($f) => mb_strtolower($f['dir'].'|'.$f['title']));
    }

    /**
     * Genre übernehmen – für die Einheit (Album: betroffene Lieder) oder ein Lied darin.
     *
     * @return int Zahl der geänderten Lieder
     */
    public function apply(string $check, string $key, ?int $trackId, string $genre): int
    {
        $genre = implode('; ', GenreTree::split($genre));
        if ($genre === '') {
            throw new InvalidArgumentException('Bitte ein Genre angeben.');
        }
        $finding = $this->findings($check)[$key] ?? null;
        if (! $finding) {
            return 0;
        }
        $ids = $trackId === null ? $finding['ids'] : array_values(array_intersect([$trackId], array_column($finding['items'], 'id')));

        return $this->write($check, $ids, $genre);
    }

    /**
     * Jeweils den ersten Vorschlag übernehmen – eine Einheit bzw. alle (null); ohne Vorschlag bleibt alles, wie es ist.
     *
     * @return int Zahl der geänderten Lieder
     */
    public function applySuggested(string $check, ?string $key = null): int
    {
        $findings = $this->findings($check);
        $n = 0;
        foreach ($key === null ? $findings : $findings->only([$key]) as $f) {
            if ($f['items']) {
                foreach ($f['items'] as $item) {
                    $n += $item['suggestions'] ? $this->write($check, [$item['id']], $item['suggestions'][0]['value'], false) : 0;
                }
            } elseif ($f['suggestions']) {
                $n += $this->write($check, $f['ids'], $f['suggestions'][0]['value'], false);
            }
        }
        $this->forget();

        return $n;
    }

    /** „Passt so“ bzw. wieder aufnehmen */
    public function exempt(string $check, string $key, bool $exempt): void
    {
        if (! in_array($check, self::EXEMPTIBLE, true)) {
            return;
        }
        $exempt ? GenreExemption::firstOrCreate(['unit' => $key, 'check' => $check])
            : GenreExemption::where('unit', $key)->where('check', $check)->delete();
    }

    /** Wert zum Eintragen: Style bzw. Familie plus Hauptgenre („Synthpop; Pop“), ein Hauptgenre allein */
    public function value(int $genreId): string
    {
        $root = $this->tree->root($genreId);
        $name = $this->name($genreId);

        return $root === null || $root === $genreId ? $name : $name.'; '.$this->name($root);
    }

    /** alle Werte zum Auswählen (je Genre im Baum) */
    public function values(): array
    {
        $values = array_map(fn ($id) => $this->value((int) $id), DB::table('genres')->pluck('id')->all());
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_unique($values));
    }

    private function write(string $check, array $ids, string $genre, bool $forget = true): int
    {
        $editor = app(TagEditor::class);
        $n = 0;
        foreach (Track::whereIn('id', $ids)->get() as $track) {
            $n += $editor->update($track, ['genre' => $genre], TagEditor::MANUAL, self::REASONS[$check]) ? 1 : 0;
        }
        if ($forget) {
            $this->forget();
        }

        return $n;
    }

    /** Befund für eine Einheit oder null */
    private function check(string $check, array $unit): ?array
    {
        $tracks = $unit['tracks'];
        $blank = fn ($t) => trim((string) $t->genre) === '';
        if ($unit['kind'] === 'sampler') {
            if ($check === 'mixed') {
                return null;
            }
            $items = [];
            foreach ($tracks as $t) {
                if ($check === 'missing' ? $blank($t) : ! $blank($t) && $this->deviates((string) $t->genre, $this->profile($t->links, null))) {
                    $p = $this->profile($t->main, null);
                    $items[] = ['id' => $t->id, 'title' => (string) $t->title, 'artist' => (string) $t->artist, 'genre' => $t->genre,
                        'suggestions' => $this->without($p['suggestions'], (string) $t->genre), 'profile' => $p['label']];
                }
            }

            return $items ? $this->finding($unit, array_column($items, 'id'), [], '', $items) : null;
        }

        $values = $tracks->reject($blank)->pluck('genre')->countBy()->sortDesc();
        if ($check === 'missing') {
            $empty = $tracks->filter($blank);
            if ($empty->isEmpty()) {
                return null;
            }
            $own = $values->map(fn ($n) => ['reason' => 'wie '.$n.' '.($n === 1 ? 'anderes Lied' : 'andere Lieder').' im Album'])->all();
            $p = $this->profile($unit['main'], $unit['hash']);

            return $this->finding($unit, $empty->pluck('id')->all(), $this->merge($own, $p['suggestions']), $p['label']);
        }
        if ($values->isEmpty()) {
            return null;
        }
        if ($check === 'mixed') {
            if ($unit['kind'] !== 'album' || $values->count() < 2) {
                return null;
            }
            $p = $this->profile($unit['main'], $unit['hash']);
            // häufigster Wert zuerst; bei Gleichstand der, der zum Interpreten passt
            $ranked = $values->sortBy(fn ($n, $v) => [-$n, $this->deviates((string) $v, $p) ? 1 : 0])
                ->map(fn ($n) => ['reason' => 'auf '.$n.' '.($n === 1 ? 'Lied' : 'Liedern')])->all();

            return $this->finding($unit, $tracks->pluck('id')->all(), $this->merge($ranked, []), $p['label'], [], $values->all());
        }
        // mismatch: das (häufigste) Genre des Albums bzw. des Einzeltitels
        $current = (string) $values->keys()->first();
        $links = $unit['kind'] === 'single' ? $tracks->first()->links : $unit['main'];
        if (! $this->deviates($current, $this->profile($links, $unit['hash']))) {
            return null;
        }
        $p = $this->profile($unit['kind'] === 'single' ? $tracks->first()->main : $unit['main'], $unit['hash']);

        return $this->finding($unit, $tracks->pluck('id')->all(), $this->without($p['suggestions'], $current), $p['label'], [], $values->all());
    }

    private function finding(array $unit, array $ids, array $suggestions, string $profile, array $items = [], ?array $current = null): array
    {
        $first = $unit['tracks']->first();
        $current ??= $unit['tracks']->whereIn('id', $ids)->pluck('genre')->map(fn ($g) => trim((string) $g))->countBy()->all();
        // zum Reinhören (Player): Sampler nur die betroffenen Lieder, sonst alle der Einheit – mit Genre und Markierung
        $songs = ($items ? $unit['tracks']->whereIn('id', $ids) : $unit['tracks'])->values()->map(fn ($t) => [
            'id' => $t->id, 'title' => (string) ($t->title ?: pathinfo((string) $t->path, PATHINFO_FILENAME)), 'artist' => (string) $t->artist,
            'cover' => $t->id, 'duration' => $t->duration, 'genre' => (string) $t->genre, 'affected' => in_array($t->id, $ids, true),
        ])->all();

        return [
            'key' => $unit['key'],
            'kind' => $unit['kind'],
            'location' => $first->location,
            'dir' => ltrim(mb_substr((string) $first->album_dir, mb_strlen(Locations::root($first->location))), '/'),
            'title' => $unit['kind'] === 'single' ? (string) $first->title : (string) $unit['album'],
            'album' => (string) $unit['album'],
            'artist' => $unit['kind'] === 'single' ? (string) $first->artist : (string) $unit['albumartist'],
            'cover' => $unit['tracks']->firstWhere('has_cover', true)?->id ?? $first->id,
            'tracks' => $unit['tracks']->count(),
            'ids' => array_values($ids),
            'current' => $current,
            'suggestions' => array_slice($suggestions, 0, 3),
            'profile' => $profile,
            'items' => $items,
            'songs' => $songs,
        ];
    }

    /** Weicht ein Genre-Wert vom Interpreten ab? Nur, wenn beide Seiten im Baum bekannt sind. */
    private function deviates(string $genre, array $profile): bool
    {
        $roots = $this->rootsOf($genre);

        return $roots && $profile['allowed'] && ! array_intersect($roots, $profile['allowed']);
    }

    /** @param  array<string, array{reason: string}>  $first  Wert => Grund */
    private function merge(array $first, array $suggestions): array
    {
        $out = [];
        foreach ($first as $value => $s) {
            $out[GenreTree::key((string) $value)] ??= ['value' => (string) $value, 'reason' => $s['reason']];
        }
        foreach ($suggestions as $s) {
            $out[GenreTree::key($s['value'])] ??= $s;
        }

        return array_values($out);
    }

    private function without(array $suggestions, string $current): array
    {
        return array_values(array_filter($suggestions, fn ($s) => GenreTree::key($s['value']) !== GenreTree::key($current)));
    }

    /**
     * Genre der Interpreten (ohne die Tags eines Albums): erlaubte Hauptgenres, Vorschläge, Beschriftung.
     *
     * @return array{allowed: list<int>, suggestions: list<array{value: string, reason: string}>, label: string}
     */
    private function profile(array $artistIds, ?string $exclude): array
    {
        sort($artistIds);
        $cacheKey = implode(',', $artistIds).'|'.$exclude;
        if (isset($this->profiles[$cacheKey])) {
            return $this->profiles[$cacheKey];
        }
        $manual = $services = $tags = [];
        foreach ($artistIds as $id) {
            foreach ($this->nodes[$id]['manual'] ?? [] as $g => $w) {
                $manual[$g] = ($manual[$g] ?? 0) + $w;
            }
            foreach ($this->nodes[$id]['services'] ?? [] as $g => $w) {
                $services[$g] = ($services[$g] ?? 0) + $w;
            }
            foreach ($this->albumTags[$id] ?? [] as $hash => $values) {
                if ($hash !== $exclude) {
                    foreach ($values as $v => $n) {
                        $tags[$v] = ($tags[$v] ?? 0) + $n;
                    }
                }
            }
        }
        $byRoot = function (array $nodes) {
            $w = [];
            foreach ($nodes as $g => $n) {
                if ($root = $this->tree->root((int) $g)) {
                    $w[$root] = ($w[$root] ?? 0) + $n;
                }
            }

            return $w;
        };
        $tagRoots = [];
        foreach ($tags as $v => $n) {
            foreach ($this->rootsOf((string) $v) as $root) {
                $tagRoots[$root] = ($tagRoots[$root] ?? 0) + $n;
            }
        }
        $shares = function (array $w) {
            $total = array_sum($w);

            return $total > 0 ? array_map(fn ($n) => $n / $total, $w) : [];
        };
        if ($manual) {
            $weights = $shares($byRoot($manual));
            $allowed = array_keys($weights);
        } else {
            $s = $shares($byRoot($services));
            $t = $shares($tagRoots);
            $weights = [];
            foreach ([$s, $t] as $part) {
                foreach ($part as $root => $x) {
                    $weights[$root] = ($weights[$root] ?? 0) + $x / (($s && $t) ? 2 : 1);
                }
            }
            $allowed = array_keys(array_filter($s, fn ($x) => $x >= $this->share) + array_filter($t, fn ($x) => $x >= $this->share));
        }
        arsort($weights);

        // Vorschläge: Tags der übrigen Alben, dann das speziellste Genre je Hauptgenre aus manuell bzw. Diensten
        $suggestions = [];
        arsort($tags);
        foreach ($tags as $v => $n) {
            if (array_intersect($this->rootsOf((string) $v), $allowed)) {
                $suggestions[GenreTree::key((string) $v)] ??= ['value' => (string) $v, 'reason' => 'auf anderen Alben ('.$n.' '.($n === 1 ? 'Lied' : 'Lieder').')'];
            }
        }
        $nodes = $manual ?: $services;
        foreach (array_keys($weights) as $root) {
            if (! in_array($root, $allowed, true)) {
                continue;
            }
            $best = null;
            foreach ($nodes as $g => $w) {
                if ($this->tree->root((int) $g) !== $root) {
                    continue;
                }
                $rank = [$g === $root ? 0 : 1, $w, $this->level((int) $g)];
                if ($best === null || $rank > $best[1]) {
                    $best = [(int) $g, $rank];
                }
            }
            if ($best) {
                $v = $this->value($best[0]);
                $suggestions[GenreTree::key($v)] ??= ['value' => $v, 'reason' => $manual ? 'manuell zugeordnet' : 'laut Diensten'];
            }
        }
        $label = implode(' · ', array_map(fn ($root) => $this->name($root).' '.round($weights[$root] * 100).' %', array_slice(array_keys($weights), 0, 4)));
        if ($label !== '') {
            $label .= ' ('.($manual ? 'manuell' : implode(', ', array_filter([$services ? 'Dienste' : null, $tags ? 'andere Alben' : null]))).')';
        }

        return $this->profiles[$cacheKey] = ['allowed' => $allowed, 'suggestions' => array_values($suggestions), 'label' => $label];
    }

    /** Hauptgenres eines Genre-Felds (nur zugeordnete Werte) */
    private function rootsOf(string $genre): array
    {
        if (! isset($this->roots[$genre])) {
            $roots = [];
            foreach (GenreTree::split($genre) as $v) {
                if (($id = $this->tree->match($v)) && ($root = $this->tree->root($id))) {
                    $roots[$root] = $root;
                }
            }
            $this->roots[$genre] = array_values($roots);
        }

        return $this->roots[$genre];
    }

    private function name(int $id): string
    {
        $path = explode(' › ', $this->tree->path($id));

        return (string) end($path);
    }

    private function level(int $id): int
    {
        return count(explode(' › ', $this->tree->path($id)));
    }

    /** Einheiten aus Inbox und Lib (Alben, Sampler, Einzeltitel) samt Genres der Interpreten */
    private function units(): array
    {
        if ($this->units !== null) {
            return $this->units;
        }
        $this->tree->forget();
        $this->share = app(Settings::class)->other('genre_share') / 100;
        $tracks = DB::table('tracks')->whereIn('location', SplitAlbums::LOCATIONS)
            ->get(['id', 'location', 'album_hash', 'album_dir', 'album', 'albumartist', 'artist', 'title', 'genre', 'has_cover', 'disc', 'track', 'path', 'duration']);
        $links = [];
        foreach (DB::table('track_artist')->join('tracks', 'tracks.id', '=', 'track_artist.track_id')->whereIn('tracks.location', SplitAlbums::LOCATIONS)
            ->where('track_artist.role', 'artist')->get(['track_artist.track_id', 'track_artist.artist_id']) as $l) {
            $links[$l->track_id][] = (int) $l->artist_id;
        }
        $names = DB::table('artists')->pluck('name', 'id');
        foreach ($tracks as $t) {
            $t->links = array_values(array_unique($links[$t->id] ?? []));
            // Hauptinterpret(en): die verbundenen, die vor „feat.“ stehen – Gäste zählen nicht
            $lead = mb_strtolower(ArtistCredits::splitGuest((string) $t->artist)[0]);
            $main = array_values(array_filter($t->links, fn ($id) => isset($names[$id]) && str_contains($lead, mb_strtolower($names[$id]))));
            $t->main = $main ?: $t->links;
        }

        $units = [];
        $unit = function (string $key, string $kind, Collection $tracks, array $main, string $hash) use (&$units) {
            $mode = fn (string $f) => (string) $tracks->pluck($f)->filter()->countBy()->sortDesc()->keys()->first();
            $units[$key] = ['key' => $key, 'kind' => $kind, 'hash' => $hash, 'tracks' => $tracks, 'main' => $main,
                'album' => $mode('album'), 'albumartist' => $mode('albumartist') ?: $mode('artist'),
                'search' => mb_strtolower($tracks->map(fn ($t) => implode('|', [$t->album, $t->albumartist, $t->artist, $t->title, $t->genre, $t->album_dir]))->implode('|'))];
        };
        foreach ($tracks->groupBy('album_hash') as $hash => $group) {
            [$loose, $rest] = $group->partition(fn ($t) => trim((string) $t->album) === '');
            if ($rest->count() === 1) {
                $loose->push($rest->first());
                $rest = collect();
            }
            foreach ($loose as $t) {
                $unit('t'.$t->id, 'single', collect([$t]), $t->main, ''); // Einzeltitel: keine eigenen Album-Tags auszuschließen
            }
            if ($rest->isEmpty()) {
                continue;
            }
            $counts = [];
            foreach ($rest as $t) {
                foreach ($t->links as $id) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
            $main = array_keys(array_filter($counts, fn ($n) => $n * 2 > $rest->count()));
            $kind = ! $main && count($counts) >= 2 ? 'sampler' : 'album';
            $unit((string) $hash, $kind, $rest->sortBy(fn ($t) => [(int) $t->disc, (int) $t->track, $t->path])->values(), $main, (string) $hash);
            // Tags der Interpreten-Alben je Hauptinterpret
            if ($kind === 'album') {
                foreach ($rest as $t) {
                    if (trim((string) $t->genre) !== '') {
                        foreach ($main as $id) {
                            $this->albumTags[$id][$hash][$t->genre] = ($this->albumTags[$id][$hash][$t->genre] ?? 0) + 1;
                        }
                    }
                }
            }
        }
        foreach (DB::table('artist_genre')->where('source', '!=', 'tags')->get(['artist_id', 'genre_id', 'source', 'weight']) as $r) {
            $part = $r->source === 'manual' ? 'manual' : 'services';
            $this->nodes[$r->artist_id][$part][$r->genre_id] = ($this->nodes[$r->artist_id][$part][$r->genre_id] ?? 0) + max(1, (int) $r->weight);
        }

        return $this->units = $units;
    }
}
