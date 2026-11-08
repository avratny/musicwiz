<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Models\Album;
use App\Support\CleanupChecks;
use App\Support\Compilations;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** Sampler erkennen: Flag oder „Various Artists“-Schreibweise im Album-Interpreten; ARTIST bleibt immer unverändert. */
class CompilationsTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/va-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_variants_and_placeholders(): void
    {
        $c = app(Compilations::class);
        foreach (['Various Artists', 'V. A.', 'V/A', 'v-a', 'VA', 'Various Artists (Compilation)', 'Various [VA]', 'Verschiedene Künstler',
            'Diverse Interpreten', 'Artistes Variés', 'Różni wykonawcy', 'AA.VV.', 'Sampler', 'Сборник', ' various artists '] as $v) {
            $this->assertTrue($c->isVariousArtists($v), $v);
        }
        foreach (['Various Cruelties', 'Divine', 'Vanessa', 'Diverse Hits', 'Unknown Artist', 'ABBA', '', null] as $v) {
            $this->assertFalse($c->isVariousArtists($v), (string) $v);
        }
        foreach (['Unknown Artist', '[unknown]', 'Unbekannter Interpret', 'unknown'] as $v) {
            $this->assertTrue(Compilations::isPlaceholder($v), $v);
        }
        $this->assertFalse(Compilations::isPlaceholder('Various Artists'));
        $this->assertSame('va', Compilations::normalize('V. A.'));
    }

    private function file(string $rel, array $tags): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags + ['title' => pathinfo($rel, PATHINFO_FILENAME)]]]);

        return $path;
    }

    public function test_cleanup_sets_various_artists_and_keeps_track_artist(): void
    {
        $this->file('library/sampler/H/Hits (2000)/01 - ABBA - SOS.mp3', ['artist' => 'ABBA', 'albumartist' => 'V.A.', 'album' => 'Hits', 'year' => '2000', 'track' => '1', 'comment' => 'LIB COMPILATION']);
        $this->file('library/sampler/H/Hits (2000)/02 - Yanou - Sunrise.mp3', ['artist' => 'Yanou', 'albumartist' => 'V.A.', 'album' => 'Hits', 'year' => '2000', 'track' => '2', 'comment' => 'LIB COMPILATION']);
        app(Scanner::class)->scan('library');

        $findings = app(CleanupChecks::class)->findings('sampler_flags');
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('„V.A.“ → „Various Artists“ (2 Titel)', $findings[0]['detail']);
        $this->assertStringContainsString('Compilation-Flag 0 → 1 (2 Titel)', $findings[0]['detail']);
        foreach ($findings[0]['actions'] as $a) {
            $this->assertSame('Various Artists', $a['tags']['albumartist']);
            $this->assertTrue($a['tags']['compilation']);
            $this->assertArrayNotHasKey('artist', $a['tags']);
        }
    }

    public function test_compilation_in_artist_folder_is_only_reported(): void
    {
        // „Various“ als Album-Interpret, liegt aber bei den Künstlern
        $this->file('library/artists/V/Various/Party (1999)/01 - ABBA.mp3', ['artist' => 'ABBA', 'albumartist' => 'Various', 'album' => 'Party', 'year' => '1999', 'track' => '1', 'comment' => 'LIB']);
        $this->file('library/artists/V/Various/Party (1999)/02 - Yanou.mp3', ['artist' => 'Yanou', 'albumartist' => 'Various', 'album' => 'Party', 'year' => '1999', 'track' => '2', 'comment' => 'LIB']);
        // Flag gesetzt, Kommentar passt nicht
        $this->file('library/artists/A/ABBA/Gold/01 - Waterloo.mp3', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Gold', 'track' => '1', 'compilation' => true]);
        // echter Album-Interpret, gemischte Interpreten: kein Sampler
        $this->file('library/artists/Y/Yanou/Sunrise (2006)/01 - King of My Castle.mp3', ['artist' => 'Yanou feat. Liz', 'albumartist' => 'Yanou', 'album' => 'Sunrise', 'year' => '2006', 'track' => '1', 'comment' => 'LIB']);
        // Platzhalter: nur prüfen
        $this->file('library/artists/U/Unknown Artist/X/01 - T.mp3', ['artist' => 'Someone', 'albumartist' => 'Unknown Artist', 'album' => 'X', 'track' => '1', 'comment' => 'LIB']);
        app(Scanner::class)->scan('library');
        $checks = app(CleanupChecks::class);

        $reported = collect($checks->findings('compilation_in_artists'));
        $this->assertEqualsCanonicalizing(['Various – Party', 'ABBA – Gold'], $reported->pluck('title')->all());
        $this->assertTrue($reported->every(fn ($f) => $f['actions'] === []));
        $this->assertStringContainsString('Compilation-Flag', $reported->firstWhere('title', 'ABBA – Gold')['detail']);
        $this->assertStringContainsString('„Various“', $reported->firstWhere('title', 'Various – Party')['detail']);

        // bis zur Entscheidung arbeitet nichts dagegen (Flag entfernen, nach V/Various verschieben)
        $titles = fn (string $key) => collect($checks->findings($key))->pluck('title')->all();
        $this->assertNotContains('ABBA – Gold', $titles('comment'));
        $this->assertNotContains('ABBA – Gold', $titles('misplaced'));
        $this->assertNotContains('Various – Party', $titles('misplaced'));

        $this->assertStringContainsString('Album-Interpret „Unknown Artist“ – prüfen (1)', collect($checks->findings('missing_tags'))->firstWhere('title', 'Unknown Artist – X')['detail']);
    }

    public function test_inbox_preselects_sampler_category(): void
    {
        $this->file('inbox/Party/01.mp3', ['artist' => 'ABBA', 'albumartist' => 'Diverse', 'album' => 'Party', 'track' => '1']);
        $this->file('inbox/Party/02.mp3', ['artist' => 'Yanou', 'albumartist' => 'Diverse', 'album' => 'Party', 'track' => '2']);
        app(Scanner::class)->scan('inbox');

        Livewire::test(AlbumEditor::class, ['album' => Album::firstOrFail()])
            ->assertSet('fields.category', 'LIB COMPILATION')
            ->assertSee('Sampler erkannt an: Album-Interpret „Diverse“.');
    }
}
