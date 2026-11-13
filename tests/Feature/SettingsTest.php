<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_keys_are_saved_encrypted_and_shown_again(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('secrets.discogs_token', '  geheimes-token  ')->set('secrets.anthropic_api_key', 'sk-ant-test')
            ->call('save')->assertSee('Gespeichert');

        $this->assertSame('geheimes-token', app(Settings::class)->get('discogs_token'));
        $this->assertStringNotContainsString('geheimes-token', (string) Setting::find('discogs_token')->value, 'verschlüsselt gespeichert');
        Livewire::test(SettingsPage::class)->assertSet('secrets.discogs_token', 'geheimes-token')->assertSet('secrets.fanart_key', '');

        Livewire::test(SettingsPage::class)->set('secrets.discogs_token', '')->call('save');
        $this->assertNull(app(Settings::class)->get('discogs_token'), 'leer = entfernt');
    }

    public function test_page_shows_folders_and_is_in_the_navigation(): void
    {
        $this->get('/einstellungen')->assertOk()->assertSee('Zugangsdaten')->assertSee('Discogs-Token')->assertDontSee('Parallele Leser');
        $this->get('/einstellungen?tab=ordner')->assertOk()->assertSee('Interpretenbilder')->assertSee('Parallele Leser')->assertSee('Dateinamen')
            ->assertDontSee('Discogs-Token');
        $this->get('/lib')->assertSee(route('settings'), false);
    }
}
