<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_hidden_but_can_be_shown(): void
    {
        app(Settings::class)->set(['discogs_token' => 'geheim-123']);
        Livewire::test(SettingsPage::class)->set('tab', 'access')
            ->assertSeeHtml('type="password"')->assertSee('Anzeigen')->assertSeeHtml("show ? 'text' : 'password'")
            ->assertSet('s.discogs_token', 'geheim-123');
    }

    public function test_overlapping_or_degenerate_folders_are_rejected(): void
    {
        app(Settings::class)->set(['base' => '/music', 'inbox' => '/music/inbox', 'trash' => '/music/_papierkorb', 'artist_images' => '/music/_artists']);
        $this->assertSame([], app(Settings::class)->pathProblems(), 'Standard passt');

        // Kategorie-Ordner "." – Papierkorb und Inbox lägen in der Lib
        $page = Livewire::test(SettingsPage::class)->set('tab', 'categories')->set('s.categories.0.folder', '.')->call('save')
            ->assertHasErrors()->assertSee('Unterordner des Basisordners');
        // Papierkorb in einem Kategorie-Ordner
        $page->set('s.categories.0.folder', 'library/artists')->set('s.trash', '/music/library/artists/_papierkorb')->call('save')
            ->assertSee('Papierkorb liegt im Ordner von Kategorie');
        $this->assertSame('/music/_papierkorb', app(Settings::class)->get('trash'), 'nichts gespeichert');

        $page->set('s.trash', '/music/_papierkorb')->call('save')->assertHasNoErrors();
    }
}
