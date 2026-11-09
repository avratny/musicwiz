<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Livewire\Cleanup;
use App\Models\Album;
use App\Support\CleanupChecks;
use App\Support\Compilations;
use App\Support\PendingChanges;
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

    public function test_album_mixed_shows_what_changes_to_what(): void
    {
        foreach ([1 => 'Gold', 2 => 'Gold', 3 => 'Gold', 4 => 'Gold (Remaster)'] as $n => $album) {
            $this->file("library/artists/A/ABBA/Gold (1992)/0$n - T$n.mp3", ['artist' => 'ABBA', 'albumartist' => $n === 4 ? '' : 'ABBA', 'album' => $album, 'year' => '1992', 'track' => (string) $n, 'comment' => 'LIB']);
        }
        app(Scanner::class)->scan('library');

        $f = app(CleanupChecks::class)->findings('album_mixed')[0];
        $this->assertSame([
            ['field' => 'Album', 'from' => 'Gold', 'count' => 3, 'to' => null],
            ['field' => 'Album', 'from' => 'Gold (Remaster)', 'count' => 1, 'to' => 'Gold'],
            ['field' => 'Album-Interpret', 'from' => 'ABBA', 'count' => 3, 'to' => null],
            ['field' => 'Album-Interpret', 'from' => '', 'count' => 1, 'to' => 'ABBA'],
        ], $f['changes']);

        Livewire::test(Cleanup::class)->set('check', 'album_mixed')
            ->assertSee('→ neu')->assertSee('Gold (Remaster)')->assertSee('✓ bleibt')->assertSee('∅ (leer)');
    }

    public function test_compilation_in_artist_folder_flag_is_removed_with_real_album_artist(): void
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
        // Einzellied in „Kein Album“ mit Flag, ohne Album-Interpreten (aus einem Sampler übrig geblieben)
        $this->file('library/artists/N/Nana/Kein Album/Lonely.mp3', ['artist' => 'Nana', 'title' => 'Lonely', 'comment' => 'LIB', 'compilation' => true]);
        // „Sampler“ als Interpret eines Titels: fehlender Name
        $this->file('library/artists/F/Foo/Y/01 - T.mp3', ['artist' => 'Sampler', 'albumartist' => 'Foo', 'album' => 'Y', 'track' => '1', 'comment' => 'LIB']);
        app(Scanner::class)->scan('library');
        $checks = app(CleanupChecks::class);

        $reported = collect($checks->findings('compilation_in_artists'));
        $this->assertCount(3, $reported, $reported->pluck('title')->toJson(JSON_UNESCAPED_UNICODE));
        $this->assertContains('Various – Party', $reported->pluck('title')->all());
        $this->assertContains('ABBA – Gold', $reported->pluck('title')->all());
        // „Kein Album“ ohne Album-Interpreten: Flag ist falsch → entfernen
        $loose = $reported->first(fn ($f) => str_contains($f['title'], 'Kein Album'));
        $this->assertStringContainsString('Flag wird entfernt', $loose['detail']);
        $this->assertSame([['type' => 'apply', 'path' => $this->base.'/library/artists/N/Nana/Kein Album/Lonely.mp3', 'tags' => ['compilation' => false]]], $loose['actions']);
        // „Various“ als Album-Interpret: nur melden, im Editor entscheiden
        $this->assertSame([], $reported->firstWhere('title', 'Various – Party')['actions']);
        $this->assertStringContainsString('„Various“', $reported->firstWhere('title', 'Various – Party')['detail']);
        // echter Album-Interpret „ABBA“: nur das Flag ist falsch → entfernen
        $gold = $reported->firstWhere('title', 'ABBA – Gold');
        $this->assertStringContainsString('Compilation-Flag', $gold['detail']);
        $this->assertStringContainsString('„ABBA“ bleibt', $gold['detail']);
        $this->assertSame([['type' => 'apply', 'path' => $this->base.'/library/artists/A/ABBA/Gold/01 - Waterloo.mp3', 'tags' => ['compilation' => false]]], $gold['actions']);
        $this->assertTrue(PendingChanges::stageable($gold['actions']), 'wird vorgemerkt, nicht sofort geschrieben');

        // bis zur Entscheidung arbeitet nichts dagegen (Flag entfernen, nach V/Various verschieben)
        $titles = fn (string $key) => collect($checks->findings($key))->pluck('title')->all();
        $this->assertNotContains('ABBA – Gold', $titles('comment'));
        $this->assertNotContains('ABBA – Gold', $titles('misplaced'));
        $this->assertNotContains('Various – Party', $titles('misplaced'));

        $this->assertStringContainsString('Album-Interpret „Unknown Artist“ – prüfen (1)', collect($checks->findings('missing_tags'))->firstWhere('title', 'Unknown Artist – X')['detail']);
        $this->assertStringContainsString('Interpret „Sampler“ – prüfen (1)', collect($checks->findings('missing_tags'))->firstWhere('title', 'Foo – Y')['detail']);
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

    public function test_compilation_flag_stays_with_several_performers_in_artist_folder(): void
    {
        // „All That Jazz“: Album-Interpret Acker Bilk, Titel von drei Interpreten – echter Sampler, nicht einfach Flag weg
        $n = 0;
        foreach (['Acker Bilk' => 'Stranger On The Shore', 'Chris Barber' => 'High Society', 'Kenny Ball' => 'Acapulco 1922'] as $artist => $title) {
            $n++;
            $this->file("library/artists/A/Acker Bilk/All That Jazz/0$n - $title.mp3", ['artist' => $artist, 'albumartist' => 'Acker Bilk', 'album' => 'All That Jazz', 'track' => (string) $n, 'title' => $title, 'comment' => 'LIB', 'compilation' => true]);
        }
        app(Scanner::class)->scan('library');

        $f = collect(app(CleanupChecks::class)->findings('compilation_in_artists'))->sole();
        $this->assertSame([], $f['actions']);
        $this->assertStringContainsString('3 verschiedene Interpreten – im Album-Editor entscheiden', $f['detail']);
    }

    public function test_album_year_majority_else_newest(): void
    {
        $y = fn (array $years) => CleanupChecks::albumYear(collect($years));
        $this->assertSame('1999', $y(['1999' => 20, '1998' => 1, '2000' => 3, '' => 14]), 'Mehrheit der Titel mit Jahr');
        $this->assertSame('1996', $y(['1957' => 2, '1961' => 1, '1978' => 2, '1996' => 1]), 'keine Mehrheit: das neueste');
        $this->assertSame('', $y(['' => 5]));
    }
}
