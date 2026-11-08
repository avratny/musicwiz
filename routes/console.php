<?php

use App\Support\Scanner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('musicwiz:scan {location : inbox, library oder blackhole} {--force : auch entfernen, wenn auffällig viele Dateien fehlen}', function (Scanner $scanner) {
    $location = $this->argument('location');
    if (! in_array($location, ['inbox', 'library', 'blackhole'], true)) {
        $this->error('inbox, library oder blackhole');

        return 1;
    }
    $start = microtime(true);
    Scanner::clearCancel();
    $result = $scanner->scan($location, (bool) $this->option('force'));
    if ($result['cancelled'] ?? false) {
        $this->warn('Abgebrochen (über die App) – Gelesenes bleibt, der nächste Scan macht weiter.');

        return 1;
    }
    $this->info(sprintf('%d Dateien, %d gelesen, %d entfernt (%.1fs)', $result['files'], $result['read'], $result['removed'], microtime(true) - $start));
    if ($result['held']) {
        $this->warn($result['held'].' bekannte Dateien fehlen und wurden nicht entfernt. Mit --force trotzdem entfernen.');
    }
    if ($result['unreadable']) {
        $this->warn($result['unreadable'].' Ordner nicht lesbar – darunter bleibt der Index unverändert.');
    }
})->purpose('Inbox oder Bestand einlesen (wie der Knopf in der App)');
