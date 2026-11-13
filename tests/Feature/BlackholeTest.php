<?php

namespace Tests\Feature;

use App\Livewire\TrackTable;
use App\Models\Artist;
use App\Models\NameRule;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\NameRules;
use App\Support\Scanner;
use App\Support\TagEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class BlackholeTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        config(['musicwiz.category_fill' => true]);
        $this->mp3('blackhole/Ignoriert/01.mp3', ['TIT2' => 'Schwarz', 'TPE1' => 'Nur Im Blackhole', 'TALB' => 'Ignoriert', 'TPE2' => 'V.A.']);
        $this->mp3('blackhole/Ignoriert/02.mp3', ['TIT2' => 'Loch', 'TPE1' => 'Nur Im Blackhole', 'TALB' => 'Ignoriert']);
        app(Scanner::class)->scan('blackhole');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    public function test_blackhole_is_ignored(): void
    {
        // kein Interpret angelegt, keine Verbindung, keine Schreibweisen-Regel, keine Kategorie – keine Änderung
        $this->assertSame(0, Artist::count());
        $this->assertSame(0, DB::table('track_artist')->count());
        $this->assertSame('V.A.', Track::where('title', 'Schwarz')->value('albumartist'));
        $this->assertNull(Track::where('title', 'Schwarz')->value('category'));
        $this->assertSame(0, TagChange::count());

        // auch nicht von Hand oder über spätere Regeln
        NameRule::create(['kind' => 'word', 'value' => 'Nur', 'replacement' => 'NUR', 'whole_word' => true]);
        app(NameRules::class)->apply();
        $this->assertSame([], app(TagEditor::class)->update(Track::where('title', 'Loch')->first(), ['title' => 'Anders']));
        $this->assertSame(['Nur Im Blackhole'], Track::pluck('artist')->unique()->values()->all());
        $this->assertSame(0, TagChange::count());
    }

    public function test_only_move_back_to_inbox(): void
    {
        $track = Track::where('title', 'Loch')->first();
        $page = Livewire::test(TrackTable::class, ['slug' => 'blackhole'])
            ->assertSee('→ In die Inbox')->assertDontSee('Interpret unbestätigt')->assertDontSee('Lib Compilation');

        $page->call('saveField', $track->id, 'title', 'Anders')->assertStatus(422);
        Livewire::test(TrackTable::class, ['slug' => 'blackhole'])->call('select', [$track->id])->assertSee('→ In die Inbox')
            ->set('form.title', 'Anders')->call('saveFlyout')->assertStatus(422);
        $this->assertSame('Loch', $track->fresh()->title);

        // ganzes Album vormerken – nur in der Datenbank, die Datei bleibt im Blackhole
        Livewire::test(TrackTable::class, ['slug' => 'blackhole'])->call('moveAlbumToInbox', $track->album_hash)
            ->assertSee('wird beim Schreiben in die Inbox verschoben');
        $this->assertSame(['inbox'], Track::pluck('move_to')->unique()->values()->all());
        $this->assertSame(['blackhole'], Track::pluck('location')->unique()->values()->all());
        $this->assertSame(0, TagChange::count());

        // einzelnes Lied wieder aufheben
        Livewire::test(TrackTable::class, ['slug' => 'blackhole'])->call('moveToInbox', [$track->id], false);
        $this->assertNull($track->fresh()->move_to);

        // nur aus dem Blackhole
        Livewire::test(TrackTable::class, ['slug' => 'lib'])->call('moveToInbox', [$track->id])->assertStatus(422);
    }
}
