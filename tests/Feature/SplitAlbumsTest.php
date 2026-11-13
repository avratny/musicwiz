<?php

namespace Tests\Feature;

use App\Livewire\AlbumList;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\NameRules;
use App\Support\Scanner;
use App\Support\SplitAlbums;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

/** Alben › Auseinandergerissen: Alben, die Navidrome wegen uneinheitlicher Tags auseinanderreißen würde – anzeigen und angleichen */
class SplitAlbumsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    /** gefundene Alben: Album-Name => [Feld => [Wert => Anzahl]] */
    private function found(): array
    {
        $out = [];
        foreach (app(SplitAlbums::class)->albums()['albums'] as $a) {
            $out[$a['album']] = array_map(fn ($i) => array_column($i['options'], 'count', 'value'), $a['issues']);
        }

        return $out;
    }

    public function test_einheitliche_alben_und_einzellieder_zaehlen_nicht(): void
    {
        $this->mp3('library/a-ha/Hits/CD1/01.mp3', ['TIT2' => 'Take On Me', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Hits', 'TDRC' => '1985']);
        $this->mp3('library/a-ha/Hits/CD2/01.mp3', ['TIT2' => 'The Sun Always Shines on T.V.', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Hits', 'TDRC' => '1985']);
        // Einzellieder ohne Album: gewollt verschieden
        $this->mp3('library/Einzeln/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Cher']);
        $this->mp3('library/Einzeln/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Madonna', 'TDRC' => '1990']);
        app(Scanner::class)->scan('library');

        $this->assertSame([], $this->found());
        $this->assertSame(0, app(SplitAlbums::class)->count());
    }

    public function test_verschiedener_album_interpret_wird_angeglichen(): void
    {
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Hits']);
        $this->mp3('inbox/Hits/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Hits']);
        $this->mp3('inbox/Hits/03.mp3', ['TIT2' => 'Drei', 'TPE1' => 'a-ha', 'TPE2' => 'A-HA', 'TALB' => 'Hits']);
        app(Scanner::class)->scan('inbox');

        $this->assertSame(['Hits' => ['albumartist' => ['a-ha' => 2, 'A-HA' => 1, NameRules::VARIOUS => 0]]], $this->found());
        $album = app(SplitAlbums::class)->albums()['albums'][0];
        $this->assertSame('a-ha', $album['issues']['albumartist']['suggest']);
        $this->assertSame('Album-Interpret unterschiedlich geschrieben (nur Groß-/Kleinschreibung).', $album['issues']['albumartist']['reason']);
        $this->assertSame('häufigster Wert (2 von 3)', $album['issues']['albumartist']['why']);
        Livewire::test(AlbumList::class, ['check' => 'getrennt'])->assertSee('unterschiedlich geschrieben')->assertSee('„a-ha“ übernehmen');

        Livewire::test(AlbumList::class)->call('align', $album['hash'], 'albumartist', 'a-ha')
            ->assertSet('message', 'Album-Interpret bei 1 Lied auf „a-ha“ gesetzt.');

        $this->assertSame(['a-ha'], Track::pluck('albumartist')->unique()->values()->all());
        $change = TagChange::sole();
        $this->assertSame(['albumartist', 'A-HA', 'a-ha', 'manual', SplitAlbums::REASON], [$change->field, $change->old_value, $change->new_value, $change->source, $change->reason]);
        $this->assertSame([], $this->found());
    }

    public function test_fehlender_album_interpret_bei_verschiedenen_interpreten(): void
    {
        $this->mp3('inbox/Bravo Hits/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Cher', 'TALB' => 'Bravo Hits']);
        $this->mp3('inbox/Bravo Hits/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Madonna', 'TALB' => 'Bravo Hits']);
        // Sampler-Flag ohne Album-Interpret: Navidrome nimmt „Various Artists“ – einheitlich
        $this->mp3('inbox/Sampler/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Cher', 'TALB' => 'Sampler', 'TCMP' => '1']);
        $this->mp3('inbox/Sampler/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Madonna', 'TALB' => 'Sampler', 'TCMP' => '1']);
        app(Scanner::class)->scan('inbox');

        $album = app(SplitAlbums::class)->albums()['albums'];
        $this->assertCount(1, $album);
        $options = $album[0]['issues']['albumartist']['options'];
        $this->assertSame([true, true, false], array_column($options, 'derived'));
        $this->assertSame(NameRules::VARIOUS, end($options)['value']);
        $this->assertStringStartsWith('Kein Album-Interpret gesetzt', $album[0]['issues']['albumartist']['reason']);
        $this->assertSame('Cher', $album[0]['issues']['albumartist']['suggest']);
        $this->assertSame('Hauptinterpret: gleich häufig – genommen wie beim ersten Lied (CD 1)', $album[0]['issues']['albumartist']['why']);

        app(SplitAlbums::class)->align($album[0]['hash'], 'albumartist', NameRules::VARIOUS);

        $tracks = Track::where('album', 'Bravo Hits')->get();
        $this->assertSame([NameRules::VARIOUS], $tracks->pluck('albumartist')->unique()->values()->all());
        $this->assertTrue($tracks->every(fn ($t) => $t->compilation));
        $this->assertSame([], $this->found());
    }

    public function test_jahr_nur_ausserhalb_von_samplern(): void
    {
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'a-ha', 'TALB' => 'Hits', 'TDRC' => '1985']);
        $this->mp3('inbox/Hits/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'a-ha', 'TALB' => 'Hits', 'TDRC' => '1986']);
        $this->mp3('inbox/Sampler/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Cher', 'TPE2' => 'Various Artists', 'TALB' => 'Sampler', 'TCMP' => '1', 'TDRC' => '1980']);
        $this->mp3('inbox/Sampler/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'Madonna', 'TPE2' => 'Various Artists', 'TALB' => 'Sampler', 'TCMP' => '1', 'TDRC' => '1990']);
        app(Scanner::class)->scan('inbox');

        $this->assertSame(['Hits' => ['year' => ['1985' => 1, '1986' => 1]]], $this->found());
    }

    public function test_musicbrainz_id_und_veroeffentlichung_aus_den_tags(): void
    {
        $id = '0b4a1c2e-1111-2222-3333-444455556666';
        $this->mp3('library/a-ha/Hits/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'a-ha', 'TALB' => 'Hits', 'TXXX:MusicBrainz Album Id' => $id, 'TDRL' => '1985-06-01']);
        $this->mp3('library/a-ha/Hits/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'a-ha', 'TALB' => 'Hits']);
        $this->flac('library/a-ha/Hunting/01.flac', ['TITLE' => 'Eins', 'ARTIST' => 'a-ha', 'ALBUM' => 'Hunting', 'MUSICBRAINZ_ALBUMID' => $id, 'RELEASEDATE' => '1985']);
        app(Scanner::class)->scan('library');

        $this->assertSame([$id, '1985'], [Track::where('ext', 'flac')->value('mb_albumid'), Track::where('ext', 'flac')->value('releasedate')]);
        $this->assertSame(['Hits' => ['mb_albumid' => [$id => 1, '' => 1], 'releasedate' => ['1985-06-01' => 1, '' => 1]]], $this->found());
        $hash = Track::where('album', 'Hits')->value('album_hash');
        $issue = app(SplitAlbums::class)->albums()['albums'][0]['issues']['mb_albumid'];
        $this->assertSame([$id, 'MusicBrainz-Album-ID fehlt bei 1 Lied von 2 – Navidrome macht daraus ein eigenes Album.'], [$issue['suggest'], $issue['reason']]);

        app(SplitAlbums::class)->align($hash, 'mb_albumid', $id);
        app(SplitAlbums::class)->align($hash, 'releasedate', '');

        $this->assertSame([], $this->found());
        $this->assertSame([null], Track::where('album', 'Hits')->pluck('releasedate')->unique()->values()->all());
        // erneut eingelesen: die Angleichung bleibt (die Datei hat noch den alten Stand)
        touch($this->base.'/library/a-ha/Hits/02.mp3', time() + 10);
        app(Scanner::class)->scan('library');
        $this->assertSame([], $this->found());
    }

    public function test_eigene_seite_im_menue(): void
    {
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Hits']);
        $this->mp3('inbox/Hits/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Greatest Hits']);
        app(Scanner::class)->scan('inbox');

        $this->get('/alben?ansicht=getrennt')->assertOk()->assertSee('Auseinandergerissen')->assertSee('Greatest Hits');
        $this->get('/interpreten')->assertOk()->assertDontSee('Auseinandergerissen');
        Livewire::test(AlbumList::class, ['check' => 'getrennt'])->assertSee('Inbox')->assertViewHas('counts', fn ($c) => ! isset($c['']) && $c['getrennt'] === 1)
            ->set('query', 'nichts')->assertSee('Nichts gefunden.')->assertViewHas('counts', fn ($c) => $c['getrennt'] === 1);
    }

    public function test_alle_alben_mit_markierung_und_sprung_zur_pruefung(): void
    {
        $this->mp3('inbox/Hits/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'a-ha', 'TPE2' => 'a-ha', 'TALB' => 'Hits', 'TDRC' => '1985']);
        $this->mp3('inbox/Hits/02.mp3', ['TIT2' => 'Zwei', 'TPE1' => 'a-ha', 'TPE2' => 'A-HA', 'TALB' => 'Hits', 'TDRC' => '1985']);
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TDRC' => '1992-09-21']);
        $this->mp3('library/Bravo/01.mp3', ['TIT2' => 'Eins', 'TPE1' => 'Cher', 'TALB' => 'Bravo', 'TCMP' => '1']);
        $this->mp3('blackhole/Weg/01.mp3', ['TIT2' => 'Weg', 'TPE1' => 'Cher', 'TALB' => 'Weg']);
        foreach (['inbox', 'library', 'blackhole'] as $location) {
            app(Scanner::class)->scan($location);
        }

        $page = $this->get('/alben')->assertOk()->assertSeeInOrder(['Hits', 'auseinandergerissen', 'Gold', 'ABBA', '1992', 'Lib', 'Bravo', NameRules::VARIOUS]);
        // Blackhole zählt nicht
        Livewire::test(AlbumList::class)->assertViewHas('counts', fn ($c) => ! isset($c['']) && $c['getrennt'] === 1)
            ->set('query', 'abba')->assertSee('Gold')->assertDontSee('Bravo')->assertViewHas('total', 1)
            ->call('showSplit', Track::where('album', 'Hits')->value('album_hash'))
            ->assertSet('check', 'getrennt')->assertSet('query', 'Hits')->assertSee('unterschiedlich geschrieben');
    }

    public function test_sampler_vorschlag_und_kein_vorschlag_bei_verschiedenen_ids(): void
    {
        foreach (['Cher', 'Madonna', 'Kylie', 'Sting'] as $i => $artist) {
            $this->mp3("inbox/Bravo/0$i.mp3", ['TIT2' => "Lied $i", 'TPE1' => $artist, 'TALB' => 'Bravo', 'TXXX:MusicBrainz Album Id' => $i < 2 ? 'id-a' : 'id-b']);
        }
        app(Scanner::class)->scan('inbox');

        $issues = app(SplitAlbums::class)->albums()['albums'][0]['issues'];
        $this->assertSame(NameRules::VARIOUS, $issues['albumartist']['suggest']);
        $this->assertStringStartsWith('4 verschiedene Interpreten bei 4 Liedern – sieht nach Sampler aus', $issues['albumartist']['why']);
        $this->assertNull($issues['mb_albumid']['suggest']);
        Livewire::test(AlbumList::class, ['check' => 'getrennt'])->assertSee('bitte selbst entscheiden');
    }
}
