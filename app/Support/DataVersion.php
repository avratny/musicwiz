<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\HistoryEntry;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;

/**
 * Billige Versionsschlüssel für Caches: ändern sich nur, wenn sich die Daten ändern (Scan, Aktion, Verzeichnis).
 * Bewusst nicht pro Request gemerkt – nach einem Schreibzugriff im selben Request muss der neue Stand gelten.
 */
class DataVersion
{
    /** Tracks und Alben (Scan, Tags, Verschieben). */
    public static function tracks(): string
    {
        return md5(implode('|', [Track::count(), Track::max('id'), Track::max('updated_at'), Album::max('updated_at'), HistoryEntry::max('id')]));
    }

    /** Künstlerverzeichnis (Namen, Aliase, gleichnamige Personen). */
    public static function directory(): string
    {
        return md5(implode('|', [Artist::count(), Artist::max('updated_at'), ArtistAlias::count(), ArtistAlias::max('updated_at'), Cache::get(self::DIRECTORY_BUMP, 0)]));
    }

    /** Zähler für Änderungen im Verzeichnis innerhalb derselben Sekunde (updated_at hat nur Sekunden). */
    public const DIRECTORY_BUMP = 'data-version:directory';

    public static function bumpDirectory(): void
    {
        Cache::increment(self::DIRECTORY_BUMP);
    }
}
