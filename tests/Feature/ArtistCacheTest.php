<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use App\Support\ArtistDirectory;
use App\Support\ArtistGroupAnalyzer;
use App\Support\DataVersion;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
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

    public function test_placeholders_are_never_proposed_as_artists(): void
    {
        foreach (['Unknown Artist', 'unknown artist', '-Interpret-', 'Unbekannter Interpret', 'ABBA', 'Sampler', 'Various Artists', 'V.A.'] as $i => $artist) {
            Track::create(['location' => 'library', 'path' => "/m/$i.mp3", 'dir' => '/m', 'filename' => "$i.mp3", 'ext' => 'mp3',
                'artist' => $artist, 'albumartist' => $i === 4 ? 'Unknown Artist' : null, 'title' => "T$i", 'album' => 'A', 'artists' => [], 'albumartists' => [], 'genres' => []]);
        }
        $directory = app(ArtistDirectory::class);
        $this->assertSame(['ABBA' => 1], $directory->namesInCollection());
        $this->assertSame('placeholder', $directory->check('Unknown Artist')['status']);
        $this->assertSame('placeholder', $directory->check('Sampler')['status'], 'Sampler-Schreibweise als Titel-Interpret: kein Künstler');
        $this->assertSame('new', $directory->check('ABBA')['status']);
        $this->assertSame([], $directory->candidateGroups(), 'auch keine Schreibweisen-Gruppe „Unknown Artist / unknown artist“');
        $this->assertSame([], $directory->keepSeparate(['Unknown Artist']));
        Livewire::test(Artists::class, ['tab' => 'unknown'])->assertSet('wizName', 'ABBA')->assertDontSee('Unknown Artist');
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
