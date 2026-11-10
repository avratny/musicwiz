<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Track;

/**
 * Berechnet aus den Eingaben des Editors die neuen Tags und Zielpfade (Vorschau) und daraus die Aktionen.
 */
class AlbumPlanner
{
    public function __construct(
        private Settings $settings,
        private PathBuilder $paths,
        private Genres $genres,
        private ArtistDirectory $directory,
        private SortNames $sortNames,
    ) {}

    /**
     * @param  array  $fields  album, albumartist, year, genres (Auswahl), category, persons (mehrdeutiger Name => voller Name)
     * @param  array  $trackEdits  [track_id => [title, artist, track, disc]]
     * @param  bool  $move  an den Regelort verschieben
     * @param  ?string  $cover  neues Cover (temporäre JPEG-Datei)
     * @param  bool  $removeCover  Cover entfernen (eingebettet + Cover-Datei in den Papierkorb)
     */
    public function plan(Album $album, array $fields, array $trackEdits, bool $move, ?string $cover = null, bool $removeCover = false): array
    {
        $tracks = $album->tracks()->with('pendingChange')->get();
        $category = $this->settings->category($fields['category'] ?? null);
        $compilationArtist = (string) $this->settings->get('compilation_artist');
        $warnings = [];
        $rows = [];

        $newTagSets = [];
        $unresolved = [];
        $persons = (array) ($fields['persons'] ?? []);
        foreach ($tracks as $t) {
            $edit = $trackEdits[$t->id] ?? [];
            $new = $t->tags();
            foreach (['title', 'artist', 'track', 'disc'] as $k) {
                if (array_key_exists($k, $edit)) {
                    $new[$k] = trim((string) $edit[$k]);
                }
            }
            foreach (['album', 'albumartist', 'year'] as $k) {
                if (array_key_exists($k, $fields)) {
                    $new[$k] = trim((string) $fields[$k]);
                }
            }
            // nur die Jahreszahl speichern („2003-05-12“ → „2003“), auch wenn ein Datum eingegeben bzw. nachgeschlagen wurde
            if (PathBuilder::year($new['year'] ?? '') !== '') {
                $new['year'] = PathBuilder::year($new['year']);
            }
            // leeres Genre-Feld lässt die vorhandenen Genres in Ruhe: das Feld zeigt nur Listen-Genres – Werte, die nicht auf
            // der Liste stehen („Schrammeln“), gingen sonst beim Speichern verloren (Zuordnung: Aufräumen › Genres)
            if (array_filter((array) ($fields['genres'] ?? []))) {
                $new['genres'] = $this->genres->expand((array) $fields['genres']);
            }
            if (! empty($edit['genres'])) {
                $new['genres'] = $this->genres->expand((array) $edit['genres']); // Sampler: Genre pro Track
            }
            if ($category) {
                $new['comment'] = $category['name'];
                $new['category'] = $category['name'];
                $new['compilation'] = $category['compilation'];
                if ($category['compilation']) {
                    $new['albumartist'] = $compilationArtist;
                }
            }
            // ARTISTS/ALBUMARTISTS: gleichnamige Künstler über die Zuordnung auflösen ("Adam" -> "Adam [DE]")
            $a = $this->directory->resolveNames($new['artist'], (array) ($t->artists ?? []), $persons);
            $aa = $this->directory->resolveNames($new['albumartist'], (array) ($t->albumartists ?? []), $persons);
            $new['artists'] = $a['names'];
            $new['albumartists'] = $aa['names'];
            $new = $this->sortNames->tags($new) + $new;
            $unresolved[$t->id] = array_values(array_unique([...$a['unresolved'], ...$aa['unresolved']]));
            $newTagSets[$t->id] = self::clearNumbersWithoutAlbum($new);
        }

        // Disc-Gesamtzahl pflegen, wenn verschiedene CD-Nummern vorkommen
        $discs = array_unique(array_filter(array_map(fn ($n) => (int) $n['disc'], $newTagSets)));
        if (count($discs) > 1) {
            foreach ($newTagSets as &$n) {
                $n['disctotal'] = (string) max($discs);
            }
            unset($n);
        }
        $multiDisc = PathBuilder::isMultiDisc($newTagSets);
        // Ordner-Jahr fürs ganze Album, auch wenn die Titel verschiedene Jahre tragen (Tags bleiben)
        $albumYear = PathBuilder::albumYear(array_column($newTagSets, 'year'));

        if ($category && ! $category['compilation'] && app(Compilations::class)->isVariousArtists($fields['albumartist'] ?? '')) {
            $warnings[] = 'Album-Interpret ist „'.trim($fields['albumartist']).'“ (= '.$compilationArtist.'), die Kategorie ist aber keine Compilation.';
        }
        if ($move && ! $category) {
            $warnings[] = 'Keine Kategorie gewählt – ohne Kategorie wird nichts verschoben.';
        }
        if ($move && $category && ! $category['compilation'] && $newTagSets) {
            // Ordner = Künstler im Tag: weicht nur Groß/klein vom Bestand ab, würde der vorhandene Ordner umbenannt
            $folder = PathBuilder::folderArtist(reset($newTagSets));
            $uniform = $this->paths->folderName($folder);
            if ($folder !== '' && $uniform !== $folder && mb_strtolower(FileOps::nfc($uniform)) === mb_strtolower(FileOps::nfc($folder))) {
                $warnings[] = 'Im Bestand heißt der Künstler „'.$uniform.'“. Mit „'.$folder.'“ im Tag würde der vorhandene Ordner umbenannt – Schreibweise im Editor übernehmen (→ '.$uniform.').';
            }
        }

        // Cover vorhanden, fehlt aber in einzelnen Dateien: beim Speichern bzw. Einordnen mit einbetten
        $embed = null;
        if (! $cover && ! $removeCover && basename($album->dir) !== $this->settings->get('no_album')) {
            $missing = Covers::missingEmbedded($tracks);
            $source = Covers::embedSource($album, $tracks);
            if ($missing && $source) {
                $embed = ['from' => $source, 'tracks' => array_map(fn ($t) => $t->id, $missing), 'total' => $tracks->count()];
            }
        }

        $targets = [];
        foreach ($tracks as $t) {
            $new = $newTagSets[$t->id];
            // mit dem Dateistand vergleichen (nicht dem Zwilling): vorgemerkte Änderungen aus dem Aufräumen werden mitgeschrieben
            $fileTags = PendingChanges::fileTags($t);
            $pending = $t->pendingChange;
            $changed = $this->diff($fileTags, $new);
            $target = null;
            $problems = [];
            foreach ($unresolved[$t->id] ?? [] as $name) {
                $problems[] = '„'.$name.'“ gibt es mehrfach – bitte Person zuordnen';
            }
            if ($move && $category) {
                if (trim($new['artist']) === '') {
                    $problems[] = 'Interpret fehlt – wird nicht eingeordnet';
                } else {
                    $target = $this->paths->target($category, ['year' => $albumYear] + $new, $multiDisc, $t->path);
                    if ($target !== $t->path) {
                        if (file_exists($target) && ! FileOps::sameFile($t->path, $target)) {
                            $problems[] = 'Zieldatei existiert bereits – '.Quality::compare($t->path, $target)['reason'];
                        }
                        if (isset($targets[$target])) {
                            $problems[] = 'Mehrere Tracks hätten dasselbe Ziel';
                        }
                        $targets[$target] = true;
                    }
                }
            }
            if (($changed || $cover || $removeCover) && ! $t->writable) {
                $problems[] = 'Format kann nicht beschrieben werden ('.$t->ext.')';
            }
            $rows[] = [
                'track_id' => $t->id,
                'path' => $t->path,
                'target' => $target !== $t->path ? $target : null,
                'changed' => $changed,
                'pending' => $pending ? ['id' => $pending->id, 'revision' => $pending->revision] : null,
                'embed' => $embed && in_array($t->id, $embed['tracks'], true),
                'old' => array_intersect_key($fileTags, $changed),
                'problems' => $problems,
                'blocked' => (bool) array_filter($problems, fn ($p) => ! str_contains($p, 'existiert') && ! str_contains($p, 'dasselbe Ziel')),
                'conflict' => (bool) array_filter($problems, fn ($p) => str_contains($p, 'existiert') || str_contains($p, 'dasselbe Ziel')),
            ];
        }

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'category' => $category,
            'cover' => $cover,
            'embed_cover' => $embed,
            'remove_cover' => $removeCover && ! $cover,
            'cover_files' => $removeCover && ! $cover ? array_values(array_unique(array_merge(...array_map(
                fn ($d) => Scanner::coverFiles($d), array_unique(array_merge([$album->dir], array_map(fn ($t) => $t->dir, $tracks->all())))
            )))) : [],
            'album_dir' => $album->dir,
            'album_dirs' => $album->allDirs(),
            'move' => $move,
        ];
    }

    /** Aktionen aus der Vorschau; blockierte Tracks werden ausgelassen. */
    public function actions(array $plan, string $onConflict = 'skip'): array
    {
        $actions = [];
        $targetDirs = [];
        $allMoved = true;
        // Cover entfernen: Cover-Dateien zuerst in den Papierkorb
        foreach ($plan['cover_files'] ?? [] as $file) {
            $actions[] = ['type' => 'trash', 'path' => $file];
        }
        $removeCover = (bool) ($plan['remove_cover'] ?? false);
        foreach ($plan['rows'] as $row) {
            $skip = $row['blocked'] || ($row['conflict'] && ! in_array($onConflict, ['number', 'best'], true));
            if ($skip || ! $row['target']) {
                $allMoved = false;
            }
            $embed = ! empty($row['embed']) && ! empty($plan['embed_cover']['from']);
            if ($skip || (! $row['changed'] && ! $row['target'] && ! $plan['cover'] && ! $removeCover && ! $embed)) {
                if (! $skip && ! $row['target']) {
                    $targetDirs[dirname($row['path'])] = true;
                }

                continue;
            }
            $actions[] = array_filter([
                'type' => 'apply',
                'path' => $row['path'],
                'tags' => $row['changed'],
                'cover' => $plan['cover'],
                'embed_from' => $embed ? $plan['embed_cover']['from'] : null,
                'remove_cover' => $removeCover ?: null,
                'target' => $row['target'],
                'pending_id' => $row['pending']['id'] ?? null,
                'revision' => $row['pending']['revision'] ?? null,
            ], fn ($v) => $v !== null && $v !== []);
            $targetDirs[dirname($row['target'] ?? $row['path'])] = true;
        }
        $targetDirs = array_keys($targetDirs);
        $mainTarget = $targetDirs ? Scanner::albumDir($targetDirs[0]) : $plan['album_dir'];
        if (count($targetDirs) > 1) {
            // mehrere CD-Ordner: gemeinsamer Elternordner
            $mainTarget = self::commonDir($targetDirs);
        }
        $albumDirs = $plan['album_dirs'] ?? [$plan['album_dir']];
        if ($plan['move'] && $allMoved) {
            // Mehrfach-CD: Cover und leere Ordner aller CD-Ordner aufräumen
            foreach ($albumDirs as $d) {
                if ($d !== $mainTarget) {
                    $actions[] = ['type' => 'album_files', 'from_dir' => $d, 'to_dir' => $mainTarget];
                }
            }
        }
        if ($plan['cover']) {
            foreach ($plan['move'] ? [$mainTarget] : $albumDirs as $d) {
                $actions[] = ['type' => 'cover_file', 'dir' => $d, 'cover' => $plan['cover']];
            }
        }

        return $actions;
    }

    /** Ohne Albumname ("Kein Album" = Einzellieder) ergeben Track- und CD-Nummern keinen Sinn – leeren. */
    public static function clearNumbersWithoutAlbum(array $tags): array
    {
        if (trim((string) ($tags['album'] ?? '')) === '') {
            foreach (['track', 'tracktotal', 'disc', 'disctotal'] as $k) {
                $tags[$k] = '';
            }
        }

        return $tags;
    }

    public function diff(array $old, array $new): array
    {
        $changed = [];
        foreach (Track::TAG_KEYS as $k) {
            $o = $old[$k] ?? null;
            $n = $new[$k] ?? null;
            if (is_array($o) || is_array($n)) {
                if (array_values((array) $o) !== array_values((array) $n)) {
                    $changed[$k] = array_values((array) $n);
                }
            } elseif ($k === 'compilation') {
                if ((bool) $o !== (bool) $n) {
                    $changed[$k] = (bool) $n;
                }
            } elseif (trim((string) $o) !== trim((string) $n)) {
                $changed[$k] = trim((string) $n);
            }
        }

        return $changed;
    }

    public static function commonDir(array $dirs): string
    {
        $parts = array_map(fn ($d) => explode('/', $d), $dirs);
        $common = [];
        foreach ($parts[0] as $i => $seg) {
            foreach ($parts as $p) {
                if (($p[$i] ?? null) !== $seg) {
                    return implode('/', $common);
                }
            }
            $common[] = $seg;
        }

        return implode('/', $common);
    }
}
