<?php

namespace Tests\Feature;

use App\Livewire\MiscPage;
use App\Models\FilePlan;
use App\Models\Track;
use App\Support\Duplicates;
use App\Support\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class DuplicatesTest extends TestCase
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

    /** MP3 mit wählbarer Bitrate (128/320) und Länge in Frames (80 ≈ 2 s) */
    private function mp3At(string $rel, array $frames, int $kbit = 128, int $count = 80): void
    {
        $path = $this->base.'/'.$rel;
        @mkdir(dirname($path), 0777, true);
        [$byte, $size] = $kbit === 320 ? ["\xE0", 1044] : ["\x90", 417];
        file_put_contents($path, str_repeat("\xFF\xFB".$byte."\x00".str_repeat("\x00", $size - 4), $count));
        $this->tag($path, 'mp3', $frames);
    }

    private function scan(): void
    {
        foreach (['library', 'inbox'] as $location) {
            app(Scanner::class)->scan($location);
        }
    }

    private function track(string $rel): Track
    {
        return Track::where('path', $this->base.'/'.$rel)->sole();
    }

    /** Rolle je Datei im einzigen Fund */
    private function roles(): array
    {
        $findings = app(Duplicates::class)->findings();
        $this->assertCount(1, $findings);

        return collect($findings->first()['members'])->mapWithKeys(fn ($m) => [str_replace($this->base.'/', '', $m['track']->path) => $m['role']])->all();
    }

    /** jede Gruppe einzeln vormerken (wie per Klick – ersetzt auch ohne Fingerabdruck) */
    private function planEach(): void
    {
        foreach (app(Duplicates::class)->findings()->keys() as $key) {
            app(Duplicates::class)->apply($key);
        }
    }

    public function test_loose_copy_goes_when_the_album_has_an_equal_or_better_one(): void
    {
        $this->flac('library/ABBA/Gold/01.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'TRACKNUMBER' => '1']);
        $this->mp3At('inbox/ABBA/Kein Album/Waterloo.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA']);
        $this->scan();

        $this->assertSame(['library/ABBA/Gold/01.flac' => 'keep', 'inbox/ABBA/Kein Album/Waterloo.mp3' => 'trash'], $this->roles());
        $this->assertSame(1, app(Duplicates::class)->count());

        $this->planEach();
        $this->assertSame('trash', $this->track('inbox/ABBA/Kein Album/Waterloo.mp3')->move_to);
        $this->assertNull($this->track('library/ABBA/Gold/01.flac')->move_to);
        $this->assertTrue(app(Duplicates::class)->findings()->first()['planned']);
        $this->assertSame(0, app(Duplicates::class)->count());
    }

    public function test_same_song_in_two_albums_of_equal_quality_is_wanted(): void
    {
        $this->flac('library/ABBA/Gold/01.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'TRACKNUMBER' => '1']);
        $this->flac('inbox/ABBA/Waterloo/01.flac', ['TITLE' => 'WATERLOO!', 'ARTIST' => 'Abba', 'ALBUM' => 'Waterloo', 'TRACKNUMBER' => '1']);
        $this->scan();

        $this->assertCount(1, app(Duplicates::class)->groups());
        $this->assertCount(0, app(Duplicates::class)->findings());
    }

    public function test_better_file_replaces_the_worse_one_in_another_album_without_tearing_it(): void
    {
        $this->flac('library/ABBA/Gold/01.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'TRACKNUMBER' => '1']);
        $this->mp3At('inbox/ABBA/Waterloo/03.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Waterloo', 'TRCK' => '3/10', 'TDRC' => '1974']);
        $this->scan();

        $this->assertSame(['library/ABBA/Gold/01.flac' => 'copies', 'inbox/ABBA/Waterloo/03.mp3' => 'replaced'], $this->roles());
        $this->planEach();

        $old = $this->track('inbox/ABBA/Waterloo/03.mp3');
        $plan = FilePlan::sole();
        $this->assertSame(['copy', $this->track('library/ABBA/Gold/01.flac')->id, $old->id, $old->album_hash, 'inbox'],
            [$plan->action, $plan->track_id, $plan->replaces_track_id, $plan->target_album_hash, $plan->target_location]);
        $this->assertSame(['Waterloo', 3, 10, '1974'], [$plan->values['album'], $plan->values['track'], $plan->values['track_total'], $plan->values['year']]);
        // das ersetzte Lied ist nicht einzeln für den Papierkorb vorgemerkt – das Album bleibt vollständig, auch wenn die Vormerkung fällt
        $this->assertNull($old->move_to);
        $this->assertSame(0, app(Duplicates::class)->count());

        app(Duplicates::class)->revert(app(Duplicates::class)->findings()->keys()->first());
        $this->assertSame(0, FilePlan::count());
        $this->assertSame(1, app(Duplicates::class)->count());
    }

    public function test_better_loose_file_moves_into_the_album(): void
    {
        $this->mp3At('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold', 'TRCK' => '1']);
        $this->flac('inbox/Kein Album/Waterloo.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA']);
        $this->scan();

        $this->assertSame(['inbox/Kein Album/Waterloo.flac' => 'moves', 'library/ABBA/Gold/01.mp3' => 'replaced'], $this->roles());
        $this->planEach();
        $this->assertSame(['move', 'Gold', 'library'], [FilePlan::sole()->action, FilePlan::sole()->values['album'], FilePlan::sole()->target_location]);
    }

    public function test_a_folder_of_songs_without_album_tag_is_an_album_and_is_not_torn_apart(): void
    {
        $this->mp3At('library/Daft Punk/Best Of/01.mp3', ['TIT2' => 'One More Time', 'TPE1' => 'Daft Punk', 'TALB' => 'Best Of', 'TRCK' => '1']);
        $this->mp3At('library/Daft Punk/Best Of/02.mp3', ['TIT2' => 'Around the World', 'TPE1' => 'Daft Punk', 'TALB' => 'Best Of', 'TRCK' => '2']);
        $this->flac('inbox/Daft Punk - Discovery (2001)/01.flac', ['TITLE' => 'One More Time', 'ARTIST' => 'Daft Punk']);
        $this->flac('inbox/Daft Punk - Discovery (2001)/02.flac', ['TITLE' => 'Aerodynamic', 'ARTIST' => 'Daft Punk']);
        $this->scan();

        // die bessere Fassung wird ins Best-of kopiert, nicht aus dem Download-Ordner herausgeholt
        $this->assertSame(['inbox/Daft Punk - Discovery (2001)/01.flac' => 'copies', 'library/Daft Punk/Best Of/01.mp3' => 'replaced'], $this->roles());
        $this->planEach();
        $this->assertSame('copy', FilePlan::sole()->action);
    }

    public function test_only_loose_copies_keep_the_best_and_second_copies_in_an_album_go(): void
    {
        $this->mp3At('inbox/Kein Album/Believe.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher']);
        $this->mp3At('inbox/Neu/Kein Album/Believe.mp3', ['TIT2' => 'Believe', 'TPE1' => 'Cher'], 320);
        $this->mp3At('library/Cher/Gold/02.mp3', ['TIT2' => 'Strong Enough', 'TPE1' => 'Cher', 'TALB' => 'Gold', 'TRCK' => '2']);
        $this->mp3At('library/Cher/Gold/02 (2).mp3', ['TIT2' => 'Strong Enough', 'TPE1' => 'Cher', 'TALB' => 'Gold', 'TRCK' => '2']);
        // anders lang (> 2 s): eine andere Fassung
        $this->mp3At('library/Cher/Gold/09.mp3', ['TIT2' => 'Strong Enough', 'TPE1' => 'Cher', 'TALB' => 'Gold', 'TRCK' => '9'], 128, 400);
        $this->scan();

        $findings = app(Duplicates::class)->findings();
        $this->assertCount(2, $findings);
        $roles = $findings->flatMap(fn ($f) => collect($f['members'])->mapWithKeys(fn ($m) => [str_replace($this->base.'/', '', $m['track']->path) => $m['role']]))->all();
        $this->assertSame(['inbox/Neu/Kein Album/Believe.mp3' => 'keep', 'inbox/Kein Album/Believe.mp3' => 'trash',
            'library/Cher/Gold/02.mp3' => 'keep', 'library/Cher/Gold/02 (2).mp3' => 'trash'], $roles);
    }

    public function test_page_plans_and_exempts_groups(): void
    {
        $this->flac('library/ABBA/Gold/01.flac', ['TITLE' => 'Waterloo', 'ARTIST' => 'ABBA', 'ALBUM' => 'Gold', 'TRACKNUMBER' => '1']);
        $this->mp3At('inbox/ABBA/Kein Album/Waterloo.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA']);
        $this->scan();
        $key = app(Duplicates::class)->findings()->keys()->first();

        $page = Livewire::test(MiscPage::class)->set('view', 'duplikate')
            ->assertSee('Waterloo')->assertSee('ABBA/Kein Album/Waterloo.mp3')->assertSee('Papierkorb')
            ->call('planDuplicates', $key)->assertSee('Vorgemerkt')->assertSee('Zurücknehmen');
        $this->assertSame('trash', $this->track('inbox/ABBA/Kein Album/Waterloo.mp3')->move_to);

        $page->call('exemptDuplicates', $key, true)->assertSee('Keine Duplikate')->assertSee('als „kein Duplikat“ ausgenommen');
        $this->assertNull($this->track('inbox/ABBA/Kein Album/Waterloo.mp3')->move_to);
        $this->assertSame(0, app(Duplicates::class)->count());

        $page->call('exemptDuplicates', $key, false);
        $this->assertSame(1, app(Duplicates::class)->count());
    }

    public function test_no_duplicate_stays_when_another_version_comes_in(): void
    {
        // Studio (FLAC, 2 s) und Live (MP3 320, 2,6 s): nah genug beieinander für eine Gruppe
        $this->flac('library/Eagles/Hotel California/01.flac', ['TITLE' => 'Hotel California', 'ARTIST' => 'Eagles', 'ALBUM' => 'Hotel California', 'TRACKNUMBER' => '1']);
        $this->mp3At('library/Eagles/Hell Freezes Over/01.mp3', ['TIT2' => 'Hotel California', 'TPE1' => 'Eagles', 'TALB' => 'Hell Freezes Over', 'TRCK' => '1'], 320, 100);
        $this->scan();
        $studio = $this->track('library/Eagles/Hotel California/01.flac');
        $live = $this->track('library/Eagles/Hell Freezes Over/01.mp3');
        $duplicates = app(Duplicates::class);
        $duplicates->exempt($duplicates->groups()->keys()->first());
        $this->assertSame(0, app(Duplicates::class)->count());

        // eine weitere Fassung (lose, MP3, 2 s) kommt dazu: Studio und Live bleiben trotzdem getrennt
        $this->mp3At('inbox/Kein Album/Hotel California.mp3', ['TIT2' => 'Hotel California', 'TPE1' => 'Eagles'], 128, 75);
        $this->scan();
        $groups = app(Duplicates::class)->groups();
        $this->assertCount(1, $groups);
        $ids = $groups->first()->pluck('id')->all();
        $this->assertContains($studio->id, $ids);
        $this->assertNotContains($live->id, $ids);

        // ausgenommen: Studio und Live – wieder aufnehmen
        $exempted = app(Duplicates::class)->findings('', true);
        $this->assertCount(1, $exempted);
        app(Duplicates::class)->exempt($exempted->keys()->first(), false);
        $this->assertSame(0, DB::table('duplicate_exemption_pairs')->count());
    }
}
