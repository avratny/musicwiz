<?php

namespace App\Support;

use App\Models\FilePlan;
use App\Models\TagChange;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sortieren beim Schreiben – der Plan je Album (Ordner): wohin jedes Lied wandert.
 * - Mit Kategorie: an den Regelort (Vorlage je Bereich und Sampler ja/nein, Einstellungen › Dateinamen) – aus der Inbox
 *   und innerhalb von Lib/Blackhole, wenn der Pfad von der Vorlage abweicht. Ohne Kategorie bleibt ein Lied liegen.
 * - Vormerkungen: „→ Papierkorb“ (move_to = trash, ersetzte Lieder aus file_plans), „→ Inbox“ (aus dem Blackhole),
 *   bessere Fassungen aus file_plans kommen mit den Album-Werten in den Album-Ordner (kopieren bzw. verschieben).
 * Schutzregeln: Ein Album (kein Einzeltitel-Ordner) wandert nur als Ganzes in genau einen Ordner – sonst bleibt alles
 * liegen; nie überschreiben (Ziel belegt → bleibt); zwei Lieder mit demselben Ziel → bleiben. Groß/klein zählt beim
 * Vergleich nicht (das NAS unterscheidet es nicht).
 */
class Sorter
{
    /** Lied-Aktionen */
    public const KINDS = ['move' => 'verschieben', 'trash' => 'Papierkorb', 'inbox' => 'zurück in die Inbox', 'copy-in' => 'Kopie ins Album', 'move-in' => 'ins Album', 'stay' => 'bleibt'];

    private ?PathTemplate $paths = null;

    /** Vorlage je Kategorie */
    public function template(string $category): string
    {
        $key = 'template_'.Categories::LOCATIONS[$category].(str_ends_with($category, 'COMPILATION') ? '_compilation' : '');
        $template = (string) app(Settings::class)->get($key, '');

        return $template !== '' && PathTemplate::problem($template) === null ? $template : PathTemplate::TEMPLATES[$key][3];
    }

    /** Plan eines Albums (aktueller Album-Hash) */
    public function plan(string $hash): array
    {
        return $this->planFor(Track::where('album_hash', $hash)->get(), FilePlan::with('track')->where('target_album_hash', $hash)->get());
    }

    /**
     * @param  Collection<int, Track>  $tracks  Lieder des Albums
     * @param  Collection<int, FilePlan>  $incoming  Vormerkungen in dieses Album
     * @return array{hash: string, location: string, loose: bool, items: list<array>, blocked: ?string, target: ?string, target_location: ?string, actions: int}
     */
    public function planFor(Collection $tracks, Collection $incoming): array
    {
        $this->paths ??= PathTemplate::fromSettings();
        $first = $tracks->first();
        $loose = self::loose($tracks);
        $replaced = $incoming->pluck('replaces_track_id')->filter()->flip();
        $items = [];
        $members = $tracks->reject(fn (Track $t) => $t->move_to === 'trash' || isset($replaced[$t->id]));
        // Album-Werte für die Vorlage (Jahr, mehrere CDs): verbleibende Lieder plus die, die dazukommen
        // Werte jetzt berechnen (nicht der Stand beim Vormerken – das Album kann inzwischen umbenannt sein)
        $values = $incoming->mapWithKeys(fn (FilePlan $p) => [$p->id => $p->currentValues()]);
        $virtual = $incoming->filter(fn ($p) => $p->track)->mapWithKeys(function (FilePlan $p) use ($values) {
            $v = clone $p->track;
            foreach ($values[$p->id] as $field => $value) {
                $v->setAttribute($field, $value);
            }
            $v->setAttribute('category', $values[$p->id]['category'] ?? null);

            return [$p->id => $v];
        });
        $album = $members->concat($virtual->values());

        foreach ($tracks as $t) {
            if ($t->move_to === 'trash' || isset($replaced[$t->id])) {
                $items[] = ['track' => $t, 'kind' => 'trash', 'location' => 'trash', 'to' => $this->trashPath($t), 'reason' => isset($replaced[$t->id]) ? 'durch bessere Fassung ersetzt' : (BrokenTracks::is($t) ? 'kaputt (kein Audio)' : 'Duplikat')];
            } elseif ($t->location === 'blackhole') {
                $items[] = $t->move_to === 'inbox'
                    ? ['track' => $t, 'kind' => 'inbox', 'location' => 'inbox', 'to' => self::lowerExtension(Locations::root('inbox').'/'.$this->relative($t))]
                    : ['track' => $t, 'kind' => 'stay', 'location' => $t->location, 'to' => null];
            } elseif ($t->category === null || ! isset(Categories::LOCATIONS[$t->category]) || $t->location === 'trash') {
                // bleibt an seinem Platz – nur die Dateiendung wird klein („Lied.MP3“ → „Lied.mp3“, nicht im Papierkorb)
                $items[] = $t->location !== 'trash' && self::lowerExtension($t->path) !== $t->path
                    ? ['track' => $t, 'kind' => 'move', 'location' => $t->location, 'to' => self::lowerExtension($t->path)]
                    : ['track' => $t, 'kind' => 'stay', 'location' => $t->location, 'to' => null];
            } else {
                $location = Categories::LOCATIONS[$t->category];
                $to = Locations::root($location).'/'.$this->paths->render($this->template($t->category), $this->paths->values($t, $album));
                $items[] = $to === $t->path
                    ? ['track' => $t, 'kind' => 'stay', 'location' => $t->location, 'to' => null, 'ruled' => true]
                    : ['track' => $t, 'kind' => 'move', 'location' => $location, 'to' => $to];
            }
        }

        // ein Album, ein Ordner – auch in der Schreibweise: sind sich alle Lieder einig, diese; sind die Album-Tags nur in
        // Groß/klein gemischt, bleibt die vorhandene Ordner-Schreibweise (sonst würde der Ordner hin und her benannt)
        if (! $loose) {
            $computed = collect($items)->filter(fn ($i) => $i['kind'] === 'move' || ($i['ruled'] ?? false))
                ->map(fn ($i) => $i['kind'] === 'move' ? dirname($i['to']) : $i['track']->dir);
            $current = $tracks->pluck('dir')->unique();
            $spelling = $computed->groupBy(fn ($d) => mb_strtolower($d))->map(function (Collection $dirs) use ($current) {
                $unique = $dirs->unique();

                return $unique->count() === 1 ? $unique->first() : ($current->first(fn ($d) => $unique->contains($d)) ?? $dirs->countBy()->sortDesc()->keys()->first());
            });
            foreach ($items as $index => $i) {
                if ($i['kind'] === 'move') {
                    $to = $spelling[mb_strtolower(dirname($i['to']))].'/'.basename($i['to']);
                    $items[$index] = $to === $i['track']->path ? [...$i, 'kind' => 'stay', 'to' => null, 'ruled' => true] : [...$i, 'to' => $to];
                }
            }
        }

        // Ordner des Albums danach: gemeinsames Ziel der wandernden Lieder, sonst der bisherige Ordner
        // (werden alle Lieder ersetzt, ist es der bisherige Ordner der ersetzten)
        $finalDir = function () use ($items) {
            $all = collect($items);
            $kept = $all->where('kind', '!=', 'trash');
            $moving = $kept->whereIn('kind', ['move', 'inbox']);
            [$dirs, $location] = match (true) {
                $moving->isNotEmpty() => [$moving->map(fn ($i) => dirname($i['to'])), $moving->first()['location']],
                $kept->isNotEmpty() => [$kept->map(fn ($i) => $i['track']->dir), $kept->first()['track']->location],
                default => [$all->map(fn ($i) => $i['track']->dir), $all->first()['track']->location ?? null],
            };

            return $dirs->unique(fn ($d) => mb_strtolower($d))->count() === 1 ? [$dirs->first(), $location] : [null, null];
        };
        [$dir, $dirLocation] = $finalDir();
        foreach ($incoming as $p) {
            $v = $virtual[$p->id] ?? null;
            if (! $v) {
                continue;
            }
            $category = $v->category ?? 'LIB';
            $name = basename($this->paths->render($this->template($category), $this->paths->values($v, $album)));
            $dirLocation ??= $p->target_location;
            // verschoben wird nur, was (noch) allein steht – liegt das Lied inzwischen in einem Album, wird kopiert
            $move = $p->action === 'move' && IncompleteAlbums::alone($p->track);
            $items[] = ['track' => $p->track, 'kind' => $move ? 'move-in' : 'copy-in', 'location' => $dirLocation,
                'to' => $dir !== null ? $dir.'/'.$name : null, 'plan' => $p, 'values' => $values[$p->id]];
        }

        $blocked = $this->blocked($items, $loose, $dir);
        // verteiltes Album: zeigen, welche Lieder wohin wollen und welche Tags sich unterscheiden
        $split = $blocked['split'] ? $this->split($items) : null;
        if ($blocked['album']) {
            $items = array_map(fn ($i) => [...$i, 'kind' => 'stay', 'to' => null, 'blocked' => $blocked['album']], $items);
        }
        foreach ($blocked['items'] as $index => $reason) {
            $items[$index] = [...$items[$index], 'kind' => 'stay', 'to' => null, 'blocked' => $reason];
        }
        $moving = collect($items)->whereIn('kind', ['move', 'inbox', 'move-in', 'copy-in']);
        $targets = $moving->map(fn ($i) => dirname($i['to']))->unique(fn ($d) => mb_strtolower($d));

        return [
            'hash' => $first?->album_hash ?? '',
            'location' => $first?->location ?? '',
            'loose' => $loose,
            'items' => $items,
            'blocked' => $blocked['album'] ?? (collect($items)->pluck('blocked')->filter()->first()),
            'split' => $split,
            'target' => $targets->count() === 1 ? $targets->first() : null,
            'target_location' => data_get($moving->first(), 'location'),
            'actions' => collect($items)->where('kind', '!=', 'stay')->count(),
        ];
    }

    /**
     * Schutzregeln. @return array{album: ?string, items: array<int, string>} Grund fürs ganze Album bzw. je Eintrag
     */
    private function blocked(array $items, bool $loose, ?string $dir): array
    {
        $out = ['album' => null, 'items' => [], 'split' => false];
        $kept = collect($items)->filter(fn ($i) => $i['kind'] !== 'trash');
        // aus dem Blackhole zurück in die Inbox geht auch einzeln – verteilt wird hier nur beim Einsortieren nicht
        // verteilt = am Ende verschiedene Album-Ordner (wer bleibt, bleibt in seinem; CD-Unterordner zählen zum Album)
        if (! $loose && $kept->contains('kind', 'move')) {
            $dirs = $kept->whereIn('kind', ['move', 'stay'])
                ->map(fn ($i) => mb_strtolower(Scanner::albumDir($i['kind'] === 'move' ? dirname($i['to']) : $i['track']->dir)))->unique();
            if ($dirs->count() > 1) {
                $out['album'] = 'Album würde auf mehrere Ordner verteilt – erst angleichen';
                $out['split'] = true;

                return $out;
            }
        }
        if ($kept->whereIn('kind', ['copy-in', 'move-in'])->isNotEmpty() && $dir === null) {
            $out['album'] = 'Album liegt in mehreren Ordnern – die bessere Fassung hat kein eindeutiges Ziel';

            return $out;
        }
        // Pfade, die in diesem Plan frei werden (wandern weg), und Ziele, die mehrfach vorkommen
        $leaving = collect($items)->filter(fn ($i) => in_array($i['kind'], ['move', 'inbox', 'trash', 'move-in'], true))
            ->map(fn ($i) => mb_strtolower($i['track']->path))->flip();
        $targets = collect($items)->filter(fn ($i) => $i['to'] !== null && $i['kind'] !== 'trash')->map(fn ($i) => mb_strtolower($i['to']));
        $counts = $targets->countBy();
        foreach ($targets as $index => $to) {
            $item = $items[$index];
            $own = mb_strtolower($item['track']->path) === $to && in_array($item['kind'], ['move', 'inbox'], true);
            $reason = match (true) {
                $counts[$to] > 1 => 'mehrere Lieder hätten dasselbe Ziel „'.basename($item['to']).'“',
                ! $own && ! isset($leaving[$to]) && (file_exists($item['to']) || Track::where('path_hash', sha1($item['to']))->exists()) => 'Ziel „'.basename($item['to']).'“ ist schon belegt',
                default => null,
            };
            if ($reason !== null) {
                if (! $loose) {
                    return ['album' => $reason, 'items' => [], 'split' => false];
                }
                $out['items'][$index] = $reason;
            }
        }

        return $out;
    }

    /** Einzeltitel-Ordner (Albums::loose) dürfen einzeln wandern – ein Album wandert nur als Ganzes */
    public static function loose(Collection $tracks): bool
    {
        return Albums::loose($tracks);
    }

    /** Tags, die den Album-Ordner bestimmen (Bereich über die Kategorie, Ordner über die Vorlage) */
    public const FOLDER_FIELDS = ['category' => 'Kategorie', 'folder_artist' => 'Album-Interpret (sonst Interpret)', 'album' => 'Album', 'compilation' => 'Sampler'];

    /**
     * Verteiltes Album: Gruppen je Ziel-Ordner (bzw. „bleibt liegen“) mit Liedern und den Werten der Tags, die den Ordner
     * bestimmen; „differs“ = Felder, die sich zwischen den Gruppen unterscheiden.
     *
     * @return array{groups: list<array{label: string, tracks: list<string>, values: array<string, string>}>, differs: list<string>}
     */
    private function split(array $items): array
    {
        $value = fn (Track $t, string $f) => match ($f) {
            'category' => Categories::LABELS[$t->category] ?? 'keine',
            'compilation' => $t->compilation ? 'ja' : 'nein',
            'folder_artist' => trim((string) ($t->albumartist ?: $t->artist)) ?: '–',
            default => (string) ($t->{$f} ?? '') !== '' ? (string) $t->{$f} : '–',
        };
        $groups = collect($items)->filter(fn ($i) => $i['kind'] === 'move' || $i['kind'] === 'stay')
            ->groupBy(fn ($i) => $i['kind'] === 'move' ? mb_strtolower(dirname($i['to'])) : '')
            ->map(function ($group, $key) use ($value) {
                $first = $group->first();
                $label = $key === ''
                    ? 'bleibt liegen'.($first['track']->category === null ? ' (keine Kategorie)' : '')
                    : '→ '.Locations::LABELS[$first['location']].' · '.ltrim(mb_substr(dirname($first['to']), mb_strlen(Locations::root($first['location']))), '/');

                return ['label' => $label, 'tracks' => $group->map(fn ($i) => $i['track']->title ?: $i['track']->filename)->values()->all(),
                    'values' => collect(self::FOLDER_FIELDS)->map(fn ($l, $f) => $group->map(fn ($i) => $value($i['track'], $f))->unique()->implode(' / '))->all()];
            })->sortByDesc(fn ($g) => count($g['tracks']))->values();
        $differs = collect(self::FOLDER_FIELDS)->keys()->filter(fn ($f) => $groups->pluck('values.'.$f)->map(fn ($v) => mb_strtolower($v))->unique()->count() > 1)->values()->all();

        return ['groups' => $groups->all(), 'differs' => $differs];
    }

    /** Ziel im Papierkorb: Bereich/relativer Pfad, belegt → „ (2)“ usw. */
    public function trashPath(Track $t): string
    {
        $base = self::lowerExtension(Locations::root('trash').'/'.$t->location.'/'.$this->relative($t));
        $info = pathinfo($base);
        for ($n = 2, $path = $base; file_exists($path); $n++) {
            $path = $info['dirname'].'/'.$info['filename'].' ('.$n.').'.$info['extension'];
        }

        return $path;
    }

    /** Dateiendungen immer klein („Lied.MP3“ → „Lied.mp3“) */
    public static function lowerExtension(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return $ext === '' || $ext === strtolower($ext) ? $path : substr($path, 0, -strlen($ext)).strtolower($ext);
    }

    private function relative(Track $t): string
    {
        $root = Locations::root($t->location);

        return str_starts_with($t->path, $root.'/') ? substr($t->path, strlen($root) + 1) : basename($t->path);
    }

    /**
     * Alle Alben mit Datei-Aktionen bzw. blockierte (für die Seite „Schreiben“), zwischengespeichert bis sich am
     * Bestand etwas ändert.
     *
     * @return Collection<string, array> Album-Hash => Zusammenfassung
     */
    public function overview(): Collection
    {
        $state = implode('|', [Track::count(), Track::max('updated_at'), TagChange::max('id'), FilePlan::count(), FilePlan::max('updated_at'),
            DB::table('settings')->max('updated_at')]);

        // nur Arrays im Cache (Objekte lässt der Cache nicht zu)
        // Version im Schlüssel: ändert sich die Form der Zusammenfassung, gilt der alte Stand nicht mehr
        return collect(Cache::remember('sorter.overview.v3.'.md5($state), now()->addDay(), function () {
            $plans = FilePlan::with('track')->get()->groupBy('target_album_hash');
            $hashes = Track::where(fn ($q) => $q->whereIn('location', SplitAlbums::LOCATIONS)->orWhereNotNull('move_to'))
                ->distinct()->pluck('album_hash')->merge($plans->keys())->unique();
            $out = collect();
            foreach ($hashes->chunk(200) as $chunk) {
                $tracks = Track::whereIn('album_hash', $chunk)->get()->groupBy('album_hash');
                foreach ($chunk as $hash) {
                    if (! isset($tracks[$hash])) {
                        continue;
                    }
                    $plan = $this->planFor($tracks[$hash], $plans[$hash] ?? collect());
                    if ($plan['actions'] || $plan['blocked']) {
                        $out[$hash] = self::summary($plan);
                    }
                }
            }

            return $out->all();
        }));
    }

    /** kurze Zusammenfassung eines Plans (ohne Modelle – für Cache und Seite) */
    public static function summary(array $plan): array
    {
        $items = collect($plan['items']);
        // je Datei: von wo nach wo (Bereich + Pfad im Bereich); „to“ null = Ziel noch offen. Gleicher Ordner = umbenennen;
        // „diff“ = Länge des gleichen Anfangs und Endes im Pfad (das Geänderte dazwischen wird markiert)
        $files = $items->where('kind', '!=', 'stay')->map(function ($i) {
            $from = self::where($i['track']->location, $i['track']->path);
            $to = $i['to'] !== null ? self::where($i['location'], $i['to']) : null;
            $same = $to && $from['location'] === $to['location'];

            return [
                'kind' => $i['kind'] === 'move' && $same && $from['dir'] === $to['dir'] ? 'rename' : $i['kind'],
                'from' => $from,
                'to' => $to,
                'diff' => $same ? TextDiff::bounds($from['path'], $to['path']) : null,
                'reason' => $i['reason'] ?? null,
            ];
        })->sortBy(fn ($f) => $f['from']['path'])->values();

        return [
            'target' => $plan['target'] ? ltrim(mb_substr($plan['target'], mb_strlen(Locations::root($plan['target_location']))), '/') : null,
            'target_location' => $plan['target_location'],
            'blocked' => $plan['blocked'],
            'split' => $plan['split'] ?? null,
            'counts' => $files->countBy('kind')->all(),
            'blocked_items' => $items->filter(fn ($i) => isset($i['blocked']))->map(fn ($i) => ($i['track']->title ?: $i['track']->filename).': '.$i['blocked'])->values()->take(5)->all(),
            'files' => $files->all(),
        ];
    }

    /** Bereich und Pfad darin, aufgeteilt in Ordner und Dateiname */
    private static function where(?string $location, string $path): array
    {
        $root = $location ? Locations::root($location) : '';
        $relative = $root !== '' && str_starts_with($path, $root.'/') ? mb_substr($path, mb_strlen($root) + 1) : $path;
        $dir = dirname($relative);

        return ['location' => $location, 'path' => $relative, 'dir' => $dir === '.' ? '' : $dir, 'name' => basename($relative)];
    }
}
