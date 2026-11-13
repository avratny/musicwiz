<?php

namespace App\Support;

use App\Models\CoverImage;
use App\Models\FolderImage;
use App\Models\TagChange;
use App\Models\Track;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

/**
 * Neu gesetzte Cover: Original im Cover-Speicher (Name = sha1 des Inhalts, gleiche Bilder nur einmal), Maße und
 * Fingerabdruck in cover_images. Gesetzt wird je Album als Tag-Änderung „cover“ (Verweis auf das Bild) – in die Dateien
 * kommt es erst beim Schreiben. Bilder, auf die weder ein Lied noch eine Änderung verweist, räumt prune() weg.
 */
class CoverStore
{
    public const REASON = 'Cover gesetzt';

    /** größte Datei, die angenommen wird */
    private const MAX_BYTES = 50 * 1024 * 1024;

    public static function dir(): string
    {
        return (string) config('musicwiz.cover_store');
    }

    public static function path(CoverImage $image): string
    {
        return self::dir().'/'.$image->hash.'.'.$image->ext;
    }

    /** Bild aus dem Netz laden und ablegen */
    public function fromUrl(string $url): CoverImage
    {
        $url = trim($url);
        if (! preg_match('~^https?://~i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Bitte eine Adresse mit http:// oder https:// angeben.');
        }
        try {
            $response = Http::timeout(30)->connectTimeout(5)->withHeaders(['User-Agent' => Lookup\Client::USER_AGENT])->get($url);
        } catch (Throwable) {
            throw new InvalidArgumentException('Das Bild ließ sich nicht laden – Adresse nicht erreichbar.');
        }
        if (! $response->successful()) {
            throw new InvalidArgumentException('Das Bild ließ sich nicht laden (Antwort '.$response->status().').');
        }

        return $this->store($response->body(), $url);
    }

    /** hochgeladenes Bild ablegen */
    public function fromUpload(UploadedFile $file): CoverImage
    {
        $data = @file_get_contents($file->getRealPath());
        if ($data === false) {
            throw new InvalidArgumentException('Das hochgeladene Bild ließ sich nicht lesen.');
        }

        return $this->store($data, 'hochgeladen: „'.$file->getClientOriginalName().'“');
    }

    /** eingebettetes Cover eines Lieds übernehmen */
    public function fromTrack(Track $track): CoverImage
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mwcover');
        try {
            $result = Process::timeout(30)->run(['python3', base_path('tools/tagreader.py'), 'cover', $track->path, $tmp]);
            if (! $result->successful() || ! (json_decode($result->output(), true)['ok'] ?? false)) {
                throw new InvalidArgumentException('Das eingebettete Cover ließ sich nicht lesen.');
            }

            return $this->store((string) file_get_contents($tmp), 'eingebettet in „'.$track->filename.'“');
        } finally {
            @unlink($tmp);
        }
    }

    /** Bild im Ordner übernehmen */
    public function fromFolderImage(FolderImage $image): CoverImage
    {
        $data = @file_get_contents($image->path);
        if ($data === false) {
            throw new InvalidArgumentException('Das Bild im Ordner ließ sich nicht lesen.');
        }

        return $this->store($data, 'Bild im Ordner „'.basename($image->path).'“');
    }

    /** Bilddaten prüfen und ablegen (JPEG und PNG wie sie sind, andere Formate als JPEG) */
    public function store(string $data, ?string $source = null): CoverImage
    {
        if ($data === '' || strlen($data) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Kein Bild (leer oder größer als 50 MB).');
        }
        $info = @getimagesizefromstring($data);
        if (! $info) {
            throw new InvalidArgumentException('Das ist kein Bild.');
        }
        $ext = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            default => null,
        };
        if ($ext === null) {
            // WebP, GIF …: Cover-Tags kennen nur JPEG und PNG
            $img = @imagecreatefromstring($data);
            if (! $img) {
                throw new InvalidArgumentException('Dieses Bildformat wird nicht unterstützt.');
            }
            ob_start();
            imagejpeg($img, null, 92);
            $data = (string) ob_get_clean();
            $ext = 'jpg';
        }
        $hash = sha1($data);
        if ($existing = CoverImage::find($hash)) {
            return $existing;
        }
        @mkdir(self::dir(), 0775, true);
        $path = self::dir().'/'.$hash.'.'.$ext;
        file_put_contents($path, $data);
        $result = Process::timeout(30)->run(['python3', base_path('tools/tagreader.py'), 'image', $path]);
        $cover = json_decode($result->output(), true)['cover'] ?? null;

        return CoverImage::create(['hash' => $hash, 'ext' => $ext, 'size' => strlen($data),
            'width' => $cover['width'] ?? $info[0], 'height' => $cover['height'] ?? $info[1], 'phash' => $cover['phash'] ?? null,
            'source' => $source !== null ? mb_substr($source, 0, 1024) : null]);
    }

    /**
     * Cover für alle Lieder eines Albums (Inbox und Lib) setzen – als Änderung in der Datenbank.
     *
     * @return int Zahl der geänderten Lieder
     */
    public function apply(string $albumHash, CoverImage $image): int
    {
        $editor = app(TagEditor::class);
        $changed = 0;
        foreach (Track::where('album_hash', $albumHash)->whereIn('location', SplitAlbums::LOCATIONS)->get() as $track) {
            if ($editor->update($track, ['cover' => $image->hash], TagEditor::MANUAL, self::REASON)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Cover nur für einzelne Lieder setzen (Einzeltitel) – als Änderung in der Datenbank.
     *
     * @param  list<int>  $ids
     * @return int Zahl der geänderten Lieder
     */
    public function applyTracks(array $ids, CoverImage $image): int
    {
        $editor = app(TagEditor::class);

        return Track::whereKey($ids)->whereIn('location', SplitAlbums::LOCATIONS)->get()
            ->filter(fn (Track $track) => $editor->update($track, ['cover' => $image->hash], TagEditor::MANUAL, self::REASON))->count();
    }

    /** verkleinerte Kopie (zwischengespeichert wie die übrigen Cover) oder null */
    public function thumbnail(string $hash, int $size): ?string
    {
        $image = CoverImage::find($hash);
        if (! $image || ! is_file(self::path($image))) {
            return null;
        }
        $size = in_array($size, Covers::SIZES, true) ? $size : 300;
        $target = config('musicwiz.cover_cache').'/store-'.$hash.'-'.$size.'.jpg';
        if (! is_file($target)) {
            @mkdir(dirname($target), 0775, true);
            if (! Covers::resize(self::path($image), $target, $size)) {
                return null;
            }
        }

        return $target;
    }

    /** Hashes, auf die ein Lied oder eine Änderung verweist */
    public static function referenced(): array
    {
        $refs = Track::whereNotNull('cover')->distinct()->pluck('cover')->flip()->all();
        foreach (TagChange::history()->where('field', 'cover')->get(['old_value', 'new_value']) as $c) {
            foreach ([$c->old_value, $c->new_value] as $v) {
                if ($v !== null) {
                    $refs[$v] = true;
                }
            }
        }

        return $refs;
    }

    /** Bilder ohne Verweis löschen (Datei und Eintrag) – nach dem Einlesen @return int Zahl der gelöschten Bilder */
    public static function prune(): int
    {
        $refs = self::referenced();
        $removed = 0;
        foreach (CoverImage::cursor() as $image) {
            if (! isset($refs[$image->hash])) {
                @unlink(self::path($image));
                $image->delete();
                $removed++;
            }
        }
        // Dateien ohne Eintrag (z. B. abgebrochenes Ablegen)
        $known = DB::table('cover_images')->pluck('hash')->flip();
        foreach (@scandir(self::dir()) ?: [] as $entry) {
            if (preg_match('/^([0-9a-f]{40})\.(jpg|png)$/', $entry, $m) && ! isset($known[$m[1]]) && @unlink(self::dir().'/'.$entry)) {
                $removed++;
            }
        }

        return $removed;
    }
}
