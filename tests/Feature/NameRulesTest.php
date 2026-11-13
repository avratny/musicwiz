<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Models\Artist;
use App\Models\NameRule;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\ArtistRegistry;
use App\Support\NameRules;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class NameRulesTest extends TestCase
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

    public function test_rules_fix_names_as_whole_value_or_as_word(): void
    {
        $rules = app(NameRules::class);
        $cases = ['Verschiedene' => 'Various Artists', 'V. A.' => 'Various Artists', 'v/a' => 'Various Artists', 'Various Artists (Compilation)' => 'Various Artists',
            'Verschiedene Künstler' => 'Various Artists', 'Verschiedene Leute' => 'Verschiedene Leute',
            'Dj Bobo' => 'DJ Bobo', 'Dr Alban' => 'Dr. Alban', 'd.r. Alban' => 'Dr. Alban', 'dr. dre & Snoop' => 'Dr. dre & Snoop', 'Dr. Alban' => 'Dr. Alban',
            'Drake' => 'Drake', 'Dr.Alban' => 'Dr.Alban', 'Jazzy Jeff & dj Mike' => 'Jazzy Jeff & DJ Mike'];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, $rules->fix($in), $in);
        }

        // auch im Wort
        $this->assertSame('Mc Hammer & Djembe', NameRules::replace('Mc Hammer & Djembe', 'dj', 'DJ', true));
        $this->assertSame('MC Hammer & DJembe', NameRules::replace(NameRules::replace('Mc Hammer & Djembe', 'dj', 'DJ', false), 'mc', 'MC', false));
    }

    public function test_scan_applies_rules_as_system_changes_and_merges_artists(): void
    {
        $this->mp3('library/S/A/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Dj Bobo', 'TPE2' => 'Verschiedene']);
        $this->mp3('library/S/A/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Dr Alban', 'TPE2' => 'V.A.']);
        app(Scanner::class)->scan('library');

        $this->assertSame([['DJ Bobo', 'Various Artists'], ['Dr. Alban', 'Various Artists']],
            Track::orderBy('title')->get()->map(fn ($t) => [$t->artist, $t->albumartist])->all());
        $this->assertSame(4, TagChange::where('source', 'system')->where('reason', 'like', 'Schreibweise:%')->count());
        $this->assertSame(['DJ Bobo', 'Dr. Alban'], Artist::orderBy('name')->pluck('name')->all(), 'alte Namen aufgegangen, Various Artists ist kein Interpret');
        $this->assertSame(2, DB::table('track_artist')->count(), 'nur die Interpreten verknüpft');

        // neue Datei mit alter Schreibweise: beim Einlesen gleich angepasst, nicht noch einmal geändert
        $this->mp3('library/S/A/03.mp3', ['TIT2' => 'Drei', 'TPE1' => 'Dj Bobo']);
        app(Scanner::class)->scan('library');
        $this->assertSame('DJ Bobo', Track::where('title', 'Drei')->value('artist'));
        $this->assertSame(5, TagChange::where('reason', 'like', 'Schreibweise:%')->count());
    }

    public function test_various_artists_is_never_an_artist(): void
    {
        $this->mp3('library/S/A/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Various Artists', 'TPE2' => 'Various Artists']);
        $this->mp3('library/S/A/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Gast', 'TPE2' => 'Diverse Interpreten']);
        app(Scanner::class)->scan('library');
        $this->assertSame(['Gast'], Artist::pluck('name')->all());

        // alte Einträge (z. B. aus früheren Läufen) werden beim nächsten Durchlauf entfernt
        $old = Artist::create(['name' => 'V.A.', 'confirmed' => true]);
        app(NameRules::class)->apply();
        $this->assertNull(Artist::find($old->id));
        $this->get('/interpreten')->assertOk()->assertDontSee('Various Artists');
    }

    public function test_existing_artist_whose_alias_is_the_new_name_is_renamed(): void
    {
        $this->mp3('library/S/A/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Mc Hammer']);
        app(Scanner::class)->scan('library');
        $artist = Artist::where('name', 'Mc Hammer')->first();
        app(ArtistRegistry::class)->addAlias($artist, 'MC Hammer');

        Livewire::test(SettingsPage::class)->set('tab', 'schreibweisen')->set('section', 'names')
            ->set('newRule', 'mc')->set('newReplacement', 'MC')->call('addRule')->assertSee('„mc“ → „MC“ hinzugefügt – 1 Lied angepasst');

        $this->assertSame('MC Hammer', Track::first()->artist);
        $this->assertSame(['MC Hammer'], Artist::pluck('name')->all());
        $this->assertSame(['Mc Hammer'], $artist->fresh()->aliases()->pluck('alias')->all());
    }

    public function test_settings_tabs_and_rule_validation(): void
    {
        $this->get('/einstellungen')->assertOk()->assertSee('Zugangsdaten')->assertSee('Schreibweisen');
        $this->get('/einstellungen?tab=schreibweisen')->assertOk()->assertSee('Various Artists')->assertSee('Verschiedene Interpreten')->assertDontSee('Discogs-Token');
        $this->get('/einstellungen?tab=schreibweisen&section=names')->assertOk()->assertSee('D.R.')->assertSee('eigenes Wort');

        $page = Livewire::test(SettingsPage::class)->set('tab', 'schreibweisen');
        $page->set('newRule', 'va')->call('addRule')->assertSee('schon abgedeckt')->assertSee('durch „V.A.“');
        $page->set('newRule', 'Various Artists')->call('addRule')->assertSee('das Ziel selbst');
        $page->set('newRule', 'Divers')->call('addRule')->assertSee('„Divers“ hinzugefügt – 0 Lieder angepasst');
        $page->set('section', 'names')->set('newRule', 'Mister')->call('addRule')->assertSee('Suchtext und Ersetzung eingeben');
        $page->set('newRule', 'dj')->set('newReplacement', 'D.J.')->call('addRule')->assertSee('wird schon ersetzt (durch „DJ“)');

        // auch im Wort – und umschalten
        $this->mp3('library/S/A/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Feat. Ftr']);
        app(Scanner::class)->scan('library');
        $page->set('newRule', 'ftr')->set('newReplacement', 'FTR')->set('newWholeWord', false)->call('addRule')->assertSee('1 Lied angepasst');
        $this->assertSame('Feat. FTR', Track::first()->artist);
        $rule = NameRule::where('value', 'ftr')->first();
        $this->assertFalse($rule->whole_word);
        $page->call('toggleWholeWord', $rule->id)->assertSee('gilt jetzt nur als eigenes Wort');
        $this->assertTrue($rule->fresh()->whole_word);

        // gruppiert nach Schreibweise, weitere Schreibweise in der Gruppe
        $page->call('addTo', 'Dr.', 'Doktor')->assertSee('„Doktor“ → „Dr.“ hinzugefügt');
        $this->assertSame(['D.R.', 'Doktor', 'Dr'], NameRule::where('replacement', 'Dr.')->orderBy('value')->pluck('value')->all());
        $this->get('/einstellungen?tab=schreibweisen&section=names')->assertSeeInOrder(['DJ', 'wird geschrieben, wenn gefunden:', 'Dr.', 'wird geschrieben, wenn gefunden:', 'D.R.', 'Doktor', 'Dr']);

        $page->call('removeRule', NameRule::where('value', 'Divers')->value('id'))->assertSee('„Divers“ entfernt');
        $this->assertFalse(NameRule::where('value', 'Divers')->exists());
    }
}
