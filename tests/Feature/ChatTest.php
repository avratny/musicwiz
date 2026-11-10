<?php

namespace Tests\Feature;

use App\Livewire\ChatPanel;
use App\Models\Artist;
use App\Models\ChatConversation;
use App\Models\Operation;
use App\Support\Chat\ChatModel;
use App\Support\Chat\ChatTools;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Assistent im Chat-Overlay: liest über die Werkzeuge, Änderungen nur nach Freigabe, schreiben nie. */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        app(Settings::class)->set(['anthropic_api_key' => 'sk-test']);
        // Attrappe für Claude: liefert vorbereitete Antworten der Reihe nach und merkt sich die Anfragen
        $this->fake = new class implements ChatModel
        {
            public array $queue = [];

            public array $requests = [];

            public function create(array $params): array
            {
                $this->requests[] = $params;

                return array_shift($this->queue) ?? ['content' => [['type' => 'text', 'text' => '(leer)']], 'stop_reason' => 'end_turn', 'usage' => []];
            }
        };
        app()->instance(ChatModel::class, $this->fake);
    }

    private function reply(array $content, string $stop = 'end_turn'): array
    {
        return ['model' => 'claude-opus-5-5', 'content' => $content, 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 10000, 'cache_creation_input_tokens' => 0]];
    }

    public function test_reads_with_tools_and_shows_costs(): void
    {
        $this->fake->queue = [
            $this->reply([['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'stats', 'input' => []]], 'tool_use'),
            $this->reply([['type' => 'text', 'text' => 'Du hast **0 Titel**.']]),
        ];

        $page = Livewire::test(ChatPanel::class)->set('text', 'Wie viele Titel habe ich?')->call('send', 'Lib – /lib')
            ->assertSee('Wie viele Titel habe ich?')->assertSee('liest Zahlen zum Bestand')->assertSee('0 Titel', false)->assertSee('ca. 0,01 $');

        $c = ChatConversation::sole();
        $this->assertSame('idle', $c->status);
        $this->assertStringStartsWith('[Aktuelle Seite: Lib – /lib]', $c->messages[0]['content']);
        // Werkzeug-Ergebnis zurück an Claude, Denk-Block unverändert im Verlauf
        $this->assertSame('tool_result', $c->messages[2]['content'][0]['type']);
        $this->assertSame('sig', $c->messages[1]['content'][0]['signature']);
        $this->assertStringContainsString('"tracks":0', $c->messages[2]['content'][0]['content']);
        // Anfrage: Modell aus den Einstellungen, stabiler Anfang im Zwischenspeicher, Ausweichmodell bei Ablehnung
        $req = $this->fake->requests[0];
        $this->assertSame('claude-opus-5-5', $req['model']);
        $this->assertSame(['type' => 'ephemeral'], $req['system'][0]['cacheControl']);
        $this->assertSame('default', $req['fallbacks']);
        // Kosten: 2 × (1000 × 4 + 200 × 20 + 10000 × 0,20) / 1 Mio = 0,02 $
        $this->assertEqualsWithDelta(0.02, $c->usage['cost'], 0.0001);
        $page->assertSee('Unterhaltung ca. 0,02 $');
    }

    public function test_changes_wait_for_approval(): void
    {
        $this->fake->queue = [
            // lesen + ändern in einer Antwort: lesen läuft, ändern wartet
            $this->reply([['type' => 'text', 'text' => 'Ich lege ihn an.'],
                ['type' => 'tool_use', 'id' => 'r1', 'name' => 'artists_search', 'input' => ['q' => 'Yanou']],
                ['type' => 'tool_use', 'id' => 'w1', 'name' => 'artist_create', 'input' => ['name' => 'Yanou', 'mbid' => 'mb-y']]], 'tool_use'),
            $this->reply([['type' => 'text', 'text' => 'Erledigt.']]),
        ];
        $page = Livewire::test(ChatPanel::class)->set('text', 'Leg Yanou an')->call('send', '')
            ->assertSee('Künstler anlegen')->assertSee('„Yanou“ anlegen mit MusicBrainz-ID')->assertSee('Ausführen');
        $c = ChatConversation::sole();
        $this->assertSame('awaiting', $c->status);
        $this->assertFalse(Artist::where('name', 'Yanou')->exists(), 'erst nach Freigabe');

        $page->call('decide', 'w1', true)->assertSee('ausgeführt')->assertSee('Erledigt.');
        $c->refresh();
        $this->assertSame('idle', $c->status);
        $this->assertSame('mb-y', Artist::where('name', 'Yanou')->sole()->mbid);
        // beide Ergebnisse in einer Nachricht, in der Reihenfolge der Aufrufe
        $this->assertSame(['r1', 'w1'], array_column($c->messages[2]['content'], 'tool_use_id'));
        $this->assertNull(Operation::first(), 'nichts geschrieben');
    }

    public function test_rejected_change_is_reported_back(): void
    {
        $this->fake->queue = [
            $this->reply([['type' => 'tool_use', 'id' => 'w1', 'name' => 'artist_create', 'input' => ['name' => 'Foo']]], 'tool_use'),
            $this->reply([['type' => 'text', 'text' => 'Gut, dann nicht.']]),
        ];
        Livewire::test(ChatPanel::class)->set('text', 'Leg Foo an')->call('send', '')->call('decide', 'w1', false)
            ->assertSee('abgelehnt')->assertSee('Gut, dann nicht.');
        $c = ChatConversation::sole();
        $this->assertTrue($c->messages[2]['content'][0]['is_error']);
        $this->assertFalse(Artist::where('name', 'Foo')->exists());
    }

    public function test_no_tool_can_write_files_and_changes_need_approval(): void
    {
        $names = array_column(ChatTools::definitions(), 'name');
        $this->assertEmpty(preg_grep('/^(write|pending_write|schreiben)|trash|delete_file|replaygain|^move|_move$|operation_run/i', $names), 'kein Werkzeug zum Schreiben: '.implode(', ', $names));
        foreach (['stage_findings', 'stage_tags', 'artist_create', 'artist_split', 'combined_apply'] as $n) {
            $this->assertTrue(ChatTools::mutating($n), $n);
        }
        $this->assertFalse(ChatTools::mutating('check_findings'));
    }

    public function test_without_key_the_panel_explains_what_is_missing(): void
    {
        app(Settings::class)->set(['anthropic_api_key' => '']);
        Livewire::test(ChatPanel::class)->assertSee('fehlt der Anthropic-API-Key')->set('text', 'Hallo')->call('send', '');
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_wikipedia_and_discogs_tools(): void
    {
        config(['musicwiz.mb_pause' => 0]);
        app(Settings::class)->set(['discogs_token' => 'tok']);
        Http::fake([
            'musicbrainz.org/*' => Http::response(['relations' => [['type' => 'wikidata', 'url' => ['resource' => 'https://www.wikidata.org/wiki/Q1']]]]),
            'www.wikidata.org/*' => Http::response(['entities' => ['Q1' => ['sitelinks' => ['dewiki' => ['title' => 'Beam & Yanou']]]]]),
            'de.wikipedia.org/*' => Http::response(['query' => ['pages' => [['title' => 'Beam & Yanou', 'fullurl' => 'https://de.wikipedia.org/wiki/Beam_%26_Yanou',
                'extract' => 'Beam & Yanou war ein deutsches Trance-Projekt, bestehend aus Michael Urgacz und Yann Peifer.']]]]),
            'api.discogs.com/database/search*' => Http::response(['results' => [['id' => 12252, 'title' => 'Beam & Yanou']]]),
            'api.discogs.com/artists/12252*' => Http::response(['id' => 12252, 'name' => 'Beam & Yanou', 'realname' => 'Michael Urgacz & Yann Peifer',
                'members' => [['name' => 'Michael Urgacz', 'active' => true], ['name' => 'Yann Peifer', 'active' => true]], 'profile' => 'German trance project by [a=Beam] and [a=Yanou].']),
        ]);
        $artist = Artist::create(['name' => 'Beam & Yanou', 'mbid' => 'mb-by']);
        $tools = app(ChatTools::class);

        $wiki = json_decode($tools->run('wikipedia_article', ['artist_id' => $artist->id])['text'], true);
        $this->assertSame('Beam & Yanou', $wiki['title'], 'Artikel über die MusicBrainz-/Wikidata-Verknüpfung');
        $this->assertStringContainsString('Trance-Projekt', $wiki['text']);

        $discogs = json_decode($tools->run('discogs_artist', ['artist_id' => $artist->id])['text'], true);
        $this->assertSame(['Michael Urgacz', 'Yann Peifer'], $discogs['members']);
        $this->assertSame('German trance project by Beam and Yanou.', $discogs['profile'], 'Discogs-Auszeichnung lesbar');
        $this->assertFalse(ChatTools::mutating('wikipedia_article'));
        $this->assertFalse(ChatTools::mutating('discogs_artist'));
    }
}
