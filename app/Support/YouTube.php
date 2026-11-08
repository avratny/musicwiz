<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Lädt Audio von YouTube per yt-dlp (mit ffmpeg) als MP3 herunter.
 * YouTube liefert Ton nur verlustbehaftet (Opus bzw. AAC, höchstens ca. 160–256 kbit/s) – daher MP3 in bester VBR-Stufe (V0).
 */
class YouTube
{
    public const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'];

    /** Unterordner in der Inbox, in dem Downloads landen. */
    public const FOLDER = 'YouTube';

    public static function isUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        return in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true);
    }

    /** yt-dlp-Rückgabewert, wenn --max-downloads erreicht ist */
    private const EXIT_MAX_DOWNLOADS = 101;

    public function __construct(private Settings $settings) {}

    /** @return array{sleep_min:int, sleep_max:int, sleep_requests:float, rate_mb:float, daily_limit:int} */
    public function limits(): array
    {
        $min = max(0, (int) $this->settings->get('yt_sleep_min'));

        return [
            'sleep_min' => $min,
            'sleep_max' => max($min, (int) $this->settings->get('yt_sleep_max')),
            'sleep_requests' => max(0, (float) $this->settings->get('yt_sleep_requests')),
            'rate_mb' => max(0, (float) $this->settings->get('yt_rate_mb')),
            'daily_limit' => max(0, (int) $this->settings->get('yt_daily_limit')),
        ];
    }

    /** Heute noch erlaubte Downloads (null = keine Tagesgrenze). */
    public function remainingToday(): ?int
    {
        $limit = $this->limits()['daily_limit'];

        return $limit ? max(0, $limit - self::countToday()) : null;
    }

    /**
     * Zähler unabhängig vom Verlauf, damit Verlauf löschen die Tagesgrenze nicht aushebelt.
     * In der Datenbank, weil Download-Worker und Oberfläche in eigenen Containern laufen.
     */
    public static function countToday(): int
    {
        return (int) Cache::store('database')->get('yt-count:'.now()->toDateString(), 0);
    }

    private static function countDownload(): void
    {
        $key = 'yt-count:'.now()->toDateString();
        Cache::store('database')->add($key, 0, now()->addDays(2));
        Cache::store('database')->increment($key);
    }

    public static function binary(): string
    {
        return config('musicwiz.ytdlp');
    }

    /**
     * @param  callable(array{index:?int, count:?int, percent:?float, title:string}):void|null  $onProgress
     * @param  ?int  $max  höchstens so viele Titel laden (Tagesgrenze)
     * @param  ?string  $archive  Liste schon geladener Videos: Fortsetzungen überspringen sie ohne erneute Anfrage
     * @return array{files: list<string>, error: ?string, limited: bool} neu angelegte Audiodateien; error bei (Teil-)Fehlschlag;
     *                                                                   limited: Grenze erreicht, es fehlen noch Titel
     */
    public function download(string $url, string $dir, bool $playlist = false, ?callable $onProgress = null, ?int $max = null, ?string $archive = null): array
    {
        if (! self::isUrl($url)) {
            throw new RuntimeException('Kein YouTube-Link: '.$url);
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Zielordner kann nicht angelegt werden: '.$dir);
        }
        // vorhandene Dateien merken: yt-dlp überspringt schon geladene Titel, die gehören dann nicht zu diesem Download
        $before = array_flip($this->audioFiles($dir));
        $printed = [];
        $errors = [];

        $process = new Process($this->command($url, $dir, $playlist, $max, $archive));
        $process->setTimeout(null);
        $buffer = '';
        $process->run(function (string $type, string $data) use (&$buffer, &$printed, &$errors, $onProgress) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (str_starts_with($line, 'MWFILE ')) {
                    $printed[] = substr($line, 7);
                    self::countDownload();
                } elseif (str_starts_with($line, 'MWPROG ') && $onProgress) {
                    [$index, $count, $percent, $title] = array_pad(explode('|', substr($line, 7), 4), 4, '');
                    $onProgress([
                        'index' => is_numeric($index) ? (int) $index : null,
                        'count' => is_numeric($count) ? (int) $count : null,
                        'percent' => is_numeric($p = trim(str_replace('%', '', $percent))) ? (float) $p : null,
                        'title' => $title,
                    ]);
                } elseif (str_starts_with($line, 'ERROR:')) {
                    $errors[] = trim(substr($line, 6));
                }
            }
        });

        $files = array_values(array_unique(array_filter($printed, fn ($f) => is_file($f) && ! isset($before[$f]))));
        $error = null;
        // yt-dlp meldet die Grenze auch, wenn genau der letzte erlaubte Titel geladen wurde – Rest gibt es nur bei Playlists
        $hitMax = $process->getExitCode() === self::EXIT_MAX_DOWNLOADS;
        $limited = $hitMax && str_contains($url, 'list=') && count($files) >= (int) $max;
        if ($hitMax) {
            $error = $errors ? implode("\n", array_slice($errors, 0, 5)) : null;
        } elseif (! $process->isSuccessful()) {
            $error = $errors ? implode("\n", array_slice($errors, 0, 5)) : (trim($process->getErrorOutput()) ?: 'yt-dlp beendet mit Code '.$process->getExitCode());
        } elseif (! $files && ! ($archive !== null && ! $printed && ! $errors)) {
            // mit Archiv und ohne Ausgabe: alles schon geladen (z. B. Fortsetzung nach erreichter Tagesgrenze) – kein Fehler
            $error = $printed ? 'Schon vorhanden – nichts Neues heruntergeladen' : 'Keine Datei heruntergeladen';
        }

        return ['files' => $files, 'error' => $error, 'limited' => $limited];
    }

    /** @return list<string> */
    public function command(string $url, string $dir, bool $playlist = false, ?int $max = null, ?string $archive = null): array
    {
        $l = $this->limits();

        return [
            self::binary(),
            '--ignore-config',
            $playlist ? '--yes-playlist' : '--no-playlist',
            '--ignore-errors',
            // schonend laden, damit YouTube nicht drosselt bzw. sperrt: Pausen zwischen Anfragen und zwischen Videos (einstellbar)
            ...($l['sleep_requests'] > 0 ? ['--sleep-requests', (string) $l['sleep_requests']] : []),
            ...($l['sleep_max'] > 0 ? ['--sleep-interval', (string) $l['sleep_min'], '--max-sleep-interval', (string) $l['sleep_max']] : []),
            ...($l['rate_mb'] > 0 ? ['--limit-rate', round($l['rate_mb'] * 1024).'K'] : []),
            ...($max !== null ? ['--max-downloads', (string) $max] : []),
            ...($archive !== null ? ['--download-archive', $archive] : []),
            '-f', 'bestaudio/best',
            '--extract-audio', '--audio-format', 'mp3', '--audio-quality', '0',
            '--embed-metadata',
            ...self::tagCleanup(),
            '--embed-thumbnail', '--convert-thumbnails', 'jpg',
            // Vorschaubild quadratisch zuschneiden, damit es als Cover taugt
            '--ppa', 'ThumbnailsConvertor+ffmpeg_o:-c:v mjpeg -vf crop="\'if(gt(ih,iw),iw,ih)\':\'if(gt(iw,ih),ih,iw)\'"',
            '--windows-filenames',
            '-P', $dir,
            '-o', '%(playlist_title|Einzeltitel)s/%(artist,uploader)s - %(track,title)s.%(ext)s',
            '--newline', '--progress',
            '--progress-template', 'download:MWPROG %(info.playlist_index)s|%(info.n_entries)s|%(progress._percent_str)s|%(info.title)s',
            '--print', 'after_move:MWFILE %(filepath)s',
            '--',
            trim($url),
        ];
    }

    /**
     * Tags aufräumen (yt-dlp-Metadaten, Reihenfolge zählt): ohne Track-/Disc-Nummer, Beschreibung, Jahr und Genre –
     * YouTube liefert dort Hochladedatum, Fantasiejahre bzw. "Music"; das ergänzt besser die Suche in der Inbox.
     * Herkunft steht in SOURCE (= YouTube) und SOURCE_URL; Album-Interpret = erster Künstler.
     * Normale Videos ohne Musik-Metadaten: "Interpret - Titel (Official Video)" in Interpret und Titel zerlegen.
     *
     * @return list<string>
     */
    public static function tagCleanup(): array
    {
        $empty = fn (string $field) => ['--parse-metadata', ':(?P<meta_'.$field.'>)'];

        return [
            ...$empty('track'), ...$empty('disc'),
            ...$empty('description'), ...$empty('synopsis'), ...$empty('comment'), ...$empty('purl'),
            ...$empty('date'), ...$empty('genre'),
            // Herkunft festhalten (eigenes Feld – der Kommentar trägt die Kategorie und wird beim Einordnen überschrieben)
            '--parse-metadata', '%(mw_source|YouTube)s:%(meta_SOURCE)s',
            '--parse-metadata', 'webpage_url:%(meta_SOURCE_URL)s',
            '--replace-in-metadata', 'uploader,channel', ' - Topic$', '',
            '--parse-metadata', '%(track|)s##%(title)s:^##(?P<meta_artist>.+?) - (?P<meta_title>.+)$',
            // Album-Interpret: erster Künstler (YouTube liefert keinen; ohne ihn fehlt beim Einordnen der Interpret)
            '--parse-metadata', '%(meta_artist,artists.0,artist,uploader)s:%(meta_album_artist)s',
            '--replace-in-metadata', 'meta_title,track',
            '(?i)\s*[\(\[][^\)\]]*\b(official|offiziell\w*|lyrics?|audio|video|visuali[sz]er|hd|hq|4k)\b[^\)\]]*[\)\]]', '',
        ];
    }

    public function version(): ?string
    {
        $p = new Process([self::binary(), '--version']);
        $p->setTimeout(10);
        try {
            $p->run();
        } catch (\Throwable) {
            return null;
        }

        return $p->isSuccessful() ? trim($p->getOutput()) : null;
    }

    /** yt-dlp auf den neuesten Stand bringen (YouTube ändert häufig etwas, alte Versionen scheitern dann). */
    public function update(): string
    {
        $p = new Process([self::binary(), '-U']);
        $p->setTimeout(120);
        $p->run();

        return trim($p->getOutput()."\n".$p->getErrorOutput());
    }

    /** @return list<string> */
    private function audioFiles(string $dir): array
    {
        $audio = config('musicwiz.audio_extensions');
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && in_array(strtolower($f->getExtension()), $audio, true)) {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
