<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** Einstellungen aus der Datenbank; Zugangsdaten werden verschlüsselt gespeichert. */
class Settings
{
    /** Zugangsdaten anderer Plattformen: Schlüssel => [Bezeichnung, Hinweis, Link] */
    public const SECRETS = [
        'discogs_token' => ['Discogs-Token', 'Persönliches Token für Album- und Interpretendaten.', 'https://www.discogs.com/settings/developers'],
        'spotify_client_id' => ['Spotify Client-ID', 'App im Spotify-Entwickler-Dashboard anlegen.', 'https://developer.spotify.com/dashboard'],
        'spotify_client_secret' => ['Spotify Client-Secret', 'Gehört zur Client-ID.', 'https://developer.spotify.com/dashboard'],
        'fanart_key' => ['Fanart.tv-API-Key', 'Interpretenbilder.', 'https://fanart.tv/get-an-api-key/'],
        'audiodb_key' => ['TheAudioDB-API-Key', 'Interpretenbilder, Genres, Biografie – leer = freier Test-Key.', 'https://www.theaudiodb.com/free_music_api'],
        'lastfm_api_key' => ['Last.fm-API-Key', 'Hörerzahlen, korrigierte Schreibweise und Tags der Interpreten.', 'https://www.last.fm/api/account/create'],
        'lastfm_secret' => ['Last.fm Shared Secret', 'Gehört zum API-Key (für spätere Funktionen mit Anmeldung).', 'https://www.last.fm/api/accounts'],
        'acoustid_key' => ['AcoustID-Anwendungsschlüssel', 'Lieder per Fingerabdruck erkennen.', 'https://acoustid.org/new-application'],
        'anthropic_api_key' => ['Anthropic-API-Key', 'Für Claude: Vorschläge bei Encoding-Fehlern (Sonstiges).', 'https://console.anthropic.com/settings/keys'],
    ];

    /** Einstellungen › Sonstiges: Schlüssel => [Bezeichnung, Hinweis, Standard, kleinster, größter Wert] (ganze Zahlen) */
    public const OTHER = [
        'cover_min_px' => ['Mindestgröße Cover (px)', 'Kürzere Seite des Covers – kleinere meldet Alben › Cover zu klein.', 500, 50, 5000],
        'genre_share' => ['Anteil Hauptgenre (%)', 'Ab diesem Anteil zählt ein Hauptgenre zum Interpreten – sonst meldet Sonstiges › Genre weicht ab.', 15, 1, 100],
    ];

    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->cache ??= Setting::pluck('value', 'key')->all();
        $value = $this->cache[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (isset(self::SECRETS[$key])) {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return $default; // mit anderem App-Schlüssel gespeichert
            }
        }

        return $value;
    }

    /** Wert aus Einstellungen › Sonstiges (Standard, wenn nicht gesetzt) */
    public function other(string $key): int
    {
        [, , $default] = self::OTHER[$key];

        return (int) $this->get($key, $default);
    }

    /** @param  array<string, mixed>  $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            $value = $value === null ? null : trim((string) $value);
            $stored = $value === null || $value === '' ? null : (isset(self::SECRETS[$key]) ? Crypt::encryptString($value) : $value);
            Setting::updateOrCreate(['key' => $key], ['value' => $stored]);
        }
        $this->cache = null;
    }
}
