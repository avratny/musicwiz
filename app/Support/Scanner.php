<?php

namespace App\Support;

use App\Models\Album;
use App\Models\DiscSet;
use App\Models\Leftover;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Liest Inbox, Lib und Blackhole per Knopf ein (jeder Bereich für sich): gedrosselt und inkrementell (unveränderte Dateien werden übersprungen).
 */
class Scanner
{
    public const STATUS_KEY = 'musicwiz.scan';

    /** Abbruch angefordert (wird zwischen zwei Ordnern bzw. Lesepaketen geprüft) */
    public const CANCEL_KEY = 'musicwiz.scan.cancel';

    /** Scan abbrechen: bis dahin Gelesenes bleibt, der nächste Scan macht dort weiter. */
    public function requestCancel(): void
    {
        if ($this->status()['running'] ?? false) {
            Cache::put(self::CANCEL_KEY, true, now()->addHours(6));
            $this->status(['phase' => 'wird abgebrochen …']);
        }
    }

    public function cancelRequested(): bool
    {
        return Cache::has(self::CANCEL_KEY);
    }

    /** Vor einem neuen Scan: alte Abbruch-Anforderung vergessen. */
    public static function clearCancel(): void
    {
        Cache::forget(self::CANCEL_KEY);
    }

    /** Status nach einem Abbruch (Index unvollständig: nichts entfernt, keine Automatiken). */
    public function markCancelled(int $read = 0, int $total = 0): array
    {
        self::clearCancel();
        $result = ['files' => 0, 'read' => $read, 'removed' => 0, 'held' => 0, 'unreadable' => 0, 'cancelled' => true];
        $this->status(['running' => false, 'phase' => 'abgebrochen', 'finished_at' => now()->toIso8601String(), 'result' => $result,
            'cancelled' => ['read' => $read, 'total' => $total]]);

        return $result;
    }

    /** Dateien pro Lesepaket (werden auf mehrere Tag-Tool-Prozesse verteilt, siehe scan_workers) */
    private const BATCH = 80;

    /** Ordner pro Abfrage beim Neubilden der Alben (SQLite bricht bei zu langen OR-Ketten ab) */
    private const SCOPE_CHUNK = 100;

    /** Fehlen mehr als so viele bekannte Dateien (und mehr als 20 %), wird erst nach Rückfrage entfernt */
    private const MASS_REMOVAL = 50;

    /** @var list<string> Ordner, die beim letzten Durchlauf nicht lesbar waren */
    private array $unreadable = [];

    /** @var array<string, list<string>> Cover-Dateien je Ordner aus dem Durchlauf (spart ein zweites scandir pro Album) */
    private array $coverNames = [];

    /** Pause pro gelesenem Ordner in Mikrosekunden (schont das Share) */
    private int $dirPause = 0;

    public function __construct(private Settings $settings, private TagReader $reader) {}

    /** @return list<string> */
    public function roots(string $location): array
    {
        $roots = $location === 'inbox'
            ? [$this->settings->inbox()]
            : array_map(fn ($c) => $this->settings->categoryRoot($c), $this->settings->categoriesIn($location));

        return array_values(array_unique(array_filter($roots, 'is_dir')));
    }

    /** Alle eingestellten Wurzeln, auch wenn sie gerade nicht erreichbar sind. */
    private function configuredRoots(): array
    {
        return array_values(array_unique(array_map(fn ($r) => rtrim($r, '/'), [
            $this->settings->inbox(),
            ...array_map(fn ($c) => $this->settings->categoryRoot($c), $this->settings->categories()),
        ])));
    }

    /**
     * Eingestellte Wurzel, unter der laut Index Musik liegt, die aber fehlt oder leer ist – meist ist das Share nicht
     * eingehängt. Aktionen darauf würden sonst ins leere Mount-Verzeichnis schreiben (Dateien später unter dem Share versteckt).
     *
     * @param  list<string|null>|null  $paths  nur Wurzeln prüfen, unter denen einer dieser Pfade liegt
     */
    public function unreachableRoot(?array $paths = null): ?string
    {
        foreach ($this->configuredRoots() as $root) {
            if ($root === '' || ($paths !== null && ! collect($paths)->contains(fn ($p) => $p && str_starts_with($p.'/', $root.'/')))) {
                continue;
            }
            if (! FileOps::hasEntries($root) && Track::where('path', 'like', $root.'/%')->exists()) {
                return $root;
            }
        }

        return null;
    }

    /**
     * @param  bool  $allowMassRemoval  auch entfernen, wenn auffällig viele bekannte Dateien fehlen (nach Rückfrage)
     */
    public function scan(string $location, bool $allowMassRemoval = false): array
    {
        $this->status(['running' => true, 'location' => $location, 'phase' => 'Ordner lesen', 'done' => 0, 'total' => 0, 'started_at' => now()->toIso8601String(),
            'error' => null, 'held' => null, 'unreadable' => null, 'auto_skipped' => null, 'cancelled' => null]);

        // Fehlt eine Wurzel, unter der schon Dateien erfasst sind (Share weg, Mount fehlt), nichts anfassen
        foreach ($this->configuredRoots() as $root) {
            if (! is_dir($root) && Track::where('location', $location)->where('path', 'like', $root.'/%')->exists()) {
                throw new RuntimeException('Ordner nicht erreichbar: '.$root.' – Scan abgebrochen, der Index bleibt unverändert.');
            }
        }
        $roots = $this->roots($location);

        $files = [];
        $leftovers = [];
        $this->unreadable = [];
        $this->coverNames = [];
        $this->dirPause = $this->throttle() / 2;
        try {
            foreach ($roots as $root) {
                $this->walk($root, $files, $leftovers);
            }
        } catch (ScanCancelled) {
            $this->dirPause = 0;

            return $this->markCancelled(); // beim Durchgehen der Ordner: noch nichts geändert
        }
        $this->dirPause = 0;

        $existing = Track::where('location', $location)->get(['id', 'path', 'mtime', 'size'])->keyBy('path');
        // Tracks, die in einem anderen Bereich erfasst waren (z. B. vorher Inbox), mitnehmen
        $changed = [];
        foreach ($files as $path => $stat) {
            $known = $existing[$path] ?? null;
            if (! $known || $known->mtime !== $stat['mtime'] || $known->size !== $stat['size']) {
                $changed[] = $path;
            }
        }

        $throttle = $this->throttle();
        $total = count($changed);
        $this->status(['phase' => 'Tags lesen', 'total' => $total, 'files' => count($files)]);
        $readDirs = [];
        foreach (array_chunk($changed, self::BATCH) as $i => $chunk) {
            if ($this->cancelRequested()) {
                // Gelesenes behalten und dafür Alben bilden; nichts entfernen (Index unvollständig)
                $this->status(['phase' => 'Alben bilden (abgebrochen)']);
                $this->rebuildAlbums(null, array_keys($readDirs));

                return $this->markCancelled(min($total, $i * self::BATCH), $total);
            }
            $this->store($location, $this->reader->readMany($chunk), $files);
            foreach ($chunk as $p) {
                $readDirs[dirname($p)] = true;
            }
            $this->status(['done' => min($total, ($i + 1) * self::BATCH), 'current' => basename(dirname(end($chunk)))]);
            if ($throttle) {
                usleep($throttle * count($chunk));
            }
        }

        // Verschwundene Dateien entfernen – aber nicht unterhalb von Ordnern, die gerade nicht lesbar waren
        $gone = array_values(array_filter($existing->keys()->diff(array_keys($files))->all(),
            fn ($p) => ! $this->underAny($p, $this->unreadable)));
        $held = null;
        if (! $allowMassRemoval && count($gone) > self::MASS_REMOVAL && count($gone) > 0.2 * count($existing)) {
            $held = ['location' => $location, 'count' => count($gone), 'known' => count($existing)];
            $gone = [];
        }
        foreach (array_chunk($gone, 500) as $chunk) {
            Track::whereIn('path', $chunk)->delete();
        }

        // Reste nur im Bestand merken (Aufräumen gilt nur dort); alte Inbox-Einträge fallen dabei weg
        $this->storeLeftovers($roots, $location === 'library' ? $leftovers : []);
        $this->status(['phase' => 'Alben bilden']);
        $this->rebuildAlbums($location);

        $this->coverNames = [];

        self::clearCancel(); // kam der Abbruch erst ganz am Ende, ist der Scan trotzdem vollständig
        $result = ['files' => count($files), 'read' => $total, 'removed' => count($gone), 'held' => $held['count'] ?? 0, 'unreadable' => count($this->unreadable)];
        $this->status(['running' => false, 'phase' => 'fertig', 'finished_at' => now()->toIso8601String(), 'result' => $result,
            'held' => $held, 'unreadable' => $this->unreadable ? array_slice($this->unreadable, 0, 20) : null]);

        return $result;
    }

    /** Eingestellte Drossel pro Datei in Mikrosekunden. */
    private function throttle(): int
    {
        return max(0, (int) $this->settings->get('scan_throttle_ms')) * 1000;
    }

    private function underAny(string $path, array $dirs): bool
    {
        foreach ($dirs as $d) {
            if (str_starts_with($path, $d.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Einzelne Pfade neu einlesen (nach Verschieben/Schreiben). Nicht vorhandene Pfade werden entfernt.
     *
     * @param  list<string>  $paths
     */
    public function rescanPaths(array $paths): void
    {
        $audio = config('musicwiz.audio_extensions');
        $paths = array_values(array_unique(array_filter($paths, fn ($p) => in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), $audio, true))));
        $dirs = [];
        // exakte Schreibweise prüfen: "Titel.MP3" gilt nach dem Umbenennen in "Titel.mp3" als verschwunden
        $listings = [];
        $existing = array_values(array_filter($paths, fn ($p) => is_file($p) && FileOps::existsExact($p, $listings)));
        unset($listings);
        $missing = array_values(array_diff($paths, $existing));

        // Ist die Wurzel eines fehlenden Pfads gerade nicht erreichbar, ist die Datei nicht weg – das Share ist weg
        $roots = $this->configuredRoots();
        foreach ($missing as $p) {
            foreach ($roots as $root) {
                if (str_starts_with($p, $root.'/') && ! is_dir($root)) {
                    throw new RuntimeException('Ordner nicht erreichbar: '.$root.' – Index nicht angepasst.');
                }
            }
        }

        foreach (array_chunk($paths, 500) as $chunk) {
            foreach (Track::whereIn('path', $chunk)->get(['dir']) as $t) {
                $dirs[$t->dir] = true;
            }
        }
        foreach (array_chunk($missing, 500) as $chunk) {
            Track::whereIn('path', $chunk)->delete();
        }
        foreach (array_chunk($existing, self::BATCH) as $chunk) {
            $byLocation = [];
            foreach ($chunk as $p) {
                $byLocation[$this->settings->locationOf($p)][] = $p;
                $dirs[dirname($p)] = true;
            }
            foreach ($byLocation as $location => $list) {
                $stats = [];
                foreach ($list as $p) {
                    clearstatcache(true, $p);
                    $stats[$p] = ['mtime' => filemtime($p), 'size' => filesize($p)];
                }
                $this->store($location, $this->reader->readMany($list), $stats);
            }
            // gleiche Drossel wie beim Scan: große Aktionen lesen sonst Tausende Dateien am Stück
            if ($throttle = $this->throttle()) {
                usleep($throttle * count($chunk));
            }
        }
        $this->rebuildAlbums(null, array_keys($dirs));
    }

    /** Pfade im Index nach Umbenennen eines Ordners anpassen (nur Schreibweise o. Ä.). */
    public function renameDirInIndex(string $old, string $new): void
    {
        $swap = fn (?string $p) => $p !== null && ($p === $old || str_starts_with($p, $old.'/')) ? $new.substr($p, strlen($old)) : $p;
        DB::transaction(function () use ($old, $swap) {
            foreach (Track::where('path', 'like', $old.'/%')->get() as $t) {
                if (str_starts_with($t->path, $old.'/')) {
                    Track::whereKey($t->id)->update(['path' => $swap($t->path), 'dir' => $swap($t->dir)]);
                }
            }
            foreach (Album::where('dir', 'like', $old.'%')->get() as $a) {
                if ($a->dir === $old || str_starts_with($a->dir, $old.'/')) {
                    Album::whereKey($a->id)->update(['dir' => $swap($a->dir), 'dirs' => $a->dirs ? json_encode(array_map($swap, $a->dirs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        'cover_file' => $swap($a->cover_file)]);
                }
            }
            foreach (Leftover::where('path', 'like', $old.'%')->get() as $l) {
                if ($l->path === $old || str_starts_with($l->path, $old.'/')) {
                    $l->update(['path' => $swap($l->path)]);
                }
            }
        });
    }

    public function status(?array $merge = null): array
    {
        $current = Cache::get(self::STATUS_KEY, []);
        if ($merge !== null) {
            $current = array_merge($current, $merge);
            Cache::forever(self::STATUS_KEY, $current);
        }

        return $current;
    }

    /**
     * Ordner rekursiv lesen. Merkt Reste: Junk-Dateien, leere Ordner, Ordner ohne eine einzige Musikdatei
     * (z. B. nur ein liegengebliebenes Folder.jpg) und kaputte Namen wie "-Interpret@1-".
     *
     * @return array{content: bool, audio: bool}
     */
    private function walk(string $dir, array &$files, array &$leftovers): array
    {
        $audioExt = config('musicwiz.audio_extensions');
        $junk = config('musicwiz.junk_files');
        $entries = @scandir($dir);
        if ($entries === false) {
            $this->unreadable[] = $dir;

            return ['content' => true, 'audio' => true]; // nicht lesbar: lieber nichts melden
        }
        if ($this->dirPause) {
            usleep($this->dirPause);
        }
        if ($this->cancelRequested()) {
            throw new ScanCancelled;
        }
        $this->coverNames[$dir] = array_values(array_filter($entries, fn ($e) => (bool) preg_match('/^(cover|folder|front)\./i', $e)));
        $content = false;
        $audio = false;
        $directAudio = false;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            // eine Abfrage pro Eintrag (übers Netzwerk ist jede ein Hin und Zurück): Art, Größe, Änderungszeit
            $st = @lstat($path);
            $type = $st ? $st['mode'] & 0170000 : 0;
            if ($type === 0120000) { // Verknüpfung
                $content = $audio = true;

                continue;
            }
            if ($type === 0040000) {
                $sub = $this->walk($path, $files, $leftovers);
                $content = $content || $sub['content'];
                $audio = $audio || $sub['audio'];

                continue;
            }
            // Zwischendatei eines abgebrochenen Tag-Schreibens: das Original ist unverändert, die Kopie ist ein Rest
            if (in_array($entry, $junk, true) || str_starts_with($entry, '._') || str_ends_with($entry, TagTool::WRITE_SUFFIX)) {
                $leftovers[$path] = 'junk';

                continue;
            }
            $content = true;
            if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $audioExt, true)) {
                $audio = $directAudio = true;
                $files[$path] = ['mtime' => (int) ($st['mtime'] ?? 0), 'size' => (int) ($st['size'] ?? 0)];
            }
        }
        if ($directAudio) {
            // Unterordner mit Extras (z. B. albumart_backup, Scans) in einem Album gehören zum Album – kein Rest
            foreach ($leftovers as $p => $kind) {
                if ($kind === 'no_music' && dirname($p) === $dir) {
                    unset($leftovers[$p]);
                }
            }
        }
        if (! $content || ! $audio) {
            // nur den obersten betroffenen Ordner melden – darunterliegende Reste sind darin enthalten
            foreach (array_keys($leftovers) as $p) {
                if (str_starts_with($p, $dir.'/')) {
                    unset($leftovers[$p]);
                }
            }
            $leftovers[$dir] = $content ? 'no_music' : 'empty_dir';
        } elseif (preg_match('/^-.*@\d+-$|^-Interpret-$/', basename($dir))) {
            $leftovers[$dir] = 'bad_dir';
        }

        return ['content' => $content, 'audio' => $audio];
    }

    /** Wurzeln, unterhalb derer nach Resten gesucht wird (nur Kategorie-Ordner – Aufräumen gilt nur im Bestand). */
    private function leftoverRoots(): array
    {
        return $this->roots('library');
    }

    /**
     * Reste nach einer Aktion neu prüfen: betroffene Ordner samt Geschwistern (Künstler-Ebene).
     *
     * @param  list<string>  $paths  berührte Dateien
     */
    public function refreshLeftovers(array $paths): void
    {
        $roots = $this->leftoverRoots();
        $starts = [];
        foreach ($paths as $p) {
            $dir = dirname(dirname($p)); // Künstlerordner
            while ($dir !== '/' && ! is_dir($dir)) {
                $dir = dirname($dir);
            }
            $root = collect($roots)->first(fn ($r) => $dir === $r || str_starts_with($dir.'/', $r.'/'));
            if (! $root) {
                continue;
            }
            $starts[$dir] = $root;
        }
        // verschachtelte Startpunkte zusammenfassen
        $dirs = array_keys($starts);
        sort($dirs);
        $unique = [];
        foreach ($dirs as $d) {
            if (! collect($unique)->contains(fn ($u) => str_starts_with($d.'/', $u.'/'))) {
                $unique[] = $d;
            }
        }
        foreach ($unique as $dir) {
            $files = [];
            $left = [];
            $this->walk($dir, $files, $left);
            Leftover::where('path', $dir)->orWhere('path', 'like', $dir.'/%')->delete();
            $now = now();
            foreach ($left as $path => $kind) {
                if (! in_array($path, $roots, true)) {
                    Leftover::updateOrCreate(['path' => $path], ['kind' => $kind, 'updated_at' => $now]);
                }
            }
        }
        // Reste, die es nicht mehr gibt – nur dort nachsehen, wo die Aktion etwas angefasst hat
        // (unterhalb der neu durchlaufenen Ordner ist schon alles aktuell; früher: file_exists auf jeden Rest im Bestand)
        $near = [];      // berührte Pfade und ihre Ordner
        $ancestors = []; // alle Ordner oberhalb davon
        foreach ($paths as $p) {
            $near[$p] = $near[dirname($p)] = true;
            for ($d = $p; $d !== '/' && $d !== '.' && ! isset($ancestors[$d]); $d = dirname($d)) {
                $ancestors[$d] = true;
            }
        }
        foreach (Leftover::all() as $l) {
            // Rest liegt über einem berührten Pfad oder darunter
            $related = isset($ancestors[$l->path]);
            for ($d = dirname($l->path); ! $related && $d !== '/' && $d !== '.'; $d = dirname($d)) {
                $related = isset($near[$d]);
            }
            if ($related && ! file_exists($l->path)) {
                $l->delete();
            }
        }
    }

    private function store(string $location, array $read, array $stats): void
    {
        $now = now();
        $rows = [];
        // Lesefehler bei einer Datei, die schon mit gültigen Daten im Index steht: alte Werte behalten, nur den Fehler
        // vermerken und beim nächsten Scan neu lesen (mtime 0). Sonst würde ein kurzer Share-Aussetzer Tags und Länge
        // löschen – und Aufräumen hielte die Datei für defekt bzw. das Album für uneinheitlich.
        $failed = array_keys(array_filter($read, fn ($a) => $a['read_failed'] ?? false));
        $keep = $failed ? Track::whereIn('path', $failed)->where('duration', '>=', 0.1)->pluck('path')->flip()->all() : [];
        foreach (array_keys($keep) as $path) {
            Track::where('path', $path)->update([
                'error' => 'Zuletzt nicht lesbar, bisherige Werte behalten: '.($read[$path]['error'] ?? 'Lesefehler'),
                'mtime' => 0,
                'updated_at' => $now,
            ]);
        }
        foreach ($read as $path => $attrs) {
            unset($attrs['read_failed']);
            if (isset($keep[$path])) {
                continue;
            }
            $row = $attrs + [
                'location' => $location,
                'path' => $path,
                'dir' => dirname($path),
                'filename' => basename($path),
                'ext' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                'size' => $stats[$path]['size'] ?? 0,
                'mtime' => $stats[$path]['mtime'] ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            foreach (['artists', 'albumartists', 'genres'] as $k) {
                $row[$k] = json_encode($row[$k], JSON_UNESCAPED_UNICODE);
            }
            $rows[] = $row;
        }
        if ($rows) {
            $columns = array_diff(array_keys($rows[0]), ['path', 'created_at']);
            Track::upsert($rows, ['path'], $columns);
        }
    }

    private function storeLeftovers(array $roots, array $leftovers): void
    {
        foreach ($roots as $root) {
            Leftover::where('path', 'like', $root.'/%')->delete();
        }
        $now = now();
        $rows = [];
        foreach ($leftovers as $path => $kind) {
            if (in_array($path, $roots, true)) {
                continue; // leere Wurzelordner sind kein Rest
            }
            $rows[] = ['path' => $path, 'kind' => $kind, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            Leftover::upsert($chunk, ['path'], ['kind', 'updated_at']);
        }
    }

    /** Alben bilden: ein Album = ein Ordner; Unterordner wie "CD1"/"Disc 2" gehören zum übergeordneten Album. */
    public static function albumDir(string $dir): string
    {
        return preg_match('/^(cd|disc|disk)\s*\d+$/i', basename($dir)) ? dirname($dir) : $dir;
    }

    /**
     * @param  list<string>|null  $dirs  nur diese Track-Ordner neu berechnen
     */
    public function rebuildAlbums(?string $location, ?array $dirs = null): void
    {
        $query = Track::query();
        if ($location) {
            $query->where('location', $location);
        }
        $scope = null;
        if ($dirs !== null) {
            $scope = array_map([self::class, 'albumDir'], $dirs);
            // Inbox: Geschwister-Ordner mit einbeziehen, damit Mehrfach-CDs vollständig zusammengefasst werden
            foreach ($scope as $d) {
                if ($this->settings->isInInbox(dirname($d).'/')) {
                    $scope[] = dirname($d);
                }
            }
            $scope = array_values(array_unique($scope));
        }

        // in Blöcken abfragen: eine OR-Kette über Hunderte Ordner sprengt SQLite ("Expression tree is too large")
        $tracks = [];
        foreach ($scope === null ? [null] : array_chunk($scope, self::SCOPE_CHUNK) as $chunk) {
            $q = clone $query;
            if ($chunk !== null) {
                $q->where(fn ($w) => $this->whereInDirs($w, $chunk));
            }
            foreach ($q->cursor() as $track) {
                $tracks[$track->id] = $track;
            }
        }
        ksort($tracks);
        $groups = [];
        foreach ($tracks as $track) {
            $groups[self::albumDir($track->dir)][] = $track;
        }
        unset($tracks);
        $sets = $this->discSets($groups);
        DB::transaction(function () use ($sets) {
            foreach ($sets as $set) {
                $album = $this->saveAlbum($set['dirs'][0], $set['tracks'], $set['dirs'], $set['disc_map'] ?? []);
                Track::whereIn('id', array_map(fn ($t) => $t->id, $set['tracks']))->update(['album_id' => $album->id]);
            }
        });

        // Alben ohne Tracks entfernen
        if ($scope !== null) {
            foreach (array_chunk($scope, self::SCOPE_CHUNK) as $chunk) {
                Album::query()->whereDoesntHave('tracks')->where(fn ($w) => $this->whereInDirs($w, $chunk))->delete();
            }
        } else {
            $orphans = Album::query()->whereDoesntHave('tracks');
            if ($location) {
                $orphans->where('location', $location);
            }
            $orphans->delete();
        }
    }

    /** Ordner samt Unterordnern (Spalte "dir"). */
    private function whereInDirs($query, array $dirs): void
    {
        foreach ($dirs as $d) {
            $query->orWhere('dir', $d)->orWhere('dir', 'like', $d.'/%');
        }
    }

    /** "Bravo Hits, Vol. 39, Disc 2" -> 2 */
    public static function discFromName(?string $name): ?int
    {
        return preg_match('/(?:^|[\s,;:\-\(\[])(?:cd|disc|disk)\s*0*(\d{1,2})\s*[\)\]]?\s*$/i', trim((string) $name), $m) ? (int) $m[1] : null;
    }

    /** "Bravo Hits, Vol. 39, Disc 2" -> "Bravo Hits, Vol. 39" */
    public static function stripDisc(?string $name): string
    {
        return trim(preg_replace('/[\s,;:\-]*[\(\[]?\s*(?:cd|disc|disk)\s*0*\d{1,2}\s*[\)\]]?\s*$/i', '', trim((string) $name)));
    }

    /** Vergleichsschlüssel für Albumnamen: ohne CD-Angabe, Satzzeichen, Leerzeichen; "1956-65" = "1956-1965". */
    public static function albumKey(?string $album): string
    {
        $s = self::stripDisc($album);
        $s = preg_replace_callback('/\b(\d{2})(\d{2})\s*[-–\/]\s*(\d{2})\b(?!\d)/u', fn ($m) => $m[1].$m[2].'-'.$m[1].$m[3], $s);

        return preg_replace('/[^a-z0-9]+/', '', strtolower(ArtistNames::stripAccents((string) $s))) ?? '';
    }

    /** Fast gleich: identische Zahlen, Buchstaben unterscheiden sich höchstens um 2 Zeichen. */
    public static function similarAlbumKeys(string $a, string $b): bool
    {
        if (preg_replace('/\D/', '', $a) !== preg_replace('/\D/', '', $b)) {
            return false;
        }
        $la = preg_replace('/\d/', '', $a);
        $lb = preg_replace('/\d/', '', $b);

        return min(strlen($la), strlen($lb)) >= 8 && levenshtein($la, $lb) <= 2;
    }

    /**
     * Inbox: Geschwister-Ordner einer Mehrfach-CD zu einem Album zusammenfassen.
     * Bedingung: gleicher Elternordner, gleicher Albumname (ohne CD-Angabe), gleicher Album-Interpret,
     * jeder Teil mit eigener, unterschiedlicher CD-Nummer (aus Ordnername, Albumname oder Tag).
     *
     * @param  array<string, list<Track>>  $groups
     * @return list<array{dirs: list<string>, tracks: list<Track>}>
     */
    private function discSets(array $groups): array
    {
        $mode = function (array $values) {
            $values = array_filter($values, fn ($v) => $v !== null && $v !== '');
            if (! $values) {
                return null;
            }
            $c = array_count_values(array_map('strval', $values));
            arsort($c);

            return (string) array_key_first($c);
        };
        $candidates = [];
        $out = [];
        // Von Hand zusammengeführte CDs haben Vorrang
        foreach (DiscSet::all() as $set) {
            $members = array_values(array_filter($set->members, fn ($m) => isset($groups[$m['dir']])));
            if (count($members) < 2) {
                continue;
            }
            usort($members, fn ($a, $b) => $a['disc'] <=> $b['disc']);
            $out[] = [
                'dirs' => array_column($members, 'dir'),
                'tracks' => array_merge(...array_map(fn ($m) => $groups[$m['dir']], $members)),
                'disc_map' => array_combine(array_column($members, 'dir'), array_map('intval', array_column($members, 'disc'))),
            ];
            foreach ($members as $m) {
                unset($groups[$m['dir']]);
            }
        }
        foreach ($groups as $dir => $tracks) {
            if ($tracks[0]->location !== 'inbox') {
                $out[] = ['dirs' => [$dir], 'tracks' => $tracks];

                continue;
            }
            $album = $mode(array_map(fn ($t) => $t->album, $tracks)) ?? basename($dir);
            $disc = self::discFromName(basename($dir)) ?? self::discFromName($album);
            if ($disc === null) {
                $tagDisc = (int) $mode(array_map(fn ($t) => (int) $t->disc, $tracks));
                $total = (int) $mode(array_map(fn ($t) => (int) $t->disctotal, $tracks));
                $disc = $total > 1 && $tagDisc > 0 ? $tagDisc : null;
            }
            $scope = dirname($dir).'|'.mb_strtolower((string) $mode(array_map(fn ($t) => $t->albumartist, $tracks)));
            $candidates[$scope][] = ['dir' => $dir, 'tracks' => $tracks, 'disc' => $disc, 'key' => self::albumKey($album)];
        }

        foreach ($candidates as $parts) {
            // 1) gleiche Schlüssel bündeln
            $clusters = [];
            foreach ($parts as $p) {
                $clusters[$p['key']][] = $p;
            }
            // 2) fast gleiche Schlüssel zusammenführen: gleiche Zahlen, max. 2 Buchstaben Unterschied, keine doppelten CD-Nummern
            $keys = array_keys($clusters);
            foreach ($keys as $i => $a) {
                foreach (array_slice($keys, $i + 1) as $b) {
                    if (! isset($clusters[$a], $clusters[$b]) || ! self::similarAlbumKeys($a, $b)) {
                        continue;
                    }
                    $discsA = array_column($clusters[$a], 'disc');
                    $discsB = array_column($clusters[$b], 'disc');
                    if (in_array(null, [...$discsA, ...$discsB], true) || array_intersect($discsA, $discsB)) {
                        continue;
                    }
                    array_push($clusters[$a], ...$clusters[$b]);
                    unset($clusters[$b]);
                }
            }
            foreach ($clusters as $cluster) {
                $discs = array_column($cluster, 'disc');
                $mergeable = count($cluster) > 1 && ! in_array(null, $discs, true) && count(array_unique($discs)) === count($discs);
                if (! $mergeable) {
                    foreach ($cluster as $p) {
                        $out[] = ['dirs' => [$p['dir']], 'tracks' => $p['tracks']];
                    }

                    continue;
                }
                usort($cluster, fn ($x, $y) => $x['disc'] <=> $y['disc']);
                $out[] = ['dirs' => array_column($cluster, 'dir'), 'tracks' => array_merge(...array_column($cluster, 'tracks')),
                    'disc_map' => array_combine(array_column($cluster, 'dir'), array_column($cluster, 'disc'))];
            }
        }

        return $out;
    }

    private function saveAlbum(string $dir, array $tracks, array $dirs = [], array $discMap = []): Album
    {
        $mode = function (array $values) {
            $values = array_filter($values, fn ($v) => $v !== null && $v !== '');
            if (! $values) {
                return null;
            }
            $counts = array_count_values($values);
            arsort($counts);

            return (string) array_key_first($counts);
        };
        $pluck = fn ($k) => array_map(fn ($t) => is_scalar($t->{$k}) ? (string) $t->{$k} : null, $tracks);

        $location = $tracks[0]->location;
        $category = $location !== 'inbox'
            ? ($this->settings->categoryForPath($dir.'/')['name'] ?? null)
            : ($this->settings->category($mode($pluck('comment')))['name'] ?? null);
        $isCompilation = (bool) ($this->settings->category($category)['compilation'] ?? false)
            || count(array_filter($tracks, fn ($t) => $t->compilation)) > count($tracks) / 2;

        $folderArtist = $mode(array_map(fn ($t) => PathBuilder::folderArtist($t->tags()), $tracks));
        $albumartist = $mode($pluck('albumartist'));
        if (! $albumartist) {
            $artists = array_unique(array_filter($pluck('artist')));
            $albumartist = count($artists) === 1 ? reset($artists) : null;
        }
        $genres = [];
        foreach ($tracks as $t) {
            foreach ($t->genres ?? [] as $g) {
                $genres[$g] = ($genres[$g] ?? 0) + 1;
            }
        }
        arsort($genres);

        [$coverFile, $fileSize] = [null, null];
        foreach ($dirs ?: [$dir] as $d) {
            [$coverFile, $fileSize] = self::findCoverFile($d, $this->coverNames[$d] ?? null);
            if ($coverFile) {
                break;
            }
        }
        $embedded = array_values(array_filter($tracks, fn ($t) => $t->cover_embedded));
        $coverSize = $embedded ? $embedded[0]->cover_size : $fileSize;

        $letter = null;
        if (! $isCompilation && $folderArtist) {
            $letter = app(PathBuilder::class)->letter($folderArtist);
        }

        return Album::updateOrCreate(['dir' => $dir], [
            'location' => $location,
            'category' => $category,
            'title' => count($dirs) > 1
                ? self::stripDisc($mode(array_map(fn ($t) => $t->album, array_filter($tracks, fn ($t) => $t->dir === $dir))) ?? $mode($pluck('album')) ?? basename($dir))
                : ($mode($pluck('album')) ?? basename($dir)),
            'dirs' => count($dirs) > 1 ? $dirs : null,
            'disc_map' => count($dirs) > 1 && $discMap ? $discMap : null,
            'albumartist' => $albumartist,
            'folder_artist' => $isCompilation ? null : $folderArtist,
            'letter' => $letter,
            'year' => PathBuilder::year($mode($pluck('year')) ?? '') ?: null,
            'genres' => array_slice(array_keys($genres), 0, 4),
            'is_compilation' => $isCompilation,
            'track_count' => count($tracks),
            'duration' => (int) array_sum(array_map(fn ($t) => $t->duration ?? 0, $tracks)),
            'cover_file' => $coverFile,
            'cover_embedded' => (bool) $embedded,
            'cover_size' => $coverSize,
        ]);
    }

    /** Alle Cover-Dateien (cover.*, folder.*, front.*) im Ordner. */
    public static function coverFiles(string $dir): array
    {
        return array_values(array_map(fn ($e) => $dir.'/'.$e, array_filter(@scandir($dir) ?: [],
            fn ($e) => (bool) preg_match('/^(cover|folder|front)\.(jpe?g|png)$/i', $e))));
    }

    /**
     * @param  list<string>|null  $entries  Ordnerinhalt, falls schon bekannt (aus dem Scan-Durchlauf)
     * @return array{0:?string, 1:?int}
     */
    public static function findCoverFile(string $dir, ?array $entries = null): array
    {
        $preferred = ['cover.jpg', 'cover.jpeg', 'cover.png', 'folder.jpg', 'folder.jpeg', 'folder.png', 'front.jpg', 'front.png'];
        $entries ??= @scandir($dir) ?: [];
        $byLower = [];
        foreach ($entries as $e) {
            $byLower[strtolower($e)] = $e;
        }
        foreach ($preferred as $name) {
            if (isset($byLower[$name])) {
                $path = $dir.'/'.$byLower[$name];

                return [$path, self::imageSize($path)];
            }
        }

        return [null, null];
    }

    /** Kürzere Seite eines Bildes; das Bild wird nur gelesen, wenn es sich seit dem letzten Mal geändert hat. */
    private static function imageSize(string $path): ?int
    {
        $stat = @stat($path);
        if (! $stat) {
            return null;
        }

        return Cache::remember('image-size:'.md5($path.'|'.$stat['mtime'].'|'.$stat['size']), now()->addDays(30), function () use ($path) {
            $size = @getimagesize($path);

            return $size ? min($size[0], $size[1]) : null;
        });
    }
}
