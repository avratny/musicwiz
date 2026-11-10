<?php

// Startwerte für die Einstellungen. Gepflegt wird alles auf der Einstellungsseite (SQLite);
// diese Werte greifen nur, solange dort nichts gespeichert ist.
return [
    'base' => env('MUSIC_BASE', '/music'),
    'inbox' => env('MUSIC_INBOX', '/music/inbox'),
    'trash' => env('MUSIC_TRASH', '/music/_papierkorb'),
    'artist_images' => env('MUSIC_ARTIST_IMAGES', '/music/_artists'),

    // feste Kategorien je Bereich (Lib, Blackhole): Name = Wert im Kommentar-Tag, Zielordner unter der Basis, Muster
    'categories' => [
        'lib' => ['name' => 'LIB', 'folder' => 'library/artists', 'pattern' => '{buchstabe}/{ordner_interpret}/{album} ({jahr})/{nr} - {titel}.{ext}'],
        'lib_compilation' => ['name' => 'LIB COMPILATION', 'folder' => 'library/sampler', 'pattern' => '{album_buchstabe}/{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}'],
        'blackhole' => ['name' => 'BLACKHOLE', 'folder' => 'blackhole/artists', 'pattern' => '{buchstabe}/{ordner_interpret}/{album} ({jahr})/{nr} - {titel}.{ext}'],
        'blackhole_compilation' => ['name' => 'BLACKHOLE COMPILATION', 'folder' => 'blackhole/sampler', 'pattern' => '{album_buchstabe}/{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}'],
    ],

    'no_album' => 'Kein Album',
    'non_letter_folder' => '0-9',
    'compilation_artist' => 'Various Artists',
    'replace_colon' => '-',
    'replace_slash' => '-',
    'id3_version' => 4,
    'scan_throttle_ms' => (int) env('MUSIC_SCAN_THROTTLE_MS', 15),
    // gleichzeitige Leseprozesse beim Scan (übers Netzwerk deutlich schneller; 1 = nacheinander)
    'scan_workers' => (int) env('MUSIC_SCAN_WORKERS', 4),
    // Pause vor dem zweiten Leseversuch einer Datei, die nicht lesbar war (Share kurz weg)
    'read_retry_ms' => (int) env('MUSIC_READ_RETRY_MS', 500),
    'small_cover_px' => 300,

    // Aufräum-Automatiken: [prüfung => [kategorie|'_other' => true]] – standardmäßig alles aus
    'auto_cleanup' => [],

    // Pause zwischen MusicBrainz-Abfragen in Sekunden (MusicBrainz erlaubt etwa eine pro Sekunde)
    'mb_pause' => 1.1,
    // so lange werden Antworten von MusicBrainz, Discogs, Deezer und Fanart.tv zwischengespeichert (Tage)
    'lookup_cache_days' => (int) env('MUSIC_LOOKUP_CACHE_DAYS', 90),

    'discogs_token' => env('DISCOGS_TOKEN', ''),
    'spotify_client_id' => env('SPOTIFY_CLIENT_ID', ''),
    'spotify_client_secret' => env('SPOTIFY_CLIENT_SECRET', ''),
    'audiodb_key' => env('AUDIODB_KEY', ''),
    'anthropic_api_key' => env('ANTHROPIC_API_KEY', ''),
    'chat_model' => env('MUSICWIZ_CHAT_MODEL', 'claude-opus-5-5'),
    'fanart_key' => env('FANART_KEY', ''),
    // AcoustID (Titel per Fingerabdruck erkennen): kostenloser Anwendungs-Schlüssel von https://acoustid.org/new-application
    'acoustid_key' => env('ACOUSTID_KEY', ''),
    // Inbox beobachten: neue Dateien automatisch einlesen (nur lesen) – standardmäßig aus
    'inbox_watch' => false,
    'inbox_watch_seconds' => 60,
    // Qobuz-Shop für „Bei Qobuz suchen“ (Land-Sprache wie in der Qobuz-Adresse)
    'qobuz_store' => env('QOBUZ_STORE', 'de-de'),
    // Umgedrehte Namen richtig herum: off | articles („Crystals, The“ → „The Crystals“) | persons (auch „Berry, Chuck“ → „Chuck Berry“)
    'invert_names' => 'articles',

    'tagtool' => env('MUSIC_TAGTOOL', base_path('tools/tagtool.py')),
    // YouTube-Download (yt-dlp, braucht ffmpeg)
    'ytdlp' => env('MUSIC_YTDLP', 'yt-dlp'),
    // Grenzen gegen Drosselung/Sperre: Pause zwischen Videos (zufällig min–max s), zwischen Anfragen (s),
    // Bandbreite (MB/s) und Titel pro Tag – jeweils 0 = aus
    'yt_sleep_min' => 5,
    'yt_sleep_max' => 15,
    'yt_sleep_requests' => 1,
    'yt_rate_mb' => 5,
    'yt_daily_limit' => 100,
    'audio_extensions' => ['mp3', 'flac', 'm4a', 'ogg', 'opus', 'wma', 'wav', 'aiff'],
    'junk_files' => ['.DS_Store', 'DS_Store', 'Thumbs.db', 'desktop.ini', '._.DS_Store'],
];
