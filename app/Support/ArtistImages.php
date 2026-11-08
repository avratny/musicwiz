<?php

namespace App\Support;

use App\Models\Artist;

/** Künstlerbilder im Bilder-Ordner ablegen (ein altes Bild wandert in den Papierkorb). */
class ArtistImages
{
    public static function store(Artist $artist, string $tmp): void
    {
        $dir = rtrim(app(Settings::class)->get('artist_images'), '/');
        $files = app(FileOps::class);
        $files->ensureDir($dir);
        $file = self::fileName($artist->name, 'jpg');
        if ($artist->image && $artist->image !== $file && is_file($dir.'/'.$artist->image)) {
            $files->trash($dir.'/'.$artist->image);
        } elseif (is_file($dir.'/'.$file)) {
            $files->trash($dir.'/'.$file);
        }
        copy($tmp, $dir.'/'.$file);
        @unlink($tmp);
        $artist->update(['image' => $file]);
        $artist->touch();
    }

    public static function fileName(string $name, string $ext): string
    {
        return str_replace(['/', ':', '\\', '?', '*', '"', '<', '>', '|'], ['-', '-', '-', '', '', "'", '', '', ''], $name).'.'.($ext ?: 'jpg');
    }
}
