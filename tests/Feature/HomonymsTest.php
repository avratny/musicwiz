<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Operation;
use App\Support\AlbumPlanner;
use App\Support\ArtistDirectory;
use App\Support\CleanupChecks;
use App\Support\Homonyms;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HomonymsTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/homonyms-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb',
            'artist_images' => $this->base.'/_artists', 'scan_throttle_ms' => 0]);
        $mp3 = str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 20);
        $tool = app(TagTool::class);
        foreach ([
            'library/artists/A/Adam/Schlager Hits (2001)/01 - Herzklopfen.mp3' => ['Adam', 'Herzklopfen', 'Schlager Hits', ['Schlager']],
            'library/artists/A/Adam/Club Nights (2010)/01 - Bassline.mp3' => ['Adam feat. Liz', 'Bassline', 'Club Nights', ['Electronic', 'House']],
        ] as $rel => [$artist, $title, $album, $genres]) {
            $path = $this->base.'/'.$rel;
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, $mp3);
            $tool->write([['path' => $path, 'tags' => ['artist' => $artist, 'albumartist' => 'Adam', 'title' => $title, 'album' => $album,
                'track' => '1', 'year' => preg_match('/\((\d{4})\)/', $rel, $m) ? $m[1] : '', 'genres' => $genres, 'comment' => 'LIB']]]);
        }
        File::ensureDirectoryExists($this->base.'/_artists');
        file_put_contents($this->base.'/_artists/Adam.jpg', 'img');
        app(Scanner::class)->scan('library');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_suffix_suggestions(): void
    {
        $out = Homonyms::withSuffixes([
            ['name' => 'Adam', 'mbid' => '1', 'country' => 'DE', 'disambiguation' => 'German Schlager singer', 'type' => 'Person'],
            ['name' => 'Adam', 'mbid' => '2', 'country' => 'GB', 'disambiguation' => null, 'type' => 'Person'],
            ['name' => 'Adam', 'mbid' => '3', 'country' => 'US', 'disambiguation' => 'house DJ, Chicago', 'type' => 'Person'],
            ['name' => 'Adam', 'mbid' => '4', 'country' => 'US', 'disambiguation' => 'rock band', 'type' => 'Group'],
            ['name' => 'Adam', 'mbid' => '5', 'country' => null, 'disambiguation' => null, 'type' => null],
        ]);
        $this->assertSame(['DE', 'GB', 'house DJ', 'rock band', '5'], array_column($out, 'suffix'));
    }

    public function test_split_assign_and_keep_assignment(): void
    {
        $adam = Artist::create(['name' => 'Adam', 'image' => 'Adam.jpg', 'default_genre' => 'Schlager']);
        $adam->aliases()->create(['alias' => 'ADAM']);
        $homonyms = app(Homonyms::class);
        $homonyms->split($adam, [['suffix' => 'DE', 'existing' => true], ['suffix' => 'DJ']]);

        $this->assertSame('Adam [DE]', $adam->fresh()->name);
        $this->assertFileExists($this->base.'/_artists/Adam [DE].jpg');
        $this->assertSame(0, $adam->aliases()->count(), 'Grundname darf keine Variante einer Person sein');
        $dir = app(ArtistDirectory::class);
        $this->assertSame('ambiguous', $dir->check('Adam')['status']);
        $this->assertSame('ambiguous', $dir->check('ADAM')['status']);
        $this->assertSame('Adam feat. Liz', $dir->correctText('Adam feat. Liz'));

        // Prüfung meldet beide Alben
        $this->assertCount(2, app(CleanupChecks::class)->findings('ambiguous'));

        // Vorschlag über Standard-Genre
        $rows = collect($homonyms->albums('Adam'))->keyBy(fn ($r) => $r['album']->title);
        $this->assertSame('Adam [DE]', $rows['Schlager Hits']['suggestion']);

        // Ohne Auswahl blockiert der Editor
        $club = Album::where('title', 'Club Nights')->firstOrFail();
        $plan = app(AlbumPlanner::class)->plan($club, ['category' => 'LIB'], [], true);
        $this->assertTrue($plan['rows'][0]['blocked']);

        // Zuordnen + Ordner nach Regel
        $assign = [Album::where('title', 'Schlager Hits')->value('id') => 'Adam [DE]', $club->id => 'Adam [DJ]'];
        $op = OperationRunner::dispatch('homonyms', 'Test', $homonyms->plan('Adam', $assign, true)['actions']);
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);

        $clubFile = $this->base.'/library/artists/A/Adam [DJ]/Club Nights (2010)/01 - Bassline.mp3';
        $this->assertFileExists($clubFile);
        $this->assertFileExists($this->base.'/library/artists/A/Adam [DE]/Schlager Hits (2001)/01 - Herzklopfen.mp3');
        $tags = app(TagTool::class)->read([$clubFile])[$clubFile]['tags'];
        $this->assertSame('Adam feat. Liz', $tags['artist'], 'Anzeigetext bleibt');
        $this->assertSame(['Adam [DJ]', 'Liz'], $tags['artists']);
        $this->assertSame(['Adam [DJ]'], $tags['albumartists']);
        $this->assertCount(0, app(CleanupChecks::class)->findings('ambiguous'));
        $this->assertCount(0, app(CleanupChecks::class)->findings('combined'), 'Kombi-Prüfung darf die Zuordnung nicht zurücksetzen');

        // Spätere Änderung (z. B. Titel) behält die Zuordnung
        $club = Album::where('title', 'Club Nights')->firstOrFail();
        $track = $club->tracks()->first();
        $plan = app(AlbumPlanner::class)->plan($club, ['category' => 'LIB'], [$track->id => ['title' => 'Bassline (Remix)']], true);
        $this->assertFalse($plan['rows'][0]['blocked']);
        $this->assertArrayNotHasKey('artists', $plan['rows'][0]['changed']);
        $this->assertStringContainsString('/Adam [DJ]/', $plan['rows'][0]['target']);

        // Rückgängig
        OperationRunner::dispatchUndo($op);
        $this->assertFileExists($this->base.'/library/artists/A/Adam/Club Nights (2010)/01 - Bassline.mp3');
        $this->assertSame('undone', $op->fresh()->status);
        $this->assertSame(2, Operation::count());
    }
}
