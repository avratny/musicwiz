<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Models\Album;
use App\Models\Track;
use App\Support\AlbumPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Sampler: Album-Genre per Klick auf alle Titel übernehmen. */
class AlbumGenreToTracksTest extends TestCase
{
    use RefreshDatabase;

    public function test_album_genre_can_be_applied_to_all_tracks(): void
    {
        $album = Album::create(['location' => 'inbox', 'dir' => '/music/inbox/VA/50s', 'title' => '50s', 'is_compilation' => true]);
        foreach ([['Singing the Blues', ['Blues']], ['Peggy Sue', ['Rock']], ['Ohne', []]] as $i => [$title, $genres]) {
            Track::create(['album_id' => $album->id, 'location' => 'inbox', 'path' => "/music/inbox/VA/50s/0$i.mp3", 'dir' => '/music/inbox/VA/50s',
                'filename' => "0$i.mp3", 'ext' => 'mp3', 'artist' => 'A'.$i, 'title' => $title, 'album' => '50s', 'genres' => $genres, 'writable' => true]);
        }

        $editor = Livewire::test(AlbumEditor::class, ['album' => $album])
            ->set('fields.category', 'LIB COMPILATION')
            ->set('fields.genres', ["Rock 'n' Roll"])
            ->assertSee('Album-Genre auf alle Titel übernehmen (2 Titel haben ein anderes Genre)')
            ->call('applyGenreToAllTracks')
            ->assertDontSee('Album-Genre auf alle Titel übernehmen');

        $this->assertSame([["Rock 'n' Roll"], ["Rock 'n' Roll"], ["Rock 'n' Roll"]], array_column($editor->get('rows'), 'genres'));

        // Vorschau: jeder Titel bekommt das Album-Genre
        $plan = app(AlbumPlanner::class)->plan($album->fresh(), ['category' => 'LIB COMPILATION', 'genres' => ["Rock 'n' Roll"]],
            collect($editor->get('rows'))->mapWithKeys(fn ($r) => [$r['id'] => ['genres' => $r['genres']]])->all(), false);
        foreach ($plan['rows'] as $row) {
            $this->assertContains("Rock 'n' Roll", $row['changed']['genres'] ?? $row['old']['genres'] ?? []);
        }
    }
}
