<?php

namespace App\Support;

use App\Models\FilePlan;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Duplikate (Inbox und Lib): gleicher Interpret, gleicher Titel (ohne Groß/klein, Satz- und Leerzeichen), Länge
 * höchstens 2 s verschieden. Zwei Regeln:
 * - Alben werden nie zerrissen: Jedes Album behält seinen Titel. Dasselbe Lied auf Single und Best-of ist gewollt.
 * - Die bessere Datei gewinnt (intakt, verlustfrei, dann Bittiefe/Abtastrate bzw. Bitrate): Ist eine Fassung besser
 *   als die in einem Album, ersetzt sie dort das Lied – kommt mit dessen Tags an dessen Platz (FilePlan mit
 *   replaces_track_id), das ersetzte wandert in den Papierkorb. Lose Kopien („Kein Album“, ohne Album) und zweite
 *   Kopien im selben Album wandern in den Papierkorb (move_to = trash), sobald eine mindestens gleich gute bleibt.
 * Gruppen ohne Handlungsbedarf (alle gleich gut, jedes in seinem Album) sind kein Fund. Alles nur vorgemerkt –
 * ausgeführt wird erst beim Schreiben. „Kein Duplikat“ gilt je Paar von Liedern (duplicate_exemption_pairs): solche
 * Lieder kommen nie in dieselbe Gruppe – auch nicht, wenn später eine weitere Fassung dazukommt.
 * Ersetzen über Albumgrenzen braucht mehr als Titel und Länge (sonst träfe es „Intro“ zweier Alben):
 * - akustischer Fingerabdruck gleich (Fingerprints::MATCH): ersetzen – auch über „Alle vormerken“
 * - Fingerabdruck klar verschieden (unter Fingerprints::DIFFERENT): kein Duplikat (wird als Paar ausgenommen)
 * - sonst nur bei höchstens 1 s Unterschied und keinem allgemeinen Titel (Intro, Outro …) – und nur einzeln vormerkbar
 */
class Duplicates
{
    /** Länge darf so viele Sekunden abweichen */
    public const TOLERANCE = 2.0;

    /** Ersetzen über Albumgrenzen ohne Fingerabdruck: Länge höchstens so viele Sekunden verschieden */
    public const REPLACE_TOLERANCE = 1.0;

    /** Titel, die auf vielen Alben vorkommen und verschiedene Stücke sind (Einstellungen › Sonstiges, Zahlen am Ende zählen nicht) */
    public const GENERIC_TITLES = 'Intro, Outro, Interlude, Skit, Prelude, Reprise, Untitled, Hidden Track, Bonus Track, Track, Unbenannt, Titel';

    /** @var array<string, ?float> Ähnlichkeit der Fingerabdrücke je Paar „kleinere-größere ID“ */
    private array $scores = [];

    /** Tags, die eine ersetzende Fassung vom ersetzten Lied übernimmt */
    public const TAKEN_FIELDS = ['title', 'artist', 'artists', 'album', 'albumartist', 'albumartists', 'artistsort', 'albumartistsort',
        'track', 'track_total', 'disc', 'disc_total', 'year', 'releasedate', 'mb_albumid', 'genre', 'compilation', 'category'];

    private const COLUMNS = ['id', 'location', 'path', 'dir', 'album_dir', 'album_hash', 'filename', 'ext', 'format', 'duration', 'bitrate',
        'sample_rate', 'bits', 'error', 'has_cover', 'title', 'artist', 'album', 'albumartist', 'track', 'disc', 'move_to'];

    private ?Collection $groups = null;

    /** @var array<string, bool> Album-Hash => Einzeltitel-Ordner (Albums::loose) */
    private array $looseAlbums = [];

    /** Gruppen gleicher Lieder (mindestens zwei, ohne ausgenommene Paare), Schlüssel = sha1 der sortierten IDs */
    public function groups(bool $retry = false): Collection
    {
        if ($this->groups !== null) {
            return $this->groups;
        }
        $title = function (string $s): string {
            $s = \Normalizer::normalize(mb_strtolower($s), \Normalizer::FORM_D) ?: mb_strtolower($s);

            return preg_replace('/[^\p{L}\p{N}]+/u', '', preg_replace('/\p{Mn}+/u', '', $s));
        };
        $out = collect();
        $exempt = $this->exemptPairs();
        Track::whereIn('location', SplitAlbums::LOCATIONS)->where('title', '<>', '')->where('artist', '<>', '')->whereNotNull('duration')
            ->get(self::COLUMNS)
            ->groupBy(fn (Track $t) => ArtistMatcher::key($t->artist).'|'.$title($t->title))
            ->filter(fn ($c) => $c->count() > 1)
            ->each(function (Collection $same) use ($out, $exempt) {
                // nach Länge sortiert: neue Gruppe, sobald ein Lied zu weit vom ersten der Gruppe abweicht
                $cluster = [];
                foreach ($same->sortBy('duration')->values()->push(null) as $t) {
                    if ($cluster && ($t === null || $t->duration - $cluster[0]->duration > self::TOLERANCE)) {
                        foreach ($this->separate($cluster, $exempt) as $group) {
                            if (count($group) > 1) {
                                $out[self::key($group)] = collect($group);
                            }
                        }
                        $cluster = [];
                    }
                    if ($t !== null) {
                        $cluster[] = $t;
                    }
                }
            });

        // klar verschiedene Fingerabdrücke: kein Duplikat – als Paar ausnehmen und die Gruppen neu bilden
        $pairs = $out->flatMap(fn (Collection $g) => collect(self::pairs($g->pluck('id')->all()))->map(fn ($p) => [$p['track_a'], $p['track_b']]))->all();
        $this->scores = app(Fingerprints::class)->scores($pairs);
        $different = array_keys(array_filter($this->scores, fn ($s) => $s !== null && $s < Fingerprints::DIFFERENT));
        if ($different && ! $retry) {
            DB::table('duplicate_exemption_pairs')->insertOrIgnore(array_map(fn ($k) => array_combine(['track_a', 'track_b'], array_map('intval', explode('-', $k))), $different));

            return $this->groups(true);
        }
        $this->looseAlbums = Albums::looseHashes($out->flatten(1)->pluck('album_hash')) + $this->looseAlbums;

        return $this->groups = $out;
    }

    /** @return array<string, true> ausgenommene Paare „kleinere-größere ID“ */
    private function exemptPairs(): array
    {
        return DB::table('duplicate_exemption_pairs')->get(['track_a', 'track_b'])->mapWithKeys(fn ($p) => [$p->track_a.'-'.$p->track_b => true])->all();
    }

    /**
     * Ausgenommene Paare trennen: jedes Lied kommt in die Teilgruppe ohne „kein Duplikat“ mit ihm, deren erstes Lied ihm
     * in der Länge am nächsten ist – sonst in eine neue.
     *
     * @param  list<Track>  $cluster
     * @return list<list<Track>>
     */
    private function separate(array $cluster, array $exempt): array
    {
        if (! $exempt) {
            return [$cluster];
        }
        $groups = [];
        foreach ($cluster as $t) {
            $best = null;
            foreach ($groups as $i => $group) {
                $fits = collect($group)->every(fn (Track $o) => ! isset($exempt[min($o->id, $t->id).'-'.max($o->id, $t->id)]));
                if ($fits && ($best === null || abs($t->duration - $group[0]->duration) < abs($t->duration - $groups[$best][0]->duration))) {
                    $best = $i;
                }
            }
            $best === null ? $groups[] = [$t] : $groups[$best][] = $t;
        }

        return $groups;
    }

    /** @param  iterable<Track>  $tracks */
    private static function key(iterable $tracks): string
    {
        return sha1(collect($tracks)->pluck('id')->sort()->implode(','));
    }

    /** alle Paare (kleinere ID zuerst) aus diesen Liedern – als Zeilen für duplicate_exemption_pairs */
    public static function pairs(array $ids): array
    {
        sort($ids);
        $rows = [];
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $rows[] = ['track_a' => $a, 'track_b' => $b];
            }
        }

        return $rows;
    }

    /**
     * Ausgenommene: Lieder, die über „kein Duplikat“-Paare zusammenhängen (zum Wieder-Aufnehmen).
     *
     * @return Collection<string, Collection<int, Track>>
     */
    private function exemptedGroups(): Collection
    {
        $pairs = DB::table('duplicate_exemption_pairs')->get(['track_a', 'track_b']);
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            return ($parent[$x] ??= $x) === $x ? $x : $parent[$x] = $find($parent[$x]);
        };
        foreach ($pairs as $p) {
            $parent[$find($p->track_a)] = $find($p->track_b);
        }
        $tracks = Track::whereIn('id', array_keys($parent))->get(self::COLUMNS)->keyBy('id');
        $this->looseAlbums += Albums::looseHashes($tracks->pluck('album_hash'));

        return $tracks->groupBy(fn (Track $t) => $find($t->id))->filter(fn ($c) => $c->count() > 1)
            ->mapWithKeys(fn (Collection $c) => [self::key($c) => $c->values()]);
    }

    /**
     * Darf $a das Lied $b in einem anderen Album ersetzen? true = Fingerabdruck gleich (geprüft), false = ungeprüft, aber
     * nach Regel (Länge ±1 s, kein allgemeiner Titel), null = nein.
     */
    private function sameRecording(Track $a, Track $b): ?bool
    {
        $score = $this->scores[min($a->id, $b->id).'-'.max($a->id, $b->id)] ?? null;
        if ($score !== null && $score >= Fingerprints::MATCH) {
            return true;
        }
        if ($score !== null && $score < Fingerprints::DIFFERENT) {
            return null;
        }

        return abs($a->duration - $b->duration) <= self::REPLACE_TOLERANCE && ! self::generic($a->title) ? false : null;
    }

    /** allgemeiner Titel („Intro“, „Interlude 2“) – kommt auf vielen Alben vor */
    public static function generic(string $title): bool
    {
        $key = fn (string $s) => preg_replace('/\d+$/', '', preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)));
        $list = (string) app(Settings::class)->get('generic_titles', self::GENERIC_TITLES);

        return in_array($key($title), array_map($key, explode(',', $list)), true);
    }

    /** lose Kopie = liegt in einem Einzeltitel-Ordner (Albums::loose) */
    private function isLoose(Track $t): bool
    {
        return $this->looseAlbums[$t->album_hash] ?? false;
    }

    /**
     * Funde mit Plan, nach Interpret und Titel sortiert.
     *
     * @return Collection<string, array>
     */
    public function findings(string $search = '', bool $exempted = false): Collection
    {
        $plans = FilePlan::whereNotNull('replaces_track_id')->get(['track_id', 'replaces_track_id', 'target_album_hash']);
        $needle = mb_strtolower(trim($search));

        return ($exempted ? $this->exemptedGroups() : $this->groups())
            ->filter(fn ($tracks) => $needle === '' || $tracks->contains(fn ($t) => str_contains(mb_strtolower($t->artist.' '.$t->title.' '.$t->path), $needle)))
            ->map(fn ($tracks, $key) => $this->plan($key, $tracks, $plans))
            ->filter(fn ($f) => $f['actions'] > 0)
            ->sortBy(fn ($f) => mb_strtolower($f['artist'].' '.$f['title']));
    }

    /** offene Funde (noch nicht vorgemerkt, nicht ausgenommen) */
    public function count(): int
    {
        return $this->findings()->where('planned', false)->count();
    }

    /**
     * Plan übernehmen (vormerken) – eine Gruppe oder alle offenen. Bei „alle“ wird über Albumgrenzen nur ersetzt, was
     * der Fingerabdruck bestätigt; Ungeprüftes nur einzeln. @return int Zahl der Gruppen
     */
    public function apply(?string $key = null): int
    {
        $findings = $this->findings()->where('planned', false)->when($key !== null, fn ($c) => $c->only([$key]));
        DB::transaction(function () use ($findings, $key) {
            foreach ($findings as $f) {
                foreach ($f['members'] as $m) {
                    if ($m['role'] === 'trash') {
                        Track::whereKey($m['track']->id)->update(['move_to' => 'trash']);
                    } elseif ($m['role'] === 'replaced' && ($key !== null || $m['verified'])) {
                        $this->replace($m['by'], $m['track']);
                    }
                }
            }
        });
        $this->groups = null;

        return $findings->count();
    }

    /** Vormerkung einer Gruppe zurücknehmen */
    public function revert(string $key): void
    {
        $ids = $this->groups()->get($key)?->pluck('id')->all() ?? [];
        DB::transaction(function () use ($ids) {
            Track::whereIn('id', $ids)->where('move_to', 'trash')->update(['move_to' => null]);
            FilePlan::whereIn('replaces_track_id', $ids)->delete();
        });
        $this->groups = null;
    }

    /**
     * Gruppe als „kein Duplikat“ ausnehmen (je Paar – eine Vormerkung wird dabei zurückgenommen) bzw. ausgenommene
     * Lieder wieder aufnehmen.
     */
    public function exempt(string $key, bool $exempt = true): void
    {
        if ($exempt) {
            $ids = $this->groups()->get($key)?->pluck('id')->all() ?? [];
            $this->revert($key);
            DB::table('duplicate_exemption_pairs')->insertOrIgnore(self::pairs($ids));
        } else {
            $ids = $this->exemptedGroups()->get($key)?->pluck('id')->all() ?? [];
            DB::table('duplicate_exemption_pairs')->whereIn('track_a', $ids)->whereIn('track_b', $ids)->delete();
        }
        $this->groups = null;
    }

    /** Rangfolge ohne Dateizugriff: intakt, FLAC vor MP3, Bittiefe/Abtastrate bzw. Bitrate (16-kbit/s-Stufen gegen VBR-Rauschen) */
    public static function rank(Track $t): array
    {
        $lossless = strtolower($t->ext) === 'flac';

        return [$t->error === null && $t->duration >= 0.1 ? 1 : 0, $lossless ? 1 : 0,
            $lossless ? (int) $t->bits * 1000 + intdiv((int) $t->sample_rate, 100) : intdiv((int) $t->bitrate, 16000)];
    }

    /**
     * Wer bleibt, wer geht: je Album-Platz (CD + Nummer, sonst Dateiname ohne „(2)“) die beste Fassung; die beste
     * überhaupt ersetzt schlechtere Album-Fassungen (nur in anderen Alben mit genau einem Platz für das Lied); lose
     * Kopien gehen, wenn eine mindestens gleich gute bleibt – sonst bleibt die beste lose.
     */
    private function plan(string $key, Collection $tracks, Collection $plans): array
    {
        $better = fn (Track $a, Track $b) => self::rank($a) <=> self::rank($b);
        // bei gleicher Qualität: Album vor lose, Lib vor Inbox, ohne „(2)“, kürzerer Pfad
        $order = fn (Track $t) => [self::rank($t), $this->isLoose($t) ? 0 : 1, $t->location === 'library' ? 1 : 0,
            preg_match('/\(\d\)\.\w+$/', $t->filename) ? 0 : 1, -mb_strlen($t->path)];
        $best = fn (Collection $c) => $c->sort(fn ($a, $b) => $order($b) <=> $order($a))->first();
        $position = fn (Track $t) => (int) $t->track > 0 ? 'n'.max(1, (int) $t->disc).'-'.(int) $t->track
            : 'f'.mb_strtolower(preg_replace('/\s*\(\d\)$/', '', pathinfo($t->filename, PATHINFO_FILENAME)));

        $roles = [];
        $inAlbum = $tracks->reject(fn ($t) => $this->isLoose($t));
        $loose = $tracks->filter(fn ($t) => $this->isLoose($t));
        $slots = collect();
        foreach ($inAlbum->groupBy('album_hash') as $hash => $members) {
            foreach ($members->groupBy($position) as $same) {
                $keep = $best($same);
                $slots->push($keep);
                foreach ($same as $t) {
                    $roles[$t->id] = $t->id === $keep->id ? ['role' => 'keep'] : ['role' => 'trash', 'reason' => 'zweite Kopie im selben Album'];
                }
            }
        }
        $winner = $best($slots->merge($loose));
        $slotsPerAlbum = $slots->countBy('album_hash');
        $moved = false;
        foreach ($slots as $s) {
            if ($s->album_hash !== $winner->album_hash && $slotsPerAlbum[$s->album_hash] === 1 && $better($winner, $s) > 0
                && ($verified = $this->sameRecording($winner, $s)) !== null) {
                $roles[$s->id] = ['role' => 'replaced', 'by' => $winner, 'verified' => $verified];
                $moved = true;
            }
        }
        foreach ($loose as $t) {
            if ($t->id === $winner->id) {
                $roles[$t->id] = $moved ? ['role' => 'moves'] : ['role' => 'keep'];
            } else {
                $roles[$t->id] = ['role' => 'trash', 'reason' => $slots->isEmpty() ? 'lose Kopie, eine bessere bzw. gleich gute bleibt' : 'lose Kopie, das Lied ist im Album'];
            }
        }
        if ($moved && ! $this->isLoose($winner)) {
            $roles[$winner->id] = ['role' => 'copies'];
        }

        $members = $tracks->sortByDesc($order)->values()->map(fn (Track $t) => ['track' => $t, ...$roles[$t->id]]);
        $actions = $members->whereIn('role', ['trash', 'replaced'])->count();
        $planned = $actions > 0 && $members->every(fn ($m) => match ($m['role']) {
            'trash' => $m['track']->move_to === 'trash',
            'replaced' => $plans->contains(fn ($p) => $p->track_id === $m['by']->id && $p->replaces_track_id === $m['track']->id),
            default => true,
        });

        return ['key' => $key, 'artist' => $winner->artist, 'title' => $winner->title, 'members' => $members->all(),
            'actions' => $actions, 'planned' => $planned, 'cover' => $tracks->firstWhere('has_cover', true)?->id ?? $winner->id,
            'unverified' => $members->where('role', 'replaced')->where('verified', false)->count()];
    }

    /** bessere Fassung $source ersetzt $old im Album: kommt mit dessen Tags an dessen Platz, $old geht beim Schreiben */
    private function replace(Track $source, Track $old): void
    {
        $old = Track::findOrFail($old->id);
        $values = collect(self::TAKEN_FIELDS)->mapWithKeys(fn ($f) => [$f => $old->{$f}])->all();
        // lose Fassung: die erste Vormerkung verschiebt, weitere kopieren (beim Schreiben erst kopieren, dann verschieben)
        $action = ($this->looseAlbums[$source->album_hash] ?? Albums::looseHashes([$source->album_hash])[$source->album_hash] ?? false) && ! FilePlan::where('track_id', $source->id)->where('action', 'move')->where('target_album_hash', '!=', $old->album_hash)->exists() ? 'move' : 'copy';
        FilePlan::updateOrCreate(['track_id' => $source->id, 'target_album_hash' => $old->album_hash],
            ['action' => $action, 'replaces_track_id' => $old->id, 'target_location' => $old->location, 'values' => $values]);
    }
}
