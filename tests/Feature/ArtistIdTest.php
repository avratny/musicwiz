<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ArtistIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_picking_a_musicbrainz_hit_saves_the_id_immediately(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response(['artists' => [
            ['id' => 'mbid-cc', 'name' => 'C.C. Catch', 'score' => 100, 'country' => 'DE', 'disambiguation' => 'German-Dutch singer'],
            ['id' => 'mbid-ac', 'name' => 'A★Teens', 'score' => 95],
        ]])]);
        $cc = Artist::create(['name' => 'C.C. Catch']);
        $ateens = Artist::create(['name' => 'A-Teens']);

        $page = Livewire::test(Artists::class)->call('select', $cc->id)->call('lookupMusicBrainz')->assertSee('German-Dutch singer')->assertSee('Treffer bei MusicBrainz')
            ->call('useMusicBrainz', 0)->assertSee('MusicBrainz-ID gespeichert');
        $this->assertSame('mbid-cc', $cc->fresh()->mbid, 'ohne „Speichern“ in der Datenbank');
        $this->assertSame('C.C. Catch', $cc->fresh()->name);

        // abweichende Schreibweise: ID gespeichert, Name nur vorgeschlagen
        $page->call('select', $ateens->id)->call('lookupMusicBrainz')->call('useMusicBrainz', 0)
            ->assertSet('form.name', 'A★Teens')->assertSee('mit „Speichern“ bestätigen');
        $this->assertSame('mbid-ac', $ateens->fresh()->mbid);
        $this->assertSame('A-Teens', $ateens->fresh()->name, 'Umbenennung erst mit Speichern');

        // von Hand eingetippt
        $page->set('form.discogs_id', '12345');
        $this->assertSame('12345', $ateens->fresh()->discogs_id);
    }

    public function test_musicbrainz_falls_back_to_free_search(): void
    {
        Http::fake(function ($request) {
            $q = urldecode($request->url());

            // exakter Ausdruck findet nichts, freie Suche findet "C.C.Catch"
            return str_contains($q, 'artist:"')
                ? Http::response(['artists' => []])
                : Http::response(['artists' => [['id' => '10cc', 'name' => '10cc', 'score' => 60], ['id' => 'cc', 'name' => 'C.C.Catch', 'score' => 100]]]);
        });
        $hits = app(Lookup::class)->musicbrainzArtists('C.C. Catch');
        $this->assertSame('C.C.Catch', $hits[0]['name']);
        $this->assertSame('cc', $hits[0]['mbid']);
    }
}
