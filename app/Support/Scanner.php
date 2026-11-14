<?php

namespace App\Support;

use App\Jobs\LookupArtists;
use App\Jobs\MeasureFingerprints;
use App\Models\Artist;
use App\Models\FolderImage;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Lookup\Client;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Einlesen eines Bereichs: der Tag-Leser (tools/tagreader.py) durchläuft den Ordner und liest nur neue bzw. geänderte
 * Dateien – parallel. Hier landen die Ergebnisse paketweise in der Datenbank. Felder, die in der Datenbank bearbeitet
 * wurden (tag_changes), bleiben erhalten; die rohen Tags zeigen immer den Stand der Datei.
 */
class Scanner
{
    private const BATCH = 500;

    /** Stand des Tag-Lesers: Lieder mit älterem Stand werden einmal komplett neu gelesen (2: Cover-Daten, 3: mehrere Interpreten) */
    public const READ_VERSION = 4;   // 4: Songtexte

    /** @var array<int, true> Interpreten der gelesenen Lieder */
    private array $touchedArtists = [];

    /** aus einem anderen Bereich übernommene Lieder in diesem Durchgang */
    private int $relocated = 0;

    /** @var array<string, string> Album-Schlüssel alt => neu der übernommenen Lieder */
    private array $relocatedAlbums = [];

    /** @return array{files: int, read: int, removed: int, errors: int, kept_missing: int, folders: int, moved: int, relocated: int, merged: int} */
    public function scan(string $location): array
    {
        $root = Locations::root($location);
        $label = Locations::LABELS[$location].' einlesen';
        Activity::start('scan', $label);
        // abgebrochene Datei-Aktionen abgleichen, bevor Fehlendes entfernt wird (sonst gingen Verlauf und ID verloren)
        app(FileMover::class)->recover();
        $artistsBefore = Artist::count();
        $this->relocated = 0;
        $this->relocatedAlbums = [];

        // älterer Stand des Tag-Lesers bzw. beim letzten Mal nicht lesbar: als geändert melden (mtime -1), damit sie neu
        // gelesen und trotzdem erkannt werden
        $known = [];
        foreach (Track::where('location', $location)->select(['path', 'mtime', 'size', 'read_version', 'error', 'file_id'])->cursor() as $t) {
            $known[$t->path] = [$t->read_version >= self::READ_VERSION && $t->error === null ? (int) $t->mtime : -1, (int) $t->size, $t->file_id];
        }
        $knownImages = [];
        foreach (FolderImage::where('location', $location)->select(['path', 'mtime', 'size'])->cursor() as $i) {
            $knownImages[$i->path] = [(int) $i->mtime, (int) $i->size];
        }

        $process = new Process(['python3', base_path('tools/tagreader.py'), 'scan']);
        $process->setTimeout(null);
        $process->setInput(json_encode([
            'roots' => [$root],
            'known' => (object) $known,
            'known_images' => (object) $knownImages,
            'extensions' => config('musicwiz.audio_extensions'),
            'workers' => config('musicwiz.scan_workers'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $process->start();

        $stats = ['files' => 0, 'read' => 0, 'removed' => 0, 'errors' => 0, 'kept_missing' => 0, 'folders' => 0, 'moved' => 0, 'relocated' => 0, 'merged' => 0];
        $batch = [];
        $done = 0;
        $images = [];
        $buffer = '';
        $missingRoots = [];
        foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $msg = json_decode($line, true);
                if (! is_array($msg)) {
                    continue;
                }
                switch ($msg['type']) {
                    case 'walk':
                        $stats['files'] = $msg['files'];
                        $stats['read'] = $msg['read'];
                        $missingRoots = $msg['missing_roots'];
                        Activity::progress('scan', 0, $msg['read'], $label.' – '.number_format($msg['read'], 0, ',', '.').' von '.number_format($msg['files'], 0, ',', '.').' Dateien neu oder geändert');
                        break;
                    case 'moved':
                        $stats['moved'] += $this->moved($location, $msg) ? 1 : 0;
                        break;
                    case 'ids':
                        $this->storeFileIds($msg['items']);
                        break;
                    case 'track':
                        $batch[] = $msg;
                        $stats['errors'] += empty($msg['ok']) ? 1 : 0;
                        if (count($batch) >= self::BATCH) {
                            $this->store($location, $batch);
                            $done += count($batch);
                            $batch = [];
                            Activity::progress('scan', $done);
                        }
                        break;
                    case 'image':
                        $images[] = $msg;
                        break;
                    case 'deleted':
                        if ($batch) {
                            $this->store($location, $batch);
                            $done += count($batch);
                            $batch = [];
                            Activity::progress('scan', $done);
                        }
                        $missing = $this->mergeIntoTwins($location, $msg['paths']);
                        $stats['merged'] = count($msg['paths']) - count($missing);
                        $stats = $this->removeMissing($missing, count($known) - $stats['merged'], $stats);
                        $this->storeImages($location, $images, $msg['images'] ?? []);
                        break;
                }
            }
        }
        $process->wait();
        if (! $process->isSuccessful()) {
            Activity::finish('scan', $label.': Fehler beim Lesen');
            throw new RuntimeException('Tag-Leser: '.trim($process->getErrorOutput()));
        }

        $stats['relocated'] = $this->relocated;
        // ganz übernommene Alben: Prüfungen und Vormerkungen ziehen mit
        foreach ($this->relocatedAlbums as $old => $new) {
            if (! Track::where('album_hash', $old)->exists()) {
                FileMover::followHash($old, $new);
            }
        }
        $newArtists = Artist::count() - $artistsBefore;
        // erst die Schreibweise bereinigen (doppelte Leerzeichen, Bindestriche …), dann die Namensregeln
        app(TextCleanup::class)->apply();
        app(NameRules::class)->apply();
        app(ArtistCredits::class)->apply();
        // Sortiernamen: Eindeutiges selbst eintragen (umgedrehte Namen, fehlende Sortiernamen)
        if (config('musicwiz.sortnames_auto')) {
            app(SortNames::class)->apply();
        }
        app(ArtistGenres::class)->syncIds(array_keys($this->touchedArtists));
        // zu breite Genres („Rock/Pop“) auch aus neu eingelesenen Liedern entfernen
        app(GenreCleanup::class)->apply();
        // Lieder ohne Kategorie vorbelegen (nach den Schreibweisen: „Various Artists“ macht ein Album zum Sampler)
        if (config('musicwiz.category_fill')) {
            app(Categories::class)->fill();
        }
        $this->touchedArtists = [];
        Client::prune();
        // neu gesetzte Cover ohne Verweis, dann Cover-Kopien von Liedern und Bildern, die es so nicht mehr gibt
        CoverStore::prune();
        Covers::prune();
        // leere Ordner (nur Systemdateien) entfernen – nicht, wenn der Bereich nicht erreichbar ist oder auffällig viel fehlt
        if (! $missingRoots && ! $stats['kept_missing']) {
            $stats['folders'] = app(FileMover::class)->removeEmptyFolders($location);
        }
        $message = $missingRoots
            ? $label.': Ordner nicht erreichbar ('.implode(', ', $missingRoots).') – nichts entfernt'
            : $label.': '.number_format($stats['files'], 0, ',', '.').' Lieder, '.$stats['read'].' neu gelesen'
                .($stats['moved'] ? ', '.$stats['moved'].' umbenannt erkannt' : '')
                .($stats['relocated'] ? ', '.$stats['relocated'].' aus anderen Bereichen übernommen' : '')
                .($stats['merged'] ? ', '.$stats['merged'].' veraltete mit dem Eintrag im anderen Bereich zusammengeführt' : '')
                .($stats['removed'] ? ', '.$stats['removed'].' entfernt' : '')
                .($stats['folders'] ? ', '.$stats['folders'].' leere Ordner gelöscht' : '')
                .($stats['errors'] ? ', '.$stats['errors'].' nicht lesbar' : '')
                .($newArtists ? ', '.$newArtists.' neue Interpreten' : '')
                .($stats['kept_missing'] ? ' – '.$stats['kept_missing'].' fehlende Dateien NICHT entfernt (Share eingehängt?)' : '');
        Activity::finish('scan', $message);
        // neue Lieder der Inbox: Fingerabdruck im Hintergrund (Lib und Blackhole nur auf Knopfdruck)
        if ($location === 'inbox' && config('musicwiz.fingerprint_inbox')) {
            MeasureFingerprints::start(['inbox']);
        }
        // neue bzw. noch nicht abgeglichene Interpreten im Hintergrund abgleichen
        if (config('musicwiz.lookup_auto') && in_array($location, ['library', 'inbox'], true)) {
            LookupArtists::start();
        }

        return $stats;
    }

    /**
     * Nach dem Schreiben: frisch gelesene Dateien übernehmen – dasselbe Lied (gleicher Pfad), Audio unverändert, also
     * bleibt auch die gemessene Lautheit.
     */
    public function refresh(string $location, array $results): void
    {
        foreach (array_chunk($results, self::BATCH) as $chunk) {
            $this->store($location, $chunk, true);
        }
        app(ArtistGenres::class)->syncIds(array_keys($this->touchedArtists));
        $this->touchedArtists = [];
    }

    /** Ein Paket Leseergebnisse speichern (neu bzw. aktualisieren). */
    private function store(string $location, array $results, bool $sameAudio = false): void
    {
        $now = now();
        $rows = [];
        foreach ($results as $r) {
            $rows[sha1($r['path'])] = $this->row($location, $r, $now);
        }
        $relocated = $sameAudio ? [] : $this->relocate($location, $rows);
        // in der Datenbank bearbeitete Felder behalten (die Datei hat noch den alten Stand)
        $existing = Track::whereIn('path_hash', array_keys($rows))->get(['id', 'path_hash', 'size', 'loudness', 'loudness_peak', 'fingerprint', ...array_keys(Track::EDITABLE)])->keyBy('id');
        // gemessene Lautheit (ReplayGain) behalten, solange die Datei gleich groß ist – sonst neu messen
        $kept = $existing->keyBy('path_hash');
        foreach ($rows as $hash => $row) {
            $same = isset($kept[$hash]) && ($sameAudio || (int) $kept[$hash]->size === (int) $row['size']);
            $rows[$hash]['loudness'] = $same ? $kept[$hash]->loudness : null;
            $rows[$hash]['loudness_peak'] = $same ? $kept[$hash]->loudness_peak : null;
            // aus einem anderen Bereich übernommen: dieselbe Datei, der Fingerabdruck gilt weiter
            if (isset($relocated[$hash])) {
                $rows[$hash]['fingerprint'] ??= $kept[$hash]->fingerprint;
            }
        }
        // bekanntes Lied gerade nicht lesbar (Datei wird geschrieben, Netz weg): nur den Fehler vermerken, die Tags bleiben –
        // beim nächsten Einlesen wird es wieder gelesen
        foreach ($rows as $hash => $row) {
            if ($row['error'] !== null && isset($kept[$hash])) {
                Track::where('path_hash', $hash)->update(['error' => $row['error'], 'size' => $row['size'], 'mtime' => $row['mtime'], 'scanned_at' => $now, 'updated_at' => $now]);
                unset($rows[$hash]);
            }
        }
        if (! $rows) {
            return;
        }
        if ($existing->isNotEmpty()) {
            $edited = TagChange::whereIn('track_id', $existing->keys())->select(['track_id', 'field'])->distinct()->get();
            foreach ($edited as $e) {
                $track = $existing[$e->track_id];
                if (array_key_exists($e->field, $rows[$track->path_hash])) {
                    $rows[$track->path_hash][$e->field] = $track->getRawOriginal($e->field);
                }
            }
        }
        $update = array_values(array_diff(array_keys(reset($rows)), ['path_hash', 'created_at']));
        DB::transaction(fn () => Track::upsert(array_values($rows), ['path_hash'], $update));
        // mit dem Künstlerverzeichnis verbinden (unbekannte Namen werden als Künstler angelegt)
        $tracks = Track::whereIn('path_hash', array_keys($rows))->get(ArtistLinker::COLUMNS);
        app(ArtistLinker::class)->link($tracks->all());
        // Genres dieser Interpreten am Ende des Einlesens neu verknüpfen
        foreach (DB::table('track_artist')->whereIn('track_id', $tracks->pluck('id'))->pluck('artist_id') as $id) {
            $this->touchedArtists[$id] = true;
        }
    }

    public function row(string $location, array $r, $now): array
    {
        $path = $r['path'];
        $dir = dirname($path);
        $albumDir = self::albumDir($dir);
        $core = $r['core'] ?? [];
        $info = $r['info'] ?? [];
        $str = fn ($v, $max = 512) => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, $max);
        $int = fn ($v) => ($n = (int) preg_replace('/\D.*$/', '', trim((string) $v))) > 0 ? min($n, 65535) : null;

        return [
            'location' => $location,
            'path' => $path,
            'path_hash' => sha1($path),
            'dir' => $dir,
            'album_dir' => $albumDir,
            'album_hash' => self::albumHash($location, $albumDir),
            'filename' => mb_substr(basename($path), 0, 512),
            'ext' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'size' => (int) $r['size'],
            'mtime' => (int) $r['mtime'],
            'file_id' => $r['fid'] ?? null,
            'format' => $info['format'] ?? null,
            'duration' => $info['duration'] ?? null,
            'bitrate' => $info['bitrate'] ?? null,
            'sample_rate' => $info['sample_rate'] ?? null,
            'channels' => $info['channels'] ?? null,
            'bits' => $info['bits'] ?? null,
            'has_cover' => (bool) ($info['has_cover'] ?? false),
            'cover_width' => self::dimension($info['cover']['width'] ?? null),
            'cover_height' => self::dimension($info['cover']['height'] ?? null),
            'cover_phash' => $info['cover']['phash'] ?? null,
            'error' => empty($r['ok']) ? mb_substr((string) ($r['error'] ?? 'nicht lesbar'), 0, 512) : null,
            'title' => $str($core['title'] ?? ''),
            'artist' => $str($core['artist'] ?? ''),
            'album' => $str($core['album'] ?? ''),
            'albumartist' => $str($core['albumartist'] ?? ''),
            'artists' => $str($core['artists'] ?? '', 1024),
            'albumartists' => $str($core['albumartists'] ?? '', 1024),
            'track' => $int($core['track'] ?? ''),
            'track_total' => $int($core['track_total'] ?? ''),
            'disc' => $int($core['disc'] ?? ''),
            'disc_total' => $int($core['disc_total'] ?? ''),
            'year' => $str($core['year'] ?? '', 32),
            'genre' => $str($core['genre'] ?? '', 255),
            'comment' => $str($core['comment'] ?? '', 65000),
            'lyrics' => Lyrics::normalize($core['lyrics'] ?? null),
            'compilation' => (bool) ($core['compilation'] ?? false),
            'category' => Categories::fromRawTags($r['tags'] ?? []),
            'rating' => Ratings::fromRawTags($r['tags'] ?? []),
            ...SortNames::fromRawTags($r['tags'] ?? []),
            ...ReplayGain::fromRawTags($r['tags'] ?? []),
            ...SplitAlbums::fromRawTags($r['tags'] ?? []),
            'fingerprint' => Fingerprints::fromRawTags($r['tags'] ?? []),
            'fingerprint_failed_at' => null,
            'tags' => json_encode($r['tags'] ?? (object) [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'scanned_at' => $now,
            'read_version' => self::READ_VERSION,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Außerhalb umbenannt bzw. verschoben (gleiche Datei-ID): den Eintrag mitziehen – gleiche ID, Verlauf, Änderungen
     * und Verknüpfungen bleiben. Ist das alte Album danach leer, wandern seine Prüfungen mit.
     */
    private function moved(string $location, array $msg): bool
    {
        $track = Track::where('path_hash', sha1($msg['from']))->first();
        if (! $track || Track::where('path_hash', sha1($msg['to']))->exists()) {
            return false;
        }
        $old = $track->album_hash;
        $dir = dirname($msg['to']);
        $albumDir = self::albumDir($dir);
        $track->update(['path' => $msg['to'], 'path_hash' => sha1($msg['to']), 'dir' => $dir, 'album_dir' => $albumDir,
            'album_hash' => self::albumHash($location, $albumDir), 'filename' => mb_substr(basename($msg['to']), 0, 512),
            'ext' => strtolower(pathinfo($msg['to'], PATHINFO_EXTENSION)), 'file_id' => $msg['fid'] ?? null]);
        if ($old !== $track->album_hash && ! Track::where('album_hash', $old)->exists()) {
            FileMover::followHash($old, $track->album_hash);
        }

        return true;
    }

    /**
     * Außerhalb in einen anderen Bereich verschoben (z. B. Inbox → Lib, anderes Share – die Datei-ID ändert sich): neue
     * Datei mit gleichem Namen und gleicher Größe wie genau ein Lied eines anderen Bereichs, dessen Datei fehlt (Bereich
     * erreichbar) – den Eintrag übernehmen statt neu anlegen. ID, Verlauf und Änderungen bleiben; die Tags kommen danach
     * wie bei jedem bekannten Lied aus der Datei (in der Datenbank Bearbeitetes bleibt).
     *
     * @param  array<string, array>  $rows  path_hash => Zeile
     * @return array<string, true> path_hash der übernommenen
     */
    private function relocate(string $location, array $rows): array
    {
        $new = array_diff_key($rows, array_flip(Track::whereIn('path_hash', array_keys($rows))->pluck('path_hash')->all()));
        $reachable = array_filter(array_keys(Locations::LABELS), fn ($l) => $l !== $location && is_dir(Locations::root($l)));
        if (! $new || ! $reachable) {
            return [];
        }
        $candidates = Track::whereIn('location', $reachable)->whereIn('size', array_unique(array_column($new, 'size')))
            ->whereIn('filename', array_unique(array_column($new, 'filename')))->get(['id', 'location', 'path', 'filename', 'size', 'album_hash'])
            ->groupBy(fn ($t) => $t->filename.'|'.$t->size);
        $claims = $relocated = [];
        foreach ($new as $row) {
            $claims[$row['filename'].'|'.$row['size']][] = $row;
        }
        foreach ($claims as $key => $claimed) {
            $gone = ($candidates[$key] ?? collect())->reject(fn ($t) => is_file($t->path));
            // nur eindeutig: genau ein verschwundenes Lied und genau eine neue Datei dazu
            if ($gone->count() !== 1 || count($claimed) !== 1) {
                continue;
            }
            $track = $gone->first();
            $row = $claimed[0];
            $this->relocatedAlbums[$track->album_hash] = $row['album_hash'];
            $track->update(['location' => $location, 'path' => $row['path'], 'path_hash' => $row['path_hash'], 'dir' => $row['dir'],
                'album_dir' => $row['album_dir'], 'album_hash' => $row['album_hash'], 'filename' => $row['filename'], 'ext' => $row['ext']]);
            $relocated[$row['path_hash']] = true;
            $this->relocated++;
        }

        return $relocated;
    }

    /**
     * Fehlende Dateien, die in einem anderen Bereich schon einen eigenen Eintrag haben (außerhalb verschoben und dort
     * neu angelegt, bevor das Einlesen das erkannte): gleicher Name, gleiche Größe, dort vorhanden, eindeutig. Offene
     * Änderungen von Hand wandern zum anderen Eintrag (außer er hat das Feld selbst von Hand geändert), Fingerabdruck,
     * Lautheit und Songtext-Abfrage werden ergänzt, Album-Prüfungen ziehen mit; der veraltete Eintrag wird entfernt.
     *
     * @param  list<string>  $paths
     * @return list<string> die übrigen fehlenden Pfade
     */
    private function mergeIntoTwins(string $location, array $paths): array
    {
        $others = array_filter(array_keys(Locations::LABELS), fn ($l) => $l !== $location && is_dir(Locations::root($l)));
        if (! $paths || ! $others) {
            return $paths;
        }
        $editor = app(TagEditor::class);
        $merged = [];
        foreach (array_chunk($paths, self::BATCH) as $chunk) {
            $stale = Track::whereIn('path_hash', array_map('sha1', $chunk))->get();
            $twins = Track::whereIn('location', $others)->whereIn('size', $stale->pluck('size')->unique())
                ->whereIn('filename', $stale->pluck('filename')->unique())->get()->groupBy(fn ($t) => $t->filename.'|'.$t->size);
            $claims = $stale->groupBy(fn ($t) => $t->filename.'|'.$t->size);
            foreach ($claims as $key => $claimed) {
                $twin = ($twins[$key] ?? collect())->filter(fn ($t) => is_file($t->path));
                if ($twin->count() !== 1 || $claimed->count() !== 1) {
                    continue;
                }
                $twin = $twin->first();
                $old = $claimed->first();
                DB::transaction(function () use ($old, $twin, $editor) {
                    $own = TagChange::where('track_id', $twin->id)->where('source', TagEditor::MANUAL)->pluck('field')->all();
                    $fields = TagChange::where('track_id', $old->id)->where('source', TagEditor::MANUAL)->whereNull('written_at')
                        ->whereNotIn('field', $own)->distinct()->pluck('field');
                    if ($fields->isNotEmpty()) {
                        $editor->update($twin, $fields->mapWithKeys(fn ($f) => [$f => $old->getAttribute($f)])->all(), TagEditor::MANUAL,
                            'übernommen von '.Locations::LABELS[$old->location].' (dort verschoben)');
                    }
                    $twin->update(array_filter(['fingerprint' => $twin->fingerprint ?? $old->fingerprint,
                        'loudness' => $twin->loudness ?? $old->loudness, 'loudness_peak' => $twin->loudness_peak ?? $old->loudness_peak], fn ($v) => $v !== null));
                    if (! DB::table('lyrics_lookups')->where('track_id', $twin->id)->exists()) {
                        DB::table('lyrics_lookups')->where('track_id', $old->id)->update(['track_id' => $twin->id]);
                    }
                    $old->delete();
                });
                if (! Track::where('album_hash', $old->album_hash)->exists()) {
                    FileMover::followHash($old->album_hash, $twin->album_hash);
                }
                $merged[] = $old->path;
            }
        }

        return array_values(array_diff($paths, $merged));
    }

    /** Datei-IDs nachtragen (ohne die Dateien neu zu lesen) */
    private function storeFileIds(array $items): void
    {
        foreach (array_chunk($items, 1000) as $chunk) {
            $case = implode(' ', array_fill(0, count($chunk), 'WHEN ? THEN ?'));
            $bindings = [];
            foreach ($chunk as [$path, $fid]) {
                array_push($bindings, sha1($path), $fid);
            }
            DB::update('UPDATE tracks SET file_id = CASE path_hash '.$case.' END WHERE path_hash IN ('.implode(',', array_fill(0, count($chunk), '?')).')',
                [...$bindings, ...array_map(fn ($i) => sha1($i[0]), $chunk)]);
        }
    }

    /** Bilder im Ordner (cover/folder/front): neue bzw. geänderte speichern, verschwundene entfernen */
    private function storeImages(string $location, array $images, array $deleted): void
    {
        $now = now();
        $rows = array_map(fn ($i) => [
            'location' => $location,
            'path' => $i['path'],
            'path_hash' => sha1($i['path']),
            'dir' => dirname($i['path']),
            'dir_hash' => sha1(dirname($i['path'])),
            'size' => (int) $i['size'],
            'mtime' => (int) $i['mtime'],
            'width' => self::dimension($i['cover']['width'] ?? null),
            'height' => self::dimension($i['cover']['height'] ?? null),
            'phash' => $i['cover']['phash'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $images);
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            FolderImage::upsert($chunk, ['path_hash'], ['location', 'dir', 'dir_hash', 'size', 'mtime', 'width', 'height', 'phash', 'updated_at']);
        }
        foreach (array_chunk($deleted, self::BATCH) as $chunk) {
            FolderImage::whereIn('path_hash', array_map('sha1', $chunk))->delete();
        }
    }

    private static function dimension(mixed $v): ?int
    {
        return is_numeric($v) && $v > 0 ? min((int) $v, 65535) : null;
    }

    /** Bekannte Dateien, die es nicht mehr gibt, entfernen – außer es fehlen auffällig viele (Share nicht eingehängt?). */
    private function removeMissing(array $paths, int $known, array $stats): array
    {
        if (! $paths) {
            return $stats;
        }
        if (count($paths) > 20 && count($paths) > $known * config('musicwiz.scan_max_missing')) {
            $stats['kept_missing'] = count($paths);

            return $stats;
        }
        foreach (array_chunk($paths, self::BATCH) as $chunk) {
            $stats['removed'] += Track::whereIn('path_hash', array_map('sha1', $chunk))->delete();
        }

        return $stats;
    }

    /**
     * Album-Ordner einer CD: Unterordner „CD1“/„Disc 2“ gehören zum Ordner darüber, Geschwister wie „Hits, Disc 1“ und
     * „Hits, Disc 2“ (auch „Hits CD1“, „Hits (CD 2)“, „Hits - Disk 3“, „Hits [CD2]“) zum Album „Hits“.
     */
    public static function albumDir(string $dir): string
    {
        $name = basename($dir);
        if (preg_match('/^(cd|disc|disk)\s*\d+$/i', $name)) {
            return dirname($dir);
        }
        if (preg_match('/^(.*?\S)[\s,._-]*[(\[]?\s*(cd|disc|disk)\s*\d+\s*[)\]]?$/iu', $name, $m)) {
            return dirname($dir).'/'.rtrim($m[1], ' ,.-_');
        }

        return $dir;
    }

    /** Gruppierung nach Album: gleicher Album-Ordner, Groß/klein egal („Just The Best“ / „Just the Best“) */
    public static function albumHash(string $location, string $albumDir): string
    {
        return sha1($location.'|'.mb_strtolower($albumDir));
    }
}
