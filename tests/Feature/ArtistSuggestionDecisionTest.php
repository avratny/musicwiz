<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Track;
use App\Support\ArtistDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Entscheidungen bei den Künstler-Vorschlägen müssen dauerhaft gelten – die Gruppe darf nicht wiederkommen. */
class ArtistSuggestionDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['Taucher', 'Child of Time'], ['Taucher', 'Atlanta'], ['Tauscher', 'Ganz anderes Lied']] as $i => [$artist, $title]) {
            Track::create(['location' => 'library', 'path' => "/m/$i.mp3", 'dir' => '/m', 'filename' => "$i.mp3", 'ext' => 'mp3',
                'artist' => $artist, 'title' => $title, 'album' => 'A'.$i, 'genres' => [], 'artists' => [], 'albumartists' => []]);
        }
    }

    private function groupKeys(): array
    {
        return array_column(app(ArtistDirectory::class)->candidateGroups(), 'key');
    }

    public function test_excluded_name_is_remembered_after_resolving(): void
    {
        $this->assertSame(['taucher'], $this->groupKeys());

        Livewire::test(Artists::class, ['tab' => 'suggestions'])
            ->call('toggleExclude', 'taucher', 'Tauscher', false)
            ->set('choice.taucher', 'Taucher')
            ->call('resolveGroup', 'taucher');

        $this->assertSame(['Taucher', 'Tauscher'], Artist::orderBy('name')->pluck('name')->all());
        $this->assertSame([], $this->groupKeys());
    }

    public function test_all_different_keeps_names_apart(): void
    {
        Livewire::test(Artists::class, ['tab' => 'suggestions'])->call('separateGroup', 'taucher');

        $this->assertSame(['Taucher', 'Tauscher'], Artist::orderBy('name')->pluck('name')->all());
        $this->assertSame(0, ArtistAlias::count());
        $this->assertSame([], $this->groupKeys());
    }

    public function test_group_with_one_undecided_name_is_still_shown(): void
    {
        Artist::create(['name' => 'Taucher']);

        $this->assertSame(['taucher'], $this->groupKeys());
    }
}
