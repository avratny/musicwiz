<?php

namespace Tests\Feature;

use App\Livewire\TextRepairAssistant;
use App\Models\CleanupException;
use App\Models\Operation;
use App\Support\CleanupChecks;
use App\Support\OperationRunner;
use App\Support\Scanner;
use App\Support\Settings;
use App\Support\TagTool;
use App\Support\TextRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** Kaputte Zeichen in Tags erkennen und über den Zeichen-Assistenten richten. */
class TextRepairTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/text-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->base.'/inbox');
        app(Settings::class)->set(['base' => $this->base, 'inbox' => $this->base.'/inbox', 'trash' => $this->base.'/_papierkorb', 'scan_throttle_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function mp3(string $rel, array $tags): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 40));
        app(TagTool::class)->write([['path' => $path, 'tags' => $tags]]);

        return $path;
    }

    private function tags(string $path): array
    {
        return app(TagTool::class)->read([$path])[$path]['tags'];
    }

    public function test_detection(): void
    {
        $safe = fn ($s) => TextRepair::analyze($s)['safe'] ?? null;
        $this->assertSame('König der Löwen', $safe('KÃ¶nig der LÃ¶wen'));
        $this->assertSame('Don’t Stop', $safe('Donâ€™t Stop'));
        $this->assertSame('Straße', $safe('StraÃŸe'));
        $this->assertSame('über', $safe("Ã\u{0083}Â¼ber"), 'doppelt kaputt');
        $this->assertSame('Hallo Welt', $safe("Hallo\u{00A0}Welt\u{200B}  "));
        $this->assertSame("\u{00FC}ber", $safe("u\u{0308}ber"), 'Mac-Unicode-Form');

        foreach (['Björk', 'Beyoncé', 'Sigur Rós', 'São Paulo', 'Motörhead', 'Œuvre', 'Côte d’Azur', 'AC/DC', 'What?'] as $ok) {
            $this->assertNull(TextRepair::analyze($ok), $ok);
        }

        $cyr = TextRepair::analyze('ÊÈÍÎ - Ãðóïïà êðîâè');
        $this->assertSame(['codepage'], $cyr['problems']);
        $this->assertNull($cyr['safe'], 'Codepage nie automatisch');
        $this->assertSame('КИНО - Группа крови', $cyr['suggestions'][0]['value']);
        $this->assertSame('Κινηματογραφος', TextRepair::analyze('Êéíçìáôïãñáöïò')['suggestions'][0]['value']);

        $lost = TextRepair::analyze('M?tley Cr?e');
        $this->assertSame(['lost'], $lost['problems']);
        $this->assertSame(['Mötley Crüe'], TextRepair::similarKnown('M?tley Cr?e', ['Mötley Crüe', 'Mötley', 'M?tley Cr?e']));
    }

    public function test_cleanup_check_fixes_only_safe_values(): void
    {
        $safe = $this->mp3('library/artists/K/Kino/A/01.mp3', ['artist' => 'KÃ¶nig', 'title' => 'Lied', 'album' => 'A', 'comment' => 'LIB']);
        $this->mp3('library/artists/K/Kino/B/01.mp3', ['artist' => 'ÊÈÍÎ', 'title' => 'Ãðóïïà êðîâè', 'album' => 'B', 'comment' => 'LIB']);
        app(Scanner::class)->scan('library');

        $findings = collect(app(CleanupChecks::class)->findings('encoding'))->keyBy('title');
        $this->assertCount(2, $findings);
        $this->assertSame([['type' => 'apply', 'path' => $safe, 'tags' => ['artist' => 'König']]], $findings->first(fn ($f) => str_contains($f['detail'], 'KÃ¶nig'))['actions']);
        $codepage = $findings->first(fn ($f) => str_contains($f['detail'], 'Zeichen-Assistenten'));
        $this->assertSame([], $codepage['actions']);
    }

    public function test_assistant_flow_with_preview_keep_custom_and_undo(): void
    {
        $lib = $this->mp3('library/artists/K/Kino/A/01.mp3', ['artist' => 'KÃ¶nig', 'artists' => ['KÃ¶nig'], 'title' => 'Ãðóïïà êðîâè', 'album' => 'A', 'comment' => 'LIB']);
        $inbox = $this->mp3('inbox/X/01.mp3', ['artist' => 'KÃ¶nig', 'title' => 'M?tley', 'album' => 'Ãëàâíîå']);
        app(Scanner::class)->scan('library');
        app(Scanner::class)->scan('inbox');

        $page = Livewire::test(TextRepairAssistant::class)
            ->assertSee('KÃ¶nig')->assertSee('König')->assertSee('Группа крови')->assertSee('Главное')
            ->assertSee('Zeichen verloren');
        $items = collect($page->instance()->choice);
        $id = fn ($group, $value) => md5($group.'|'.$value);
        $this->assertSame('o0', $items[$id('artist', 'KÃ¶nig')], 'sicher: vorausgewählt');
        $this->assertSame('o0', $items[$id('title', 'Ãðóïïà êðîâè')], 'ein plausibler Codepage-Vorschlag: vorausgewählt');
        $this->assertSame('skip', $items[$id('title', 'M?tley')]);
        // „Eigene Eingabe“ ist mit dem Text vorbelegt (Vorschlag bzw. bisheriger Wert), nicht nur Platzhalter
        $this->assertSame('König', $page->instance()->custom[$id('artist', 'KÃ¶nig')]);
        $this->assertSame('M?tley', $page->instance()->custom[$id('title', 'M?tley')]);

        $page->set('choice.'.$id('album', 'Ãëàâíîå'), 'keep')
            ->set('choice.'.$id('title', 'M?tley'), 'custom')->set('custom.'.$id('title', 'M?tley'), 'Mötley')
            ->call('previewChanges')
            ->assertSee('Vorschau: 3 Werte, 2 Dateien')
            ->call('confirm');

        $op = Operation::where('type', 'repair')->sole();
        $this->assertSame('done', $op->fresh()->status, (string) $op->fresh()->error);
        $this->assertSame('König', $this->tags($lib)['artist']);
        $this->assertSame(['König'], $this->tags($lib)['artists']);
        $this->assertSame('Группа крови', $this->tags($lib)['title']);
        $this->assertSame('König', $this->tags($inbox)['artist']);
        $this->assertSame('Mötley', $this->tags($inbox)['title']);
        $this->assertSame('Ãëàâíîå', $this->tags($inbox)['album'], 'so gelassen');
        $this->assertTrue(CleanupException::where('check', 'encoding')->where('item_key', 'album|Ãëàâíîå')->exists());

        // danach: nichts mehr offen, das Gelassene bleibt ausgeblendet
        Livewire::test(TextRepairAssistant::class)->assertSee('Keine kaputten Zeichen')->assertSee('Als richtig markiert (1)');

        OperationRunner::dispatchUndo($op);
        $this->assertSame('KÃ¶nig', $this->tags($lib)['artist']);
        $this->assertSame('Ãðóïïà êðîâè', $this->tags($lib)['title']);
    }
}
