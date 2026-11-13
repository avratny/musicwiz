<?php

namespace Tests\Feature;

use App\Models\TagChange;
use App\Models\Track;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        config(['musicwiz.api_token' => self::TOKEN]);
        $this->mp3('library/ABBA/Gold/01.mp3', ['TIT2' => 'Waterloo', 'TPE1' => 'ABBA', 'TALB' => 'Gold']);
        $this->mp3('library/ABBA/Gold/02.mp3', ['TIT2' => 'SOS', 'TPE1' => 'ABBA', 'TALB' => 'Gold']);
        app(Scanner::class)->scan('library');
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    private function api(): static
    {
        return $this->withToken(self::TOKEN);
    }

    private function track(string $title): Track
    {
        return Track::where('title', $title)->firstOrFail();
    }

    public function test_with_token_everything_but_the_overview_needs_it(): void
    {
        $this->getJson('/api')->assertOk()->assertJsonPath('ui.track-table.mount', ['slug: string'])
            ->assertJsonPath('ui.track-table.actions.rateTrack.params', ['id: int', 'stars: int']);
        $this->getJson('/api/tracks')->assertUnauthorized();
        $this->withToken('falsch')->getJson('/api/tracks')->assertUnauthorized();
        $this->flushHeaders();
        $this->withHeader('X-Api-Token', self::TOKEN)->getJson('/api/tracks')->assertOk()->assertJsonPath('total', 2);

    }

    public function test_without_token_the_api_is_open(): void
    {
        config(['musicwiz.api_token' => null]);
        $this->getJson('/api/tracks')->assertOk()->assertJsonPath('total', 2);
        $this->postJson('/api/tracks/'.$this->track('SOS')->id, ['tags' => ['year' => '1975']])->assertOk();
    }

    public function test_read_tracks_status_and_changes(): void
    {
        $this->api()->getJson('/api/tracks?q=water')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('tracks.0.title', 'Waterloo');
        $id = $this->track('SOS')->id;
        $this->api()->getJson('/api/tracks/'.$id)->assertOk()->assertJsonPath('track.title', 'SOS')->assertJsonPath('artists.0.name', 'ABBA');
        $this->api()->getJson('/api/status')->assertOk()->assertJsonPath('counts.library', 2);
        $this->api()->getJson('/api/artists?q=abb')->assertOk()->assertJsonPath('artists.0.name', 'ABBA');
    }

    public function test_change_and_revert_tags_like_by_hand(): void
    {
        $id = $this->track('SOS')->id;

        $this->api()->postJson('/api/tracks/'.$id, ['tags' => ['year' => '1975']])->assertOk()->assertJsonPath('changed.year', '1975');
        $change = TagChange::sole();
        $this->assertSame(['year', null, '1975', 'manual', 'über die Schnittstelle'], [$change->field, $change->old_value, $change->new_value, $change->source, $change->reason]);
        $this->api()->getJson('/api/changes?field=year')->assertJsonPath('total', 1)->assertJsonPath('changes.0.track.title', 'SOS');

        $this->api()->postJson('/api/tracks/'.$id, ['tags' => ['path' => '/etc/passwd']])->assertStatus(422);
        $this->api()->postJson('/api/tracks/'.$id.'/revert', ['fields' => ['year']])->assertOk()->assertJsonPath('reverted', ['year']);
        $this->assertNull($this->track('SOS')->year);
        $this->assertSame(0, TagChange::count());
    }

    public function test_ui_shows_page_and_runs_actions_like_a_click(): void
    {
        $this->api()->getJson('/api/ui/track-table?mount[slug]=lib')->assertOk()
            ->assertJsonPath('data.location', 'library')->assertSee('Waterloo');

        $id = $this->track('SOS')->id;
        $this->api()->postJson('/api/ui/track-table', ['mount' => ['slug' => 'lib'], 'call' => [['method' => 'rateTrack', 'params' => [$id, 4]]]])
            ->assertOk()->assertJsonPath('returns', [null]);
        $this->assertSame(4, $this->track('SOS')->rating);

        $this->api()->postJson('/api/ui/track-table', ['mount' => ['slug' => 'lib'], 'call' => [['method' => 'delete']]])->assertStatus(422);
        $this->api()->getJson('/api/ui/gibts-nicht')->assertStatus(422);
        $this->api()->getJson('/api/ui/track-table?mount[slug]=xyz')->assertNotFound();
    }

    public function test_ui_never_returns_secrets(): void
    {
        app(Settings::class)->set(['discogs_token' => 'geheim123']);

        $response = $this->api()->getJson('/api/ui/settings-page?html=1')->assertOk()->assertJsonPath('data.secrets.discogs_token', '***');
        $this->assertStringNotContainsString('geheim123', json_encode($response->json('data')));
    }

    public function test_sql_only_reads(): void
    {
        $this->api()->getJson('/api/sql?q='.urlencode('SELECT 1 + 1 AS n'))->assertOk()->assertJsonPath('rows.0.n', 2);
        $this->api()->getJson('/api/sql?q='.urlencode('DELETE FROM tracks'))->assertStatus(422);
        $this->api()->getJson('/api/sql?q='.urlencode('SELECT 1; DELETE FROM tracks'))->assertStatus(422);
        $this->api()->getJson('/api/sql?q='.urlencode('SELECT * FROM gibts_nicht'))->assertStatus(422);
        $this->assertSame(2, Track::count());
    }
}
