<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Support\DataVersion;
use App\Support\NameConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** „Name passt“: von Hand oder automatisch in eindeutigen Fällen – deine Entscheidung gewinnt immer. */
class NameConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function combi(string $name, string $verdict, array $votes): void
    {
        Cache::put('combi-check:v1:'.md5($name), ['verdict' => $verdict, 'parts' => ['A', 'B'], 'reason' => 'Test', 'local' => ['own_albums' => 0, 'parts' => [], 'parts_solo' => false],
            'checked_at' => now()->toIso8601String(), 'evidence' => array_map(fn ($s, $v) => ['source' => $s, 'title' => 'Lied', 'credited' => [$name], 'vote' => $v], array_keys($votes), $votes)]);
    }

    public function test_combo_is_confirmed_when_two_services_agree_and_withdrawn_on_contradiction(): void
    {
        $sg = Artist::create(['name' => 'Simon & Garfunkel']);
        $by = Artist::create(['name' => 'Beam & Yanou']);
        $this->combi('Simon & Garfunkel', 'act', ['Deezer' => 'act', 'MusicBrainz' => 'act']);
        $this->combi('Beam & Yanou', 'act', ['MusicBrainz' => 'act']); // nur ein Dienst: nicht eindeutig

        app(NameConfirmation::class)->evaluateAll();
        $this->assertSame('auto', $sg->fresh()->name_confirmed);
        $this->assertStringContainsString('Deezer, MusicBrainz', $sg->fresh()->name_confirmed_reason);
        $this->assertNull($by->fresh()->name_confirmed);

        // später widerspricht ein Dienst: automatische Bestätigung wird zurückgenommen
        $this->combi('Simon & Garfunkel', 'unclear', ['Deezer' => 'act', 'Spotify' => 'collab']);
        app(NameConfirmation::class)->evaluateAll();
        $this->assertNull($sg->fresh()->name_confirmed);
    }

    public function test_single_name_with_musicbrainz_songs_is_confirmed(): void
    {
        $wb = Artist::create(['name' => 'WestBam', 'mbid' => 'mb-wb']);
        Cache::put('artist-prefetched:v2:'.md5('WestBam'), ['hits' => [['name' => 'WestBam', 'mbid' => 'mb-wb', 'exact' => true, 'songs' => ['Sunshine'], 'score' => 100]]]);
        app(NameConfirmation::class)->evaluateAll();
        $this->assertSame('auto', $wb->fresh()->name_confirmed);
        $this->assertStringContainsString('Sunshine', $wb->fresh()->name_confirmed_reason);
    }

    public function test_your_decision_wins_and_does_not_invalidate_caches(): void
    {
        $by = Artist::create(['name' => 'Beam & Yanou']);
        $this->combi('Beam & Yanou', 'collab', ['Deezer' => 'collab', 'Spotify' => 'collab']);
        $version = DataVersion::directory();

        $page = Livewire::test(Artists::class)->assertSee('1 wahrscheinlich Zusammenarbeit')->assertSee('Noch nicht geprüft (1)')
            ->call('select', $by->id)->assertSee('Name passt')->call('setConfirmed', true)
            ->assertSee('von dir bestätigt')->assertDontSee('wahrscheinlich Zusammenarbeit')->assertSee('Noch nicht geprüft (0)');
        $this->assertSame('manual', $by->fresh()->name_confirmed);
        $this->assertSame($version, DataVersion::directory(), 'Häkchen ist keine Verzeichnisänderung (Aufräumen rechnet nicht neu)');

        // automatische Bewertung lässt deine Entscheidung in Ruhe
        app(NameConfirmation::class)->evaluateAll();
        $this->assertSame('manual', $by->fresh()->name_confirmed);

        // zurücknehmen: zählt wieder als offen, die App bestätigt nicht von selbst
        $sg = Artist::create(['name' => 'Simon & Garfunkel']);
        $this->combi('Simon & Garfunkel', 'act', ['Deezer' => 'act', 'MusicBrainz' => 'act']);
        app(NameConfirmation::class)->evaluateAll();
        $page->call('select', $sg->id)->call('setConfirmed', false)->assertSee('von dir zurückgenommen');
        app(NameConfirmation::class)->evaluateAll();
        $this->assertSame('rejected', $sg->fresh()->name_confirmed);
    }

    public function test_api_can_confirm(): void
    {
        $a = Artist::create(['name' => 'Yanou']);
        $this->postJson('/api/artists/'.$a->id, ['confirmed' => true])->assertOk();
        $this->assertSame('manual', $a->fresh()->name_confirmed);
        $this->getJson('/api/artists/'.$a->id)->assertJsonPath('name_confirmed', 'manual');
    }
}
