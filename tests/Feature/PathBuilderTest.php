<?php

namespace Tests\Feature;

use App\Support\PathBuilder;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PathBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function target(string $category, array $tags, string $source = '/in/x.flac', ?bool $multi = null): string
    {
        $settings = app(Settings::class)->override(['base' => '/music']);
        $builder = new PathBuilder($settings);

        return $builder->target($settings->category($category), $tags, $multi ?? PathBuilder::isMultiDisc([$tags]), $source);
    }

    public function test_lib_pattern(): void
    {
        $this->assertSame('/music/library/artists/A/ABBA/Waterloo (1974)/09 - Waterloo.flac',
            $this->target('LIB', ['artist' => 'ABBA', 'albumartist' => 'ABBA', 'album' => 'Waterloo', 'year' => '1974-03-04', 'track' => '9', 'title' => 'Waterloo']));
    }

    public function test_folder_artist_is_first_artist(): void
    {
        $this->assertSame('/music/library/artists/Y/Yanou/Sunrise (2006)/05 - King of My Castle.mp3',
            $this->target('LIB', ['artist' => 'Yanou feat. Liz', 'album' => 'Sunrise', 'year' => '2006', 'track' => '5', 'title' => 'King of My Castle'], '/in/a.MP3'));
        $this->assertSame('/music/library/artists/Y/Yanou/S/01 - T.flac',
            $this->target('LIB', ['artist' => 'Liz', 'albumartists' => ['Yanou'], 'album' => 'S', 'track' => '1', 'title' => 'T']));
    }

    public function test_missing_year_album_and_track(): void
    {
        $this->assertSame('/music/library/artists/A/ABBA/Kein Album/Waterloo.flac',
            $this->target('LIB', ['artist' => 'ABBA', 'album' => '', 'year' => '1974', 'track' => '', 'title' => 'Waterloo']));
    }

    public function test_multi_disc_and_compilation(): void
    {
        $this->assertSame('/music/library/sampler/Bravo Hits 39 (2002)/2-04 - Kim Sozzi - Break Up.mp3',
            $this->target('LIB COMPILATION', ['artist' => 'Kim Sozzi', 'album' => 'Bravo Hits 39', 'year' => '2002', 'track' => '4', 'disc' => '2', 'disctotal' => '2', 'title' => 'Break Up'], '/in/x.mp3'));
        $this->assertSame('/music/blackhole/sampler/X/01 - A - B.mp3',
            $this->target('BLACKHOLE COMPILATION', ['artist' => 'A', 'album' => 'X', 'track' => '1/12', 'disc' => '1/1', 'title' => 'B'], '/in/x.mp3'));
    }

    public function test_special_characters_and_letters(): void
    {
        $this->assertSame('/music/library/artists/A/AC-DC/Back In Black (1980)/01 - Hells Bells.flac',
            $this->target('LIB', ['artist' => 'AC/DC', 'album' => 'Back In Black', 'year' => '1980', 'track' => '1', 'title' => 'Hells Bells']));
        $this->assertSame('/music/library/artists/0-9/2 Unlimited/La Mer- Vom Tag/01 - T.flac',
            $this->target('LIB', ['artist' => '2 Unlimited', 'album' => 'La Mer: Vom Tag', 'track' => '1', 'title' => 'T']));
        $this->assertSame('/music/library/artists/O/Öffi/A/01 - T.flac',
            $this->target('LIB', ['artist' => 'Öffi', 'album' => 'A', 'track' => '1', 'title' => 'T']));
        $this->assertSame('/music/library/artists/T/The Beatles/A/01 - T.flac',
            $this->target('LIB', ['artist' => 'The Beatles', 'album' => 'A', 'track' => '1', 'title' => 'T']));
    }

    public function test_leading_dots_empty_values_and_long_names(): void
    {
        // führende Punkte würden den Ordner unsichtbar machen
        $this->assertSame('/music/library/artists/0-9/38 Special/Wild-Eyed Southern Boys (1981)/01 - Hold On Loosely.flac',
            $this->target('LIB', ['artist' => '.38 Special', 'album' => 'Wild-Eyed Southern Boys', 'year' => '1981', 'track' => '1', 'title' => 'Hold On Loosely']));
        // Album bzw. Titel aus lauter unzulässigen Zeichen: wie ohne Album bzw. Dateiname als Titel
        $this->assertSame('/music/library/artists/A/ABBA/Kein Album/x.flac',
            $this->target('LIB', ['artist' => 'ABBA', 'album' => '...', 'year' => '1974', 'title' => '???']));
        // zu lange Namen werden gekürzt, die Endung bleibt
        $long = str_repeat('Sinfonie für großes Orchester ', 12);
        $path = $this->target('LIB', ['artist' => 'Mahler', 'album' => $long, 'track' => '1', 'title' => $long]);
        foreach (explode('/', $path) as $segment) {
            $this->assertLessThanOrEqual(PathBuilder::MAX_SEGMENT_BYTES, strlen($segment));
            $this->assertTrue(mb_check_encoding($segment, 'UTF-8'));
        }
        $this->assertStringEndsWith('.flac', $path);
        $this->assertStringStartsWith('/music/library/artists/M/Mahler/Sinfonie für großes Orchester', $path);
    }

    public function test_multi_disc_detection(): void
    {
        $this->assertFalse(PathBuilder::isMultiDisc([['disc' => '1', 'disctotal' => '1'], ['disc' => '1']]));
        $this->assertTrue(PathBuilder::isMultiDisc([['disc' => '1', 'disctotal' => '2']]));
        $this->assertTrue(PathBuilder::isMultiDisc([['disc' => '1'], ['disc' => '2']]));
    }
}
