<?php

namespace Tests\Feature;

use App\Livewire\ArtistDistinct;
use App\Livewire\ArtistList;
use App\Livewire\ArtistSimilar;
use App\Models\Artist;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\ArtistMatcher;
use App\Support\ArtistRegistry;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class SimilarArtistsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        File::ensureDirectoryExists($this->base.'/_artists');
        config(['musicwiz.artist_images' => $this->base.'/_artists']);
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    /** @return list<list<string>> Gruppen als Namen, Vorschlag zuerst */
    private function groupNames(): array
    {
        $names = Artist::pluck('name', 'id');

        return array_map(fn ($g) => array_map(fn ($id) => $names[$id], $g['ids']), app(ArtistMatcher::class)->groups());
    }

    private function artists(array $names): void
    {
        foreach ($names as $name => $confirmed) {
            Artist::create(['name' => $name, 'confirmed' => $confirmed]);
        }
    }

    public function test_groups_are_built_once_per_spelling_family(): void
    {
        $this->artists(['Abba' => false, 'ABBA' => true, 'A.B.B.A.' => false, 'Simon & Garfunkel' => false, 'Simon and Garfunkel' => false,
            'Moby' => false, 'Toby' => false, 'Tobi' => false, 'Cher' => false]);

        $groups = $this->groupNames();
        $this->assertContains(['ABBA', 'A.B.B.A.', 'Abba'], $groups, 'gleich bis auf Zeichen: eine Gruppe, bestätigt zuerst');
        $this->assertContains(['Simon & Garfunkel', 'Simon and Garfunkel'], $groups, '„&“ = „and“');
        $this->assertCount(3, $groups, 'Cher steht allein');
        $typo = array_values(array_filter($groups, fn ($g) => in_array('Toby', $g, true)))[0];
        $this->assertCount(2, $typo, 'Moby – Toby – Tobi: keine Kette, nur direkt Ähnliche');
        $this->assertSame([ArtistMatcher::CHARS, ArtistMatcher::CHARS, ArtistMatcher::TYPO], array_column(app(ArtistMatcher::class)->groups(), 'level'), 'sicherste zuerst');

        // verschieden markiert: kommt nicht wieder, auch nicht im Flyout-Vorschlag
        $abba = Artist::where('name', 'ABBA')->first();
        app(ArtistRegistry::class)->distinct([$abba->id, Artist::where('name', 'Abba')->value('id')]);
        $this->assertContains(['ABBA', 'A.B.B.A.'], $this->groupNames());
        $this->assertNotContains('Abba', array_map(fn ($s) => $s['artist']->name, app(ArtistMatcher::class)->similar($abba)));
    }

    public function test_merging_a_group_confirms_the_choice_logs_system_changes_and_keeps_unchecked_apart(): void
    {
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TPE2' => 'ABBA']);
        $this->mp3('library/ABBA/Gold/02.mp3', ['TIT2' => 'SOS', 'TPE1' => 'Abba', 'TALB' => 'Gold', 'TPE2' => 'ABBA']);
        $this->mp3('library/ABBA/Live/01.mp3', ['TIT2' => 'Fernando', 'TPE1' => 'A.B.B.A.', 'TALB' => 'Live']);
        app(Scanner::class)->scan('library');
        [$abba, $lower, $dots] = [Artist::where('name', 'ABBA')->first(), Artist::where('name', 'Abba')->first(), Artist::where('name', 'A.B.B.A.')->first()];

        $page = Livewire::test(ArtistSimilar::class)->assertSee('gleich bis auf Zeichen')->assertSee('Gold');
        // „A.B.B.A.“ abgewählt: bleibt eigener Interpret und taucht mit ABBA nicht mehr auf
        $page->call('mergeSimilar', $abba->id, [$lower->id], [$dots->id], '')
            ->assertSee('„Abba“ → „ABBA“ bestätigt – 1 Lied angepasst.')->assertSee('Keine ähnlichen Schreibweisen');

        $this->assertTrue($abba->fresh()->confirmed);
        $this->assertSame(['ABBA', 'Abba'], $abba->fresh()->spellings());
        $this->assertSame('ABBA', Track::where('title', 'SOS')->value('artist'));
        $this->assertSame([TagEditor::SYSTEM, '„Abba“ ist „ABBA“ (zusammengeführt)'], [TagChange::sole()->source, TagChange::sole()->reason]);
        $this->assertSame('A.B.B.A.', Track::where('title', 'Fernando')->value('artist'));
        $this->assertSame([], $this->groupNames());
    }

    public function test_own_spelling_renames_first_so_every_track_changes_once(): void
    {
        $this->mp3('library/a/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'DJ Bobo', 'TALB' => 'X']);
        $this->mp3('library/a/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'DJ bobo', 'TALB' => 'X']);
        $this->mp3('library/a/03.mp3', ['TIT2' => 'Drei', 'TPE1' => 'Cher', 'TALB' => 'X']);
        app(Scanner::class)->scan('library');
        $ids = Artist::whereIn('name', ['DJ Bobo', 'DJ bobo'])->pluck('id', 'name');

        $page = Livewire::test(ArtistSimilar::class);
        $page->call('mergeSimilar', $ids['DJ bobo'], [$ids['DJ Bobo']], [], 'Cher')->assertSee('gehört schon zu „Cher“');
        $this->assertSame(2, Artist::whereIn('id', $ids)->count(), 'nichts halb erledigt');

        $page->call('mergeSimilar', $ids['DJ bobo'], [$ids['DJ Bobo']], [], 'DJ BoBo')->assertSee('„DJ BoBo“ bestätigt – 2 Lieder angepasst.');
        $artist = Artist::where('name', 'DJ BoBo')->sole();
        $this->assertTrue($artist->confirmed);
        $this->assertSame(['DJ BoBo'], Track::whereIn('title', ['Eins', 'Zwei'])->distinct()->pluck('artist')->all());
        $this->assertSame(2, TagChange::where('field', 'artist')->where('new_value', 'DJ BoBo')->count(), 'je Lied eine Änderung');
        $this->assertSame(['DJ BoBo', 'DJ Bobo', 'DJ bobo'], $artist->spellings());

        // eigene Schreibweise = Name eines Mitglieds: das wird das Ziel
        $this->artists(['Atc' => false, 'ATC' => false]);
        $page->call('mergeSimilar', Artist::where('name', 'Atc')->value('id'), [Artist::where('name', 'ATC')->value('id')], [], 'ATC');
        $this->assertSame(['ATC', 'Atc'], Artist::where('name', 'ATC')->sole()->spellings());
    }

    public function test_a_combo_as_own_spelling_is_not_doubled(): void
    {
        $this->mp3('library/a/01.mp3', ['TIT2' => 'The Last Unicorn', 'TPE1' => 'In-Mood', 'TALB' => 'X']);
        $this->mp3('library/a/02.mp3', ['TIT2' => 'The Last Unicorn (Remix)', 'TPE1' => 'In Mood', 'TALB' => 'X']);
        app(Scanner::class)->scan('library');
        $ids = Artist::whereIn('name', ['In-Mood', 'In Mood'])->pluck('id', 'name');

        Livewire::test(ArtistSimilar::class)->call('mergeSimilar', $ids['In-Mood'], [$ids['In Mood']], [], 'In-Mood feat. Juliette');

        $this->assertSame(['In-Mood feat. Juliette'], Track::distinct()->pluck('artist')->all());
        $this->assertSame(['In-Mood; Juliette'], Track::distinct()->pluck('artists')->all());
        $this->assertFalse(Artist::where('name', 'like', '%Juliette feat.%')->exists());

        // weitere Schreibweise: nichts verdoppelt
        app(ArtistRegistry::class)->addAlias(Artist::where('name', 'In-Mood feat. Juliette')->sole(), 'In-mood');
        $this->assertSame(['In-Mood feat. Juliette'], Track::distinct()->pluck('artist')->all());
    }

    public function test_different_and_later_and_merge_keeps_distinct_marks(): void
    {
        $this->artists(['Sash!' => false, 'Sasha' => false, 'Taucher' => false, 'Tauscher' => false, 'SASH!' => false]);
        $ids = Artist::pluck('id', 'name');

        $page = Livewire::test(ArtistSimilar::class);
        $this->assertSame([['Sash!', 'SASH!', 'Sasha'], ['Taucher', 'Tauscher']], $this->groupNames());
        $page->call('notSame', [$ids['Sash!'], $ids['SASH!'], $ids['Sasha']])->assertSee('bleiben verschieden');
        $this->assertSame([['Taucher', 'Tauscher']], $this->groupNames());

        // zusammengeführt: wer vom einen verschieden war, ist es auch vom anderen
        app(ArtistRegistry::class)->merge(Artist::find($ids['Sash!']), Artist::find($ids['SASH!']));
        $this->assertSame([['Taucher', 'Tauscher']], $this->groupNames());

        $page->call('postpone', $ids['Taucher'].'-'.$ids['Tauscher'])->assertSet('later', [$ids['Taucher'].'-'.$ids['Tauscher']]);

        $this->get('/interpreten?ansicht=aehnlich')->assertOk()->assertSee('Ähnliche Schreibweisen')->assertSee('Taucher');
        $this->get('/interpreten')->assertOk()->assertSee('Ähnlich');
    }

    public function test_distinct_pairs_are_listed_and_can_be_undone_in_the_list_and_the_flyout(): void
    {
        $this->artists(['Sash!' => false, 'Sasha' => false, 'Taucher' => false, 'Tauscher' => false]);
        $ids = Artist::pluck('id', 'name');
        $registry = app(ArtistRegistry::class);
        $registry->distinct([$ids['Sash!'], $ids['Sasha']]);
        $registry->distinct([$ids['Taucher'], $ids['Tauscher']]);
        $this->assertSame([], $this->groupNames());

        $page = Livewire::test(ArtistDistinct::class)->assertSee('2 Paare')->assertSee('Sasha')->assertSee('Tauscher');
        $page->set('query', 'sash')->assertSee('Sasha')->assertDontSee('Tauscher');
        $page->call('undistinct', $ids['Sasha'], $ids['Sash!'])->assertSee('wieder als ähnlich vorgeschlagen')->assertSee('Nichts gefunden');
        $this->assertSame([['Sash!', 'Sasha']], $this->groupNames());

        // im Flyout: „Nicht derselbe wie“ mit Aufheben
        $flyout = Livewire::test(ArtistList::class)->call('open', $ids['Taucher'])->assertSee('Nicht derselbe wie')->assertSee('Tauscher');
        $flyout->call('undistinct', $ids['Taucher'], $ids['Tauscher'])->assertDontSee('Nicht derselbe wie');
        $this->assertCount(2, $this->groupNames());
        $this->get('/interpreten?ansicht=verschieden')->assertOk()->assertSee('Noch nichts als verschieden markiert');
    }

    public function test_comparison_key_ignores_case_accents_articles_and_and(): void
    {
        $same = [['The Bellamy Brothers', 'Bellamy Brothers, The'], ['Simon & Garfunkel', 'Simon and Garfunkel'], ['Beyoncé', 'BEYONCE'], ["Shakin' Stevens", 'Shakin Stevens']];
        foreach ($same as [$a, $b]) {
            $this->assertSame(ArtistMatcher::key($a), ArtistMatcher::key($b), "$a = $b");
        }
        $this->assertNotSame(ArtistMatcher::key('ABBA'), ArtistMatcher::key('Abbacadabra'));
    }
}
