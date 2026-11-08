<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sampler bekommen Buchstabenordner nach dem Album ({album_buchstabe}). Gespeicherte Einstellungen, die noch das alte
 * Standard-Muster enthalten, werden umgestellt; selbst angepasste Muster bleiben unverändert. Dateien verschiebt das
 * nicht – das läuft wie immer über „Datei liegt nicht am Regelort“ mit Vorschau.
 */
return new class extends Migration
{
    private const OLD = '{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}';

    private const NEW = '{album_buchstabe}/{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}';

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        $row = DB::table('settings')->where('key', 'categories')->first();
        $categories = $row ? json_decode((string) $row->value, true) : null;
        if (! is_array($categories)) {
            return;
        }
        $changed = false;
        foreach ($categories as $i => $c) {
            if (is_array($c) && trim((string) ($c['pattern'] ?? '')) === $from) {
                $categories[$i]['pattern'] = $to;
                $changed = true;
            }
        }
        if ($changed) {
            DB::table('settings')->where('key', 'categories')->update(['value' => json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }
};
