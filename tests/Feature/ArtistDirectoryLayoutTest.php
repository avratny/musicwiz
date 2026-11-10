<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Album;
use App\Models\Artist;
use App\Models\ArtistSplit;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Künstlerverzeichnis: Filter (kombinierbar), Sortierung, Kopf mit Reitern, Dialoge – keine Funktion geht verloren. */
class ArtistDirectoryLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $artist, string $location = 'library', string $title = 'Lied'): void
    {
        $album = Album::firstOrCreate(['location' => $location, 'dir' => '/m/'.$location.'/'.$artist], ['title' => 'Album', 'category' => 'LIB']);
        Track::create(['album_id' => $album->id, 'location' => $location, 'path' => '/m/'.$location.'/'.$artist.'/'.md5($title.$artist).'.mp3', 'dir' => $album->dir, 'filename' => 'x.mp3', 'ext' => 'mp3',
            'artist' => $artist, 'title' => $title, 'album' => 'Album', 'artists' => [], 'albumartists' => [], 'genres' => [], 'duration' => 200]);
    }

    public function test_filters_combine_and_sort(): void
    {
        $abba = Artist::create(['name' => 'ABBA', 'default_genre' => 'Pop', 'mbid' => 'mb-a', 'image' => 'abba.jpg']);
        $abba->aliases()->create(['alias' => 'Abba']);
        Artist::create(['name' => 'Yanou', 'default_genre' => 'Trance']);
        Artist::create(['name' => 'Beam & Yanou']);
        Artist::create(['name' => 'Neuling']);
        foreach (['ABBA', 'ABBA', 'Abba', 'Yanou'] as $i => $a) {
            $this->track($a, 'library', 'T'.$i);
        }
        $this->track('Neuling', 'inbox');
        ArtistSplit::remember('Foo & Bar', ['Foo', 'Bar']);

        $page = Livewire::test(Artists::class)->assertViewHas('allTotal', 4)
            ->assertViewHas('filterCounts', fn ($c) => $c['no_image'] === 3 && $c['no_genre'] === 2 && $c['no_mbid'] === 3 && $c['combo'] === 1 && $c['aliases'] === 1
                && $c['in_lib'] === 2 && $c['inbox_only'] === 1 && $c['splits'] === 1 && $c['unconfirmed'] === 4);

        // kombinierbar: ohne Bild + ohne Genre
        $page->call('toggleFilter', 'no_image')->call('toggleFilter', 'no_genre')
            ->assertViewHas('artists', fn ($l) => $l->pluck('name')->sort()->values()->all() === ['Beam & Yanou', 'Neuling']);
        $page->call('toggleFilter', 'no_genre')->call('toggleFilter', 'no_image')->call('toggleFilter', 'inbox_only')
            ->assertViewHas('artists', fn ($l) => $l->pluck('name')->all() === ['Neuling'])->assertSee('1 in Inbox');
        $page->call('clearFilters')->set('genreFilter', 'Trance')->assertViewHas('artists', fn ($l) => $l->pluck('name')->all() === ['Yanou']);
        // Sortierung nach Titeln (Name + Schreibweisen: ABBA 3)
        $page->set('genreFilter', '')->set('listSort', 'tracks')->assertViewHas('artists', fn ($l) => $l->first()->name === 'ABBA')->assertSee('3 Titel');
        // Suche auch über Schreibweisen
        $page->set('listSort', 'name')->set('q', 'abba')->assertViewHas('artistTotal', 1);
        // gemerkte Trennungen als eigene Liste
        $page->set('q', '')->call('toggleFilter', 'splits')->assertSee('Foo & Bar')->assertSee('Foo · Bar');
    }

    public function test_detail_on_one_page_with_global_save_and_image_dialog(): void
    {
        $a = Artist::create(['name' => 'Westbam', 'mbid' => 'mb-wb']);
        $a->aliases()->create(['alias' => 'West Bam']);
        $page = Livewire::test(Artists::class)->call('select', $a->id)
            ->assertSee('MusicBrainz ↗')->assertSee('Name passt')->assertSee('Speichern')
            // alles auf einer Seite
            ->assertSee('Standard-Genres')->assertSee('Zuordnung')->assertSee('Schreibweisen')->assertSee('West Bam')->assertSee('Hörproben')
            ->assertSee('Suchen (Discogs)');

        // Name im Kopf ändern + Speichern: alter Name wird Schreibweise
        $page->set('form.name', 'Maximilian Lenz')->assertSee('ungespeicherte Änderungen')->call('saveArtist');
        $this->assertSame('Maximilian Lenz', $a->fresh()->name);
        $this->assertTrue($a->fresh()->aliases()->where('alias', 'Westbam')->exists());
        $page->assertDontSee('ungespeicherte Änderungen');

        // Dialoge
        $page->set('imagesOpen', true)->assertSee('Klick auf ein Bild übernimmt es')->set('imagesOpen', false);
        $page->set('mergeOpen', true)->assertSee('Ist derselbe wie …')->set('mergeOpen', false);
        $page->set('newOpen', true)->assertSee('Neuer Künstler')->set('newName', 'Yanou')->call('create')->assertSet('newOpen', false);
        $this->assertTrue(Artist::where('name', 'Yanou')->exists());
    }
}
