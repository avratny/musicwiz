<?php

namespace Tests\Feature;

use App\Support\ArtistGenreSuggestions;
use App\Support\Lookup;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** TheAudioDB: über die MusicBrainz-ID der richtige Künstler – Fotos, Genre/Stil, Biografie. */
class AudioDbTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_by_mbid_with_images_genre_and_bio(): void
    {
        app(Settings::class)->set(['audiodb_key' => 'premium-123']);
        Http::fake([
            'theaudiodb.com/api/v1/json/premium-123/artist-mb.php*' => Http::response(['artists' => [[
                'idArtist' => '1', 'strArtist' => 'WestBam', 'strMusicBrainzID' => 'mb-wb', 'strGenre' => 'Techno', 'strStyle' => 'Electronic', 'intMembers' => '1',
                'strArtistThumb' => 'https://r2.theaudiodb.com/thumb.jpg', 'strArtistFanart' => 'https://r2.theaudiodb.com/fanart.jpg', 'strBiographyDE' => 'Berliner DJ.',
            ]]]),
            'theaudiodb.com/api/v1/json/premium-123/search.php*' => Http::response(['artists' => [
                ['idArtist' => '7', 'strArtist' => 'Beam', 'strMusicBrainzID' => 'mb-a'], ['idArtist' => '8', 'strArtist' => 'BEAM', 'strMusicBrainzID' => 'mb-b'],
            ]]),
            '*' => Http::response([]),
        ]);
        $l = app(Lookup::class);

        $a = $l->audiodbArtist('mb-wb', 'WestBam');
        $this->assertSame(['Techno', 'Electronic', 1, 'de', 'Berliner DJ.'], [$a['genre'], $a['style'], $a['members'], $a['biography_lang'], $a['biography']]);
        $this->assertSame(['TheAudioDB: Foto', 'TheAudioDB: Fanart'], array_column($a['images'], 'source'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/premium-123/'));

        $this->assertNull($l->audiodbArtist(null, 'Beam'), 'mehrere Gleichnamige ohne MusicBrainz-ID: lieber nichts');

        // Bildvorschläge aus dem Zwischenspeicher: TheAudioDB vorne; Genre-Vorschlag aus Genre/Stil
        $this->assertSame('TheAudioDB: Foto', $l->cachedArtistImages(['WestBam'], 'mb-wb')[0]['source']);
        $proposals = app(ArtistGenreSuggestions::class)->for('WestBam', 'mb-wb', null)['proposals'];
        $this->assertContains('TheAudioDB', array_column($proposals, 'source'));
    }
}
