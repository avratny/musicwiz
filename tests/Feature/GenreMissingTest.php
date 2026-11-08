<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** „Alben ohne Genre“: Vorschlag aus dem Standard-Genre des Künstlers bzw. seinen anderen Liedern. */
class GenreMissingTest extends TestCase
{
    use RefreshDatabase;

    public function test_albums_without_genre_get_a_suggestion(): void
    {
        $base = storage_path('framework/testing/genremissing-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'inbox_paths' => [$base.'/inbox'], 'trash' => $base.'/_papierkorb', 'scan_throttle_ms' => 0]);
        $files = [
            'A/ABBA/Gold (1992)/01 - Waterloo.mp3' => ['ABBA', 'Gold', ['Pop']],
            'A/ABBA/Gold (1992)/02 - SOS.mp3' => ['ABBA', 'Gold', ['Pop']],
            'A/ABBA/Arrival (1976)/01 - Fernando.mp3' => ['ABBA', 'Arrival', []],
            'T/Taucher/Atlanta (1999)/01 - Atlanta.mp3' => ['Taucher', 'Atlanta', []],
            'U/Unbekannt/Nichts (2000)/01 - Nichts.mp3' => ['Unbekannt', 'Nichts', []],
        ];
        foreach ($files as $rel => [$artist, $album, $genres]) {
            $path = $base.'/library/artists/'.$rel;
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60));
            app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => $artist, 'albumartist' => $artist, 'album' => $album, 'genres' => $genres, 'comment' => 'LIB']]]);
        }
        Artist::create(['name' => 'Taucher', 'default_genre' => 'Trance']);
        app(Scanner::class)->scan('library');

        $findings = collect(app(CleanupChecks::class)->findings('genre_missing'));
        $detail = fn (string $needle) => $findings->first(fn ($f) => str_contains($f['title'], $needle));

        $this->assertStringContainsString('Vorschlag: Pop (2 von 3 Liedern von ABBA)', $detail('Arrival')['detail']);
        $this->assertStringContainsString('Vorschlag: Trance (Standard-Genre von Taucher)', $detail('Atlanta')['detail']);
        $this->assertStringContainsString('kein Vorschlag', $detail('Nichts')['detail']);
        $this->assertSame([], $detail('Nichts')['actions'], 'ohne Vorschlag nichts zu übernehmen');

        // Übernehmen schreibt Unterstil samt Hauptgenre
        OperationRunner::dispatch('cleanup', 'Genre', $detail('Atlanta')['actions']);
        $path = $base.'/library/artists/T/Taucher/Atlanta (1999)/01 - Atlanta.mp3';
        $this->assertSame(['Electronic', 'Trance'], app(TagTool::class)->read([$path])[$path]['tags']['genres']);
        File::deleteDirectory($base);
    }
}
