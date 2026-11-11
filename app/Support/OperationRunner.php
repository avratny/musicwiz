<?php

namespace App\Support;

use App\Jobs\RunOperation;
use App\Models\HistoryEntry;
use App\Models\Leftover;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Führt bestätigte Aktionen aus und protokolliert jede Änderung im Verlauf, damit sie rückgängig gemacht werden kann.
 * Der Verlaufseintrag entsteht vor der Dateiaktion (pending) und wird danach bestätigt: Bricht der Worker mittendrin ab,
 * bleibt die Änderung trotzdem rückgängig machbar.
 *
 * Aktionen im Payload ("actions"):
 *   apply        {path, tags?, cover?, embed_from?, remove_cover?, target?}  Tags/Cover schreiben, danach verschieben
 *                embed_from: fehlendes Cover aus einem anderen Track (eingebettet) bzw. einer Cover-Datei übernehmen
 *   move         {path, target}
 *   album_files  {from_dir, to_dir}                  cover.jpg (bzw. folder.jpg) mitnehmen, leeren Quellordner entfernen
 *   cover_file   {dir, cover}                        cover.jpg im Ordner ablegen
 *   trash        {path}
 *   delete_trash {path}                              Datei im Papierkorb endgültig löschen (nicht rückgängig machbar)
 *   delete_junk  {path}                              Reste wie .DS_Store bzw. leere Ordner entfernen
 *   rename_image {from, to}                          Künstlerbild umbenennen
 *   download     {url, dir, playlist?}               von YouTube als MP3 in die Inbox laden (rückgängig: in den Papierkorb)
 */
class OperationRunner
{
    private array $touched = [];

    /** @var array<string, string|false> Cover-Quelle => vorbereitete JPEG-Datei (false = nicht lesbar) */
    private array $coverSources = [];

    /** Fehler, nach denen die Aktion trotzdem weiterläuft (z. B. Cover nicht einbettbar) */
    private array $softErrors = [];

    /** Downloads, die wegen der Tagesgrenze auf morgen verschoben werden (ab dem ersten alle folgenden) */
    private array $deferred = [];

    public function __construct(
        private TagTool $tagTool,
        private FileOps $files,
        private Scanner $scanner,
        private Settings $settings,
    ) {}

    public static function dispatch(string $type, string $description, array $actions, array $options = []): Operation
    {
        $op = Operation::create([
            'type' => $type,
            'description' => $description,
            'payload' => ['actions' => array_values($actions), 'options' => $options],
            'total' => count($actions),
        ]);
        // Downloads dauern lange: eigene Warteschlange, damit sie andere Aktionen nicht aufhalten
        $job = RunOperation::dispatch($op->id)->onQueue($type === 'download' ? 'downloads' : 'default');
        if (! empty($options['not_before'])) {
            $job->delay(Carbon::parse($options['not_before']));
        }

        return $op;
    }

    public static function dispatchUndo(Operation $original): Operation
    {
        // Doppelklick bzw. zweiter Tab: kein zweites Rückgängig für dieselbe Aktion
        if ($running = $original->activeUndo()) {
            return $running;
        }
        $op = Operation::create([
            'type' => 'undo',
            'description' => 'Rückgängig: '.$original->description,
            'payload' => ['undo' => $original->id],
            'total' => $original->entries()->whereNull('undone_at')->count(),
            'undo_of' => $original->id,
        ]);
        RunOperation::dispatch($op->id);

        return $op;
    }

    public function run(Operation $op): void
    {
        $op->update(['status' => 'running']);
        $this->touched = [];
        $errors = [];
        // Ordner mit korrigierter Schreibweise sofort protokollieren, nicht erst am Ende
        $this->files->onDirRenamed = fn (string $old, string $new) => $this->db(
            fn () => $op->entries()->create(['kind' => 'move', 'old_path' => $old, 'new_path' => $new, 'error' => 'Ordner-Schreibweise']));

        if ($root = $this->scanner->unreachableRoot($this->affectedPaths($op))) {
            $this->files->onDirRenamed = null;
            $op->update(['status' => 'failed', 'error' => 'Ordner nicht erreichbar oder leer: '.$root.' – ist das Share eingehängt? Es wurde nichts verändert. '
                .'(Wurden dort wirklich alle Dateien entfernt, zuerst neu scannen.)', 'finished_at' => now()]);

            return;
        }

        if ($op->type === 'undo') {
            $errors = $this->undo($op);
        } else {
            $onConflict = $op->payload['options']['on_conflict'] ?? 'skip';
            $this->prepareCoverSources($op->payload['actions'] ?? []);
            $this->deferred = [];
            foreach ($op->payload['actions'] ?? [] as $action) {
                if ($this->deferred) {
                    $this->deferred[] = $action;

                    continue;
                }
                try {
                    $this->runAction($op, $action, $onConflict);
                    $this->db(fn () => $op->increment('done'));
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                    $this->db(fn () => $op->increment('failed'));
                    $this->db(fn () => $op->entries()->create(['kind' => 'error', 'old_path' => $action['path'] ?? $action['from_dir'] ?? $action['url'] ?? null, 'error' => $e->getMessage()]));
                }
            }
            $this->scheduleDeferred($op);
            foreach (array_filter($this->coverSources) as $tmp) {
                @unlink($tmp);
            }
            $this->coverSources = [];
        }

        try {
            // Ordner mit korrigierter Schreibweise: Pfade in der Datenbank mitziehen
            foreach ($this->files->renamedDirs as $old => $new) {
                $this->scanner->renameDirInIndex($old, $new);
                // betroffene Pfade auch in der neuen Schreibweise neu einlesen (veraltete Einträge fallen so weg)
                foreach (array_keys($this->touched) as $p) {
                    if (str_starts_with($p, $old.'/')) {
                        $this->touch($new.substr($p, strlen($old)));
                    }
                }
            }
            $this->files->renamedDirs = [];
            $this->files->onDirRenamed = null;
            // Neu einlesen – bei kurzzeitig gesperrter Datenbank (gleichzeitige Zugriffe) erneut versuchen
            $this->db(function () {
                $this->scanner->rescanPaths(array_keys($this->touched));
                $this->scanner->refreshLeftovers(array_keys($this->touched));
            });
            foreach ($this->pendingCarry as $path => $rest) {
                PendingChanges::carryOver($path, $rest);
            }
            $this->pendingCarry = [];
            PendingChanges::pruneCovers();
        } catch (\Throwable $e) {
            report($e);
            $errors[] = 'Neu einlesen: '.$e->getMessage();
        }

        $errors = array_merge($errors, $this->softErrors);
        $this->softErrors = [];
        $op->update([
            'status' => $errors ? 'failed' : 'done',
            'error' => $errors ? implode("\n", array_slice($errors, 0, 20)) : null,
            'finished_at' => now(),
        ]);
    }

    /** Alle Pfade, die eine Aktion bzw. ein Rückgängig anfasst (für die Prüfung, ob die Ordner erreichbar sind). */
    private function affectedPaths(Operation $op): array
    {
        if ($op->type === 'undo') {
            return Operation::find($op->payload['undo'])?->entries()->get(['old_path', 'new_path'])
                ->flatMap(fn ($e) => [$e->old_path, $e->new_path])->filter()->values()->all() ?? [];
        }
        $paths = [];
        foreach ($op->payload['actions'] ?? [] as $a) {
            foreach (['path', 'target', 'from_dir', 'to_dir', 'dir'] as $k) {
                if (! empty($a[$k]) && is_string($a[$k])) {
                    $paths[] = $a[$k];
                }
            }
        }

        return $paths;
    }

    /** Vormerkungen, die nach dem Schreiben noch offen sind (neuer Pfad => Rest), siehe PendingChanges::written */
    private array $pendingCarry = [];

    private function runAction(Operation $op, array $a, string $onConflict): void
    {
        switch ($a['type']) {
            case 'apply':
                $path = $a['path'];
                if (! is_file($path)) {
                    throw new RuntimeException('Datei fehlt: '.$path);
                }
                // vorgemerkte Änderung: nur schreiben, wenn sie noch genau so besteht (Vorschau kann veraltet sein – inzwischen
                // verworfen oder Neues vorgemerkt); sonst nichts schreiben, die Vormerkung bleibt fürs nächste Mal
                if (! empty($a['pending_id']) && ! PendingChanges::stillCurrent((int) $a['pending_id'], (int) ($a['revision'] ?? 0))) {
                    $this->db(fn () => $op->entries()->create(['kind' => 'info', 'old_path' => $path, 'error' => 'übersprungen – Vormerkung inzwischen geändert oder verworfen']));
                    break;
                }
                $this->touch($path);
                $tags = $a['tags'] ?? [];
                $cover = $a['cover'] ?? null;
                if (! $cover && ! empty($a['embed_from'])) {
                    $cover = ($this->coverSources[$a['embed_from']] ?? false) ?: null;
                    if (! $cover) {
                        // Tags und Verschieben trotzdem erledigen, nur das Cover fehlt weiter
                        $msg = 'Cover nicht eingebettet ('.basename($path).'): Quelle nicht lesbar – '.basename($a['embed_from']);
                        $this->softErrors[] = $msg;
                        $this->db(fn () => $op->entries()->create(['kind' => 'error', 'old_path' => $path, 'error' => $msg]));
                    }
                }
                $removeCover = ! $cover && ! empty($a['remove_cover']);
                if ($tags || $cover || $removeCover) {
                    $this->writeTags($op, $path, $tags, $cover, $removeCover);
                }
                if (! empty($a['target']) && $a['target'] !== $path) {
                    $this->moveLogged($op, $path, $a['target'], $a['on_conflict'] ?? $onConflict);
                } elseif (! empty($a['held'])) {
                    $this->db(fn () => $op->entries()->create(['kind' => 'info', 'old_path' => $path, 'error' => $a['held']]));
                }
                // vorgemerkte Änderung geschrieben: erledigt – oder (unterwegs Neues vorgemerkt) auf den neuen Pfad tragen
                if (! empty($a['pending_id'])) {
                    $rest = PendingChanges::written((int) $a['pending_id'], (int) ($a['revision'] ?? 0), $tags);
                    if ($rest) {
                        // tatsächlicher Ort: am Ziel – oder (Ziel belegt, übersprungen) noch am alten Platz
                        $this->pendingCarry[! empty($a['target']) && ! is_file($path) ? $a['target'] : $path] = $rest;
                    }
                }
                break;

            case 'move':
                $this->touch($a['path']);
                $this->moveLogged($op, $a['path'], $a['target'], $a['on_conflict'] ?? $onConflict);
                break;

            case 'album_files':
                $from = $a['from_dir'];
                $to = $a['to_dir'];
                if ($from !== $to && is_dir($from)) {
                    [$coverFile] = Scanner::findCoverFile($from);
                    $target = $to.'/cover.'.strtolower(pathinfo((string) $coverFile, PATHINFO_EXTENSION) ?: 'jpg');
                    if ($coverFile && (! file_exists($target) || FileOps::sameFile($coverFile, $target))) {
                        // auch wenn sich nur Groß/klein bzw. die Unicode-Form des Ordners ändert: dieselbe Datei, nur umbenennen
                        $this->moveLogged($op, $coverFile, $target, 'skip');
                    } elseif ($coverFile && md5_file($coverFile) === md5_file($target)) {
                        // identisches Cover liegt schon am Ziel (z. B. von CD 1): Kopie in den Papierkorb
                        $this->trashLogged($op, $coverFile, 'identisches Cover liegt schon am Ziel');
                    } elseif ($coverFile) {
                        // anderes Bild: nicht wegwerfen, daneben ablegen
                        $this->moveLogged($op, $coverFile, $target, 'number');
                    }
                    // Liegt keine Musik mehr im alten Ordner: übrige Dateien (Bilder, Booklets, Unterordner) mitnehmen
                    if (is_dir($from) && ! $this->containsAudio($from)) {
                        foreach (array_diff(@scandir($from) ?: [], ['.', '..']) as $entry) {
                            if (in_array($entry, config('musicwiz.junk_files'), true) || str_starts_with($entry, '._')) {
                                continue;
                            }
                            $this->moveLogged($op, $from.'/'.$entry, rtrim($to, '/').'/'.$entry, 'number');
                        }
                    }
                    $this->files->removeEmptyDirs($from);
                }
                break;

            case 'cover_file':
                $target = rtrim($a['dir'], '/').'/cover.jpg';
                $backup = null;
                if (file_exists($target)) {
                    $backup = $this->backupPath('jpg');
                    if (! @copy($target, Storage::path($backup))) {
                        throw new RuntimeException('Altes cover.jpg konnte nicht gesichert werden: '.$target);
                    }
                }
                $this->logged($op, ['kind' => 'file', 'new_path' => $target, 'backup' => $backup], function () use ($a, $target) {
                    $this->files->ensureDir($a['dir']);
                    if (! @copy($a['cover'], $target)) {
                        throw new RuntimeException('cover.jpg konnte nicht geschrieben werden: '.$target);
                    }
                });
                break;

            case 'trash':
                $this->touch($a['path']);
                $this->trashLogged($op, $a['path'], $a['reason'] ?? null);
                $this->files->removeEmptyDirs(dirname($a['path']));
                break;

            case 'delete_trash':
                app(Trash::class)->delete($a['path']);
                $op->entries()->create(['kind' => 'delete', 'old_path' => $a['path'], 'error' => 'endgültig gelöscht']);
                $this->files->removeEmptyDirs(dirname($a['path']));
                break;

            case 'delete_junk':
                $p = $a['path'];
                if (is_dir($p)) {
                    $this->files->removeEmptyDirs($p);
                    if (is_dir($p)) {
                        throw new RuntimeException('Ordner ist nicht leer: '.$p);
                    }
                } elseif (file_exists($p)) {
                    unlink($p);
                    $this->files->removeEmptyDirs(dirname($p));
                }
                Leftover::where('path', $p)->delete();
                $op->entries()->create(['kind' => 'delete', 'old_path' => $p]);
                break;

            case 'replaygain':
                // ein Album: erst alle Titel messen, dann schreiben (Album-Wert braucht alle Messungen)
                $measured = [];
                foreach ($a['paths'] as $path => $duration) {
                    if (! is_file($path)) {
                        throw new RuntimeException('Datei fehlt: '.$path);
                    }
                    $m = app(Loudness::class)->measure($path);
                    if (! $m) {
                        throw new RuntimeException('Lautheit nicht messbar ('.basename($path).') – Album übersprungen');
                    }
                    $measured[$path] = $m + ['duration' => (float) $duration];
                }
                foreach (Loudness::tags($measured) as $path => $tags) {
                    $this->touch($path);
                    $this->writeTags($op, $path, $tags, null);
                }
                break;

            case 'lyrics':
                $path = $a['path'];
                if (! is_file($path)) {
                    throw new RuntimeException('Datei fehlt: '.$path);
                }
                usleep(300_000); // LRCLIB ist ein freier Dienst – nicht im Takt der Festplatte abfragen
                $text = app(Lyrics::class)->find((string) $a['artist'], (string) $a['title'], (string) ($a['album'] ?? ''), (float) ($a['duration'] ?? 0));
                if ($text === null) {
                    Lyrics::markMissing($path);
                    $this->db(fn () => $op->entries()->create(['kind' => 'info', 'old_path' => $path, 'error' => 'kein Songtext gefunden']));
                    break;
                }
                $this->touch($path);
                $this->writeTags($op, $path, ['lyrics' => $text], null);
                break;

            case 'rename_image':
                $to = $this->files->target($a['from'], $a['to'], 'skip');
                $this->logged($op, ['kind' => 'move', 'old_path' => $a['from'], 'new_path' => $to], fn () => $this->files->move($a['from'], $to, 'skip'));
                break;

            case 'download':
                $youtube = app(YouTube::class);
                $remaining = $youtube->remainingToday();
                if ($remaining === 0) {
                    $this->deferred[] = $a;
                    break;
                }
                $result = $youtube->download($a['url'], $a['dir'], (bool) ($a['playlist'] ?? false),
                    fn (array $p) => Cache::put('download-progress:'.$op->id, $p, 3600), $remaining, $a['archive'] ?? null);
                if ($result['limited']) {
                    // Playlist nur teilweise geladen: Rest (laut Archiv ohne die schon geladenen Titel) morgen
                    $this->deferred[] = $a;
                }
                foreach ($result['files'] as $file) {
                    $this->db(fn () => $op->entries()->create(['kind' => 'download', 'new_path' => $file]));
                    $this->touch($file);
                }
                Cache::forget('download-progress:'.$op->id);
                if ($result['error'] && ! $result['files']) {
                    throw new RuntimeException($a['url'].': '.$result['error']);
                }
                if ($result['error']) {
                    // Playlist teilweise geladen: Rest weiter nutzbar, Fehler aber anzeigen
                    $this->softErrors[] = $a['url'].': '.$result['error'];
                    $this->db(fn () => $op->entries()->create(['kind' => 'error', 'old_path' => $a['url'], 'error' => $result['error']]));
                }
                break;

            default:
                throw new RuntimeException('Unbekannte Aktion: '.$a['type']);
        }
    }

    /** Wegen der Tagesgrenze verschobene Downloads als neue Aktion für morgen früh einplanen. */
    private function scheduleDeferred(Operation $op): void
    {
        if (! $this->deferred) {
            return;
        }
        $next = now()->addDay()->startOfDay()->addMinutes(5);
        $label = preg_replace('/^((Fortsetzung|Erneut): )+/', '', $op->description);
        self::dispatch('download', 'Fortsetzung: '.$label, $this->deferred, ['not_before' => $next->toIso8601String(), 'continues' => $op->id]);
        // verschobene Links zählen nicht mehr zu dieser Aktion
        $this->db(fn () => $op->update(['total' => $op->done + $op->failed]));
        $this->deferred = [];
    }

    private function writeTags(Operation $op, string $path, array $tags, ?string $cover, bool $removeCover = false): void
    {
        if (! TagTool::canWrite($path)) {
            throw new RuntimeException('Format kann nicht beschrieben werden: '.basename($path));
        }
        $old = $this->tagTool->read([$path])[$path] ?? null;
        if (! ($old['ok'] ?? false)) {
            throw new RuntimeException('Tags nicht lesbar: '.($old['error'] ?? $path));
        }
        $oldTags = array_intersect_key($old['tags'], $tags);

        $backup = null;
        if ($cover || $removeCover) {
            $backup = $this->backupPath('img');
            // nur das Frontcover sichern: nur das wird ersetzt bzw. entfernt (Rückseite & Co. bleiben in der Datei)
            if (! $this->tagTool->extractCover($path, Storage::path($backup), true)) {
                $backup = null; // es gab kein eingebettetes Frontcover
            }
        }

        $this->logged($op, [
            'kind' => 'tags',
            'old_path' => $path,
            'new_path' => $path,
            'old_tags' => $oldTags,
            'new_tags' => $tags,
            'backup' => $backup,
            'cover_changed' => $cover || $removeCover,
        ], function () use ($path, $tags, $cover, $removeCover) {
            $result = $this->tagTool->write([['path' => $path, 'tags' => $tags, 'cover' => $cover, 'remove_cover' => $removeCover]])[$path] ?? ['ok' => false];
            if (! $result['ok']) {
                throw new RuntimeException('Tags schreiben fehlgeschlagen ('.basename($path).'): '.($result['error'] ?? ''));
            }
        });
    }

    private function moveLogged(Operation $op, string $from, string $to, string $onConflict): void
    {
        // Ziel belegt + "bessere Version behalten": die schlechtere Fassung wandert in den Papierkorb
        // „best_skip“ (Doppel aus einem anderen Album): wie „best“, aber nicht nachweislich dasselbe Lied → bleibt liegen
        if (in_array($onConflict, ['best', 'best_skip'], true) && file_exists($to) && ! FileOps::sameFile($from, $to)) {
            $cmp = Quality::compare($from, $to);
            if ($cmp['winner'] === 'both' && $onConflict === 'best_skip') {
                $this->db(fn () => $op->entries()->create(['kind' => 'info', 'old_path' => $from, 'error' => 'nicht verschoben – Ziel belegt, '.str_replace('beide bleiben, diese mit „(2)“', 'diese bleibt am alten Ort', $cmp['reason'])]));

                return;
            }
            if ($cmp['winner'] === 'both') {
                // unklar: nichts aussortieren, zweite Fassung daneben ablegen
                $this->moveFile($op, $from, $to, 'number', $cmp['reason']);

                return;
            }
            $loser = $cmp['winner'] === 'source' ? $to : $from;
            $this->trashLogged($op, $loser, $cmp['reason']);
            $this->touch($loser);
            if ($cmp['winner'] === 'target') {
                $this->files->removeEmptyDirs(dirname($from));

                return;
            }
            $onConflict = 'skip';
        }
        $this->moveFile($op, $from, $to, $onConflict);
    }

    private function moveFile(Operation $op, string $from, string $to, string $onConflict, ?string $note = null): void
    {
        $to = $this->files->target($from, $to, $onConflict);
        $this->logged($op, ['kind' => 'move', 'old_path' => $from, 'new_path' => $to, 'error' => $note], fn () => $this->files->move($from, $to, 'skip'));
        $this->touch($from);
        $this->touch($to);
        $this->files->removeEmptyDirs(dirname($from));
    }

    private function trashLogged(Operation $op, string $path, ?string $note = null): void
    {
        $target = $this->files->trashTarget($path);
        $this->logged($op, ['kind' => 'trash', 'old_path' => $path, 'new_path' => $target, 'error' => $note], fn () => $this->files->move($path, $target, 'skip'));
    }

    /**
     * Verlaufseintrag vor der Aktion anlegen (pending) und danach bestätigen. Scheitert die Aktion, fällt der Eintrag weg;
     * stirbt der Worker dazwischen, bleibt er als pending stehen und das Rückgängigmachen prüft, was tatsächlich passiert ist.
     */
    private function logged(Operation $op, array $entry, callable $action): mixed
    {
        $e = $this->db(fn () => $op->entries()->create($entry + ['pending' => true]));
        try {
            $result = $action();
        } catch (\Throwable $ex) {
            rescue(fn () => $this->db(fn () => $e->delete()), report: false);
            throw $ex;
        }
        rescue(fn () => $this->db(fn () => $e->update(['pending' => false])), report: true);

        return $result;
    }

    /** Datenbankzugriff bei kurzzeitig gesperrter SQLite-Datenbank (gleichzeitige Zugriffe) wiederholen. */
    private function db(callable $fn): mixed
    {
        return retry(3, $fn, fn (int $attempt) => $attempt * 2000, fn (\Throwable $e) => str_contains($e->getMessage(), 'database is locked'));
    }

    /** @return list<string> Fehler */
    private function undo(Operation $op): array
    {
        $original = Operation::findOrFail($op->payload['undo']);
        $errors = [];
        // bereits zurückgenommene Einträge überspringen: ein abgebrochenes Rückgängig lässt sich so wiederholen
        foreach ($original->entries()->whereNull('undone_at')->orderByDesc('id')->get() as $e) {
            try {
                $this->undoEntry($op, $e);
                $this->db(fn () => $e->update(['undone_at' => now()]));
                $this->db(fn () => $op->increment('done'));
            } catch (\Throwable $ex) {
                $errors[] = $ex->getMessage();
                $this->db(fn () => $op->increment('failed'));
                $this->db(fn () => $op->entries()->create(['kind' => 'error', 'old_path' => $e->new_path ?? $e->old_path, 'error' => $ex->getMessage()]));
            }
        }
        $this->db(fn () => $original->update(['status' => $errors ? 'partly_undone' : 'undone']));

        return $errors;
    }

    private function undoEntry(Operation $op, HistoryEntry $e): void
    {
        switch ($e->kind) {
            case 'move':
            case 'trash':
                if ($e->error === 'Ordner-Schreibweise') {
                    if (is_dir($e->new_path)) {
                        FileOps::renameVia($e->new_path, $e->old_path);
                        $this->scanner->renameDirInIndex($e->new_path, $e->old_path);
                    }
                    break;
                }
                if (! file_exists($e->new_path)) {
                    if ($e->pending && file_exists($e->old_path)) {
                        break; // Aktion wurde abgebrochen, bevor die Datei bewegt war: nichts zurückzunehmen
                    }
                    throw new RuntimeException('Nicht mehr vorhanden: '.$e->new_path);
                }
                $back = $this->files->move($e->new_path, $e->old_path, 'skip');
                $op->entries()->create(['kind' => 'move', 'old_path' => $e->new_path, 'new_path' => $back]);
                $this->touch($e->new_path);
                $this->touch($back);
                $this->files->removeEmptyDirs(dirname($e->new_path));
                break;

            case 'tags':
                $path = $e->old_path;
                $item = ['path' => $path, 'tags' => $e->old_tags ?? []];
                if ($e->cover_changed) {
                    if ($e->backup && ! is_file(Storage::path($e->backup))) {
                        throw new RuntimeException('Gesichertes Cover fehlt ('.basename($path).'): '.$e->backup);
                    }
                    $e->backup ? $item['cover'] = Storage::path($e->backup) : $item['remove_cover'] = true;
                }
                $result = $this->tagTool->write([$item])[$path] ?? ['ok' => false];
                if (! $result['ok']) {
                    throw new RuntimeException('Tags zurückschreiben fehlgeschlagen: '.($result['error'] ?? $path));
                }
                $op->entries()->create(['kind' => 'tags', 'old_path' => $path, 'new_path' => $path, 'old_tags' => $e->new_tags, 'new_tags' => $e->old_tags]);
                $this->touch($path);
                break;

            case 'download':
                // heruntergeladene Datei nicht löschen, sondern in den Papierkorb
                if (! file_exists($e->new_path)) {
                    throw new RuntimeException('Nicht mehr vorhanden: '.$e->new_path);
                }
                $target = $this->files->trashTarget($e->new_path);
                $this->files->move($e->new_path, $target, 'skip');
                $op->entries()->create(['kind' => 'trash', 'old_path' => $e->new_path, 'new_path' => $target]);
                $this->touch($e->new_path);
                $this->files->removeEmptyDirs(dirname($e->new_path));
                break;

            case 'file':
                if ($e->backup) {
                    if (! is_file(Storage::path($e->backup))) {
                        throw new RuntimeException('Gesichertes cover.jpg fehlt: '.$e->backup);
                    }
                    if (! @copy(Storage::path($e->backup), $e->new_path)) {
                        throw new RuntimeException('cover.jpg konnte nicht wiederhergestellt werden: '.$e->new_path);
                    }
                } elseif (file_exists($e->new_path)) {
                    unlink($e->new_path);
                }
                $this->touch($e->new_path);
                break;
        }
    }

    /**
     * Cover-Quellen fürs Einbetten vor der ersten Aktion als JPEG bereitlegen: Der Quell-Track bzw. die cover.jpg
     * kann im Lauf derselben Aktion verschoben werden.
     */
    private function prepareCoverSources(array $actions): void
    {
        $this->coverSources = [];
        $audio = config('musicwiz.audio_extensions');
        foreach ($actions as $a) {
            $src = $a['embed_from'] ?? null;
            if (! $src || array_key_exists($src, $this->coverSources)) {
                continue;
            }
            Storage::makeDirectory('tmp');
            $tmp = Storage::path('tmp/embed-'.bin2hex(random_bytes(6)).'.jpg');
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            try {
                $ok = match (true) {
                    ! is_file($src) => false,
                    in_array($ext, $audio, true) => $this->tagTool->extractCover($src, $tmp),
                    in_array($ext, ['jpg', 'jpeg'], true) => @copy($src, $tmp),
                    // PNG o. Ä.: als JPEG einbetten (das Tag-Tool schreibt image/jpeg)
                    default => app(Covers::class)->resize($src, $tmp, 1500),
                };
            } catch (\Throwable $e) {
                report($e);
                $ok = false;
            }
            $valid = $ok && is_file($tmp) && @getimagesize($tmp);
            if (! $valid) {
                @unlink($tmp);
            }
            $this->coverSources[$src] = $valid ? $tmp : false;
        }
    }

    private function containsAudio(string $dir): bool
    {
        $audio = config('musicwiz.audio_extensions');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && in_array(strtolower($f->getExtension()), $audio, true)) {
                return true;
            }
        }

        return false;
    }

    private function touch(string $path): void
    {
        $this->touched[$path] = true;
    }

    private function backupPath(string $ext): string
    {
        Storage::makeDirectory('history');

        return 'history/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.'.$ext;
    }
}
