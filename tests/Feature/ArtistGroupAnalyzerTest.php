<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Support\ArtistGroupAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArtistGroupAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $artist, string $title, string $album, array $genres = ['Pop']): void
    {
        static $i = 0;
        $i++;
        Track::create(['location' => 'library', 'path' => "/m/$i.mp3", 'dir' => '/m', 'filename' => "$i.mp3", 'ext' => 'mp3',
            'artist' => $artist, 'title' => $title, 'album' => $album, 'genres' => $genres, 'artists' => [], 'albumartists' => []]);
    }

    private function group(array $names): array
    {
        return ['key' => 'k', 'names' => $names, 'suggestion' => array_key_first($names), 'known' => null];
    }

    public function test_shared_songs_make_variants_likely_the_same(): void
    {
        $this->track('ABBA', 'Waterloo', 'Waterloo');
        $this->track('ABBA', 'Mamma Mia', 'ABBA');
        $this->track('Abba', 'Waterloo (Live)', 'Live');

        $a = app(ArtistGroupAnalyzer::class)->analyze($this->group(['ABBA' => 2, 'Abba' => 1]));
        $this->assertSame('ABBA', $a['main']);
        $this->assertGreaterThan(0.95, $a['variants']['Abba']['probability']);
        $this->assertSame('ABBA', $a['recommendation']);
    }

    public function test_similar_names_without_common_songs_are_unlikely(): void
    {
        $this->track('Rob Dougan', 'Clubbed to Death', 'Furious Angels', ['Electronic']);
        $this->track('Rob Duggan', 'Irish Ballad', 'Green Fields', ['Country / Folk']);

        $a = app(ArtistGroupAnalyzer::class)->analyze($this->group(['Rob Dougan' => 1, 'Rob Duggan' => 1]));
        $other = $a['main'] === 'Rob Dougan' ? 'Rob Duggan' : 'Rob Dougan';
        $this->assertLessThan(0.4, $a['variants'][$other]['probability']);
    }

    public function test_research_decides_and_suggests_musicbrainz_spelling(): void
    {
        $this->track('Everly Brothers, The', 'Wake Up Little Susie', 'Best Of');
        $this->track('Everly Brothers', 'All I Have to Do Is Dream', 'Hits');
        $this->track('Everly Btothers, The', 'Bye Bye Love', 'Sampler');
        $group = $this->group(['Everly Brothers, The' => 1, 'Everly Brothers' => 1, 'Everly Btothers, The' => 1]);
        $hit = ['name' => 'The Everly Brothers', 'mbid' => 'x', 'disambiguation' => null, 'country' => 'US', 'score' => 100, 'exact' => true];
        Cache::forever(ArtistGroupAnalyzer::researchKey($group), [
            'artists' => ['Everly Brothers, The' => $hit, 'Everly Brothers' => $hit, 'Everly Btothers, The' => null],
            'songs' => ['Everly Btothers, The' => ['Bye Bye Love' => true]],
        ]);

        $a = app(ArtistGroupAnalyzer::class)->analyze($group);
        $this->assertNotSame('Everly Btothers, The', $a['main']);
        $this->assertGreaterThan(0.6, $a['variants']['Everly Btothers, The']['probability']);
        $this->assertSame('The Everly Brothers', $a['recommendation']);
    }

    public function test_song_of_main_artist_and_unknown_variant_name_means_typo(): void
    {
        // Taucher/Tauscher: "Bizarre" ist laut MusicBrainz von Taucher, einen Künstler "Tauscher" gibt es dort nicht
        $this->track('Taucher', 'Child of the Universe', 'Formel Eins', ['Trance']);
        $this->track('Taucher', 'Pictures of a Gallery', 'DJ Convention', ['Trance']);
        $this->track('Tauscher', 'Bizarre (Radio Mix)', "Dance Club '99", ['Trance']);
        $group = $this->group(['Taucher' => 2, 'Tauscher' => 1]);
        Cache::forever(ArtistGroupAnalyzer::researchKey($group), [
            'artists' => [
                'Taucher' => ['name' => 'Taucher', 'mbid' => 't', 'disambiguation' => 'Ralph Armand Beck', 'country' => 'DE', 'score' => 100, 'exact' => true],
                'Tauscher' => ['name' => 'Herbert Tauscher', 'mbid' => 'h', 'disambiguation' => 'bassoonist', 'country' => null, 'score' => 100, 'exact' => false],
            ],
            'songs' => ['Tauscher' => ['Bizarre (Radio Mix)' => true]],
        ]);

        $v = app(ArtistGroupAnalyzer::class)->analyze($group)['variants']['Tauscher'];
        $this->assertGreaterThan(0.9, $v['probability']);
        $this->assertNotContains(['-', 'keine gemeinsamen Lieder, Alben oder Mitwirkenden'], $v['reasons']);
    }

    public function test_variant_with_own_songs_on_musicbrainz_is_a_different_artist(): void
    {
        $this->track('Rob Dougan', 'Clubbed to Death', 'Furious Angels', ['Electronic']);
        $this->track('Rob Duggan', 'Irish Ballad', 'Green Fields', ['Electronic']);
        $group = $this->group(['Rob Dougan' => 1, 'Rob Duggan' => 1]);
        Cache::forever(ArtistGroupAnalyzer::researchKey($group), [
            'artists' => [
                'Rob Dougan' => ['name' => 'Rob Dougan', 'mbid' => 'a', 'disambiguation' => null, 'country' => 'AU', 'score' => 100, 'exact' => true],
                'Rob Duggan' => ['name' => 'Rob Duggan', 'mbid' => 'b', 'disambiguation' => null, 'country' => 'IE', 'score' => 100, 'exact' => true],
            ],
            'songs' => ['Rob Duggan' => ['Irish Ballad' => false]],
            'own' => ['Rob Duggan' => ['Irish Ballad' => true]],
        ]);

        $a = app(ArtistGroupAnalyzer::class)->analyze($group);
        $other = $a['main'] === 'Rob Dougan' ? 'Rob Duggan' : 'Rob Dougan';
        $this->assertLessThan(0.1, $a['variants'][$other]['probability']);
    }
}
