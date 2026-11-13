<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/** Antworten aller Dienste für „a-ha“ (MusicBrainz verknüpft Spotify und Discogs), Bilder als kleines JPEG. */
trait FakesLookupServices
{
    protected const MBID = '7364dea6-ca9a-48e3-be01-b44ad0d19897';

    protected function fakeServices(): void
    {
        $jpeg = (function () {
            ob_start();
            imagejpeg(imagecreatetruecolor(500, 500));

            return ob_get_clean();
        })();
        Http::fake([
            'musicbrainz.org/ws/2/artist/'.self::MBID.'*' => Http::response(['id' => self::MBID, 'name' => 'a‐ha', 'type' => 'Group', 'country' => 'NO',
                'disambiguation' => 'Norwegian synth-pop band', 'life-span' => ['begin' => '1982'], 'genres' => [['name' => 'synth-pop', 'count' => 5]],
                'aliases' => [['name' => 'A-Ha', 'type' => 'Search hint'], ['name' => 'Aha', 'type' => 'Search hint'], ['name' => 'Morten Harket Band', 'type' => 'Legal name']],
                'relations' => [['url' => ['resource' => 'https://open.spotify.com/artist/2jzc5TC5TVFLXQlBNiIUzE']], ['url' => ['resource' => 'https://www.discogs.com/artist/37757-A-ha']]]]),
            'musicbrainz.org/ws/2/artist*' => Http::response(['artists' => [['id' => self::MBID, 'name' => 'a‐ha', 'score' => 100], ['id' => 'x', 'name' => 'Aha Shake', 'score' => 50]]]),
            'accounts.spotify.com/*' => Http::response(['access_token' => 'tkn']),
            'api.spotify.com/v1/artists/2jzc5TC5TVFLXQlBNiIUzE' => Http::response(['id' => '2jzc5TC5TVFLXQlBNiIUzE', 'name' => 'a-ha',
                'images' => [['url' => 'https://i.scdn.co/big.jpg', 'width' => 640, 'height' => 640], ['url' => 'https://i.scdn.co/small.jpg', 'width' => 320, 'height' => 320]],
                'external_urls' => ['spotify' => 'https://open.spotify.com/artist/2jzc5TC5TVFLXQlBNiIUzE']]),
            'api.discogs.com/artists/37757' => Http::response(['id' => 37757, 'name' => 'a-ha', 'profile' => 'Norwegian [b]band[/b].', 'namevariations' => ['A-Ha', 'A Ha'],
                'images' => [['type' => 'primary', 'uri' => 'https://i.discogs.com/1.jpg', 'uri150' => 'https://i.discogs.com/1s.jpg']]]),
            'www.theaudiodb.com/*' => Http::response(['artists' => [['idArtist' => '113130', 'strArtist' => 'a-ha', 'strMusicBrainzID' => self::MBID, 'strGenre' => 'Pop',
                'strArtistThumb' => 'https://r2.theaudiodb.com/t.jpg', 'strBiographyDE' => 'Norwegische Band.']]]),
            'webservice.fanart.tv/*' => Http::response(['name' => 'a‐ha', 'artistthumb' => [['url' => 'https://assets.fanart.tv/fanart/a.jpg', 'likes' => '3']]]),
            'i.scdn.co/*' => Http::response($jpeg), 'i.discogs.com/*' => Http::response($jpeg), 'r2.theaudiodb.com/*' => Http::response($jpeg), 'assets.fanart.tv/*' => Http::response($jpeg),
        ]);
    }
}
