<?php

namespace Tests\Feature;

use App\Jobs\AskClaudeTextRepairs;
use App\Livewire\MiscPage;
use App\Models\TagChange;
use App\Models\TextRepair;
use App\Models\Track;
use App\Support\Activity;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TextRepairAi;
use App\Support\TextRepairs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\MakesAudioFiles;
use Tests\TestCase;

/** Sonstiges › Encoding-Fehler: erkennen, sicher zurückrechnen, Vorschläge von Claude (gespeichert), wählen oder selbst schreiben */
class TextRepairsTest extends TestCase
{
    use MakesAudioFiles, RefreshDatabase;

    /** gestellte Fragen an die Claude-Attrappe */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMusicFolders();
        Cache::flush();
        $this->mp3('inbox/Sampler/01.mp3', ['TIT2' => 'Energy', 'TPE1' => 'Martin Silence', 'TPE2' => 'Various Artists', 'TALB' => "34\u{FFFD} Dance Sommerhits 2006"]);
        $this->mp3('inbox/Sampler/02.mp3', ['TIT2' => 'Rocking', 'TPE1' => 'Sandra Flynn', 'TPE2' => 'Various Artists', 'TALB' => "34\u{FFFD} Dance Sommerhits 2006"]);
        $this->mp3('inbox/König/01.mp3', ['TIT2' => 'Can You Feel the Love Tonight', 'TPE1' => 'Elton John', 'TALB' => 'Der KÃ¶nig der LÃ¶wen']);
        $this->mp3('inbox/Crue/01.mp3', ['TIT2' => "Kickstart\u{200B} My Heart", 'TPE1' => "Mo\u{0308}tley Cru\u{0308}e", 'TALB' => 'Dr. Feelgood']);
        $this->mp3('inbox/Ok/01.mp3', ['TIT2' => 'Why? Not', 'TPE1' => 'São Paulo Band', 'TALB' => 'SÃO PAULO']);
        app(Scanner::class)->scan('inbox');
        app(Settings::class)->set(['anthropic_api_key' => 'sk-test']);
    }

    protected function tearDown(): void
    {
        $this->tearDownMusicFolders();
        parent::tearDown();
    }

    /** Claude-Attrappe: liefert die angegebenen Vorschläge (oder wirft) */
    private function fakeClaude(array|RuntimeException $answer): void
    {
        $test = $this;
        $this->app->instance(TextRepairAi::class, new class(app(Settings::class), $answer, $test) extends TextRepairAi
        {
            public function __construct(Settings $settings, private array|RuntimeException $answer, private $test)
            {
                parent::__construct($settings);
            }

            protected function ask(string $value, array $fields, array $context): array
            {
                $this->test->recordQuestion($value, $fields, $context);
                if ($this->answer instanceof RuntimeException) {
                    throw $this->answer;
                }

                return [self::clean($value, $this->answer), 'claude-opus-5-5'];
            }
        });
    }

    public function recordQuestion(string $value, array $fields, array $context): void
    {
        $this->asked[] = compact('value', 'fields', 'context');
    }

    private function problem(string $contains): array
    {
        return app(TextRepairs::class)->problems()->first(fn ($p) => str_contains($p['value'], $contains));
    }

    public function test_finds_broken_values_and_repairs_the_safe_ones_without_guessing(): void
    {
        $problems = app(TextRepairs::class)->problems();
        $this->assertSame(4, $problems->count(), 'SÃO PAULO und „Why? Not“ sind in Ordnung');
        $this->assertSame(['lost'], $problems->first()['kinds'], 'verlorene Zeichen zuerst');
        $this->assertSame(['album' => 2], $this->problem('Sommerhits')['fields']);
        $this->assertNull($this->problem('Sommerhits')['rule'], 'verloren: nicht zurückzurechnen');
        $this->assertSame([['mojibake'], 'Der König der Löwen'], [$this->problem('KÃ¶nig')['kinds'], $this->problem('KÃ¶nig')['rule']]);
        $this->assertSame([['invisible'], 'Kickstart My Heart'], [$this->problem('Kickstart')['kinds'], $this->problem('Kickstart')['rule']]);
        $this->assertSame([['nfd'], 'Mötley Crüe'], [$this->problem('tley')['kinds'], $this->problem('tley')['rule']]);

        $this->get('/sonstiges')->assertOk()->assertSee('Zeichen & Schreibweise')->assertSee('Zeichen verloren')->assertSee('Album · 2 Lieder');
        Livewire::test(MiscPage::class)->call('take', $this->problem('KÃ¶nig')['hash'], 'Der König der Löwen')
            ->assertSee('„Der König der Löwen“ übernommen – bei 1 Lied');

        $this->assertSame('Der König der Löwen', Track::where('title', 'Can You Feel the Love Tonight')->value('album'));
        $change = TagChange::sole();
        $this->assertSame(['album', 'manual', 'Encoding repariert: „Der KÃ¶nig der LÃ¶wen“ → „Der König der Löwen“'], [$change->field, $change->source, $change->reason]);
        $this->assertSame(3, app(TextRepairs::class)->count());
    }

    public function test_claude_suggestions_are_stored_and_can_be_chosen(): void
    {
        $answer = [['text' => '34° Dance Sommerhits 2006', 'reason' => 'Gradzeichen, typisch für die Reihe']];
        foreach (range(1, 11) as $i) {
            $answer[] = ['text' => "34.{$i} Dance Sommerhits 2006", 'reason' => 'Ziffer'];
        }
        $answer[] = ['text' => '34° Dance Sommerhits 2006', 'reason' => 'doppelt'];
        $answer[] = ['text' => "34\u{FFFD} Dance Sommerhits 2006", 'reason' => 'unverändert'];
        $this->fakeClaude($answer);
        $hash = $this->problem('Sommerhits')['hash'];

        $page = Livewire::test(MiscPage::class)->call('ask', $hash)->assertSee('Claude hat 10 Vorschläge gemacht.')
            ->assertSee('34° Dance Sommerhits 2006')->assertSee('Gradzeichen, typisch für die Reihe')->assertSee('Neu fragen');

        // gefragt mit Feld und Zusammenhang
        $this->assertSame(['album' => 2], $this->asked[0]['fields']);
        $context = collect($this->asked[0]['context'])->firstWhere('Titel', 'Energy');
        $this->assertSame(['Energy', 'Martin Silence', 'Sampler', '01.mp3'], [$context['Titel'], $context['Interpret'], $context['Ordner'], $context['Datei']]);
        $repair = TextRepair::sole();
        $this->assertCount(10, $repair->suggestions, 'höchstens 10, ohne Doppelte und Unverändertes');
        $this->assertSame('claude-opus-5-5', $repair->model);

        $page->call('take', $hash, '34° Dance Sommerhits 2006')->assertSee('bei 2 Liedern');
        $this->assertSame(['34° Dance Sommerhits 2006'], Track::where('albumartist', 'Various Artists')->pluck('album')->unique()->values()->all());
        $this->assertNull(app(TextRepairs::class)->problems()->firstWhere('hash', $hash));
    }

    public function test_own_spelling_and_errors(): void
    {
        $hash = $this->problem('Sommerhits')['hash'];
        $page = Livewire::test(MiscPage::class);

        // eigene Schreibweise darf das verlorene Zeichen nicht mehr enthalten
        $page->set("custom.{$hash}", "34\u{FFFD} Dance")->call('take', $hash, null)->assertSee('enthält noch ein verlorenes Zeichen');
        $page->set("custom.{$hash}", '34 Dance Sommerhits 2006')->call('take', $hash, null)->assertSee('„34 Dance Sommerhits 2006“ übernommen');

        // Claude-Fehler: Meldung, gespeichert
        $this->fakeClaude(new RuntimeException('Claude antwortet mit Fehler 529: overloaded'));
        $kick = $this->problem('Kickstart')['hash'];
        $page->call('ask', $kick)->assertSee('Claude antwortet mit Fehler 529');
        $this->assertSame('Claude antwortet mit Fehler 529: overloaded', TextRepair::find($kick)->error);
    }

    public function test_without_key_only_safe_repairs_and_own_spelling(): void
    {
        app(Settings::class)->set(['anthropic_api_key' => '']);
        Livewire::test(MiscPage::class)->assertSee('Anthropic-API-Key unter')->assertDontSee('Vorschläge holen')
            ->call('ask', $this->problem('Sommerhits')['hash'])->assertSee('Kein Anthropic-API-Key in den Einstellungen');
    }

    public function test_asking_all_open_values_in_the_background(): void
    {
        $this->fakeClaude([['text' => '34° Dance Sommerhits 2006', 'reason' => 'Gradzeichen']]);
        $this->assertSame(1, app(TextRepairs::class)->unasked()->count(), 'nur verlorene Zeichen ohne Vorschläge – der Rest ist sicher reparierbar');

        $this->assertTrue(AskClaudeTextRepairs::start());   // Warteschlange in Tests: sofort

        $this->assertSame(['34° Dance Sommerhits 2006'], array_column(TextRepair::sole()->suggestions, 'text'));
        $this->assertSame('Claude hat Vorschläge für 1 Wert gemacht', Activity::current(AskClaudeTextRepairs::KEY)['message']);
        $this->assertFalse(AskClaudeTextRepairs::start(), 'nichts mehr offen');
    }
}
