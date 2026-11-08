<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gespeichertes altes Standard-Muster für Sampler wird auf Buchstabenordner umgestellt, eigene Muster bleiben. */
class SamplerLetterMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_default_pattern_is_switched_custom_kept(): void
    {
        $old = '{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}';
        $custom = '{jahr}/{album}/{nr} - {interpret} - {titel}.{ext}';
        app(Settings::class)->set(['categories' => [
            'lib_compilation' => ['name' => 'LIB COMPILATION', 'folder' => 'library/sampler', 'pattern' => $old],
            'blackhole_compilation' => ['name' => 'BLACKHOLE COMPILATION', 'folder' => 'blackhole/sampler', 'pattern' => $custom],
        ]]);

        (require database_path('migrations/2026_11_08_001400_sampler_letter_folders.php'))->up();

        $settings = new Settings;
        $this->assertSame('{album_buchstabe}/'.$old, $settings->category('LIB COMPILATION')['pattern']);
        $this->assertSame($custom, $settings->category('BLACKHOLE COMPILATION')['pattern']);
    }
}
