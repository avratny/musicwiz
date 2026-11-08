<?php

namespace Tests\Feature;

use App\Livewire\Artists;
use App\Models\Artist;
use App\Models\RejectedAlias;
use App\Support\ArtistDirectory;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** „Ist derselbe wie …“: andere Einträge gehen im gewählten Künstler auf. */
class ArtistMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_moves_spellings_ids_and_image(): void
    {
        $base = storage_path('framework/testing/merge-'.bin2hex(random_bytes(4)));
        app(Settings::class)->set(['base' => $base, 'artist_images' => $base.'/_artists', 'trash' => $base.'/_papierkorb']);
        File::ensureDirectoryExists($base.'/_artists');
        file_put_contents($base.'/_artists/DJ Bobo.jpg', 'bild');

        $target = Artist::create(['name' => 'DJ BoBo']);
        $other = Artist::create(['name' => 'DJ Bobo', 'mbid' => 'bobo-mbid', 'default_genre' => 'Eurodance', 'image' => 'DJ Bobo.jpg']);
        $other->aliases()->create(['alias' => 'D.J. BoBo', 'source' => 'musicbrainz']);
        RejectedAlias::remember($other->id, 'René Baumann');
        $third = Artist::create(['name' => 'Bobo']);
        $person = Artist::create(['name' => 'Bobo [CH]', 'base_name' => 'Bobo', 'suffix' => 'CH']);
        Artist::create(['name' => 'ABBA']);

        $page = Livewire::test(Artists::class)->call('select', $target->id)
            ->set('mergeOpen', true)
            ->assertSee('Ist derselbe wie');
        // ähnliche Namen zur Auswahl, keine gleichnamigen Personen, nichts Fremdes
        $this->assertEqualsCanonicalizing(['DJ Bobo', 'Bobo'], $page->viewData('mergeCandidates')->pluck('name')->all());
        $this->assertSame(['ABBA'], $page->set('mergeQ', 'ab')->viewData('mergeCandidates')->pluck('name')->all());
        $page->set('mergeQ', '');
        $page->set('mergeIds', [(string) $other->id, (string) $third->id, (string) $person->id])
            ->call('merge')
            ->assertSee('jetzt Schreibweise von „DJ BoBo“')
            ->assertSee('Ausgelassen (gleichnamige Person');

        $target->refresh();
        $this->assertSame('bobo-mbid', $target->mbid);
        $this->assertSame('Eurodance', $target->default_genre);
        $this->assertSame('DJ BoBo.jpg', $target->image);
        $this->assertFileExists($base.'/_artists/DJ BoBo.jpg');
        // „DJ Bobo“ unterscheidet sich nur in Groß/klein – erkennt das Verzeichnis ohnehin, keine eigene Schreibweise
        $this->assertEqualsCanonicalizing(['Bobo', 'D.J. BoBo'], $target->aliases()->pluck('alias')->all());
        $this->assertSame(['René Baumann'], $target->rejectedAliases()->pluck('alias')->all());
        $this->assertFalse(Artist::whereIn('id', [$other->id, $third->id])->exists());
        $this->assertTrue(Artist::whereKey($person->id)->exists());

        // Verzeichnis erkennt die alten Namen jetzt als Variante
        $this->assertSame(['status' => 'variant', 'canonical' => 'DJ BoBo'], app(ArtistDirectory::class)->check('DJ Bobo'));
        $this->assertSame(['status' => 'variant', 'canonical' => 'DJ BoBo'], app(ArtistDirectory::class)->check('D.J. BoBo'));
        File::deleteDirectory($base);
    }
}
