<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Covers
{
    public function __construct(private TagTool $tagTool) {}

    /**
     * Woher ein fehlendes eingebettetes Cover kommt: aus einem Track, der schon eins hat (wie in der Anzeige),
     * sonst aus der Cover-Datei im Ordner. Nur Datenbank, kein Zugriff aufs Share.
     *
     * @param  iterable<Track>  $tracks
     */
    public static function embedSource(Album $album, iterable $tracks): ?string
    {
        foreach ($tracks as $t) {
            if ($t->cover_embedded) {
                return $t->path;
            }
        }

        // Cover-Datei nur, wenn der Scan sie als Bild lesen konnte (sonst fehlt die Größe)
        return $album->cover_file && $album->cover_size ? $album->cover_file : null;
    }

    /**
     * Tracks ohne eingebettetes Cover, die sich beschreiben lassen.
     *
     * @param  iterable<Track>  $tracks
     * @return list<Track>
     */
    public static function missingEmbedded(iterable $tracks): array
    {
        $out = [];
        foreach ($tracks as $t) {
            if (! $t->cover_embedded && $t->writable) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** Vorschaubild eines Albums: eingebettetes Cover, sonst cover.jpg im Ordner. */
    public function albumThumb(Album $album, int $size): ?string
    {
        $size = max(64, min(1200, $size));
        $cache = 'covers/album-'.$album->id.'-'.($album->updated_at?->timestamp ?? 0).'-'.$size.'.jpg';
        if (Storage::exists($cache)) {
            return Storage::path($cache);
        }
        Storage::makeDirectory('covers');

        $source = null;
        $tmp = null;
        $track = $album->tracks()->where('cover_embedded', true)->first();
        if ($track && is_file($track->path)) {
            $tmp = Storage::path('covers/tmp-'.bin2hex(random_bytes(4)));
            if ($this->tagTool->extractCover($track->path, $tmp)) {
                $source = $tmp;
            }
        }
        if (! $source && $album->cover_file && is_file($album->cover_file)) {
            $source = $album->cover_file;
        }
        if (! $source) {
            return null;
        }
        $ok = $this->resize($source, Storage::path($cache), $size);
        if ($tmp) {
            @unlink($tmp);
        }

        return $ok ? Storage::path($cache) : $source;
    }

    /** Hochgeladenes Bild als JPEG ablegen (max. 1500 px). Gibt den absoluten Pfad zurück. */
    public function normalizeUpload(string $path, string $dir = 'tmp', int $max = 1500): string
    {
        Storage::makeDirectory($dir);
        $target = Storage::path($dir.'/cover-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.jpg');
        if (! $this->resize($path, $target, $max, false)) {
            throw new RuntimeException('Bild konnte nicht gelesen werden (kein JPEG/PNG/WebP/GIF?).');
        }

        return $target;
    }

    public function resize(string $source, string $target, int $max, bool $square = false): bool
    {
        $data = @file_get_contents($source);
        $img = $data !== false ? @imagecreatefromstring($data) : false;
        if (! $img) {
            return false;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagejpeg($out, $target, 88);
        imagedestroy($img);
        imagedestroy($out);

        return $ok;
    }

    /** Bild aus einer URL laden (Künstlerbilder, Cover-Kandidaten). */
    public function download(string $url, string $dir = 'tmp'): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Bitte eine gültige http(s)-URL angeben.');
        }
        $response = Http::timeout(20)->withHeaders(['User-Agent' => Lookup::userAgent()])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Download fehlgeschlagen ('.$response->status().')');
        }
        if (strlen($response->body()) > 25 * 1024 * 1024) {
            throw new RuntimeException('Bild ist größer als 25 MB.');
        }
        Storage::makeDirectory($dir);
        $raw = Storage::path($dir.'/dl-'.bin2hex(random_bytes(6)));
        file_put_contents($raw, $response->body());
        try {
            return $this->normalizeUpload($raw, $dir);
        } finally {
            @unlink($raw);
        }
    }
}
