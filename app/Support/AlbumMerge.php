<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Track;

/**
 * Mehrere Alben mit demselben Zielordner (z. B. dasselbe Album doppelt unter zwei Interpreten-Schreibweisen) – nie mischen:
 * - Titel ergänzen sich (andere Nummern, z. B. ein auf mehrere Interpreten-Ordner verteiltes Album) → zusammen
 * - dasselbe Album doppelt (gleiche Nummer, gleiches Lied) → je Lied nur eine Fassung: Ziel der vorhandenen, beim
 *   Schreiben entscheidet die Qualität („best_skip“), die schlechtere wandert in den Papierkorb
 * - ein anderes Album mit gleichem Ordner → das hinzukommende bleibt, wo es ist (Tags werden trotzdem geschrieben)
 * In „Kein Album“-Ordnern (Einzellieder) gilt dasselbe je Datei: gleiches Ziel → beste Fassung oder bleibt liegen.
 */
class AlbumMerge
{
    public function __construct(private Settings $settings) {}

    /**
     * @param  list<array>  $actions  Aktionen wie aus PendingChanges::actions (apply/move mit target, album_files …)
     * @return list<array> dieselben Aktionen, Ziele ggf. angepasst bzw. zurückgehalten („held“, „merge“, „on_conflict“)
     */
    public function resolve(array $actions): array
    {
        $moving = [];
        foreach ($actions as $i => $a) {
            if (in_array($a['type'], ['apply', 'move'], true) && ! empty($a['target']) && $a['target'] !== $a['path']) {
                $moving[$a['path']] = $i;
            }
        }
        if (! $moving) {
            return $actions;
        }
        // Zielordner (Album-Ebene) je bewegter Datei; nur Ordner, in die mehr als eine Quelle will, genauer ansehen
        $tracks = collect(array_chunk(array_keys($moving), 500))
            ->flatMap(fn ($paths) => Track::whereIn('path', $paths)->get())->keyBy('path');
        $byDir = [];
        foreach ($moving as $path => $i) {
            if ($t = $tracks[$path] ?? null) {
                $byDir[Scanner::albumDir(dirname($actions[$i]['target']))][(int) $t->album_id][] = $t;
            }
        }
        $residentAlbums = collect(array_chunk(array_keys($byDir), 500))
            ->flatMap(fn ($dirs) => Album::where('location', 'library')->whereIn('dir', $dirs)->get(['id', 'dir']));
        $residents = $residentAlbums->isEmpty() ? collect() : Track::whereIn('album_id', $residentAlbums->pluck('id'))->get()
            ->reject(fn ($t) => isset($moving[$t->path]));
        foreach ($residents as $t) {
            $dir = $residentAlbums->firstWhere('id', $t->album_id)->dir;
            $byDir[$dir][(int) $t->album_id][] = $t;
        }

        $noAlbum = (string) $this->settings->get('no_album');
        $dropAlbumFiles = [];
        foreach ($byDir as $dir => $sources) {
            if (count($sources) < 2) {
                continue;
            }
            $final = fn (Track $t) => isset($moving[$t->path]) ? $actions[$moving[$t->path]]['target'] : $t->path;
            if (basename($dir) === $noAlbum) {
                $this->resolveLoose($actions, $moving, $sources, $final);

                continue;
            }
            // führend: das Album, das schon dort liegt, sonst das größte
            $resident = array_keys(array_intersect_key($sources, array_flip($residentAlbums->where('dir', $dir)->pluck('id')->all())));
            uksort($sources, fn ($a, $b) => [! in_array($a, $resident, true), -count($sources[$a]), $a] <=> [! in_array($b, $resident, true), -count($sources[$b]), $b]);
            $kept = [];
            foreach ($sources as $albumId => $group) {
                if (! $kept) {
                    $kept = $this->byPosition($group);

                    continue;
                }
                $pairs = [];
                $foreign = false;
                foreach ($group as $t) {
                    $pos = self::position($t);
                    $other = $pos !== null ? ($kept[$pos] ?? null) : null;
                    if ($other === null) {
                        // gleicher Dateiname ohne passende Nummer: kein Zusammenführen
                        $foreign = $foreign || collect($kept)->contains(fn ($k) => $final($k) === $final($t));

                        continue;
                    }
                    if (! self::sameSong($t, $other)) {
                        $foreign = true;

                        continue;
                    }
                    $pairs[] = [$t, $other];
                }
                $dropAlbumFiles[$group[0]->dir] = true;
                if ($foreign) {
                    // anderes Album mit gleichem Zielordner: nicht mischen
                    foreach ($group as $t) {
                        if (isset($moving[$t->path])) {
                            $a = &$actions[$moving[$t->path]];
                            $a['held'] = 'nicht verschoben – im Zielordner liegt ein anderes Album ('.basename($dir).')';
                            unset($a['target']);
                            unset($a);
                        }
                    }

                    continue;
                }
                foreach ($pairs as [$t, $other]) {
                    if (isset($moving[$t->path])) {
                        // doppelt: auf dasselbe Ziel, beim Schreiben bleibt die bessere Fassung
                        $a = &$actions[$moving[$t->path]];
                        $a['target'] = $final($other);
                        $a['on_conflict'] = 'best_skip';
                        $a['merge'] = true;
                        unset($a);
                    }
                }
                $kept += $this->byPosition($group);
            }
        }

        // Cover & Extras eines aufgelösten Doppels bleiben im alten Ordner (dann unter „Reste“), statt neben das Album zu
        // fallen; zurückgehaltenes reines Verschieben entfällt (ohne Tags gibt es nichts zu tun)
        return array_values(array_filter($actions, fn ($a) => match ($a['type']) {
            'album_files' => ! isset($dropAlbumFiles[$a['from_dir']]),
            'move' => ! empty($a['target']),
            default => true,
        }));
    }

    /** „Kein Album“: Einzellieder aus mehreren Ordnern – gleiche Datei am Ziel → beste Fassung, sonst bleibt sie liegen. */
    private function resolveLoose(array &$actions, array $moving, array $sources, \Closure $final): void
    {
        $seen = [];
        foreach ($sources as $group) {
            foreach ($group as $t) {
                $seen[mb_strtolower($final($t))][(int) $t->album_id] = true;
            }
        }
        foreach ($sources as $albumId => $group) {
            foreach ($group as $t) {
                $key = mb_strtolower($final($t));
                if (count($seen[$key]) > 1 && isset($moving[$t->path])) {
                    $actions[$moving[$t->path]]['on_conflict'] = 'best_skip';
                    $actions[$moving[$t->path]]['merge'] = true;
                }
            }
        }
    }

    /** @return array<string, Track> */
    private function byPosition(array $group): array
    {
        $out = [];
        foreach ($group as $t) {
            if (($pos = self::position($t)) !== null) {
                $out[$pos] ??= $t;
            }
        }

        return $out;
    }

    private static function position(Track $t): ?string
    {
        $track = (int) $t->track;

        return $track > 0 ? max(1, (int) $t->disc).'|'.$track : null;
    }

    /** Gleiches Lied nach Tags: Länge (wie beim Qualitätsvergleich) und Titel (einer beginnt mit dem anderen). */
    public static function sameSong(Track $a, Track $b): bool
    {
        if (abs((float) $a->duration - (float) $b->duration) > Quality::LENGTH_TOLERANCE) {
            return false;
        }
        $key = fn (Track $t) => preg_replace('/[^a-z0-9]+/', '', strtolower(ArtistNames::stripAccents((string) $t->title)));
        [$ka, $kb] = [$key($a), $key($b)];

        return $ka !== '' && $kb !== '' && (str_starts_with($ka, $kb) || str_starts_with($kb, $ka));
    }
}
