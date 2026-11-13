<?php

namespace Tests\Feature;

use App\Livewire\ArtistSimilar;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Support\ArtistMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesMusicBrainzDumps;
use Tests\TestCase;

/** Interpreten › Ähnlich mit MusicBrainz: derselbe Eintrag (auch bei ganz anderem Namen), verschiedene Einträge, Schreibweise */
class SimilarMusicBrainzTest extends TestCase
{
    use MakesMusicBrainzDumps, RefreshDatabase;

    private const DIDDY = 'bbbbbbbb-0000-0000-0000-000000000001';

    protected function tearDown(): void
    {
        $this->tearDownMusicBrainzDumps();
        parent::tearDown();
    }

    private function artists(array $names): void
    {
        foreach ($names as $name) {
            Artist::create(['name' => $name]);
        }
    }

    private function groupNames(): array
    {
        $names = Artist::pluck('name', 'id');

        return array_map(fn ($g) => ['names' => array_map(fn ($id) => $names[$id], $g['ids']), 'level' => $g['level']], app(ArtistMatcher::class)->groups());
    }

    private function load(): void
    {
        $this->loadMusicBrainz([
            ['id' => self::DIDDY, 'name' => 'Diddy', 'type' => 'Person', 'country' => 'US', 'aliases' => [['name' => 'Puff Daddy', 'type' => 'Artist name'], ['name' => 'P. Diddy', 'type' => 'Artist name']]],
            ['id' => 'bbbbbbbb-0000-0000-0000-000000000002', 'name' => 'Sash!', 'type' => 'Group', 'country' => 'DE'],
            ['id' => 'bbbbbbbb-0000-0000-0000-000000000003', 'name' => 'Sasha', 'type' => 'Person', 'country' => 'DE', 'disambiguation' => 'German pop singer'],
            ['id' => 'bbbbbbbb-0000-0000-0000-000000000004', 'name' => "Shakin' Stevens", 'type' => 'Person', 'country' => 'GB'],
            ['id' => 'bbbbbbbb-0000-0000-0000-000000000005', 'name' => 'Beam', 'type' => 'Person'],
            ['id' => 'bbbbbbbb-0000-0000-0000-000000000006', 'name' => 'BEAM', 'type' => 'Person', 'disambiguation' => 'US rapper'],
        ]);
    }

    public function test_same_entry_groups_different_names_and_different_entries_are_kept_apart(): void
    {
        $this->artists(['Puff Daddy', 'P. Diddy', 'Sash!', 'Sasha', 'Shakin Stevens', "Shakin' Stevens", 'Taucher', 'Tauscher', 'Abba', 'ABBA']);
        $this->assertNotContains(['Puff Daddy', 'P. Diddy'], array_column($this->groupNames(), 'names'), 'ohne MusicBrainz: Namen zu verschieden');

        $this->load();

        $groups = collect($this->groupNames())->keyBy(fn ($g) => implode(' / ', $g['names']));
        $this->assertSame(ArtistMatcher::MUSICBRAINZ, $groups['P. Diddy / Puff Daddy']['level'] ?? $groups['Puff Daddy / P. Diddy']['level'], 'derselbe Eintrag');

        $page = Livewire::test(ArtistSimilar::class)
            ->assertSee('gleicher MusicBrainz-Eintrag')
            ->assertSee('Fazit: Zusammenführen')->assertSee('als „Diddy“')
            ->assertSee('MusicBrainz: „Diddy“ (Person, US)')
            ->assertSee('Fazit: Verschieden')->assertSee('„Sasha“ = „Sasha“ (Person, DE, German pop singer)')
            ->assertSee('MusicBrainz kennt die Namen nicht')            // Taucher / Tauscher
            ->assertSee('nur Groß/klein anders');                       // Abba / ABBA: ohne MusicBrainz trotzdem zusammenführen

        // Schreibweise laut MusicBrainz: das Mitglied, das schon so heißt, ist das Ziel
        $shakin = collect($page->viewData('groups'))->first(fn ($g) => in_array("Shakin' Stevens", array_map(fn ($m) => $m['artist']->name, $g['members']), true));
        $this->assertSame(['merge', 'sicher', Artist::where('name', "Shakin' Stevens")->value('id'), null],
            [$shakin['verdict']['action'], $shakin['verdict']['confidence'], $shakin['verdict']['target'], $shakin['verdict']['name']]);

        // übernehmen: unter dem Namen laut MusicBrainz zusammengeführt
        $diddy = collect($page->viewData('groups'))->first(fn ($g) => $g['level'] === ArtistMatcher::MUSICBRAINZ)['verdict'];
        $others = Artist::whereIn('name', ['Puff Daddy', 'P. Diddy'])->where('id', '!=', $diddy['target'])->pluck('id')->all();
        $page->call('mergeSimilar', $diddy['target'], $others, [], $diddy['name']);
        $this->assertSame(['Diddy'], Artist::whereIn('name', ['Diddy', 'Puff Daddy', 'P. Diddy'])->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['P. Diddy', 'Puff Daddy'], ArtistAlias::pluck('alias')->all());
    }

    public function test_ambiguous_names_get_no_entry_and_flyout_suggestions_know_the_same_entry(): void
    {
        $this->artists(['Beam', 'Puff Daddy', 'P. Diddy']);
        $this->load();
        $matcher = app(ArtistMatcher::class);

        $this->assertSame(['mbid' => null, 'candidates' => 2], $matcher->identity(Artist::where('name', 'Beam')->value('id')), 'Beam und BEAM: mehrdeutig');
        $this->assertSame(self::DIDDY, $matcher->identity(Artist::where('name', 'Puff Daddy')->value('id'))['mbid']);

        $similar = $matcher->similar(Artist::where('name', 'Puff Daddy')->sole());
        $this->assertSame([['P. Diddy', 'gleicher MusicBrainz-Eintrag']], array_map(fn ($s) => [$s['artist']->name, $s['reason']], $similar));
    }
}
