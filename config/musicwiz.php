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
        'lib_compilation' => ['name' => 'LIB COMPILATION', 'folder' => 'library/sampler', 'pattern' => '{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}'],
        'blackhole' => ['name' => 'BLACKHOLE', 'folder' => 'blackhole/artists', 'pattern' => '{buchstabe}/{ordner_interpret}/{album} ({jahr})/{nr} - {titel}.{ext}'],
        'blackhole_compilation' => ['name' => 'BLACKHOLE COMPILATION', 'folder' => 'blackhole/sampler', 'pattern' => '{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}'],
    ],

    'no_album' => 'Kein Album',
    'non_letter_folder' => '0-9',
    'compilation_artist' => 'Various Artists',
    'replace_colon' => '-',
    'replace_slash' => '-',
    'id3_version' => 4,
    'scan_throttle_ms' => (int) env('MUSIC_SCAN_THROTTLE_MS', 15),
    'small_cover_px' => 300,

    // Aufräum-Automatiken: [prüfung => [kategorie|'_other' => true]] – standardmäßig alles aus
    'auto_cleanup' => [],

    // Pause zwischen MusicBrainz-Abfragen in Sekunden (MusicBrainz erlaubt etwa eine pro Sekunde)
    'mb_pause' => 1.1,

    'discogs_token' => env('DISCOGS_TOKEN', ''),
    'fanart_key' => env('FANART_KEY', ''),

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
