<?php

namespace Tests\Feature;

use App\Livewire\AlbumList;
use App\Livewire\ArtistSortNames;
use App\Livewire\SettingsPage;
use App\Models\Artist;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\Scanner;
use App\Support\SortNames;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class SortNamesTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        config(['musicwiz.sortnames_auto' => true]);
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

    public function test_rules(): void
    {
        $sorts = app(SortNames::class);
        $this->assertSame('Beatles, The', $sorts->byRule('The Beatles'));
        $this->assertSame('Prinzen, Die', $sorts->byRule('Die Prinzen'));
        $this->assertSame('Udo Jürgens', $sorts->byRule('Udo Jürgens'), 'Personen nie umgedreht');
        $this->assertSame('The', $sorts->byRule('The'));
        $this->assertSame('The Art of Noise', $sorts->uninverted('Art of Noise, The'));
        $this->assertNull($sorts->uninverted('Jürgens, Udo'));
        $this->assertSame('Beatles, The feat. Liz', $sorts->expected('The Beatles feat. Liz'));
        $this->assertNull($sorts->expected('Thomas Gottschalk Und Die Besorgten Väter', 'Thomas Gottschalk; Die Besorgten Väter'), 'nur der Name am Anfang');
        $this->assertSame('Prinzen, Die & The Gang', $sorts->expected('Die Prinzen & The Gang', 'Die Prinzen; The Gang'));
        $this->assertNull($sorts->expected('Udo Jürgens'));
        $this->assertNull($sorts->expected('Various Artists'));
        $this->assertTrue(SortNames::sameWords('Jürgens, Udo', 'Udo Jürgens'));
    }

    public function test_sure_ones_are_set_automatically_others_stay_open(): void
    {
        $this->mp3('library/Beatles/01.mp3', ['TIT2' => 'Help', 'TPE1' => 'The Beatles', 'TALB' => 'Help']);
        $this->mp3('library/Udo/01.mp3', ['TIT2' => 'Merci', 'TPE1' => 'Udo Jürgens', 'TALB' => 'Merci', 'TSOP' => 'Jürgens, Udo']);
        $this->mp3('library/Cher/01.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher', 'TALB' => 'Believe', 'TSOP' => 'Sherry']);
        $this->mp3('library/Noise/01.mp3', ['TIT2' => 'Moments', 'TPE1' => 'Art of Noise, The', 'TALB' => 'Moments']);
        $this->flac('library/Prinzen/01.flac', ['TITLE' => 'Millionär', 'ARTIST' => 'Die Prinzen', 'ALBUMARTIST' => 'Die Prinzen', 'ALBUMARTISTSORT' => 'Prinzen, Die']);
        app(Scanner::class)->scan('library');

        $this->assertSame('Beatles, The', $this->track('Help')->artistsort);
        $this->assertNull($this->track('Merci')->artistsort, '„Jürgens, Udo“ entfernt');
        $this->assertSame('Sherry', $this->track('Believe')->artistsort, 'unklar: bleibt offen');
        $this->assertSame(['The Art of Noise', 'Art of Noise, The'], [$this->track('Moments')->artist, $this->track('Moments')->artistsort]);
        $this->assertTrue(Artist::where('name', 'The Art of Noise')->exists());
        $this->assertSame(['Prinzen, Die', 'Prinzen, Die'], [$this->track('Millionär')->artistsort, $this->track('Millionär')->albumartistsort]);
        $this->assertSame('system', TagChange::where('track_id', $this->track('Help')->id)->where('field', 'artistsort')->value('source'));

        // offen: Cher – unter Interpreten und Alben
        $open = app(SortNames::class)->openByArtist();
        $this->assertSame(['Cher'], array_values(array_map(fn ($e) => $e['artist']->name, $open)));
        Livewire::test(AlbumList::class)->set('check', 'sortiernamen')->assertSee('Sherry')->assertSee('keiner nötig')->assertDontSee('Help');

        // behalten = eigener Sortiername
        $cher = Artist::where('name', 'Cher')->first();
        Livewire::test(ArtistSortNames::class)->assertSee('„Sherry“ behalten')->call('keep', $cher->id, 'Sherry')->assertSee('Nichts offen.');
        $this->assertSame('Sherry', $cher->fresh()->sort_name);
        $this->assertSame([], app(SortNames::class)->openByArtist());

        // eigenen Sortiernamen für die Beatles: trägt MusicWiz selbst ein
        $beatles = Artist::where('name', 'The Beatles')->first();
        Livewire::test(ArtistSortNames::class)->set('own.'.$beatles->id, 'Pilzköpfe')->call('setOwn', $beatles->id);
        $this->assertSame('Pilzköpfe', $this->track('Help')->artistsort);
        Livewire::test(ArtistSortNames::class)->call('resetOwn', $beatles->id);
        $this->assertSame('Beatles, The', $this->track('Help')->artistsort);
    }

    public function test_album_takes_the_rule_and_name_change_follows(): void
    {
        $this->mp3('library/Cher/01.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher', 'TALB' => 'Believe', 'TSOP' => 'Sherry']);
        $this->mp3('library/Beatles/01.mp3', ['TIT2' => 'Help', 'TPE1' => 'The Beatles', 'TALB' => 'Help']);
        app(Scanner::class)->scan('library');

        Livewire::test(AlbumList::class)->set('check', 'sortiernamen')->call('fixSortNames', $this->track('Believe')->album_hash)->assertSee('bei 1 Lied');
        $this->assertNull($this->track('Believe')->artistsort);
        $this->assertSame('manual', TagChange::where('track_id', $this->track('Believe')->id)->where('field', 'artistsort')->value('source'));

        // Interpret geändert: Sortiername zieht mit
        app(TagEditor::class)->update($this->track('Help'), ['artist' => 'The Rolling Stones']);
        $this->assertSame('Rolling Stones, The', $this->track('Help')->artistsort);
        app(TagEditor::class)->update($this->track('Help'), ['artist' => 'Cher']);
        $this->assertNull($this->track('Help')->artistsort, 'für „Cher“ ist keiner nötig');
    }

    public function test_articles_in_settings(): void
    {
        $this->mp3('library/Toten/01.mp3', ['TIT2' => 'Hier kommt Alex', 'TPE1' => 'Los Toten Hosen']);
        app(Scanner::class)->scan('library');
        $this->assertSame('Toten Hosen, Los', $this->track('Hier kommt Alex')->artistsort);

        Livewire::test(SettingsPage::class)->set('tab', 'schreibweisen')->set('section', 'sortierung')
            ->set('articles', 'The, Die')->call('saveArticles')->assertSee('angepasst');
        $this->assertNull($this->track('Hier kommt Alex')->artistsort);
    }
}
