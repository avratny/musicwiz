<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use Illuminate\Support\Facades\DB;

/**
 * Ähnliche Interpreten finden (mögliche richtige Schreibweise): gleich bis auf Groß/klein, Akzente, Satzzeichen,
 * Artikel („The“) und „&/and/und“ – oder nur ein, zwei Buchstaben anders. Bestätigte zuerst, dann nach Liedern.
 * Mit den lokalen MusicBrainz-Daten zählt auch derselbe Eintrag dort („Puff Daddy“ = „P. Diddy“).
 * Als „verschieden“ markierte Paare (artist_distinct) gelten nie als ähnlich.
 */
class ArtistMatcher
{
    /** Stufen der Ähnlichkeit, sicherste zuerst */
    public const CASE = 0;

    public const CHARS = 1;

    /** derselbe Eintrag bei MusicBrainz (über die Schreibweisen dort – „Puff Daddy“ = „P. Diddy“) */
    public const MUSICBRAINZ = 2;

    public const TYPO = 3;

    public const LEVELS = [self::CASE => 'nur Groß/klein anders', self::CHARS => 'gleich bis auf Zeichen', self::MUSICBRAINZ => 'gleicher MusicBrainz-Eintrag',
        self::TYPO => 'ähnlich geschrieben'];

    /** Schlüssel-Vorsilbe für den MusicBrainz-Eintrag (zählt beim Gruppieren wie ein gleicher Name, nie als Tippfehler) */
    private const MB = 'mb:';

    /** @var array<int, array{id: int, name: string, confirmed: bool, keys: list<string>, tracks: int}>|null */
    private ?array $index = null;

    /** @var array<int, array{mbid: ?string, candidates: int}> MusicBrainz-Eintrag je Interpret */
    private array $identity = [];

    /** @var array<string, true>|null „kleinere-größere ID“ */
    private ?array $distinct = null;

    /**
     * @return list<array{artist: Artist, reason: string, tracks: int}>
     */
    public function similar(Artist $artist, int $limit = 6): array
    {
        $own = array_values(array_unique(array_filter(array_map([self::class, 'key'], $artist->spellings()))));
        if ($mbid = $this->identity($artist->id)['mbid']) {
            $own[] = self::MB.$mbid;
        }
        if (! $own) {
            return [];
        }
        $found = [];
        foreach ($this->index() as $candidate) {
            if ($candidate['id'] === $artist->id || $this->isDistinct($candidate['id'], $artist->id)) {
                continue;
            }
            $best = null;
            foreach ($candidate['keys'] as $key) {
                foreach ($own as $mine) {
                    if ($key === $mine) {
                        $best = [0, str_starts_with($key, self::MB) ? 'gleicher MusicBrainz-Eintrag'
                            : (mb_strtolower($candidate['name']) === mb_strtolower($artist->name) ? 'nur Groß/klein anders' : 'gleich bis auf Zeichen')];
                        break 2;
                    }
                    if (str_starts_with($key, self::MB) || str_starts_with($mine, self::MB)) {
                        continue;
                    }
                    $distance = self::typo($key, $mine);
                    if ($distance !== null && ($best === null || $distance < $best[0])) {
                        $best = [$distance, 'ähnlich geschrieben'];
                    }
                }
            }
            if ($best) {
                $found[] = ['id' => $candidate['id'], 'reason' => $best[1], 'rank' => [$best[0], ! $candidate['confirmed'], -$candidate['tracks']], 'tracks' => $candidate['tracks']];
            }
        }
        usort($found, fn ($a, $b) => $a['rank'] <=> $b['rank']);
        $found = array_slice($found, 0, $limit);
        $artists = Artist::whereIn('id', array_column($found, 'id'))->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($f) => isset($artists[$f['id']])
            ? ['artist' => $artists[$f['id']], 'reason' => $f['reason'], 'tracks' => $f['tracks']] : null, $found)));
    }

    /**
     * Alle Gruppen ähnlicher Interpreten („Interpreten › Ähnlich“). Gleicher Vergleichsschlüssel → eine Gruppe; dazu
     * kommen Tippfehler-Varianten, aber nur, wenn sie dem ersten (stärksten) Mitglied direkt ähneln – keine Ketten
     * („Moby“ → „Toby“ → „Tobi“). Ein als verschieden markiertes Paar landet nie in derselben Gruppe.
     * Reihenfolge: sicherste Stufe zuerst, dann nach Liedern; in der Gruppe der Vorschlag für den richtigen Namen
     * zuerst (bestätigt, dann die meisten Lieder).
     *
     * @return list<array{ids: list<int>, level: int, tracks: int}>
     */
    public function groups(): array
    {
        $index = array_column($this->index(), null, 'id');
        $rank = fn (int $id) => [! $index[$id]['confirmed'], -$index[$id]['tracks'], mb_strtolower($index[$id]['name']), $id];
        $byRank = fn (int $a, int $b) => $rank($a) <=> $rank($b);

        // 1. gleicher Schlüssel (auch über Schreibweisen): Gruppen bilden
        $byKey = [];
        foreach ($index as $id => $a) {
            foreach ($a['keys'] as $key) {
                $byKey[$key][] = $id;
            }
        }
        $clusters = [];   // Nummer => IDs
        $clusterOf = [];  // ID => Nummer
        foreach ($byKey as $ids) {
            foreach ($ids as $id) {
                if (! isset($clusterOf[$id])) {
                    $clusterOf[$id] = count($clusters);
                    $clusters[] = [$id];
                }
            }
            // stärkste zuerst; jeder kommt zum ersten, von dem er nicht als verschieden markiert ist
            usort($ids, $byRank);
            for ($i = 1; $i < count($ids); $i++) {
                for ($j = 0; $j < $i; $j++) {
                    [$a, $b] = [$clusterOf[$ids[$j]], $clusterOf[$ids[$i]]];
                    if ($a === $b) {
                        break;
                    }
                    if (! $this->anyDistinct($clusters[$a], $clusters[$b])) {
                        foreach ($clusters[$b] as $id) {
                            $clusterOf[$id] = $a;
                        }
                        $clusters[$a] = [...$clusters[$a], ...$clusters[$b]];
                        $clusters[$b] = [];
                        break;
                    }
                }
            }
        }
        $clusters = array_values(array_filter($clusters));
        foreach ($clusters as $c => $ids) {
            usort($ids, $byRank);
            $clusters[$c] = $ids;
        }
        usort($clusters, fn ($a, $b) => $byRank($a[0], $b[0]));
        $clusterOf = [];
        foreach ($clusters as $c => $ids) {
            foreach ($ids as $id) {
                $clusterOf[$id] = $c;
            }
        }

        // 2. Tippfehler: Kandidaten über gemeinsame Löschvarianten (ein, zwei Zeichen weg) statt jeder mit jedem.
        //    Gemerkt nur als Prüfsumme (spart Speicher) – eine zufällig gleiche Summe fällt beim Vergleich unten raus.
        $first = [];      // Prüfsumme => Nummer der ersten Gruppe
        $more = [];       // Prüfsumme => weitere Nummern
        $near = [];       // Nummer => [Nummer => true]
        foreach ($index as $id => $a) {
            $c = $clusterOf[$id];
            foreach ($a['keys'] as $key) {
                if (mb_strlen($key) < 3 || str_starts_with($key, self::MB)) {
                    continue;
                }
                foreach (self::deletions($key, strlen($key) >= 8 ? 2 : 1) as $v) {
                    $h = crc32($v);
                    if (! isset($first[$h])) {
                        $first[$h] = $c;

                        continue;
                    }
                    foreach ([$first[$h], ...($more[$h] ?? [])] as $other) {
                        if ($other !== $c) {
                            $near[$c][$other] = $near[$other][$c] = true;
                        }
                    }
                    if ($first[$h] !== $c && ! in_array($c, $more[$h] ?? [], true)) {
                        $more[$h][] = $c;
                    }
                }
            }
        }
        $text = fn (array $keys) => array_filter($keys, fn ($k) => ! str_starts_with($k, self::MB));
        $keysOf = fn (array $ids) => array_unique($text(array_merge(...array_map(fn ($id) => $index[$id]['keys'], $ids))));

        // 3. vom stärksten Mitglied aus: direkt ähnliche Gruppen dazunehmen
        $taken = [];
        $groups = [];
        foreach ($clusters as $c => $ids) {
            if (isset($taken[$c])) {
                continue;
            }
            $taken[$c] = true;
            $members = $ids;
            $typo = false;
            $centerKeys = $text($index[$ids[0]]['keys']);
            foreach (array_keys($near[$c] ?? []) as $other) {
                if (isset($taken[$other]) || $this->anyDistinct($members, $clusters[$other])) {
                    continue;
                }
                foreach ($keysOf($clusters[$other]) as $key) {
                    foreach ($centerKeys as $mine) {
                        if (self::typo($key, $mine) !== null) {
                            $taken[$other] = $typo = true;
                            $members = [...$members, ...$clusters[$other]];

                            continue 3;
                        }
                    }
                }
            }
            if (count($members) < 2) {
                continue;
            }
            usort($members, $byRank);
            $lower = array_unique(array_map(fn ($id) => mb_strtolower($index[$id]['name']), $members));
            $groups[] = [
                'ids' => $members,
                'level' => $typo ? self::TYPO : (count($lower) === 1 ? self::CASE
                    : (self::connected($members, fn ($id) => $text($index[$id]['keys'])) ? self::CHARS : self::MUSICBRAINZ)),
                'tracks' => array_sum(array_map(fn ($id) => $index[$id]['tracks'], $members)),
            ];
        }
        usort($groups, fn ($a, $b) => [$a['level'], -$a['tracks'], $a['ids'][0]] <=> [$b['level'], -$b['tracks'], $b['ids'][0]]);

        return $groups;
    }

    /** MusicBrainz-Eintrag eines Interpreten (null: unbekannt oder mehrdeutig; candidates: wie viele Einträge in Frage kamen) */
    public function identity(int $artistId): array
    {
        $this->index();

        return $this->identity[$artistId] ?? ['mbid' => null, 'candidates' => 0];
    }

    /** Hängen alle über gemeinsame Namens-Schlüssel zusammen (ohne MusicBrainz)? Sonst hat erst MusicBrainz sie verbunden. */
    private static function connected(array $ids, callable $keys): bool
    {
        $reached = [array_shift($ids) => true];
        $seen = array_flip($keys(array_key_first($reached)));
        do {
            $before = count($reached);
            foreach ($ids as $id) {
                if (! isset($reached[$id]) && array_intersect_key(array_flip($keys($id)), $seen)) {
                    $reached[$id] = true;
                    $seen += array_flip($keys($id));
                }
            }
        } while (count($reached) > $before);

        return count($reached) === count($ids) + 1;
    }

    /** Tippfehler-Abstand zweier Schlüssel, wenn sie als ähnlich gelten (ab 4 Zeichen 1 anders, ab 10 Zeichen 2), sonst null */
    public static function typo(string $a, string $b): ?int
    {
        $len = max(mb_strlen($a), mb_strlen($b));
        if ($a === $b || $len < 4 || abs(strlen($a) - strlen($b)) > 2) {
            return null;
        }
        $distance = levenshtein($a, $b);

        return $distance <= ($len >= 10 ? 2 : 1) ? $distance : null;
    }

    /** Der Schlüssel und alles, was mit bis zu $depth gelöschten Bytes daraus wird (levenshtein zählt Bytes) */
    private static function deletions(string $key, int $depth): array
    {
        $out = [$key => true];
        $level = [$key];
        for ($d = 0; $d < $depth; $d++) {
            $next = [];
            foreach ($level as $s) {
                for ($i = 0, $n = strlen($s); $i < $n; $i++) {
                    $v = substr($s, 0, $i).substr($s, $i + 1);
                    if (! isset($out[$v])) {
                        $out[$v] = true;
                        $next[] = $v;
                    }
                }
            }
            $level = $next;
        }

        return array_keys($out);
    }

    private function isDistinct(int $a, int $b): bool
    {
        if ($this->distinct === null) {
            $this->distinct = [];
            foreach (DB::table('artist_distinct')->get(['artist_id', 'other_id']) as $r) {
                $this->distinct[$r->artist_id.'-'.$r->other_id] = true;
            }
        }

        return isset($this->distinct[min($a, $b).'-'.max($a, $b)]);
    }

    private function anyDistinct(array $ids, array $others): bool
    {
        foreach ($ids as $a) {
            foreach ($others as $b) {
                if ($this->isDistinct($a, $b)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Typografische Varianten gleichsetzen (Bindestriche, Apostrophe, Anführungszeichen, Leerzeichen) – „a‐ha“ = „a-ha“ */
    public static function plain(string $name): string
    {
        $name = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $name);
        $name = preg_replace('/[\x{2018}\x{2019}\x{201B}\x{2032}`´]/u', "'", $name);
        $name = preg_replace('/[\x{201C}-\x{201F}]/u', '"', $name);

        return trim(preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}]+/u', ' ', $name));
    }

    /** Vergleichsschlüssel: klein, ohne Akzente, ohne Artikel, „&/and/und“ vereinheitlicht, nur Buchstaben und Ziffern */
    public static function key(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s);
        $s = preg_replace('/^(the|die|der|das)\s+|,\s*(the|die|der|das)$/u', '', $s);
        $s = preg_replace('/\s+(and|und|&|\+|n|\'n\')\s+/u', '&', $s);

        return preg_replace('/[^\p{L}\p{N}&]+/u', '', $s) ?? '';
    }

    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $tracks = DB::table('track_artist')->selectRaw('artist_id, COUNT(DISTINCT track_id) AS n')->groupBy('artist_id')->pluck('n', 'artist_id');
        $aliases = ArtistAlias::get(['artist_id', 'alias'])->groupBy('artist_id');
        $this->index = [];
        $artists = Artist::get(['id', 'name', 'confirmed', 'mbid']);
        $spellings = $artists->mapWithKeys(fn ($a) => [$a->id => [$a->name, ...($aliases[$a->id] ?? collect())->pluck('alias')->all()]]);
        // MusicBrainz-Eintrag je Interpret (lokale Daten; ohne sie leer)
        $this->identity = app(MusicBrainzData::class)->identify($artists->mapWithKeys(fn ($a) => [$a->id => ['mbid' => $a->mbid, 'spellings' => $spellings[$a->id]]])->all());
        foreach ($artists as $a) {
            $keys = array_values(array_unique(array_filter(array_map([self::class, 'key'], $spellings[$a->id]))));
            if ($mbid = $this->identity[$a->id]['mbid'] ?? null) {
                $keys[] = self::MB.$mbid;
            }
            $this->index[] = ['id' => $a->id, 'name' => $a->name, 'confirmed' => (bool) $a->confirmed, 'keys' => $keys, 'tracks' => (int) ($tracks[$a->id] ?? 0)];
        }

        return $this->index;
    }
}
