<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Models\GenreMapping;
use App\Models\Track;
use App\Support\Genres;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArtistDefaultGenreTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $artist, array $genres): void
    {
        static $i = 0;
        $i++;
        Track::create(['location' => 'library', 'path' => "/m/$i.mp3", 'dir' => '/m', 'filename' => "$i.mp3", 'ext' => 'mp3',
            'artist' => $artist, 'title' => "Song $i", 'genres' => $genres, 'artists' => [], 'albumartists' => []]);
    }

    public function test_suggestion_live_save_and_bulk_fill(): void
    {
        GenreMapping::updateOrCreate(['source' => 'Euro-Dance'], ['target' => 'Eurodance']);
        $this->track('ATC', ['Electronic', 'Eurodance']);
        $this->track('ATC feat. Liz', ['Euro-Dance']);        // über Zuordnung + Kombi erkannt
        $this->track('Atc', ['Pop']);                          // Variante (Vergleichsschlüssel)
        $this->track('ABBA', ['Pop']);
        $atc = Artist::create(['name' => 'ATC']);
        $abba = Artist::create(['name' => 'ABBA', 'default_genre' => 'Schlager']);
        Artist::create(['name' => 'Unbekannt']);

        $this->assertSame(['genre' => 'Eurodance', 'count' => 2, 'total' => 3], app(Genres::class)->suggestForArtist($atc));

        $page = Livewire::test(Artists::class)->call('select', $atc->id)
            ->assertSee('Vorschlag aus Bestand: Eurodance (2 von 3 Liedern)')
            ->call('useSuggestedGenre');
        $this->assertSame('Eurodance', $atc->fresh()->default_genre);

        // sofort speichern beim Ändern
        $page->set('form.default_genre', 'Pop');
        $this->assertSame('Pop', $atc->fresh()->default_genre);

        // alle ohne Genre füllen – vorhandene bleiben
        $atc->update(['default_genre' => null]);
        $page->call('fillDefaultGenres')->assertSee('1 Künstler haben jetzt ein Standard-Genre');
        $this->assertSame('Eurodance', $atc->fresh()->default_genre);
        $this->assertSame('Schlager', $abba->fresh()->default_genre, 'vorhandenes Standard-Genre bleibt');
        $this->assertNull(Artist::where('name', 'Unbekannt')->value('default_genre'));
    }
}
