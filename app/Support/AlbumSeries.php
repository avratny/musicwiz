<?php

namespace App\Support;

use App\Models\AlbumExemption;
use App\Models\SeriesPattern;
use App\Models\Track;
use Illuminate\Support\Collection;

/**
 * Serien (Inbox und Lib): Alben, deren Name ohne Zahlen, Groß/klein und Satzzeichen gleich ist („20 Top Hits aus den
 * Charts 1/99“, „… 6/2003“), sollen albumübergreifend dasselbe Muster haben. Der feste Text in der Schreibweise der
 * meisten Alben (oder das gewählte Muster, wenn es mehrere gibt), Jahre vierstellig („1/99“ → „1/1999“); die übrigen
 * Zahlen bleiben. „Vol“, „Vol.“ und „Volume“ sind für den Aufbau egal („Viva Hits 15“ und „Viva Hits, Vol. 5“ gehören
 * zusammen; das Muster sagt, ob „, Vol.“ dazugehört). Verglichen werden nur Alben mit demselben Aufbau (gleiche Textteile,
 * gleich viele Zahlen) – andere stehen als „anderer Aufbau“ dabei. Übernommen wird
 * als Tag-Änderung (Album), je Serie; „keine Serie“ nimmt eine Gruppe aus.
 */
class AlbumSeries
{
    public const CHECK = 'series';

    /** Gruppe: Name ohne Zahlen, Zusätze wie „Vol.“/„CD“, Groß/klein und Satzzeichen */
    public static function key(string $name): string
    {
        $s = mb_strtolower($name);
        $s = preg_replace('/\b(vol(ume)?|teil|part|cd|disc|folge|nr|no)\b\.?/u', ' ', $s);
        $s = preg_replace('/[^\p{L}]+/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Text und Zahlen abwechselnd: [Text, Zahl, Text, …, Text] */
    public static function parts(string $name): array
    {
        return preg_split('/(\d+)/', $name, -1, PREG_SPLIT_DELIM_CAPTURE);
    }

    /** Aufbau: die Textteile ohne Groß/klein, Leer- und Satzzeichen */
    private static function shape(array $parts): string
    {
        $texts = [];
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0) {
                $texts[] = preg_replace('/[^\p{L}]+/u', '', preg_replace('/\bvol(ume)?\b/u', '', mb_strtolower($p)));
            }
        }

        return implode('#', $texts);
    }

    /** zweistelliges Jahr vierstellig: ab 50 → 19.., sonst 20.. */
    private static function year(string $n): string
    {
        return strlen($n) === 2 ? ((int) $n >= 50 ? '19' : '20').$n : $n;
    }

    /** Ausnahme-Schlüssel einer Serie (liegt als „Album“ in album_exemptions) */
    private static function exemptionKey(string $key): string
    {
        return sha1('series|'.$key);
    }

    /**
     * Serien mit Vorschlägen (bzw. alle, $all).
     *
     * @return Collection<string, array{key: string, name: string, pattern: string, patterns: list<array>, custom: bool, albums: int, changes: list<array>, others: list<string>}>
     */
    public function series(bool $exempted = false, bool $all = false): Collection
    {
        $stored = SeriesPattern::pluck('pattern', 'series_key');
        $exempt = AlbumExemption::where('check', self::CHECK)->pluck('album_hash')->flip();
        $albums = Track::whereIn('location', SplitAlbums::LOCATIONS)->whereNotNull('album')->where('album', '<>', '')
            ->get(['id', 'album_hash', 'album', 'album_dir', 'location', 'has_cover'])->groupBy('album_hash')
            ->map(fn (Collection $t) => ['hash' => $t->first()->album_hash, 'name' => $t->pluck('album')->countBy()->sortDesc()->keys()->first(),
                'dir' => $t->first()->album_dir, 'location' => $t->first()->location, 'cover' => $t->firstWhere('has_cover', true)?->id ?? $t->first()->id])
            ->filter(fn ($a) => preg_match('/\d/', $a['name']) && self::key($a['name']) !== '');
        // Einzeltitel-Ordner („Kein Album“, Sammelordner) sind keine Alben einer Serie
        $loose = Albums::looseHashes($albums->keys());
        $albums = $albums->reject(fn ($a, $hash) => $loose[$hash] ?? false);

        return $albums->groupBy(fn ($a) => self::key($a['name']))
            ->filter(fn (Collection $g, $key) => $g->pluck('name')->unique()->count() > 1 && isset($exempt[self::exemptionKey($key)]) === $exempted)
            ->map(fn (Collection $g, $key) => $this->suggest($key, $g, $stored[self::exemptionKey($key)] ?? null))
            // $all: auch Serien, die schon einheitlich sind (um ein Muster festzulegen)
            ->filter(fn ($s) => $s && ($s['changes'] || $exempted || $all))
            ->sortBy('name');
    }

    /**
     * Muster einer Gruppe und die Alben, die davon abweichen. Muster = Text mit Platzhaltern {Nr} (Zahl bleibt) und
     * {Jahr} (vierstellig): gespeichert (gewählt bzw. eigenes, $stored) oder das häufigste vorkommende.
     */
    private function suggest(string $key, Collection $group, ?string $stored): ?array
    {
        $shapes = $group->map(fn ($a) => [...$a, 'parts' => self::parts($a['name'])])->groupBy(fn ($a) => self::shape($a['parts']));
        $members = $shapes->sortByDesc(fn ($g) => $g->count())->first();
        if ($members->count() < 2) {
            return null;
        }
        $length = count($members->first()['parts']);
        // Stellen der Zahlen in den Teilen (Text, Zahl, Text, …)
        $numbers = array_filter(array_keys($members->first()['parts']), fn ($i) => $i % 2 === 1);
        // Jahr: nur zwei- und vierstellige Zahlen, mindestens ein vierstelliges Jahr (19xx/20xx); fest: bei allen gleich
        $years = $constant = [];
        foreach ($numbers as $i) {
            $values = $members->map(fn ($a) => $a['parts'][$i]);
            $years[$i] = $values->every(fn ($v) => strlen($v) === 2 || preg_match('/^(19|20)\d\d$/', $v)) && $values->contains(fn ($v) => strlen($v) === 4);
            $constant[$i] = $values->unique()->count() === 1;
        }
        // die vorkommenden Muster: fester Text eines Albums, feste Zahlen wörtlich („20 Top Hits“)
        $render = function (array $parts) use ($numbers, $years, $constant) {
            foreach ($numbers as $i) {
                $parts[$i] = $constant[$i] ? $parts[$i] : ($years[$i] ? '{Jahr}' : '{Nr}');
            }

            return implode('', $parts);
        };
        $variants = $members->map(fn ($a) => $render($a['parts']))->countBy()->sortKeys()->sortDesc();
        $varying = array_values(array_filter($numbers, fn ($i) => ! $constant[$i]));
        $problem = $stored !== null ? self::problem($stored, count($varying)) : null;
        $pattern = $stored !== null && $problem === null ? $stored : $variants->keys()->first();
        // Platzhalter der Reihe nach für die Zahlen, die sich ändern – feste („20 Top Hits“) stehen im Text
        $slots = $varying;

        $changes = [];
        foreach ($members->sortBy('name', SORT_NATURAL) as $a) {
            $k = 0;
            $to = preg_replace_callback('/\{(Nr|Jahr)\}/', function ($m) use ($a, $slots, &$k) {
                $value = $a['parts'][$slots[$k++]];

                return $m[1] === 'Jahr' ? self::year($value) : $value;
            }, $pattern);
            $to = trim(preg_replace('/\s+/u', ' ', $to));
            if ($to !== $a['name']) {
                $changes[] = ['hash' => $a['hash'], 'from' => $a['name'], 'to' => $to, 'location' => $a['location'], 'cover' => $a['cover'],
                    'dir' => ltrim(mb_substr($a['dir'], mb_strlen(Locations::root($a['location']))), '/')];
            }
        }

        return [
            'key' => self::exemptionKey($key),
            'name' => trim(preg_replace('/\{(Nr|Jahr)\}/', '', $pattern)),
            'pattern' => $pattern,
            'patterns' => $variants->map(fn ($count, $p) => ['pattern' => $p, 'count' => $count])->values()->all(),
            'custom' => $stored !== null && $problem === null && ! $variants->has($stored),
            'stored' => $stored,
            'problem' => $problem,
            'placeholders' => count($varying),
            'albums' => $members->count(),
            'changes' => $changes,
            'others' => $shapes->filter(fn ($g) => $g !== $members)->flatten(1)->pluck('name')->sort(SORT_NATURAL)->values()->all(),
        ];
    }

    /** Fehler in einem Muster (null = passt): so viele Platzhalter wie Zahlen, die sich in der Serie ändern */
    private static function problem(string $pattern, int $varying): ?string
    {
        if (preg_match_all('/\{(Nr|Jahr)\}/', $pattern) !== $varying) {
            return 'Das Muster braucht '.$varying.' Platzhalter ({Nr} oder {Jahr}) – so viele Zahlen ändern sich in der Serie.';
        }
        if (preg_match('/\{(?!Nr\}|Jahr\})[^}]*\}/', $pattern)) {
            return 'Nur {Nr} und {Jahr} sind als Platzhalter erlaubt.';
        }

        return null;
    }

    /**
     * Muster einer Serie festlegen (gewählt oder eigenes) – gilt dauerhaft, auch für später dazukommende Alben.
     *
     * @return string|null Fehler (null = gespeichert)
     */
    public function setPattern(string $key, string $pattern): ?string
    {
        $series = $this->series(false, true)->merge($this->series(true))->firstWhere('key', $key);
        if (! $series) {
            return 'Serie nicht gefunden.';
        }
        $pattern = trim(preg_replace('/\s+/u', ' ', $pattern));
        $problem = self::problem($pattern, $series['placeholders']);
        if ($problem !== null) {
            return $problem;
        }
        SeriesPattern::updateOrCreate(['series_key' => $key], ['pattern' => $pattern]);

        return null;
    }

    /** gespeichertes Muster entfernen – wieder das häufigste */
    public function resetPattern(string $key): void
    {
        SeriesPattern::where('series_key', $key)->delete();
    }

    /** Serien mit offenen Vorschlägen (zum vorbelegten Muster) */
    public function count(): int
    {
        return $this->series()->count();
    }

    /**
     * Vorschläge einer Serie (bzw. aller, null) übernehmen – Album bei den Liedern der abweichenden Alben, die den
     * bisherigen Album-Namen tragen (andere Lieder im Ordner bleiben, wie sie sind).
     *
     * @return int Zahl der geänderten Lieder
     */
    public function apply(?string $key = null): int
    {
        $editor = app(TagEditor::class);
        $n = 0;
        foreach ($this->series()->when($key !== null, fn ($c) => $c->where('key', $key)) as $series) {
            foreach ($series['changes'] as $c) {
                foreach (Track::where('album_hash', $c['hash'])->whereIn('location', SplitAlbums::LOCATIONS)->where('album', $c['from'])->get() as $track) {
                    if ($track->album !== $c['from']) {
                        continue;   // Groß/klein anders: eigener Wert
                    }
                    $n += $editor->update($track, ['album' => $c['to']]) ? 1 : 0;
                }
            }
        }

        return $n;
    }

    /** Serie als „keine Serie“ ausnehmen bzw. wieder aufnehmen */
    public function exempt(string $key, bool $exempt = true): void
    {
        $exempt ? AlbumExemption::firstOrCreate(['album_hash' => $key, 'check' => self::CHECK])
            : AlbumExemption::where('album_hash', $key)->where('check', self::CHECK)->delete();
    }
}
