<?php

namespace Tests\Feature;

use App\Jobs\PrefetchArtistLookup;
use App\Support\Lookup;
use App\Support\ServiceThrottled;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Spotify antwortet mit 429: an „Retry-After“ halten, nicht weiter fragen und nichts als „nicht gefunden“ merken. */
class SpotifyThrottleTest extends TestCase
{
    use RefreshDatabase;

    private int $spotifyCalls = 0;

    /** Retry-After der ersten Antwort (danach antwortet Spotify normal, wenn $onlyFirst) */
    private string $retryAfter = '120';

    private bool $onlyFirst = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['musicwiz.mb_pause' => 0, 'musicwiz.spotify_pause' => 0]);
        app(Settings::class)->set(['spotify_client_id' => 'id', 'spotify_client_secret' => 'secret']);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'accounts.spotify.com')) {
                return Http::response(['access_token' => 'tok']);
            }
            if (str_contains($url, 'api.spotify.com')) {
                $this->spotifyCalls++;

                return $this->onlyFirst && $this->spotifyCalls > 1
                    ? Http::response(['tracks' => ['items' => [['name' => '9 PM', 'artists' => [['id' => 'x', 'name' => 'ATB']]]]]])
                    : Http::response(['error' => ['status' => 429]], 429, ['Retry-After' => $this->retryAfter]);
            }

            return Http::response([]);
        });
    }

    public function test_retry_after_blocks_further_requests(): void
    {
        $lookup = app(Lookup::class);
        try {
            $lookup->spotifyArtistBySongs('ATB', ['9 PM']);
            $this->fail('ServiceThrottled erwartet');
        } catch (ServiceThrottled $e) {
            $this->assertStringContainsString('Spotify bremst', $e->getMessage());
        }
        $this->assertSame(1, $this->spotifyCalls, 'lange Wartezeit: nicht sofort wiederholen');
        $this->assertNotNull(Lookup::spotifyBlockedUntil());

        // gesperrt: gar nicht mehr fragen
        $this->expectException(ServiceThrottled::class);
        try {
            $lookup->spotifyArtist('Yanou', ['King of My Castle']);
        } finally {
            $this->assertSame(1, $this->spotifyCalls);
        }
    }

    public function test_short_retry_after_is_waited_once(): void
    {
        [$this->retryAfter, $this->onlyFirst] = ['1', true];
        $hit = app(Lookup::class)->spotifyArtistBySongs('ATB', ['9 PM']);
        $this->assertSame('ATB', $hit['name']);
        $this->assertSame(2, $this->spotifyCalls);
        $this->assertNull(Lookup::spotifyBlockedUntil());
    }

    public function test_old_results_are_shown_but_fetched_again(): void
    {
        \Illuminate\Support\Facades\Cache::put('artist-prefetched:v2:'.md5('ATB'), ['hits' => [], 'services' => ['deezer' => null, 'spotify' => null]]);
        $this->assertTrue(PrefetchArtistLookup::results(['ATB'])['ATB']['stale']);
        $this->assertFalse(PrefetchArtistLookup::isFresh('ATB'));
    }

    public function test_prefetch_is_not_marked_done_while_spotify_throttles(): void
    {
        PrefetchArtistLookup::prefetch(app(Lookup::class), 'ATB', ['9 PM']);
        $this->assertNull(PrefetchArtistLookup::results(['ATB'])['ATB'], 'beim nächsten Vorladen erneut fragen');
    }
}
