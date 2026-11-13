<?php

namespace App\Support;

use App\Models\FileOperation;
use App\Models\FilePlan;
use App\Models\FolderImage;
use App\Models\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Plan eines Albums ausführen (Sorter): erst bessere Fassungen hinein (kopieren bzw. verschieben, mit den Album-Werten
 * als Tag-Änderung), dann die Lieder an ihr Ziel, zuletzt der Papierkorb – ein ersetztes Lied nur, wenn sein Ersatz
 * angekommen ist. Jedes Lied behält seinen Eintrag (gleiche ID –
 * Verlauf, Bewertung, Lautheit bleiben). Sind alle Lieder eines Ordners in einen gemeinsamen Ordner gewandert, ziehen
 * Bilder und Beiwerk mit; leer gewordene Ordner werden entfernt (nie die Wurzel eines Bereichs) – nach dem Einlesen
 * auch alle anderen leeren Ordner eines Bereichs (removeEmptyFolders). Nie überschreiben.
 * Jede Datei-Aktion steht in file_operations – vorher als „pending“, danach „done“; bricht ein Lauf dazwischen ab,
 * gleicht recover() beim nächsten Schreiben bzw. Einlesen Datei und Datenbank ab. Zwischen Bereichen auf verschiedenen
 * Shares wird selbst kopiert (versteckte Hilfsdatei, Größe prüfen, umbenennen, Original löschen) – nie eine halbe Datei
 * unter dem echten Namen, nie ein Original, das stehen bleibt.
 */
class FileMover
{
    /** Systemdateien, die einen Ordner nicht „belegen“ */
    private const JUNK = ['.ds_store', 'thumbs.db', 'desktop.ini'];

    /**
     * @return array{moved: int, failed: array<string, string>, new: list<int>} new: neue bzw. hereingeholte Lieder (Tags noch zu schreiben)
     */
    public function run(array $plan): array
    {
        $moved = 0;
        $failed = [];
        $new = [];
        $from = [];
        // erst kopieren, dann verschieben, zuletzt Papierkorb – ein ersetztes Lied geht nur, wenn sein Ersatz angekommen ist
        $order = ['copy-in' => 0, 'move-in' => 1, 'move' => 2, 'inbox' => 2, 'trash' => 3];
        $items = collect($plan['items'])->filter(fn ($i) => $i['kind'] !== 'stay' && $i['to'] !== null)->sortBy(fn ($i) => $order[$i['kind']]);
        $trashTo = $items->where('kind', 'trash')->mapWithKeys(fn ($i) => [$i['track']->id => $i['to']]);
        $replacedBy = $items->filter(fn ($i) => isset($i['plan']) && $i['plan']->replaces_track_id)->mapWithKeys(fn ($i) => [$i['plan']->replaces_track_id => $i['plan']->id]);
        $imported = [];
        $trashed = [];
        $editor = app(TagEditor::class);
        foreach ($items as $item) {
            $track = Track::find($item['track']->id);
            if (! $track || isset($trashed[$track->id])) {
                continue;
            }
            // ersetztes Lied, dessen Ersatz nicht angekommen ist: bleibt (die Vormerkung auch)
            if ($item['kind'] === 'trash' && isset($replacedBy[$track->id]) && ! isset($imported[$replacedBy[$track->id]])) {
                continue;
            }
            try {
                if (in_array($item['kind'], ['copy-in', 'move-in'], true)) {
                    $old = $item['plan']->replaces_track_id ? Track::find($item['plan']->replaces_track_id) : null;
                    $swap = $old && isset($trashTo[$old->id]) && mb_strtolower($old->path) === mb_strtolower($item['to']);
                    $result = $this->import($item, $track, $swap ? $old : null, $swap ? $trashTo[$old->id] : null, $from);
                    $editor->update($result, $item['values'], TagEditor::SYSTEM, $item['kind'] === 'copy-in' ? 'bessere Fassung ins Album kopiert' : 'bessere Fassung ins Album verschoben');
                    $item['plan']->delete();
                    $imported[$item['plan']->id] = true;
                    if ($swap) {
                        $trashed[$old->id] = true;
                        $moved++;
                    }
                    $new[] = $result->id;
                } else {
                    $from[] = $track->dir;
                    $from[] = $track->album_dir;
                    $this->move($track, $item['to'], $item['location'], $item['kind'] === 'trash' ? 'trash' : 'move');
                }
                $moved++;
            } catch (Throwable $e) {
                $failed[$track->path] = $e->getMessage();
            }
        }
        if (! $failed && $plan['target'] !== null && $plan['hash'] !== '') {
            $this->followAlbum($plan['hash'], $plan['target'], $plan['target_location'], array_unique($from));
        }
        foreach (array_unique($from) as $dir) {
            $this->removeEmpty($dir);
        }

        return ['moved' => $moved, 'failed' => $failed, 'new' => $new];
    }

    /**
     * Bessere Fassung ins Album holen (kopieren bzw. verschieben). Soll sie genau an den Platz des ersetzten Lieds
     * ($old, gleicher Dateiname): erst unter einem Hilfsnamen hinein, dann $old in den Papierkorb, dann umbenennen –
     * klappt der Papierkorb nicht, wird das Hereinholen zurückgenommen und $old bleibt.
     */
    private function import(array $item, Track $track, ?Track $old, ?string $oldTrashTo, array &$from): Track
    {
        $to = $old ? dirname($item['to']).'/.musicwiz-swap.'.basename($item['to']) : $item['to'];
        $back = [$track->path, $track->location];
        if ($item['kind'] === 'copy-in') {
            $result = $this->copy($track, $to, $item['location']);
        } else {
            $from[] = $track->dir;
            $from[] = $track->album_dir;
            $this->move($track, $to, $item['location']);
            $result = $track;
        }
        if (! $old) {
            return $result;
        }
        try {
            $this->move($old, $oldTrashTo, 'trash', 'trash');
        } catch (Throwable $e) {
            if ($item['kind'] === 'copy-in') {
                @unlink($to);
                $result->delete();
            } else {
                $this->move($result, $back[0], $back[1]);
            }
            throw $e;
        }
        $this->move($result, $item['to'], $item['location']);

        return $result;
    }

    /** Datei verschieben und den Eintrag mitziehen */
    public function move(Track $track, string $to, string $location, string $action = 'move'): void
    {
        $op = FileOperation::create(['track_id' => $track->id, 'action' => $action, 'from_path' => $track->path, 'to_path' => $to,
            'location' => $location, 'status' => 'pending']);
        try {
            $this->moveFile($track->path, $to);
        } catch (Throwable $e) {
            $op->delete();   // nichts passiert (moveFile räumt selbst auf)
            throw $e;
        }
        DB::transaction(function () use ($track, $to, $location, $op) {
            self::relocate($track, $to, $location);
            $op->update(['status' => 'done']);
        });
    }

    /** Eintrag eines Lieds auf den neuen Pfad setzen (gleiche ID) */
    private static function relocate(Track $track, string $to, string $location): void
    {
        $dir = dirname($to);
        $albumDir = Scanner::albumDir($dir);
        clearstatcache(true, $to);
        $stat = @stat($to);
        $track->update(['location' => $location, 'path' => $to, 'path_hash' => sha1($to), 'dir' => $dir, 'album_dir' => $albumDir,
            'album_hash' => Scanner::albumHash($location, $albumDir), 'filename' => mb_substr(basename($to), 0, 512),
            'ext' => strtolower(pathinfo($to, PATHINFO_EXTENSION)), 'mtime' => (int) ($stat['mtime'] ?? 0),
            'file_id' => $stat ? $stat['dev'].':'.$stat['ino'] : null, 'move_to' => null]);
    }

    /** Datei ins Album kopieren und als neues Lied aufnehmen (gelesen wie beim Einlesen) */
    private function copy(Track $source, string $to, string $location): Track
    {
        if (file_exists($to)) {
            throw new RuntimeException('Ziel existiert bereits: '.basename($to));
        }
        File::ensureDirectoryExists(dirname($to));
        $tmp = self::partPath($to);
        $op = FileOperation::create(['action' => 'copy', 'from_path' => $source->path, 'to_path' => $to, 'location' => $location, 'tmp_path' => $tmp, 'status' => 'pending']);
        try {
            if (! @copy($source->path, $tmp) || self::size($tmp) !== self::size($source->path) || ! @rename($tmp, $to)) {
                throw new RuntimeException('Kopieren fehlgeschlagen: '.basename($source->path));
            }
            $process = new Process(['python3', base_path('tools/tagreader.py'), 'read', $to]);
            $process->setTimeout(120)->run();
            $read = json_decode(trim($process->getOutput()), true);
            if (! is_array($read) || empty($read['ok'])) {
                throw new RuntimeException('Kopie nicht lesbar: '.basename($to));
            }
            app(Scanner::class)->refresh($location, [$read]);
            $copy = Track::where('path_hash', sha1($to))->firstOrFail();
            // dieselbe Aufnahme: gemessene Lautheit gilt auch für die Kopie
            $copy->update(['loudness' => $source->loudness, 'loudness_peak' => $source->loudness_peak]);
            $op->update(['track_id' => $copy->id, 'status' => 'done']);
        } catch (Throwable $e) {
            // die eigene Kopie wieder entfernen – sonst wäre das Album dauerhaft „belegt“
            @unlink($tmp);
            @unlink($to);
            Track::where('path_hash', sha1($to))->delete();
            $op->delete();
            throw $e;
        }

        return $copy;
    }

    /** Verschieben; nur Groß/klein anders → über einen Zwischennamen; nie überschreiben */
    private function moveFile(string $from, string $to): void
    {
        if (! file_exists($from)) {
            throw new RuntimeException('Datei fehlt: '.basename($from));
        }
        $this->fixDirCase(dirname($to));
        File::ensureDirectoryExists(dirname($to));
        if (mb_strtolower($from) === mb_strtolower($to)) {
            $tmp = $from.'.musicwiz-tmp';
            if (! @rename($from, $tmp) || ! @rename($tmp, $to)) {
                file_exists($tmp) && @rename($tmp, $from);
                throw new RuntimeException('Umbenennen fehlgeschlagen: '.basename($from));
            }
        } else {
            if (file_exists($to)) {
                throw new RuntimeException('Ziel existiert bereits: '.basename($to));
            }
            if (self::sameDevice($from, dirname($to)) && ! config('musicwiz.copy_moves')) {
                if (! @rename($from, $to)) {
                    throw new RuntimeException('Verschieben fehlgeschlagen: '.basename($from));
                }
            } else {
                $this->copyMove($from, $to);
            }
        }
    }

    /**
     * Zwischen Shares: kopieren in eine versteckte Hilfsdatei im Zielordner, Größe prüfen, umbenennen (im selben Share
     * ein Schritt), dann das Original löschen. Bei jedem Fehler bleibt der Zustand wie vorher.
     */
    private function copyMove(string $from, string $to): void
    {
        $part = self::partPath($to);
        if (! @copy($from, $part) || self::size($part) !== self::size($from)) {
            @unlink($part);
            throw new RuntimeException('Kopieren fehlgeschlagen: '.basename($from));
        }
        if (! @rename($part, $to)) {
            @unlink($part);
            throw new RuntimeException('Verschieben fehlgeschlagen: '.basename($from));
        }
        @unlink($from);
        clearstatcache(true, $from);
        if (file_exists($from)) {
            @unlink($to);
            throw new RuntimeException('Original ließ sich nicht entfernen: '.basename($from));
        }
    }

    /** versteckte Hilfsdatei neben dem Ziel (der Tag-Leser übergeht versteckte Dateien) */
    private static function partPath(string $to): string
    {
        return dirname($to).'/.'.basename($to).'.musicwiz-part';
    }

    private static function size(string $path): int|false
    {
        clearstatcache(true, $path);

        return @filesize($path);
    }

    private static function sameDevice(string $path, string $dir): bool
    {
        $a = @stat($path);
        $b = @stat($dir);

        return $a && $b && $a['dev'] === $b['dev'];
    }

    /**
     * Ordner gibt es schon, nur anders geschrieben („abba“ statt „ABBA“): auf einem Dateisystem ohne Groß/klein landete
     * die Datei sonst im alten Ordner – also den Ordner über einen Zwischennamen umbenennen und alle Einträge darin
     * mitziehen. Gibt es beide Schreibweisen (Dateisystem mit Groß/klein), bleibt alles, wie es ist.
     */
    private function fixDirCase(string $dir): void
    {
        $root = collect(array_keys(Locations::LABELS))->map(fn ($l) => Locations::root($l))->first(fn ($r) => str_starts_with($dir, $r.'/'));
        if ($root === null) {
            return;
        }
        $current = $root;
        foreach (explode('/', substr($dir, strlen($root) + 1)) as $segment) {
            $entries = is_dir($current) ? (scandir($current) ?: []) : [];
            $actual = collect($entries)->first(fn ($e) => $e !== $segment && mb_strtolower($e) === mb_strtolower($segment) && is_dir($current.'/'.$e));
            if ($actual === null || in_array($segment, $entries, true)) {
                if (! is_dir($current.'/'.$segment)) {
                    return;
                }
                $current .= '/'.$segment;

                continue;
            }
            $old = $current.'/'.$actual;
            $new = $current.'/'.$segment;
            $op = FileOperation::create(['action' => 'rename', 'from_path' => $old, 'to_path' => $new, 'tmp_path' => $old.'.musicwiz-tmp', 'status' => 'pending']);
            if (! @rename($old, $old.'.musicwiz-tmp') || ! @rename($old.'.musicwiz-tmp', $new)) {
                is_dir($old.'.musicwiz-tmp') && @rename($old.'.musicwiz-tmp', $old);
                $op->delete();
                throw new RuntimeException('Ordner umbenennen fehlgeschlagen: '.$actual);
            }
            DB::transaction(function () use ($old, $new, $op) {
                $this->renamePrefix($old, $new);
                $op->update(['status' => 'done']);
            });
            $current = $new;
        }
    }

    /**
     * Abgebrochene Datei-Aktionen abgleichen (beim Start des Schreibens und vor dem Einlesen): ist die Datei schon am
     * Ziel, wird der Eintrag nachgezogen (gleiche ID, Verlauf bleibt); ist sie noch am alten Ort, gilt die Aktion als
     * nicht passiert. Halbe Kopien und Hilfsdateien werden entfernt. Liegt die Datei an beiden Orten, bleibt alles und
     * die Aktion wird gemeldet.
     *
     * @return array<string, string> Probleme (Pfad => Grund)
     */
    public function recover(): array
    {
        $problems = [];
        foreach (FileOperation::where('status', 'pending')->orderBy('id')->get() as $op) {
            clearstatcache();
            $from = $op->from_path !== null && file_exists($op->from_path);
            $to = $op->to_path !== null && file_exists($op->to_path);
            if ($op->tmp_path !== null && $op->action !== 'rename' && file_exists($op->tmp_path)) {
                @unlink($op->tmp_path);
            }
            if (in_array($op->action, ['move', 'trash'], true)) {
                $track = $op->track_id ? Track::find($op->track_id) : null;
                if ($from && $to) {
                    $problems[$op->to_path] = 'Abgebrochenes Verschieben: Datei liegt am alten und am neuen Ort – bitte prüfen';

                    continue;
                }
                if (! $from && $to && $track && $track->path === $op->from_path) {
                    // inzwischen am Ziel neu eingelesen: dieser Eintrag ist jünger und hat keinen Verlauf – der alte bleibt
                    Track::where('path_hash', sha1($op->to_path))->whereKeyNot($track->id)->delete();
                    self::relocate($track, $op->to_path, $op->location ?? $track->location);
                }
            } elseif ($op->action === 'copy') {
                // halb fertige Kopie ohne Eintrag: weg damit – die Vormerkung besteht noch und wird neu ausgeführt
                if ($to && ! Track::where('path_hash', sha1($op->to_path))->exists()) {
                    @unlink($op->to_path);
                }
            } elseif ($op->action === 'rename') {
                if (! is_dir($op->from_path) && is_dir($op->tmp_path)) {
                    @rename($op->tmp_path, $op->from_path);   // zwischen den beiden Umbenennungen abgebrochen
                } elseif (! is_dir($op->from_path) && is_dir($op->to_path)) {
                    $this->renamePrefix($op->from_path, $op->to_path);
                }
            }
            $op->update(['status' => 'done']);
        }
        $this->recoverSwaps();

        return $problems;
    }

    /**
     * Bessere Fassung, die unter dem Hilfsnamen im Album liegt (abgebrochen vor dem Umbenennen): ist der Platz frei
     * (das alte Lied im Papierkorb), dorthin – sonst zurück an den Ort, von dem sie kam.
     */
    private function recoverSwaps(): void
    {
        foreach (Track::where('filename', 'like', '.musicwiz-swap.%')->get() as $track) {
            $final = dirname($track->path).'/'.substr($track->filename, strlen('.musicwiz-swap.'));
            $back = FileOperation::where('track_id', $track->id)->where('to_path', $track->path)->latest('id')->first();
            $target = file_exists($final) ? $back?->from_path : $final;
            if ($target === null || file_exists($target)) {
                continue;
            }
            $location = $target === $final ? $track->location : (Locations::of($target) ?? $track->location);
            try {
                $this->move($track, $target, $location);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Einträge unter einem umbenannten Ordner mitziehen */
    private function renamePrefix(string $old, string $new): void
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $old).'/%';
        $swap = fn (string $path) => $new.substr($path, strlen($old));
        $hashes = [];
        DB::transaction(function () use ($like, $old, $swap, &$hashes) {
            foreach (Track::where('path', 'like', $like)->get() as $t) {
                if (str_starts_with($t->path, $old.'/')) {
                    $albumDir = $swap($t->album_dir);
                    $hashes[$t->album_hash] = Scanner::albumHash($t->location, $albumDir);
                    $t->update(['path' => $swap($t->path), 'path_hash' => sha1($swap($t->path)), 'dir' => $swap($t->dir), 'album_dir' => $albumDir,
                        'album_hash' => $hashes[$t->album_hash]]);
                }
            }
            foreach (FolderImage::where('path', 'like', $like)->get() as $i) {
                if (str_starts_with($i->path, $old.'/')) {
                    $i->update(['path' => $swap($i->path), 'path_hash' => sha1($swap($i->path)), 'dir' => $swap($i->dir), 'dir_hash' => sha1($swap($i->dir))]);
                }
            }
        });
        foreach ($hashes as $from => $to) {
            if ($from !== $to && ! Track::where('album_hash', $from)->exists()) {
                self::followHash($from, $to);
            }
        }
    }

    /**
     * Alle Lieder eines Ordners sind im gemeinsamen Ziel: Bilder und Beiwerk mitnehmen, Album-Verweise umhängen.
     *
     * @param  list<string>  $dirs  bisherige Ordner (Lied-Ordner und Album-Ordner)
     */
    private function followAlbum(string $oldHash, string $target, ?string $location, array $dirs): void
    {
        $audio = config('musicwiz.audio_extensions');
        foreach ($dirs as $dir) {
            if ($dir === $target || ! is_dir($dir) || Track::where('dir', $dir)->exists() || $this->isRoot($dir)) {
                continue;
            }
            foreach (File::files($dir) as $file) {
                $name = $file->getFilename();
                if (in_array(strtolower($name), self::JUNK, true) || in_array(strtolower($file->getExtension()), $audio, true)) {
                    continue;
                }
                $to = Sorter::lowerExtension($target.'/'.$name);   // Endung klein („Folder.JPG“ → „Folder.jpg“)
                if (file_exists($to)) {
                    continue; // nie überschreiben – bleibt als Rest liegen
                }
                if (@rename($file->getPathname(), $to)) {
                    FileOperation::create(['action' => 'extra', 'from_path' => $file->getPathname(), 'to_path' => $to]);
                    FolderImage::where('path_hash', sha1($file->getPathname()))->update(['location' => $location, 'path' => $to, 'path_hash' => sha1($to),
                        'dir' => $target, 'dir_hash' => sha1($target)]);
                }
            }
        }
        // Prüfungen, die am Album hängen, wandern mit
        $newHash = Track::where('dir', $target)->value('album_hash');
        if ($newHash && $newHash !== $oldHash && ! Track::where('album_hash', $oldHash)->exists()) {
            self::followHash($oldHash, $newHash);
        }
    }

    /** Album hat einen neuen Schlüssel (verschoben, umbenannt): Ausnahmen, Titellisten und Vormerkungen ziehen mit */
    public static function followHash(string $oldHash, string $newHash): void
    {
        foreach (['album_exemptions', 'album_tracklists'] as $table) {
            if (! DB::table($table)->where('album_hash', $newHash)->exists()) {
                DB::table($table)->where('album_hash', $oldHash)->update(['album_hash' => $newHash]);
            }
        }
        if (! DB::table('genre_exemptions')->where('unit', $newHash)->exists()) {
            DB::table('genre_exemptions')->where('unit', $oldHash)->update(['unit' => $newHash]);
        }
        FilePlan::where('target_album_hash', $oldHash)->update(['target_album_hash' => $newHash]);
    }

    /** leer gewordene Ordner entfernen (Systemdateien zählen nicht) – nach oben bis zur Wurzel des Bereichs */
    private function removeEmpty(string $dir): void
    {
        while (! $this->isRoot($dir) && is_dir($dir) && ! Track::where('dir', $dir)->exists()) {
            $entries = array_diff(scandir($dir) ?: [], ['.', '..']);
            $junk = array_filter($entries, fn ($e) => in_array(strtolower($e), self::JUNK, true) || str_starts_with($e, '._'));
            if (count($entries) !== count($junk)) {
                return;
            }
            foreach ($junk as $e) {
                @unlink($dir.'/'.$e);
            }
            if (! @rmdir($dir)) {
                return;
            }
            FileOperation::create(['action' => 'rmdir', 'from_path' => $dir]);
            FolderImage::where('dir', $dir)->delete();
            $dir = dirname($dir);
        }
    }

    /**
     * Alle leeren Ordner eines Bereichs entfernen (nach dem Einlesen): leer = nichts außer Systemdateien und leeren
     * Unterordnern. Versteckte Ordner und Verknüpfungen zählen als Inhalt; die Wurzel bleibt.
     *
     * @return int Zahl der entfernten Ordner
     */
    public function removeEmptyFolders(string $location): int
    {
        $root = Locations::root($location);

        return is_dir($root) ? $this->sweep($root, true) : 0;
    }

    private function sweep(string $dir, bool $root): int
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return 0;
        }
        $removed = 0;
        $content = false;
        $junk = [];
        foreach (array_diff($entries, ['.', '..']) as $e) {
            $path = $dir.'/'.$e;
            if (is_link($path) || (is_dir($path) && str_starts_with($e, '.'))) {
                $content = true;
            } elseif (is_dir($path)) {
                $removed += $this->sweep($path, false);
                $content = $content || is_dir($path);
            } elseif (in_array(strtolower($e), self::JUNK, true) || str_starts_with($e, '._')) {
                $junk[] = $path;
            } else {
                $content = true;
            }
        }
        if ($root || $content) {
            return $removed;
        }
        array_map(fn ($j) => @unlink($j), $junk);
        if (@rmdir($dir)) {
            FileOperation::create(['action' => 'rmdir', 'from_path' => $dir]);
            FolderImage::where('dir', $dir)->delete();
            $removed++;
        }

        return $removed;
    }

    /** Wurzel eines Bereichs – oder gar nicht in einem Bereich: dort wird nichts angefasst */
    private function isRoot(string $dir): bool
    {
        $dir = rtrim($dir, '/');
        foreach (array_keys(Locations::LABELS) as $location) {
            $root = Locations::root($location);
            if ($dir !== $root && str_starts_with($dir, $root.'/')) {
                return false;
            }
        }

        return true;
    }
}
