<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Support\ArtistDirectory;
use App\Support\ArtistNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_only_on_semicolon_and_feat(): void
    {
        $this->assertSame(['Yanou', 'Liz'], ArtistNames::split('Yanou feat. Liz'));
        $this->assertSame(['Yanou', 'Liz'], ArtistNames::split('Yanou ft. Liz'));
        $this->assertSame(['Yanou', 'Liz'], ArtistNames::split('Yanou (featuring Liz)'));
        $this->assertFalse(ArtistNames::looksCombined('Apollo vs. Crystal Lake'), 'vs. wird automatisch getrennt, keine Rückfrage');
        // umgedrehte Namen (Sortierschreibweise) sind kein zweiter Künstler
        foreach (['Crystals, The', 'Pretenders, The', 'Shangri-Las, The', 'Berry, Chuck'] as $inverted) {
            $this->assertFalse(ArtistNames::looksCombined($inverted), $inverted);
        }
        $this->assertTrue(ArtistNames::looksCombined('Earth, Wind & Fire'), 'mehr als ein Komma bzw. & bleibt eine Kombi');
        $this->assertTrue(ArtistNames::looksCombined('Crosby, Stills, Nash'));
        $this->assertSame(['2Pac', 'Dr. Dre', 'Roger Troutman'], ArtistNames::split('2Pac; Dr. Dre; Roger Troutman'));
        $this->assertSame(['Simon & Garfunkel'], ArtistNames::split('Simon & Garfunkel'));
        $this->assertSame(['Earth, Wind & Fire'], ArtistNames::split('Earth, Wind & Fire'));
        $this->assertSame(['Rob und Chris'], ArtistNames::split('Rob und Chris'));
        $this->assertSame(['Daft Punk'], ArtistNames::split('Daft Punk'));
        $this->assertSame([], ArtistNames::split(''));
    }

    public function test_split_on_versus_but_protect_directory_names(): void
    {
        $this->assertSame(['Marc Korn', 'Trusted Playaz', 'Mel W.'], ArtistNames::split('Marc Korn vs. Trusted Playaz feat. Mel W.'));
        $this->assertSame(['Apollo', 'Crystal Lake'], ArtistNames::split('Apollo vs. Crystal Lake'));
        $this->assertSame(['Cerla', 'Manian'], ArtistNames::split('Cerla vs Manian'));
        $this->assertSame(['DJ A', 'DJ B'], ArtistNames::split('DJ A versus DJ B'));
        $this->assertSame(['Canvas Dream'], ArtistNames::split('Canvas Dream'), 'kein Trenner mitten im Wort');

        // Bandname mit "vs" im Verzeichnis wird nie zerlegt – auch nicht als Teil einer Kombi
        Artist::create(['name' => 'Spy vs Spy']);
        $this->assertSame(['Spy vs Spy'], ArtistNames::split('Spy vs Spy'));
        $this->assertSame(['Spy vs Spy', 'Guest'], ArtistNames::split('Spy vs Spy feat. Guest'));
        $this->assertSame(['spy vs spy', 'Other'], ArtistNames::split('spy vs spy vs. Other'), 'Groß/klein egal, Schreibweise aus dem Tag bleibt');
    }

    public function test_compare_key(): void
    {
        $this->assertSame(ArtistNames::compareKey('A-teens'), ArtistNames::compareKey('A. Teens'));
        $this->assertSame(ArtistNames::compareKey('7T; Mike D; Damize'), ArtistNames::compareKey('7t; Mike D.; Damize'));
        $this->assertSame(ArtistNames::compareKey('Beyoncé'), ArtistNames::compareKey('Beyonce'));
        $this->assertSame(ArtistNames::compareKey('Simon & Garfunkel'), ArtistNames::compareKey('Simon and Garfunkel'));
        $this->assertSame(ArtistNames::compareKey('The Bellamy Brothers'), ArtistNames::compareKey('Bellamy Brothers, The'));
        $this->assertNotSame(ArtistNames::compareKey('ABBA'), ArtistNames::compareKey('Abbacadabra'));
    }

    public function test_directory_suggests_canonical_spelling(): void
    {
        $dir = app(ArtistDirectory::class);
        $dir->resolve('A-Teens', ['A. Teens', 'A-teens']);

        $this->assertSame(['status' => 'variant', 'canonical' => 'A-Teens'], $dir->check('A. Teens'));
        $this->assertSame(['status' => 'similar', 'canonical' => 'A-Teens'], $dir->check('a teens'));
        $this->assertSame(['status' => 'ok', 'canonical' => 'A-Teens'], $dir->check('A-Teens'));
        $this->assertSame('new', $dir->check('Cher')['status']);
        $this->assertSame('A-Teens feat. Cher', $dir->correctText('A. Teens feat. Cher'));
    }
}
