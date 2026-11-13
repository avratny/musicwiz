<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\FolderImage;
use App\Models\Track;
use Illuminate\Support\Facades\Process;

/**
 * Cover eines Lieds: eingebettet, sonst Bild im Ordner (cover/folder/front) – verkleinert und zwischengespeichert.
 * Name der Kopie: Lied bzw. Bild mit Stand (Pfad, Änderungszeit, Größe) – ändert sich die Datei, entsteht eine neue,
 * die alte räumt prune() nach dem Einlesen weg.
 */
class Covers
{
    public const SIZES = [64, 300, 600];

    /** so lange darf der Browser Cover mit Versionszusatz behalten (ändert sich das Cover, ändert sich die Adresse) */
    public const BROWSER_CACHE = 60 * 86400;

    /** @var array<int, string> Lied => Version (je Anfrage gemerkt) */
    private static array $versions = [];

    /**
     * Adresse des Covers mit Versionszusatz (Dateistand und neu gesetztes Cover) – darf lange zwischengespeichert werden.
     * Mit Lied-Objekt ohne Abfrage, sonst eine kurze Abfrage je Lied.
     */
    public static function url(Track|int $track, int $size = 300, bool $fileOnly = false): string
    {
        $id = $track instanceof Track ? $track->id : $track;
        if ($track instanceof Track && array_key_exists('mtime', $track->getAttributes()) && array_key_exists('cover', $track->getAttributes())) {
            self::$versions[$id] = self::version($track);
        }
        self::$versions[$id] ??= ($t = Track::select(['id', 'mtime', 'size', 'cover'])->find($id)) ? self::version($t) : '0';

        return route('cover', array_filter([$id, $size, 'datei' => $fileOnly ? 1 : null, 'v' => self::$versions[$id]]));
    }

    private static function version(Track $t): string
    {
        return substr(md5($t->mtime.'|'.$t->size.'|'.$t->cover), 0, 10);
    }

    /** Pfad des verkleinerten Covers oder null (kein Cover); $fileOnly: das aus der Datei, auch wenn ein neues gesetzt ist */
    public function thumbnail(Track $track, int $size, bool $fileOnly = false): ?string
    {
        // neu gesetztes Cover (noch nicht in der Datei) – außer das aus der Datei ist gefragt
        if (! $fileOnly && $track->cover && ($file = app(CoverStore::class)->thumbnail($track->cover, $size))) {
            return $file;
        }
        $size = in_array($size, self::SIZES, true) ? $size : 300;
        $dir = config('musicwiz.cover_cache');
        $key = sha1($track->path.'|'.$track->mtime.'|'.$track->size);
        $target = $dir.'/'.$key.'-'.$size.'.jpg';
        $none = $dir.'/'.$key.'.none';
        if (is_file($target)) {
            return $target;
        }
        if (is_file($none) && filemtime($none) > time() - 3600) {
            return null;
        }
        @mkdir($dir, 0775, true);
        $source = $this->source($track, $dir.'/'.$key.'.src');
        if (! $source) {
            touch($none);

            return null;
        }
        $ok = self::resize($source, $target, $size);
        if (str_ends_with($source, '.src')) {
            @unlink($source);
        }

        return $ok ? $target : null;
    }

    /**
     * Zwischengespeicherte Kopien löschen, auf die nichts mehr verweist: Lieder und Bilder im Ordner, die es nicht mehr
     * (oder nur in anderem Stand) gibt, Interpretenbilder gelöschter Interpreten, liegengebliebene Zwischendateien.
     * Neu gesetzte Cover: solange ein Lied oder eine Änderung darauf verweist (CoverStore::referenced).
     *
     * @return int Zahl der gelöschten Dateien
     */
    public static function prune(): int
    {
        $dir = (string) config('musicwiz.cover_cache');
        $entries = @scandir($dir);
        if ($entries === false) {
            return 0;
        }
        $keep = [];
        foreach (Track::select(['path', 'mtime', 'size'])->cursor() as $t) {
            $keep[sha1($t->path.'|'.$t->mtime.'|'.$t->size)] = true;
        }
        foreach (FolderImage::select(['path', 'mtime', 'size'])->cursor() as $i) {
            $keep['folder-'.sha1($i->path.'|'.$i->mtime.'|'.$i->size)] = true;
        }
        $artists = Artist::pluck('id')->flip();
        $store = CoverStore::referenced();
        $removed = 0;
        foreach ($entries as $entry) {
            $path = $dir.'/'.$entry;
            if (! is_file($path)) {
                continue;
            }
            $stale = match (true) {
                (bool) preg_match('/^artist-(\d+)-/', $entry, $m) => ! isset($artists[(int) $m[1]]),
                (bool) preg_match('/^store-([0-9a-f]{40})-/', $entry, $m) => ! isset($store[$m[1]]),
                (bool) preg_match('/^(folder-[0-9a-f]{40})-\d+\.jpg$/', $entry, $m) => ! isset($keep[$m[1]]),
                (bool) preg_match('/^([0-9a-f]{40})(-\d+\.jpg|\.none)$/', $entry, $m) => ! isset($keep[$m[1]]),
                // Zwischendatei beim Auslesen (abgebrochen)
                str_ends_with($entry, '.src') => filemtime($path) < time() - 3600,
                default => false,
            };
            if ($stale && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function source(Track $track, string $tmp): ?string
    {
        if ($track->has_cover && is_file($track->path)) {
            $result = Process::timeout(30)->run(['python3', base_path('tools/tagreader.py'), 'cover', $track->path, $tmp]);
            if ($result->successful() && (json_decode($result->output(), true)['ok'] ?? false) && is_file($tmp)) {
                return $tmp;
            }
        }
        foreach (@scandir($track->dir) ?: [] as $entry) {
            if (preg_match('/^(cover|folder|front)\.(jpe?g|png)$/i', $entry)) {
                return $track->dir.'/'.$entry;
            }
        }

        return null;
    }

    public static function resize(string $source, string $target, int $size): bool
    {
        $data = @file_get_contents($source);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (! $img) {
            return false;
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, $size / max($w, $h));
        $out = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);

        return $out && imagejpeg($out, $target, 85);
    }
}
