<?php

namespace Tests\Feature;

use App\Livewire\TrackTable;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Ratings;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class RatingsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function track(string $title): Track
    {
        return Track::where('title', $title)->firstOrFail();
    }

    public function test_reads_rating_from_files(): void
    {
        $this->mp3('library/A/01.mp3', ['TIT2' => 'Vier MP3', 'TALB' => 'A', 'POPM' => 196]);
        $this->mp3('library/A/02.mp3', ['TIT2' => 'Ohne', 'TALB' => 'A']);
        $this->flac('library/B/01.flac', ['TITLE' => 'Drei FLAC', 'RATING' => '60']);
        $this->flac('library/B/02.flac', ['TITLE' => 'Fünf FLAC', 'RATING' => '5']);
        app(Scanner::class)->scan('library');

        $this->assertSame([4, null, 3, 5], [$this->track('Vier MP3')->rating, $this->track('Ohne')->rating, $this->track('Drei FLAC')->rating, $this->track('Fünf FLAC')->rating]);
        $this->assertSame([1, 2, 3, 4, 5, null], array_map(Ratings::fromPopm(...), [1, 64, 128, 196, 255, 0]));
        $this->assertSame(0, TagChange::count());
    }

    public function test_rate_tracks_and_albums_in_the_database(): void
    {
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TALB' => 'Gold', 'POPM' => 128]);
        $this->mp3('library/ABBA/Gold/02.mp3', ['TIT2' => 'SOS', 'TALB' => 'Gold']);
        app(Scanner::class)->scan('library');
        $page = Livewire::test(TrackTable::class, ['slug' => 'lib'])->assertSee('Bewertung: 3 von 5');

        $page->call('rateTrack', $this->track('SOS')->id, 4)->assertSee('Bewertung: 3,5 von 5');
        $this->assertSame(4, $this->track('SOS')->rating);
        $change = TagChange::where('field', 'rating')->sole();
        $this->assertSame(['manual', null, '4'], [$change->source, $change->old_value, $change->new_value]);

        // Album: alle Lieder; 0 = entfernen
        $page->call('rateAlbum', $this->track('SOS')->album_hash, 5);
        $this->assertSame([5], Track::pluck('rating')->unique()->values()->all());
        $page->call('rateTrack', $this->track('Waterloo')->id, 0);
        $this->assertNull($this->track('Waterloo')->rating);
        $page->call('rateTrack', $this->track('Waterloo')->id, 6)->assertStatus(422);

        // Flyout
        Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('select', [$this->track('Waterloo')->id])
            ->assertSet('form.rating', null)->set('form.rating', 2)->call('saveFlyout');
        $this->assertSame(2, $this->track('Waterloo')->rating);

        // zurück auf den Stand der Datei: Änderungen heben sich auf
        Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('rateTrack', $this->track('Waterloo')->id, 3);
        $this->assertFalse(TagChange::where('track_id', $this->track('Waterloo')->id)->exists());
    }

    public function test_blackhole_shows_but_does_not_rate(): void
    {
        $this->mp3('blackhole/X/01.mp3', ['TIT2' => 'Schwarz', 'POPM' => 255]);
        app(Scanner::class)->scan('blackhole');

        Livewire::test(TrackTable::class, ['slug' => 'blackhole'])->assertSee('Bewertung: 5 von 5')
            ->call('rateTrack', $this->track('Schwarz')->id, 1)->assertStatus(422);
        $this->assertSame(5, $this->track('Schwarz')->rating);
    }

    public function test_new_default_column_shows_up_in_existing_session(): void
    {
        session(['lw'.crc32('track-table'.'visible') => ['title', 'artist']]); // Spaltenauswahl einer älteren Sitzung
        $this->mp3('library/A/01.mp3', ['TIT2' => 'Eins']);
        app(Scanner::class)->scan('library');

        $page = Livewire::test(TrackTable::class, ['slug' => 'lib']);
        $this->assertContains('rating', $page->get('visible'));
        $this->assertNotContains('album', $page->get('visible'), 'ausgeblendete Spalten bleiben aus');
    }
}
