<?php

namespace App\Support;

use App\Models\Artist;

/**
 * Interpretenbilder für Navidrome (ArtistImageFolder): Navidrome sucht im Bilderordner eine Datei, die genau wie die
 * MusicBrainz-ID oder der Name des Interpreten heißt (Groß/klein egal, Zeichen werden nicht umgewandelt). MusicWiz
 * speichert jedes Bild als „Name.jpg“ mit ersetzten Sonderzeichen (auch über SMB/Windows nutzbar) – dazu kommen beim
 * Schreiben Kopien unter der MusicBrainz-ID und, wenn der Name Sonderzeichen hat, unter dem exakten Namen
 * („*NSYNC.jpg“ neben „-NSYNC.jpg“). Kopien werden nur geschrieben, wenn sie fehlen oder vom Bild abweichen.
 */
class ArtistImages
{
    /** Dateinamen der Kopien eines Interpreten (ohne das Bild selbst) */
    public static function copies(Artist $artist): array
    {
        if (! $artist->image) {
            return [];
        }
        $names = [];
        if ($artist->mbid) {
            $names[] = $artist->mbid.'.jpg';
        }
        // exakter Name – „/“ geht in keinem Dateinamen, „.“ und „..“ sind keine
        $exact = trim($artist->name).'.jpg';
        if (! str_contains($artist->name, '/') && ! in_array(trim($artist->name), ['', '.', '..'], true)) {
            $names[] = $exact;
        }

        return array_values(array_filter(array_unique($names), fn ($n) => $n !== $artist->image));
    }

    /**
     * Kopien aller Interpreten mit Bild anlegen bzw. auffrischen.
     *
     * @return array{written: int, failed: array<string, string>} failed: Datei => Grund
     */
    public function sync(): array
    {
        $dir = rtrim((string) config('musicwiz.artist_images'), '/');
        $written = 0;
        $failed = [];
        foreach (Artist::whereNotNull('image')->get(['id', 'name', 'mbid', 'image']) as $artist) {
            $source = $artist->imagePath();
            if (! $source) {
                continue;
            }
            $hash = null;
            foreach (self::copies($artist) as $name) {
                $target = $dir.'/'.$name;
                if (is_file($target) && filesize($target) === filesize($source) && md5_file($target) === ($hash ??= md5_file($source))) {
                    continue;
                }
                if (@copy($source, $target)) {
                    $written++;
                } else {
                    $failed[$target] = 'Interpretenbild nicht kopierbar';
                }
            }
        }

        return ['written' => $written, 'failed' => $failed];
    }

    /**
     * Umbenannt: das Bild zieht unter den neuen Namen um (sonst findet Navidrome es nicht), die Kopie unter dem alten
     * exakten Namen verschwindet, wenn sie noch dem Bild entspricht. Gibt es unter dem neuen Namen schon eine Datei,
     * bleibt alles, wie es ist – nichts wird überschrieben.
     */
    public function renamed(Artist $artist, string $oldName): void
    {
        $source = $artist->imagePath();
        if (! $source) {
            return;
        }
        $dir = dirname($source);
        $file = ArtistRegistry::fileName($artist->name);
        if ($file !== $artist->image && ! file_exists($dir.'/'.$file) && @rename($source, $dir.'/'.$file)) {
            $artist->update(['image' => $file]);
            $source = $dir.'/'.$file;
        }
        $old = $dir.'/'.trim($oldName).'.jpg';
        if ($old !== $source && ! str_contains($oldName, '/') && is_file($old) && md5_file($old) === md5_file($source)) {
            @unlink($old);
        }
    }
}
