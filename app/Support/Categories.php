<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Kategorie eines Lieds: wohin es beim Schreiben einsortiert wird – Lib oder Blackhole, jeweils Interpreten-Alben oder
 * Sampler. Steht als eigenes Tag MUSICWIZ_CATEGORY in der Datei (MP3: TXXX); der Kommentar bleibt, wie er ist.
 * Gewählt wird nur in der Datenbank (wie jede Tag-Änderung); verschoben wird erst später beim Schreiben.
 */
class Categories
{
    public const TAG = 'MUSICWIZ_CATEGORY';

    /** Wert im Tag => Beschriftung */
    public const LABELS = [
        'LIB' => 'Lib',
        'LIB COMPILATION' => 'Lib Compilation',
        'BLACKHOLE' => 'Blackhole',
        'BLACKHOLE COMPILATION' => 'Blackhole Compilation',
    ];

    /** Wert im Tag => Bereich, in den das Lied gehört */
    public const LOCATIONS = [
        'LIB' => 'library',
        'LIB COMPILATION' => 'library',
        'BLACKHOLE' => 'blackhole',
        'BLACKHOLE COMPILATION' => 'blackhole',
    ];

    /** Kategorie aus den rohen Tags der Datei (unbekannte Werte zählen als keine) */
    public static function fromRawTags(array $raw): ?string
    {
        $upper = array_change_key_case($raw, CASE_UPPER);
        foreach (['TXXX:'.self::TAG, '----:COM.APPLE.ITUNES:'.self::TAG, self::TAG] as $key) {
            foreach ((array) ($upper[$key] ?? []) as $v) {
                if ($category = self::normalize($v)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /** „lib compilation“, „Lib_Compilation“ → „LIB COMPILATION“; anderes → null */
    public static function normalize(mixed $value): ?string
    {
        $value = strtoupper(trim(preg_replace('/[\s_]+/', ' ', (string) $value)));

        return isset(self::LABELS[$value]) ? $value : null;
    }

    /** Kategorie für einen Bereich (library, blackhole) und Sampler ja/nein */
    public static function for(string $location, bool $compilation): string
    {
        return ($location === 'blackhole' ? 'BLACKHOLE' : 'LIB').($compilation ? ' COMPILATION' : '');
    }

    /** gleicher Bereich, Sampler ja/nein angepasst (keine Kategorie bleibt keine) */
    public static function follow(?string $category, bool $compilation): ?string
    {
        return $category === null ? null : self::for(self::LOCATIONS[$category] ?? 'library', $compilation);
    }

    /**
     * Lieder ohne Kategorie vorbelegen (System-Änderung): Inbox und Lib → LIB, Sampler (Flag oder Album-Interpret
     * „Various Artists“) → LIB COMPILATION. Blackhole wird ignoriert, Papierkorb bleibt ohne.
     *
     * @return int Zahl der vorbelegten Lieder
     */
    public function fill(): int
    {
        $filled = 0;
        foreach (['inbox' => 'library', 'library' => 'library'] as $location => $target) {
            foreach ([false, true] as $compilation) {
                $category = self::for($target, $compilation);
                $query = fn () => DB::table('tracks')->where('location', $location)->whereNull('category')
                    ->where(fn ($q) => $compilation
                        ? $q->where('compilation', true)->orWhere('albumartist', NameRules::VARIOUS)
                        : $q->where('compilation', false)->where(fn ($q) => $q->whereNull('albumartist')->orWhere('albumartist', '!=', NameRules::VARIOUS)));
                // in einem Rutsch: erst die Änderungen festhalten, dann setzen
                DB::transaction(function () use ($query, $category, &$filled) {
                    DB::table('tag_changes')->insertUsing(['track_id', 'field', 'old_value', 'new_value', 'source', 'reason', 'created_at'],
                        $query()->selectRaw("id, 'category', NULL, ?, ?, ?, ?", [$category, TagEditor::SYSTEM, 'Kategorie vorbelegt', now()]));
                    $filled += $query()->update(['category' => $category, 'updated_at' => now()]);
                });
            }
        }

        return $filled;
    }
}
