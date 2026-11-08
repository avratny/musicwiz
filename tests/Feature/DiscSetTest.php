<?php

namespace Tests\Feature;

use App\Livewire\AlbumEditor;
use App\Models\Album;
use App\Models\Operation;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class DiscSetTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/discs-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function make(string $dir, string $album, array $titles, string $disc = '1', string $disctotal = '1', bool $cover = true): void
    {
        $mp3 = str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 60);
        File::ensureDirectoryExists($this->base.'/inbox/Various/'.$dir);
        foreach ($titles as $i => $title) {
            $path = $this->base.'/inbox/Various/'.$dir.'/'.sprintf('%02d', $i + 1).' '.$title.'.mp3';
            file_put_contents($path, $mp3);
            app(TagTool::class)->write([['path' => $path, 'tags' => ['artist' => 'Artist '.$title, 'title' => $title, 'album' => $album,
                'track' => (string) ($i + 1), 'disc' => $disc, 'disctotal' => $disctotal, 'year' => '1990']]]);
        }
        if ($cover) {
            file_put_contents($this->base.'/inbox/Various/'.$dir.'/Folder.jpg', 'jpg-'.$dir);
        }
    }

    public function test_sibling_disc_folders_become_one_album_and_are_filed_together(): void
    {
        // Sonderfall: CD-Nummer nur im Ordner-/Albumnamen, Tag sagt bei beiden "1/1"
        $this->make('Rock Years [Disc 1]', 'Rock Years [Disc 1]', ['Alpha', 'Beta']);
        $this->make('Rock Years [Disc 2]', 'Rock Years [Disc 2]', ['Gamma', 'Delta']);
        // gleicher Name ohne CD-Angabe in anderem Elternordner bleibt getrennt
        $this->make('../Other/Rock Years', 'Rock Years', ['Solo'], cover: false);
        app(Scanner::class)->scan('inbox');

        $set = Album::whereNotNull('dirs')->firstOrFail();
        $this->assertSame(2, $set->discCount());
        $this->assertSame(4, $set->track_count);
        $this->assertSame('Rock Years', $set->title);
        $this->assertSame(2, Album::count());

        $editor = Livewire::test(AlbumEditor::class, ['album' => $set])
            ->assertSet('fields.album', 'Rock Years')
            ->assertSee('2 CDs');
        $this->assertSame(['1', '1', '2', '2'], array_column($editor->get('rows'), 'disc'));
        $this->assertSame(['Alpha', 'Beta', 'Gamma', 'Delta'], array_column($editor->get('rows'), 'title'));

        $editor->set('fields.category', 'LIB COMPILATION')->call('preview', true)->call('confirm');
        $op = Operation::latest('id')->firstOrFail();
        $this->assertSame('done', $op->status, (string) $op->error);

        $target = $this->base.'/library/sampler/R/Rock Years (1990)';
        foreach (['1-01 - Artist Alpha - Alpha.mp3', '1-02 - Artist Beta - Beta.mp3', '2-01 - Artist Gamma - Gamma.mp3', '2-02 - Artist Delta - Delta.mp3', 'cover.jpg'] as $f) {
            $this->assertFileExists($target.'/'.$f);
        }
        $tags = app(TagTool::class)->read([$target.'/2-01 - Artist Gamma - Gamma.mp3'])[$target.'/2-01 - Artist Gamma - Gamma.mp3']['tags'];
        $this->assertSame(['2', '2', 'Rock Years'], [$tags['disc'], $tags['disctotal'], $tags['album']]);
        $this->assertDirectoryDoesNotExist($this->base.'/inbox/Various/Rock Years [Disc 1]');
        $this->assertDirectoryDoesNotExist($this->base.'/inbox/Various/Rock Years [Disc 2]');
        $this->assertFileExists($target.'/cover (2).jpg', 'anderes Cover der CD 2 bleibt erhalten');

        OperationRunner::dispatchUndo($op);
        $this->assertFileExists($this->base.'/inbox/Various/Rock Years [Disc 2]/01 Gamma.mp3');
        $this->assertFileExists($this->base.'/inbox/Various/Rock Years [Disc 2]/Folder.jpg');
        $this->assertFileExists($this->base.'/inbox/Various/Rock Years [Disc 1]/Folder.jpg');
        $this->assertSame(2, Album::where('location', 'inbox')->whereNotNull('dirs')->firstOrFail()->discCount());
    }

    public function test_same_named_albums_without_disc_numbers_are_not_merged(): void
    {
        $this->make('Greatest Hits', 'Greatest Hits', ['A']);
        $this->make('Greatest Hits (2)', 'Greatest Hits', ['B']);
        app(Scanner::class)->scan('inbox');

        $this->assertSame(2, Album::count());
        $this->assertSame(0, Album::whereNotNull('dirs')->count());
    }

    public function test_differently_spelled_disc_is_added_but_different_numbers_are_not(): void
    {
        $this->make('Rock Years 1956-65 [Disc 1]', "The Greatest Years of Rock'n'roll 1956-65 [Disc 1]", ['Alpha']);
        $this->make('Rock Years 1956-65 [Disc 2]', "The Greatest Years of Rock'n'roll 1956-65 [Disc 2]", ['Beta']);
        $this->make('Rock Years 1956 - 1965 (Disc 4)', "The Greatest Years of Rock 'n' Roll 1956 - 1965 (Disc 4)", ['Gamma']);
        // nur die Zahl unterscheidet sich -> verschiedene Alben
        $this->make('Just the Best 2-2001, Disc 1', 'Just the Best 2/2001', ['A'], '1', '2');
        $this->make('Just the Best 2-2001, Disc 2', 'Just the Best 2/2001', ['B'], '2', '2');
        $this->make('Just the Best 4-2000, Disc 1', 'Just The Best 4/2000', ['C'], '1', '2');
        $this->make('Just the Best 4-2000, Disc 2', 'Just the Best 4/2000', ['D'], '2', '2');
        app(Scanner::class)->scan('inbox');

        $sets = Album::whereNotNull('dirs')->get()->mapWithKeys(fn ($a) => [$a->title => $a->discCount()])->all();
        $this->assertEqualsCanonicalizing(['Just the Best 2/2001' => 2, 'Just The Best 4/2000' => 2, "The Greatest Years of Rock'n'roll 1956-65" => 3], $sets);

        $rock = Album::where('title', 'like', 'The Greatest%')->firstOrFail();
        $rows = Livewire::test(AlbumEditor::class, ['album' => $rock])->get('rows');
        $this->assertSame(['1', '2', '4'], array_column($rows, 'disc'));
        $this->assertSame([1, 2, 4], $rock->discNumbers());
        $this->assertSame([3], $rock->missingDiscs());
    }

    public function test_manually_added_disc_survives_rescan_and_can_be_removed(): void
    {
        $this->make('Rock Years [Disc 1]', 'Rock Years [Disc 1]', ['Alpha']);
        $this->make('Rock Years [Disc 2]', 'Rock Years [Disc 2]', ['Beta']);
        $this->make('The Best Ten Years of Rock 1959 - 1961', 'The Best Ten Years of Rock 1959 - 1961', ['Gamma']);
        $this->make('Schlager Party', 'Schlager Party', ['Delta'], cover: false);
        app(Scanner::class)->scan('inbox');
        $set = Album::whereNotNull('dirs')->firstOrFail();
        $third = Album::where('title', 'like', 'The Best Ten%')->firstOrFail();

        $editor = Livewire::test(AlbumEditor::class, ['album' => $set])->call('openAddDisc')
            ->assertSet('addDiscNumber', 3)
            ->assertSeeInOrder(['The Best Ten Years', 'Schlager Party']) // ähnlichstes zuerst
            ->set('addDiscAlbum', $third->id)
            ->call('addDisc');
        $editor->assertRedirect();

        $set = Album::whereNotNull('dirs')->firstOrFail();
        $this->assertSame([1, 2, 3], $set->discNumbers());
        $this->assertSame('Rock Years', $set->title);
        $rows = Livewire::test(AlbumEditor::class, ['album' => $set])->get('rows');
        $this->assertSame(['1', '2', '3'], array_column($rows, 'disc'));
        $this->assertSame('Gamma', $rows[2]['title']);

        // Zuordnung bleibt nach einem neuen Scan
        app(Scanner::class)->scan('inbox');
        $this->assertSame([1, 2, 3], Album::whereNotNull('dirs')->firstOrFail()->discNumbers());

        // herauslösen
        Livewire::test(AlbumEditor::class, ['album' => Album::whereNotNull('dirs')->firstOrFail()])
            ->call('removeDisc', $this->base.'/inbox/Various/The Best Ten Years of Rock 1959 - 1961');
        $this->assertSame(2, Album::whereNotNull('dirs')->firstOrFail()->discCount());
        $this->assertSame(1, Album::where('title', 'like', 'The Best Ten%')->count());
    }

    public function test_disc_header_changes_number_for_whole_disc_and_persists(): void
    {
        $this->make('Rock Years [Disc 1]', 'Rock Years [Disc 1]', ['Alpha', 'Beta']);
        $this->make('Rock Years [Disc 2]', 'Rock Years [Disc 2]', ['Gamma', 'Delta']);
        app(Scanner::class)->scan('inbox');
        $set = Album::whereNotNull('dirs')->firstOrFail();

        $editor = Livewire::test(AlbumEditor::class, ['album' => $set])
            ->assertSee('Nummer dieser CD ändern (gilt für alle 2 Tracks)')
            ->call('setDiscGroup', 2, '3');
        $this->assertSame(['1', '1', '3', '3'], array_column($editor->get('rows'), 'disc'));

        app(Scanner::class)->scan('inbox');
        $set = Album::whereNotNull('dirs')->firstOrFail();
        $this->assertSame([1, 3], $set->discNumbers());
        $this->assertSame(['1', '1', '3', '3'], array_column(Livewire::test(AlbumEditor::class, ['album' => $set])->get('rows'), 'disc'));
    }

    public function test_identical_disc_covers_are_deduplicated(): void
    {
        $this->make('Hits [Disc 1]', 'Hits [Disc 1]', ['Alpha'], cover: false);
        $this->make('Hits [Disc 2]', 'Hits [Disc 2]', ['Beta'], cover: false);
        file_put_contents($this->base.'/inbox/Various/Hits [Disc 1]/Folder.jpg', 'same');
        file_put_contents($this->base.'/inbox/Various/Hits [Disc 2]/Folder.jpg', 'same');
        app(Scanner::class)->scan('inbox');
        Livewire::test(AlbumEditor::class, ['album' => Album::whereNotNull('dirs')->firstOrFail()])
            ->set('fields.category', 'LIB COMPILATION')->call('preview', true)->call('confirm');

        $target = $this->base.'/library/sampler/H/Hits (1990)';
        $this->assertFileExists($target.'/cover.jpg');
        $this->assertFileDoesNotExist($target.'/cover (2).jpg');
        $this->assertFileExists($this->base.'/_papierkorb/inbox/Various/Hits [Disc 2]/Folder.jpg');
    }

    public function test_album_keys(): void
    {
        $this->assertSame(Scanner::albumKey("Rock'n'roll 1956-65 [Disc 1]"), Scanner::albumKey("Rock 'n' Roll 1956 - 1965 (Disc 4)"));
        $this->assertTrue(Scanner::similarAlbumKeys(Scanner::albumKey('Dance Sensation Mega Party'), Scanner::albumKey('Dance Sensations Mega Party')));
        $this->assertFalse(Scanner::similarAlbumKeys(Scanner::albumKey('Just the Best 2/2001'), Scanner::albumKey('Just the Best 4/2000')));
        $this->assertFalse(Scanner::similarAlbumKeys(Scanner::albumKey('Hits 1'), Scanner::albumKey('Hats 1')), 'zu kurz für Unschärfe');
    }
}
