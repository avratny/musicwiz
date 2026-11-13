<?php

namespace Tests\Feature;

use App\Jobs\WriteChanges;
use App\Livewire\MiscPage;
use App\Models\Track;
use App\Support\BrokenTracks;
use App\Support\Scanner;
use App\Support\Sorter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class BrokenTracksTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        $this->flac('inbox/Rock/05.flac', ['TITLE' => 'Blue Suede Shoes', 'ARTIST' => 'Carl Perkins', 'ALBUM' => 'Rock', 'TRACKNUMBER' => '5']);
        $this->emptyFlac('inbox/Rock/19.flac', ['TITLE' => 'Blue Suede Shoes', 'ARTIST' => 'Carl Perkins', 'ALBUM' => 'Rock', 'TRACKNUMBER' => '19']);
        $this->emptyFlac('inbox/Rock/20.flac', ['TITLE' => 'Bye Bye Love', 'ARTIST' => 'The Everly Brothers', 'ALBUM' => 'Rock', 'TRACKNUMBER' => '20']);
        app(Scanner::class)->scan('inbox');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function track(string $file): Track
    {
        return Track::where('filename', $file)->firstOrFail();
    }

    public function test_files_without_audio_are_found_with_their_intact_version(): void
    {
        $this->assertNull($this->track('19.flac')->error, 'lesbar – nicht unter „Nicht lesbar“');
        $findings = app(BrokenTracks::class)->findings();

        $this->assertSame(['19.flac', '20.flac'], $findings->map(fn ($f) => $f['track']->filename)->all());
        $this->assertSame('05.flac', $findings[0]['intact']->filename);
        $this->assertNull($findings[1]['intact']);

        Livewire::test(MiscPage::class)->set('view', 'kaputt')->assertSee('Kaputte Lieder')->assertSee('kein Audio')
            ->assertSee('intakte Fassung im Album: Nr. 5');
    }

    public function test_trash_one_or_all_and_take_back(): void
    {
        $page = Livewire::test(MiscPage::class)->set('view', 'kaputt');

        $page->call('trashBroken', $this->track('19.flac')->id)->assertSee('1 Lied für den Papierkorb vorgemerkt')->assertSee('→ Papierkorb vorgemerkt');
        $this->assertSame([null, 'trash', null], [$this->track('05.flac')->move_to, $this->track('19.flac')->move_to, $this->track('20.flac')->move_to]);

        $page->call('keepBroken', $this->track('19.flac')->id);
        $this->assertNull($this->track('19.flac')->move_to);

        $page->call('trashBroken', null)->assertSee('2 Lieder für den Papierkorb vorgemerkt');
        $this->assertSame(0, app(BrokenTracks::class)->trash(), 'schon vorgemerkt');

        $plan = app(Sorter::class)->plan($this->track('19.flac')->album_hash);
        $this->assertSame(['kaputt (kein Audio)'], collect($plan['items'])->where('track.filename', '19.flac')->pluck('reason')->all());
    }

    public function test_writing_moves_them_to_the_trash(): void
    {
        app(BrokenTracks::class)->trash();
        WriteChanges::start();

        $this->assertSame(['inbox', 'trash', 'trash'], [$this->track('05.flac')->location, $this->track('19.flac')->location, $this->track('20.flac')->location]);
        $this->assertSame(0, app(BrokenTracks::class)->count());
    }
}
