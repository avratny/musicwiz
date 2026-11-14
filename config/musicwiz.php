<?php

return [
    // Die vier Bereiche: je ein Ordner, getrennt einlesbar
    'locations' => [
        'inbox' => env('MUSIC_INBOX', '/music/inbox'),
        'library' => env('MUSIC_LIBRARY', '/music/library'),
        'blackhole' => env('MUSIC_BLACKHOLE', '/music/blackhole'),
        'trash' => env('MUSIC_TRASH', '/music/_papierkorb'),
    ],

    // Zwischenspeicher: verkleinerte Cover/Interpretenbilder und Vorschauen aus dem Abgleich (Tests: eigene Ordner)
    'cover_cache' => storage_path('app/covers'),
    // neu gesetzte Cover im Original (bis sie in die Dateien geschrieben werden)
    'cover_store' => storage_path('app/cover-store'),
    'preview_dir' => storage_path('app/artist-previews'),

    // Künstlerbilder (Dateiname = Künstlername, z. B. „a-ha.jpg“)
    'artist_images' => env('MUSIC_ARTIST_IMAGES', '/music/_artists'),

    // Verschieben immer selbst kopieren (wie zwischen Shares) – nur für Tests
    'copy_moves' => false,

    // Badges (offene Punkte) nach der Antwort rechnen statt die Seite warten zu lassen – Tests rechnen sofort
    'counts_in_background' => (bool) env('COUNTS_IN_BACKGROUND', true),

    // Interpreten › Eindeutig/Wahrscheinlich/Schreibweise: so lange gilt die gerechnete Liste (Sekunden, 0 = immer neu)
    'list_cache_seconds' => (int) env('LIST_CACHE_SECONDS', 120),

    // nur MP3 und FLAC – andere Formate werden nicht eingelesen
    'audio_extensions' => ['mp3', 'flac'],

    // parallele Leseprozesse beim Einlesen (übers Netzwerk ruhig mehr als CPU-Kerne)
    'scan_workers' => (int) env('MUSIC_SCAN_WORKERS', 8),

    // Abgleich der Interpreten: Abfragen je Minute und Dienst (Rate-Limits der Dienste – lieber zu vorsichtig)
    'lookup_rates' => [
        'musicbrainz' => (int) env('LOOKUP_RATE_MUSICBRAINZ', 55),  // Regel: höchstens 1 je Sekunde
        'spotify' => (int) env('LOOKUP_RATE_SPOTIFY', 100),         // kein festes Limit, bei 429 gilt Retry-After
        'discogs' => (int) env('LOOKUP_RATE_DISCOGS', 50),          // 60 je Minute mit Token
        'audiodb' => (int) env('LOOKUP_RATE_AUDIODB', 120),
        'fanart' => (int) env('LOOKUP_RATE_FANART', 60),
        'lastfm' => (int) env('LOOKUP_RATE_LASTFM', 200),           // Regel: im Schnitt höchstens 5 je Sekunde
        'images' => (int) env('LOOKUP_RATE_IMAGES', 240),           // Vorschaubilder von den Bild-Servern
        'coverartarchive' => (int) env('LOOKUP_RATE_COVERARTARCHIVE', 60),
        'deezer' => (int) env('LOOKUP_RATE_DEEZER', 50),            // Regel: 50 je 5 Sekunden – vorsichtig
        'itunes' => (int) env('LOOKUP_RATE_ITUNES', 20),            // Regel: ca. 20 je Minute
        'lrclib' => (int) env('LOOKUP_RATE_LRCLIB', 60),            // frei, ohne festes Limit – Songtexte
    ],
    // nach dem Einlesen automatisch abgleichen (Tests: aus)
    'lookup_auto' => (bool) env('LOOKUP_AUTO', true),
    // nach dem Einlesen Lieder ohne Kategorie vorbelegen (System-Änderung; Tests: aus, außer im Kategorie-Test)
    'category_fill' => (bool) env('CATEGORY_FILL', true),
    // nach dem Einlesen eindeutige Sortiernamen selbst eintragen (System-Änderung; Tests: aus, außer im Sortiernamen-Test)
    'sortnames_auto' => (bool) env('SORTNAMES_AUTO', true),
    // nach dem Einlesen der Inbox Fingerabdrücke der neuen Lieder ermitteln (Tests: aus, außer im Fingerabdruck-Test)
    'fingerprint_inbox' => (bool) env('FINGERPRINT_INBOX', true),
    // erneut abgleichen nach … Tagen (gefunden / nicht gefunden)
    'lookup_refresh_days' => 90,
    // MusicBrainz-Dumps (Einstellungen › MusicBrainz): Verzeichnis mit LATEST und je Stand artist/release-group.tar.xz
    'musicbrainz_dumps' => env('MUSICBRAINZ_DUMPS', 'https://data.metabrainz.org/pub/musicbrainz/data/json-dumps/'),
    'lookup_retry_days' => 30,

    // Fehlen beim Einlesen mehr als dieser Anteil der bekannten Dateien, wird nichts entfernt (Share nicht eingehängt?)
    'scan_max_missing' => 0.3,

    // Schnittstelle /api (Analyse und Aktionen von außen) – ohne Token offen, mit Token nur mit Token
    'api_token' => env('API_TOKEN'),
];
