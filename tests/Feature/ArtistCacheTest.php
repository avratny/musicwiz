<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistGroupAnalyzer;
use App\Support\DataVersion;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Namensliste und Profile nur neu berechnen, wenn sich Namen/Titel ändern – nicht nach Bewertungen o. Ä. */
class ArtistCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_follow_directory_without_regrouping(): void
    {
        foreach (['Scooter', 'Scooter', 'SCOOTER', 'Mark Oh', 'Mark \'Oh'] as $i => $artist) {
            Track::create(['location' => 'library', 'path' => "/m/$i.mp3", 'dir' => '/m', 'filename' => "$i.mp3", 'ext' => 'mp3',
                'artist' => $artist, 'title' => "T$i", 'album' => 'A', 'artists' => [], 'albumartists' => [], 'genres' => []]);
        }
        $groups = app(ArtistDirectory::class)->candidateGroups();
        $sets = fn (array $groups) => collect($groups)->map(fn ($g) => collect(array_keys($g['names']))->sort()->implode(' | '))->sort()->values()->all();
        $this->assertSame(["Mark 'Oh | Mark Oh", 'SCOOTER | Scooter'], $sets($groups));
        $analysis = app(ArtistGroupAnalyzer::class)->analyze($groups[0]);
        $this->assertSame($analysis, app(ArtistGroupAnalyzer::class)->analyze($groups[0]), 'zweites Mal aus dem Zwischenspeicher');

        // Vorschlag übernommen: Schreibweise festgelegt, Variante zugeordnet → Gruppe verschwindet sofort
        $scooter = Artist::create(['name' => 'Scooter']);
        ArtistAlias::create(['artist_id' => $scooter->id, 'alias' => 'SCOOTER']);
        app(ArtistDirectory::class)->flush();
        $left = (new ArtistDirectory(app(Settings::class)))->candidateGroups();
        $this->assertSame(["Mark 'Oh | Mark Oh"], $sets($left));
    }

    public function test_content_version_ignores_ratings_but_not_names(): void
    {
        foreach (['ABBA', 'ABBA', 'Yanou feat. Liz'] as $i => $artist) {
            Track::create(['location' => 'library', 'path' => "/m/$i.mp3", 'dir' => '/m', 'filename' => "$i.mp3", 'ext' => 'mp3',
                'artist' => $artist, 'title' => "T$i", 'album' => 'A', 'artists' => [], 'albumartists' => [], 'genres' => []]);
        }
        $this->assertSame(['ABBA' => 2, 'Yanou' => 1, 'Liz' => 1], app(ArtistDirectory::class)->namesInCollection());
        $content = DataVersion::artistContent();

        Carbon::setTestNow(now()->addMinute());
        Track::where('path', '/m/0.mp3')->update(['rating' => 5, 'replaygain' => '-6.00 dB', 'has_lyrics' => true]);
        $this->assertSame($content, DataVersion::artistContent(), 'Bewertung/ReplayGain/Songtext: Namen bleiben gültig');

        Carbon::setTestNow(now()->addMinute());
        Track::where('path', '/m/1.mp3')->update(['artist' => 'Abba']);
        $this->assertNotSame($content, DataVersion::artistContent());
        $this->assertSame(['ABBA' => 1, 'Abba' => 1, 'Yanou' => 1, 'Liz' => 1], (new ArtistDirectory(app(Settings::class)))->namesInCollection());
        $this->assertArrayHasKey('Abba', app(ArtistGroupAnalyzer::class)->profiles());
    }
}
