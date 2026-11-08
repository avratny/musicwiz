<?php

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Blackhole ist ein eigener Bereich (eigener Scan, nicht mehr Teil der Lib); dazu nur noch ein Inbox-Ordner
// und feste Kategorien je Bereich.
return new class extends Migration
{
    public function up(): void
    {
        $settings = new Settings;
        // gespeicherte Einstellungen ins neue Format bringen (Settings liest beides, schreibt aber nur das neue)
        $stored = Setting::pluck('value', 'key')->all();
        if (! isset($stored['inbox']) && ! empty($stored['inbox_paths'])) {
            $settings->set(['inbox' => $settings->inbox()]);
        }
        Setting::where('key', 'inbox_paths')->delete();
        if (isset($stored['categories'])) {
            $settings->set(['categories' => collect($settings->categories())
                ->mapWithKeys(fn ($c) => [$c['role'] => ['name' => $c['name'], 'folder' => $c['folder'], 'pattern' => $c['pattern']]])->all()]);
        }

        foreach ($settings->categoriesIn('blackhole') as $c) {
            $root = $settings->categoryRoot($c);
            DB::table('tracks')->where('location', 'library')->where('path', 'like', $root.'/%')->update(['location' => 'blackhole']);
            DB::table('albums')->where('location', 'library')->where(fn ($q) => $q->where('dir', $root)->orWhere('dir', 'like', $root.'/%'))->update(['location' => 'blackhole']);
            // Aufräumen gilt nur in der Lib
            DB::table('leftovers')->where('path', 'like', $root.'/%')->delete();
        }
    }

    public function down(): void
    {
        DB::table('tracks')->where('location', 'blackhole')->update(['location' => 'library']);
        DB::table('albums')->where('location', 'blackhole')->update(['location' => 'library']);
    }
};
