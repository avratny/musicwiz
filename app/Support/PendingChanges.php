<?php

namespace App\Support;

use App\Models\Album;
use App\Models\PendingChange;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Digitaler Zwilling: Aufräumen und Künstlerverzeichnis ändern nur die Track-Zeilen (gewünschter Stand) und merken sich
 * hier, was in der Datei noch anders ist. „Änderungen schreiben“ schreibt dann je Titel einmal die Tags, berechnet den
 * Zielpfad aus den fertigen Tags und verschiebt höchstens einmal; das Neueinlesen übernimmt den neuen Pfad.
 */
class PendingChanges
{
    /** Aktionen, die sich vormerken lassen (alles andere läuft sofort als Aktion) */
    private const STAGEABLE = ['apply', 'move', 'album_files'];

    /** Vormerkbar: nur Tags/Ablage, kein Cover und nichts, was sofort Dateien braucht (Papierkorb, Messen …). */
    public static function stageable(array $actions): bool
    {
        foreach ($actions as $a) {
            if (! in_array($a['type'] ?? '', self::STAGEABLE, true)) {
                return false;
            }
            if (($a['type'] === 'apply') && (! empty($a['cover']) || ! empty($a['embed_from']) || ! empty($a['remove_cover'])
                || array_diff(array_keys($a['tags'] ?? []), Track::TAG_KEYS))) {
                return false;
            }
        }

        return (bool) $actions;
    }

    /**
     * Aktionen eines Funds vormerken: Tags in die Track-Zeilen, Ablage als „an den Regelort“.
     *
     * @return int Anzahl betroffener Titel
     */
    public function stage(array $actions, string $label): int
    {
        $dirs = [];
        $n = 0;
        DB::transaction(function () use ($actions, $label, &$dirs, &$n) {
            foreach ($actions as $a) {
                if (($a['type'] ?? '') === 'album_files' || empty($a['path'])) {
                    continue; // Cover & Extras folgen beim Schreiben, wenn das ganze Album umzieht
                }
                $track = Track::where('path', $a['path'])->first();
                if (! $track) {
                    continue;
                }
                $this->stageTrack($track, $a['tags'] ?? [], ! empty($a['target']) || $a['type'] === 'move', $label, $a['target'] ?? null);
                $dirs[$track->dir] = true;
                $n++;
            }
        });
        $this->afterChange(array_keys($dirs));

        return $n;
    }

    /** Zusatz für Meldungen nach Künstler-Entscheidungen */
    public static function note(int $staged): string
    {
        return $staged ? ' '.$staged.' Titel vorgemerkt – in die Dateien unter „Änderungen schreiben“.' : '';
    }

    /** Prüfungen, deren Behebung nach Künstler-Entscheidungen gleich vorgemerkt wird – in dieser Reihenfolge */
    private const NAME_CHECKS = ['spelling', 'combined', 'inverted'];

    /**
     * Nach einer Entscheidung im Künstlerverzeichnis (umbenennen, zusammenführen, Schreibweise, Trennen …): alle Lib-Titel,
     * in denen einer der Namen vorkommt, gleich passend vormerken (Schreibweise, ARTISTS, umgedrehte Namen).
     *
     * @param  list<string>  $names  alte und neue Namen
     * @return int Anzahl vorgemerkter Titel
     */
    public function stageForNames(array $names, string $label): int
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names))));
        if (! $names) {
            return 0;
        }
        // grob vorfiltern (ein „_“ im Namen trifft etwas mehr – genau geprüft wird danach von den Prüfungen)
        $like = fn (string $n) => '%'.$n.'%';
        $albumIds = Track::where('location', 'library')->whereNotNull('album_id')
            ->where(function ($q) use ($names, $like) {
                foreach ($names as $n) {
                    foreach (['artist', 'albumartist', 'artists', 'albumartists'] as $col) {
                        $q->orWhere($col, 'like', $like($n));
                    }
                }
            })->distinct()->pluck('album_id')->all();
        $count = 0;
        foreach (self::NAME_CHECKS as $key) {
            // jede Prüfung auf dem schon aktualisierten Zwilling (frische Instanz)
            foreach (app()->make(CleanupChecks::class)->fixActions($albumIds, $key) as $actions) {
                if (self::stageable($actions)) {
                    $count += $this->stage($actions, $label);
                }
            }
        }

        return $count;
    }

    /**
     * Einen Titel vormerken (Tags in den Zwilling, Dateistand merken).
     *
     * @param  array{cover?: ?string, remove_cover?: bool, embed_cover?: bool}  $cover  Cover-Änderung aus dem Album-Editor
     */
    public function stageTrack(Track $track, array $tags, bool $relocate, string $label, ?string $target = null, array $cover = []): void
    {
        $pc = PendingChange::firstOrNew(['track_id' => $track->id]);
        $before = (array) ($pc->tags_before ?? []);
        $wanted = (array) ($pc->tags ?? []);
        $current = $track->tags();
        foreach ($tags as $k => $v) {
            if (! in_array($k, Track::TAG_KEYS, true)) {
                continue;
            }
            if (! array_key_exists($k, $before)) {
                $before[$k] = $current[$k] ?? null; // Dateistand (der Zwilling war bis jetzt gleich der Datei)
            }
            $wanted[$k] = $v;
            $track->{$k} = $v;
            if (self::same($k, $before[$k], $v)) {
                unset($before[$k], $wanted[$k]); // wieder wie in der Datei
            }
        }
        $track->save();
        $relocate = $relocate || (bool) $pc->relocate;
        // Cover: neues ersetzt „entfernen“ und umgekehrt; „fehlendes einbetten“ nur, wenn sonst nichts vorgemerkt ist
        $covers = ['cover' => $pc->cover, 'remove_cover' => (bool) $pc->remove_cover, 'embed_cover' => (bool) $pc->embed_cover];
        if (! empty($cover['cover'])) {
            $covers = ['cover' => $cover['cover'], 'remove_cover' => false, 'embed_cover' => false];
        } elseif (! empty($cover['remove_cover'])) {
            $covers = ['cover' => null, 'remove_cover' => true, 'embed_cover' => false];
        } elseif (! empty($cover['embed_cover']) && ! $covers['cover'] && ! $covers['remove_cover']) {
            $covers['embed_cover'] = true;
        }
        if (! $before && ! $relocate && ! array_filter($covers)) {
            $pc->exists && $pc->delete();

            return;
        }
        $pc->fill($covers + [
            'tags_before' => $before, 'tags' => $wanted, 'relocate' => $relocate,
            'target' => $target !== null && $target !== $track->path ? $target : $pc->target,
            'labels' => array_values(array_unique([...((array) $pc->labels), $label])),
            'revision' => ((int) $pc->revision) + 1,
        ])->save();
    }

    /**
     * Vorgemerktes verwerfen: Zwilling zurück auf den Dateistand.
     *
     * @param  list<int>|null  $albumIds  nur diese Alben; null = alles
     */
    public function discard(?array $albumIds = null): int
    {
        $dirs = [];
        $pending = $this->query($albumIds)->with('track')->get();
        DB::transaction(function () use ($pending, &$dirs) {
            foreach ($pending as $pc) {
                if ($pc->track) {
                    foreach ((array) $pc->tags_before as $k => $v) {
                        $pc->track->{$k} = $v;
                    }
                    $pc->track->save();
                    $dirs[$pc->track->dir] = true;
                }
                $pc->delete();
            }
        });
        $this->afterChange(array_keys($dirs));
        self::pruneCovers();

        return $pending->count();
    }

    /** Bestimmte Vormerkungen verwerfen (z. B. im Album-Editor auf den Dateistand zurückgesetzt). */
    public function discardIds(array $ids): void
    {
        $pending = PendingChange::with('track')->whereIn('id', $ids)->get();
        $dirs = [];
        foreach ($pending as $pc) {
            if ($pc->track) {
                foreach ((array) $pc->tags_before as $k => $v) {
                    $pc->track->{$k} = $v;
                }
                $pc->track->save();
                $dirs[$pc->track->dir] = true;
            }
            $pc->delete();
        }
        $this->afterChange(array_keys($dirs));
        self::pruneCovers();
    }

    /** Tags, wie sie in der Datei stehen: Zwilling, für vorgemerkte Felder der Dateistand. */
    public static function fileTags(Track $track): array
    {
        $tags = $track->tags();
        $pc = $track->relationLoaded('pendingChange') ? $track->pendingChange : PendingChange::where('track_id', $track->id)->first();

        return $pc ? array_replace($tags, (array) $pc->tags_before) : $tags;
    }

    public function count(?array $albumIds = null): int
    {
        return $this->query($albumIds)->count();
    }

    public function albumCount(): int
    {
        return (int) PendingChange::join('tracks', 'tracks.id', '=', 'pending_changes.track_id')->distinct()->count('tracks.album_id');
    }

    /**
     * Übersicht je Album (für „Änderungen schreiben“): Titel mit Feld bisher → neu und Ordner alt → neu.
     *
     * @return list<array{id: int, title: string, count: int, rows: list<array>}>
     */
    public function summary(int $limit = 50, int $rowsPerAlbum = 12): array
    {
        $albumIds = PendingChange::join('tracks', 'tracks.id', '=', 'pending_changes.track_id')
            ->select('tracks.album_id')->distinct()->orderBy('tracks.album_id')->limit($limit)->pluck('album_id')->filter()->all();
        // Zielordner wie beim Schreiben (nur für die angezeigten Alben berechnet)
        $targets = collect($this->actions($albumIds)['actions'])->filter(fn ($a) => ! empty($a['target']))->mapWithKeys(fn ($a) => [$a['path'] => $a['target']]);
        $out = [];
        foreach (PendingChange::with('track.albumRecord')->whereHas('track', fn ($q) => $q->whereIn('album_id', $albumIds))->get() as $pc) {
            $t = $pc->track;
            $id = (int) $t->album_id;
            $out[$id] ??= ['id' => $id, 'title' => trim(($t->albumRecord?->albumartist ? $t->albumRecord->albumartist.' – ' : '').($t->albumRecord?->title ?? $t->album), ' –'), 'count' => 0, 'rows' => []];
            $out[$id]['count']++;
            if (count($out[$id]['rows']) < $rowsPerAlbum) {
                $target = $targets[$t->path] ?? null;
                $out[$id]['rows'][] = ['file' => $t->filename, 'before' => (array) $pc->tags_before,
                    'after' => array_intersect_key($t->tags(), (array) $pc->tags_before), 'labels' => (array) $pc->labels,
                    'from' => $target ? dirname($t->path) : null, 'to' => $target ? dirname($target) : null,
                    'rename' => $target && dirname($target) === dirname($t->path) ? basename($target) : null,
                    'cover' => $pc->cover ? 'neues Cover' : ($pc->remove_cover ? 'Cover entfernen' : ($pc->embed_cover && ! $t->cover_embedded ? 'fehlendes Cover einbetten' : null))];
            }
        }

        return array_values($out);
    }

    /**
     * Alles Vorgemerkte strukturiert (zum Prüfen, z. B. als JSON-Export): je Album und Titel die Felder bisher → neu,
     * Verschieben alt → neu, Cover und Herkunft – dazu die Aktionen, wie „Schreiben“ sie jetzt ausführen würde.
     * Pfade relativ zum Musikordner.
     */
    public function export(): array
    {
        $base = rtrim(app(Settings::class)->base(), '/').'/';
        $rel = fn (?string $p) => $p !== null && str_starts_with($p, $base) ? substr($p, strlen($base)) : $p;
        $plan = $this->actions();
        $targets = collect($plan['actions'])->filter(fn ($a) => ! empty($a['target']))->mapWithKeys(fn ($a) => [$a['path'] => $a['target']]);
        $albums = [];
        foreach (PendingChange::with('track.albumRecord')->orderBy('id')->get() as $pc) {
            $t = $pc->track;
            if (! $t) {
                continue;
            }
            $id = (int) $t->album_id;
            $albums[$id] ??= ['album_id' => $id ?: null, 'album' => $t->albumRecord?->title, 'albumartist' => $t->albumRecord?->albumartist,
                'category' => $t->albumRecord?->category, 'dir' => $rel($t->albumRecord?->dir), 'tracks' => []];
            $after = $t->tags();
            $target = $targets[$t->path] ?? null;
            $albums[$id]['tracks'][] = array_filter([
                'track_id' => $t->id,
                'path' => $rel($t->path),
                'changes' => collect((array) $pc->tags_before)->map(fn ($from, $k) => ['from' => $from, 'to' => $after[$k] ?? null])->all() ?: null,
                'move' => $target ? ['from' => $rel($t->path), 'to' => $rel($target)] : null,
                'cover' => $pc->cover ? 'neues Cover' : ($pc->remove_cover ? 'entfernen' : ($pc->embed_cover && ! $t->cover_embedded ? 'fehlendes einbetten' : null)),
                'relocate' => $pc->relocate ?: null,
                'labels' => (array) $pc->labels,
                'staged_at' => $pc->updated_at?->toIso8601String(),
            ], fn ($v) => $v !== null && $v !== []);
        }
        $actions = array_map(function ($a) use ($rel) {
            foreach (['path', 'target', 'from_dir', 'to_dir', 'dir'] as $k) {
                if (isset($a[$k])) {
                    $a[$k] = $rel($a[$k]);
                }
            }
            if (isset($a['cover'])) {
                $a['cover'] = basename($a['cover']);
            }

            return $a;
        }, $plan['actions']);

        return [
            'exported_at' => now()->toIso8601String(),
            'base' => $base,
            'totals' => ['tracks' => $plan['tracks'], 'albums' => count($albums), 'actions' => count($actions)],
            'albums' => array_values($albums),
            'planned_actions' => $actions,
        ];
    }

    /**
     * Aktionen zum Schreiben: je Titel einmal Tags schreiben (Zwilling statt Datei) und an den Ort, den die fertigen
     * Tags ergeben; je Album Cover & Extras mit, wenn alle Titel in denselben Ordner wandern.
     *
     * @param  list<int>|null  $albumIds
     * @return array{actions: list<array>, albums: int, tracks: int}
     */
    public function actions(?array $albumIds = null): array
    {
        $pending = $this->query($albumIds)->with('track')->get()->filter(fn ($pc) => $pc->track)->keyBy(fn ($pc) => $pc->track->path);
        $albums = $pending->map(fn ($pc) => $pc->track->album_id)->filter()->unique()->values()->all();
        // Ablage aus den fertigen Tags – dieselbe Logik wie „Nicht am Regelort“, für die ganzen betroffenen Alben
        $placement = app(CleanupChecks::class)->placementActions($albums);
        $actions = [];
        $handled = [];
        $embedFrom = [];
        $cover = function (?PendingChange $pc) use (&$embedFrom): array {
            if (! $pc) {
                return [];
            }
            if ($pc->cover) {
                return ['cover' => $pc->cover];
            }
            if ($pc->remove_cover) {
                return ['remove_cover' => true];
            }
            if ($pc->embed_cover && ! $pc->track->cover_embedded && $pc->track->album_id) {
                // Quelle erst jetzt bestimmen (eingebettetes Cover eines anderen Titels bzw. cover.jpg)
                $id = $pc->track->album_id;
                $embedFrom[$id] ??= ($album = Album::find($id)) ? (Covers::embedSource($album, Track::where('album_id', $id)->get()) ?? false) : false;

                return $embedFrom[$id] ? ['embed_from' => $embedFrom[$id]] : [];
            }

            return [];
        };
        foreach ($placement as $a) {
            $path = $a['path'] ?? null;
            if ($a['type'] === 'album_files' || $path === null) {
                $actions[] = $a;

                continue;
            }
            $pc = $pending[$path] ?? null;
            $tags = ($a['tags'] ?? []) + ($pc ? self::wantedTags($pc) : []);
            $actions[] = array_filter(['type' => 'apply', 'path' => $path, 'tags' => $tags ?: null, 'target' => $a['target'] ?? null,
                'pending_id' => $pc?->id, 'revision' => $pc?->revision] + $cover($pc), fn ($v) => $v !== null);
            $handled[$path] = true;
        }
        // vorgemerkte Titel, für die der Regelort kein Ziel ergibt: Tags und ggf. das ausdrückliche Ziel (z. B. Endung klein)
        foreach ($pending as $path => $pc) {
            if (! isset($handled[$path])) {
                $tags = self::wantedTags($pc);
                $target = $pc->target && $pc->target !== $path ? $pc->target : null;
                // auch ohne Tags/Ziel (liegt schon richtig): leere Aktion erledigt die Vormerkung beim Schreiben
                $actions[] = array_filter(['type' => 'apply', 'path' => $path, 'tags' => $tags ?: null, 'target' => $target, 'pending_id' => $pc->id, 'revision' => $pc->revision] + $cover($pc), fn ($v) => $v !== null);
            }
        }
        $actions = [...$this->albumCoverActions($pending, $actions), ...$actions];
        // Reihenfolge: Cover-Dateien in den Papierkorb, Titel, album_files (wie „Nicht am Regelort“), zuletzt neues cover.jpg
        $rank = fn ($a) => match ($a['type']) {
            'trash' => 0, 'album_files' => 2, 'cover_file' => 3, default => 1
        };
        usort($actions, fn ($a, $b) => $rank($a) <=> $rank($b));

        return ['actions' => $actions, 'albums' => count($albums), 'tracks' => $pending->count()];
    }

    /**
     * Cover-Aktionen je Album: „entfernen“ legt die Cover-Dateien in den Papierkorb, ein neues Cover kommt als cover.jpg
     * in den Ordner, in dem das Album nach dem Schreiben liegt.
     */
    private function albumCoverActions(Collection $pending, array $actions): array
    {
        $out = [];
        $targets = collect($actions)->filter(fn ($a) => ! empty($a['target']))->mapWithKeys(fn ($a) => [$a['path'] => $a['target']]);
        foreach ($pending->filter(fn ($pc) => $pc->cover || $pc->remove_cover)->groupBy(fn ($pc) => $pc->track->album_id) as $albumId => $group) {
            $album = $albumId ? Album::find($albumId) : null;
            if (! $album) {
                continue;
            }
            $tracks = Track::where('album_id', $albumId)->get();
            if ($new = $group->first(fn ($pc) => $pc->cover)?->cover) {
                $dirs = $tracks->map(fn ($t) => dirname($targets[$t->path] ?? $t->path))->unique()->values()->all();
                $dir = count($dirs) > 1 ? AlbumPlanner::commonDir($dirs) : Scanner::albumDir($dirs[0] ?? $album->dir);
                $out[] = ['type' => 'cover_file', 'dir' => $dir, 'cover' => $new];
            } else {
                $dirs = array_unique([...$album->allDirs(), ...$tracks->pluck('dir')->all()]);
                foreach (array_unique(array_merge(...array_map(fn ($d) => Scanner::coverFiles($d), $dirs))) as $file) {
                    $out[] = ['type' => 'trash', 'path' => $file];
                }
            }
        }

        return $out;
    }

    /** Neues Cover aus dem Editor (temporäre Datei) für die Vormerkung aufheben. */
    public static function keepCover(string $tmp): string
    {
        Storage::makeDirectory('pending-covers');
        $path = Storage::path('pending-covers/'.basename($tmp));
        copy($tmp, $path);

        return $path;
    }

    /** Aufgehobene Cover, die keine Vormerkung mehr braucht, löschen. */
    public static function pruneCovers(): void
    {
        $used = array_map('basename', PendingChange::whereNotNull('cover')->pluck('cover')->all());
        foreach (Storage::files('pending-covers') as $file) {
            if (! in_array(basename($file), $used, true)) {
                Storage::delete($file);
            }
        }
    }

    /** Vorgemerkte Felder mit ihren gewünschten Werten (aktueller Zwilling). */
    private static function wantedTags(PendingChange $pc): array
    {
        return array_intersect_key($pc->track->tags(), (array) $pc->tags_before);
    }

    /** Besteht die Vormerkung noch unverändert (gleiche Revision)? */
    public static function stillCurrent(int $id, int $revision): bool
    {
        return PendingChange::whereKey($id)->where('revision', $revision)->exists();
    }

    /**
     * Nach dem Schreiben einer vorgemerkten Datei: erledigt, wenn seit dem Start nichts Neues vorgemerkt wurde; sonst für
     * die geschriebenen Felder den neuen Dateistand merken (Rest bleibt vorgemerkt).
     *
     * @return array|null übrige Vormerkung (für die Weitergabe an den neuen Pfad), null = erledigt
     */
    public static function written(int $id, int $revision, array $writtenTags): ?array
    {
        $pc = PendingChange::find($id);
        if (! $pc || $pc->revision === $revision) {
            $pc?->delete();

            return null;
        }
        $before = (array) $pc->tags_before;
        foreach ($writtenTags as $k => $v) {
            if (array_key_exists($k, $before)) {
                $before[$k] = $v;
                if (self::same($k, $v, $pc->tags[$k] ?? null)) {
                    unset($before[$k]);
                }
            }
        }
        // Cover ist geschrieben; bleibt nichts übrig, ist die Vormerkung erledigt
        $pc->update(['tags_before' => $before, 'cover' => null, 'remove_cover' => false, 'embed_cover' => false]);
        if (! $before && ! $pc->relocate) {
            $pc->delete();

            return null;
        }

        return ['tags_before' => $before, 'tags' => array_intersect_key((array) $pc->tags, $before), 'relocate' => false, 'labels' => $pc->labels];
    }

    /** Vormerkung nach dem Verschieben auf den neuen Titel (neue Zeile nach dem Einlesen) übertragen. */
    public static function carryOver(string $path, array $data): void
    {
        $track = Track::where('path', $path)->first();
        if (! $track || ! $data['tags_before']) {
            return;
        }
        foreach ($data['tags'] as $k => $v) {
            $track->{$k} = $v;
        }
        $track->save();
        PendingChange::updateOrCreate(['track_id' => $track->id], $data + ['revision' => 1]);
    }

    /**
     * Nach dem Einlesen (Datei extern geändert): Zwilling behält die vorgemerkten Werte, der Dateistand wird nachgeführt.
     *
     * @param  list<string>  $paths
     */
    public static function reapply(array $paths): void
    {
        foreach (array_chunk($paths, 500) as $chunk) {
            $pending = PendingChange::with('track')->whereHas('track', fn ($q) => $q->whereIn('path', $chunk))->get();
            foreach ($pending as $pc) {
                $file = $pc->track->tags();
                $before = (array) $pc->tags_before;
                foreach ((array) $pc->tags as $k => $v) {
                    $before[$k] = $file[$k] ?? null; // Dateistand wie gerade gelesen
                    $pc->track->{$k} = $v;
                }
                $pc->track->save();
                $pc->update(['tags_before' => $before]);
            }
        }
    }

    /** @param  list<int>|null  $albumIds */
    private function query(?array $albumIds)
    {
        $q = PendingChange::query();
        if ($albumIds !== null) {
            $q->whereHas('track', fn ($t) => $t->whereIn('album_id', $albumIds));
        }

        return $q;
    }

    /** Alben neu bilden (nur DB) und dem Aufräumen-Stand sicher mitteilen, dass sich etwas geändert hat. */
    public function afterChange(array $dirs): void
    {
        if ($dirs) {
            app(Scanner::class)->rebuildAlbums(null, $dirs);
        }
        Cache::increment(Scanner::INDEX_BUMP);
    }

    private static function same(string $key, mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return array_values((array) $a) === array_values((array) $b);
        }
        if ($key === 'compilation') {
            return (bool) $a === (bool) $b;
        }

        return trim((string) $a) === trim((string) $b);
    }

    /** @return Collection<int, PendingChange> */
    public function forAlbum(int $albumId): Collection
    {
        return $this->query([$albumId])->with('track')->get();
    }
}
