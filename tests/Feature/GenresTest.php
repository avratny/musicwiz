<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\GenreMapping;
use App\Support\Genres;
use App\Support\GenreTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenresTest extends TestCase
{
    use RefreshDatabase;

    public function test_sub_style_writes_main_genre_too(): void
    {
        $g = app(Genres::class);
        $this->assertSame(['Electronic', 'Trance'], $g->expand(['Trance']));
        $this->assertSame(['Rock'], $g->expand(['Rock']));
        $this->assertSame(['Trance'], $g->selection(['Electronic', 'Trance']));
        $this->assertSame(['Rock'], $g->selection(['Rock', 'Euro House']));
    }

    public function test_mapping_suggestion(): void
    {
        GenreMapping::updateOrCreate(['source' => 'Euro-Dance'], ['target' => 'Eurodance']);
        $this->assertSame(['Eurodance'], app(Genres::class)->suggest(['Euro-Dance']));
    }

    public function test_extended_template_and_start_mappings(): void
    {
        foreach (['Rock \'n\' Roll / Rockabilly', 'Hard Rock', 'Latin', 'Blasmusik / Marsch', 'Klassik-Crossover', 'Euro Disco / Hi-NRG', 'Italo Disco'] as $g) {
            $this->assertTrue(Genre::where('name', $g)->exists(), $g);
        }
        $this->assertSame('Electronic', Genre::where('name', 'Italo Disco')->first()->parent->name);
        $this->assertSame(['Rock \'n\' Roll / Rockabilly'], app(Genres::class)->suggest(['Rock & Roll']));
        $this->assertSame(['Euro Disco / Hi-NRG'], app(Genres::class)->suggest(['Euro-Disco']));
        $this->assertSame(['Electronic', 'Italo Disco'], app(Genres::class)->expand(app(Genres::class)->suggest(['Italo-Disco'])));
        $this->assertSame(0, GenreTemplate::addMissing(), 'zweiter Lauf ändert nichts');
    }
}
