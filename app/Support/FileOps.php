<?php

namespace App\Support;

use RuntimeException;

class FileOps
{
    /** Ordner, deren Schreibweise beim Verschieben korrigiert wurde: alt => neu */
    public array $renamedDirs = [];

    /** Wird nach jeder Ordner-Umbenennung aufgerufen (alt, neu) – der OperationRunner protokolliert damit sofort. */
    public ?\Closure $onDirRenamed = null;

    public function __construct(private Settings $settings) {}

    /**
     * Zeigen zwei Pfade auf dieselbe Datei? (nur andere Groß/klein-Schreibung oder Unicode-Form, z. B. "ü" zusammengesetzt vs. einzeln)
     */
    public static function sameFile(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        clearstatcache(true, $a);
        clearstatcache(true, $b);
        $sa = @stat($a);
        $sb = @stat($b);

        return $sa && $sb && $sa['ino'] === $sb['ino'] && $sa['dev'] === $sb['dev'];
    }

    public static function nfc(string $s): string
    {
        return class_exists(\Normalizer::class) ? (\Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s) : $s;
    }

    /** Tatsächliche Schreibweise eines Pfads auf der Platte (Groß/klein und Unicode-Form wie gespeichert). */
    public static function actualPath(string $path): ?string
    {
        if (! file_exists($path)) {
            return null;
        }
        $current = '';
        foreach (array_filter(explode('/', $path), fn ($p) => $p !== '') as $part) {
            $entries = @scandir($current === '' ? '/' : $current) ?: [];
            $hit = in_array($part, $entries, true) ? $part : null;
            foreach ($hit === null ? $entries : [] as $e) {
                if (strcasecmp(self::nfc($e), self::nfc($part)) === 0) {
                    $hit = $e;
                    break;
                }
            }
            if ($hit === null) {
                return null;
            }
            $current .= '/'.$hit;
        }

        return $current;
    }

    /**
     * Existiert die Datei mit genau dieser Schreibweise? (Share und Mac unterscheiden Groß/klein nicht)
     *
     * @param  array<string, array<string, true>>|null  $listings  Ordnerinhalte über mehrere Aufrufe wiederverwenden
     *                                                             (sonst ein scandir pro Pfadebene und Datei)
     */
    public static function existsExact(string $path, ?array &$listings = null): bool
    {
        clearstatcache(true, $path);
        if (! file_exists($path)) {
            return false;
        }
        $current = '';
        foreach (array_filter(explode('/', $path), fn ($p) => $p !== '') as $part) {
            $dir = $current === '' ? '/' : $current;
            $entries = $listings[$dir] ??= array_fill_keys(@scandir($dir) ?: [], true);
            if (! isset($entries[$part])) {
                return false;
            }
            $current .= '/'.$part;
        }

        return true;
    }

    /**
     * Gleiche Datei unter anderem Namen (Groß/klein, Unicode-Form)? Dann nur umbenennen, nie als Konflikt behandeln.
     * Steht das Ziel in genau dieser Schreibweise als eigener Eintrag im Ordner, ist es eine andere Datei
     * (Dateisystem mit Unterscheidung von Groß/klein, z. B. lokale Linux-Platte) – dann Konflikt statt Überschreiben.
     */
    private static function caseOnly(string $from, string $to): bool
    {
        if (self::sameFile($from, $to)) {
            return true;
        }

        return strcasecmp($from, $to) === 0 && ! self::existsExact($to);
    }

    /**
     * Tatsächliches Ziel einer Verschiebung, ohne etwas anzufassen: bei belegtem Ziel durchnummeriert ("number")
     * bzw. Fehler. So kann der Verlauf das Ziel schon vor der Aktion festhalten.
     */
    public function target(string $from, string $to, string $onConflict = 'skip'): string
    {
        if ($from === $to || ! file_exists($to) || self::caseOnly($from, $to)) {
            return $to;
        }
        if ($onConflict !== 'number') {
            throw new RuntimeException('Ziel existiert bereits: '.$to);
        }

        return self::numbered($to);
    }

    /** Datei verschieben; über Dateisystemgrenzen hinweg per Kopie. Gibt den tatsächlichen Zielpfad zurück. */
    public function move(string $from, string $to, string $onConflict = 'skip'): string
    {
        if (! file_exists($from)) {
            throw new RuntimeException('Quelle fehlt: '.$from);
        }
        if ($from === $to) {
            return $to;
        }
        $caseOnly = self::caseOnly($from, $to);
        $to = $this->target($from, $to, $onConflict);
        $this->ensureDir(dirname($to));

        if ($caseOnly) {
            self::renameVia($from, $to);

            return $to;
        }
        if (@rename($from, $to)) {
            return $to;
        }
        if (! @copy($from, $to) || filesize($from) !== filesize($to)) {
            @unlink($to);
            throw new RuntimeException('Verschieben fehlgeschlagen: '.$from);
        }
        @touch($to, filemtime($from));
        unlink($from);

        return $to;
    }

    /**
     * Umbenennen über einen Zwischennamen (nötig, wenn sich nur Groß/klein ändert). Scheitert der zweite Schritt,
     * wird der erste zurückgenommen – es bleibt nie still ein "….musicwiz-tmp" liegen.
     */
    public static function renameVia(string $from, string $to): void
    {
        $tmp = $from.'.musicwiz-tmp';
        if (! @rename($from, $tmp)) {
            throw new RuntimeException('Umbenennen fehlgeschlagen: '.$from);
        }
        if (! @rename($tmp, $to)) {
            if (! @rename($tmp, $from)) {
                throw new RuntimeException('Umbenennen abgebrochen – liegt jetzt unter '.$tmp);
            }
            throw new RuntimeException('Umbenennen fehlgeschlagen: '.$from.' → '.$to);
        }
    }

    /** "Titel.mp3" -> "Titel (2).mp3", "Titel (3).mp3", ... */
    public static function numbered(string $path): string
    {
        $dir = dirname($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $name = pathinfo($path, PATHINFO_FILENAME);
        for ($i = 2; ; $i++) {
            $candidate = $dir.'/'.$name.' ('.$i.')'.($ext !== '' ? '.'.$ext : '');
            if (! file_exists($candidate)) {
                return $candidate;
            }
        }
    }

    /** Enthält der Ordner etwas außer Resten wie .DS_Store? (false auch, wenn er fehlt oder nicht lesbar ist) */
    public static function hasEntries(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..' && ! in_array($e, config('musicwiz.junk_files'), true) && ! str_starts_with($e, '._')) {
                return true;
            }
        }

        return false;
    }

    public function ensureDir(string $dir): void
    {
        // leerer Basisordner = Share vermutlich nicht eingehängt: nichts ins Mount-Verzeichnis darunter anlegen
        $base = $this->settings->base();
        if ($base !== '' && str_starts_with($dir.'/', $base.'/') && ! is_dir($dir) && ! self::hasEntries($base)) {
            throw new RuntimeException('Basisordner '.$base.' ist leer oder nicht erreichbar – ist das Share eingehängt? Ordner wird nicht angelegt: '.$dir);
        }
        $this->fixCase($dir);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Ordner kann nicht angelegt werden: '.$dir);
        }
    }

    /**
     * Ordner unterhalb der Basis, die nur in Groß/klein abweichen ("A-ha" statt "A-Ha"), auf die gewünschte Schreibweise umbenennen.
     * Ohne das bliebe der alte Ordnername auf Dateisystemen ohne Unterscheidung von Groß/klein für immer bestehen.
     */
    private function fixCase(string $dir): void
    {
        $roots = array_filter([$this->settings->base(), $this->settings->inbox()], fn ($r) => str_starts_with($dir.'/', $r.'/'));
        if (! $roots) {
            return;
        }
        $root = array_reduce($roots, fn ($a, $b) => strlen($b) > strlen((string) $a) ? $b : $a);
        $current = $root;
        foreach (array_filter(explode('/', substr($dir, strlen($root) + 1))) as $part) {
            $entries = @scandir($current) ?: [];
            if (! in_array($part, $entries, true)) {
                $match = null;
                foreach ($entries as $e) {
                    if (strcasecmp(self::nfc($e), self::nfc($part)) === 0) {
                        $match = $e;
                        break;
                    }
                }
                if ($match === null) {
                    return; // existiert noch nicht – wird angelegt
                }
                // scheitert das, Fehler statt still im alten Ordner weiterzumachen (der Index fände die Dateien sonst nicht)
                self::renameVia($current.'/'.$match, $current.'/'.$part);
                $this->renamedDirs[$current.'/'.$match] = $current.'/'.$part;
                if ($this->onDirRenamed) {
                    ($this->onDirRenamed)($current.'/'.$match, $current.'/'.$part);
                }
            }
            $current .= '/'.$part;
        }
    }

    /** In den Papierkorb verschieben; die Ordnerstruktur unterhalb der Basis bleibt erhalten. */
    public function trash(string $path): string
    {
        return $this->move($path, $this->trashTarget($path), 'skip');
    }

    /** Ziel im Papierkorb (bei belegtem Namen durchnummeriert). */
    public function trashTarget(string $path): string
    {
        $base = $this->settings->base().'/';
        $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : ltrim($path, '/');

        return $this->target($path, rtrim($this->settings->get('trash'), '/').'/'.$relative, 'number');
    }

    /**
     * Leere Ordner (auch mit nur .DS_Store o. Ä.) nach oben hin entfernen, bis zu einer Wurzel.
     */
    public function removeEmptyDirs(string $dir): void
    {
        $stops = array_merge([$this->settings->base(), rtrim($this->settings->get('trash'), '/'), $this->settings->inbox()],
            array_map(fn ($c) => $this->settings->categoryRoot($c), $this->settings->categories()));
        $junk = config('musicwiz.junk_files');
        while ($dir !== '/' && $dir !== '' && ! in_array($dir, $stops, true) && is_dir($dir)) {
            $entries = array_diff(@scandir($dir) ?: [], ['.', '..']);
            foreach ($entries as $e) {
                if (! in_array($e, $junk, true) && ! str_starts_with($e, '._')) {
                    return;
                }
            }
            foreach ($entries as $e) {
                @unlink($dir.'/'.$e);
            }
            if (! @rmdir($dir)) {
                return;
            }
            $dir = dirname($dir);
        }
    }
}
