<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Album oder Einzeltitel? Ein Album-Ordner (album_hash) gilt als lose (Einzeltitel – Lieder dürfen einzeln wandern,
 * ersetzt bzw. verworfen werden), wenn
 * - er „Kein Album“ heißt (Einstellungen › Dateinamen),
 * - er nur ein Lied ohne Album-Tag enthält oder
 * - jedes Lied mit Album-Tag ein anderes Album hat (Sammelordner – Lieder ohne Album-Tag zählen dabei nicht).
 * Sonst ist es ein Album – auch mehrere Lieder ganz ohne Album-Tag (frischer Download) oder uneinheitliche Tags.
 */
class Albums
{
    /** Name des Ordners für Lieder ohne Album */
    public static function noAlbum(): string
    {
        return mb_strtolower((string) app(Settings::class)->get('no_album', '') ?: PathTemplate::OPTIONS['no_album'][1]);
    }

    /** @param  Collection<int, Track>  $tracks  alle Lieder eines Album-Ordners (mit album und album_dir bzw. dir) */
    public static function loose(Collection $tracks): bool
    {
        if ($tracks->isEmpty()) {
            return false;
        }
        $first = $tracks->first();
        if (mb_strtolower(basename((string) ($first->album_dir ?? $first->dir))) === self::noAlbum()) {
            return true;
        }
        $tagged = $tracks->map(fn ($t) => mb_strtolower(trim((string) $t->album)))->filter(fn ($a) => $a !== '');

        return $tracks->count() === 1 ? $tagged->isEmpty() : $tagged->isNotEmpty() && $tagged->unique()->count() === $tagged->count();
    }

    /**
     * Für viele Album-Ordner auf einmal (per SQL).
     *
     * @param  iterable<string>  $hashes
     * @return array<string, bool> Album-Hash => lose
     */
    public static function looseHashes(iterable $hashes): array
    {
        $out = [];
        [$sql, $bindings] = self::looseSql();
        foreach (collect($hashes)->unique()->chunk(1000) as $chunk) {
            foreach (DB::table('tracks')->whereIn('album_hash', $chunk->values())->groupBy('album_hash')
                ->selectRaw('album_hash, '.$sql.' AS loose', $bindings)->get() as $row) {
                $out[$row->album_hash] = (bool) $row->loose;
            }
        }

        return $out;
    }

    /** SQL-Ausdruck (je album_hash gruppiert): 1 = lose. @return array{0: string, 1: list<string>} */
    public static function looseSql(): array
    {
        $tagged = "SUM(album IS NOT NULL AND TRIM(album) <> '')";

        return ["(LOWER(SUBSTRING_INDEX(MIN(album_dir), '/', -1)) = ? OR (COUNT(*) = 1 AND $tagged = 0)"
            ." OR (COUNT(*) > 1 AND $tagged > 0 AND COUNT(DISTINCT NULLIF(LOWER(TRIM(album)), '')) = $tagged))", [self::noAlbum()]];
    }
}
