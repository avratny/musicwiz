<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\HistoryEntry;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    /**
     * Inhalt der Lib, soweit er Künstlernamen und Profile betrifft (Interpret, Titel, Album, Genre, Länge …). Ändert sich
     * nicht bei Bewertungen, ReplayGain, Songtexten oder Verschieben – so müssen Namensliste und Profile nach solchen
     * Änderungen nicht neu berechnet werden. Der Fingerabdruck selbst wird nur neu gebildet, wenn sich tracks() ändert.
     */
    public static function artistContent(): string
    {
        $tracks = self::tracks();
        static $memo = [];
        if (isset($memo[$tracks])) {
            return $memo[$tracks];
        }

        return $memo[$tracks] = Cache::remember('data-version:artist-content:'.$tracks, now()->addDays(7), function () {
            $hash = hash_init('xxh128');
            $rows = DB::table('tracks')->where('location', 'library')->orderBy('id')
                ->select(['id', 'album_id', 'artist', 'artists', 'albumartist', 'albumartists', 'title', 'album', 'genres', 'compilation', 'duration'])->cursor();
            foreach ($rows as $r) {
                hash_update($hash, implode("\x1f", (array) $r)."\x1e");
            }

            return hash_final($hash);
        });
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
