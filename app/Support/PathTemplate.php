<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Dateinamen aus Vorlagen (relativ zum Ordner von Lib bzw. Blackhole) – je Bereich eine für Interpreten-Alben und eine
 * für Sampler (Sampler-Flag oder Album-Interpret „Various Artists“). Geschrieben wird damit noch nichts; die Vorlagen
 * werden in den Einstellungen gepflegt und mit Beispielen aus dem Bestand angezeigt.
 */
class PathTemplate
{
    public const PLACEHOLDERS = [
        'buchstabe' => 'Anfangsbuchstabe des Ordner-Interpreten (sonst „Ordner für Nicht-Buchstaben“)',
        'album_buchstabe' => 'Anfangsbuchstabe des Albums – für Sampler',
        'ordner_interpret' => 'Album-Interpret, sonst Interpret',
        'interpret' => 'Interpret des Lieds',
        'album' => 'Album (ohne Album: „Ohne-Album-Name“)',
        'jahr' => 'Jahr des Albums: das der meisten Lieder, sonst das neueste',
        'nr' => 'Nummer, zweistellig – bei mehreren CDs mit CD davor („2-05“)',
        'titel' => 'Titel (ohne Titel: bisheriger Dateiname)',
        'ext' => 'Dateiendung',
    ];

    /** Vorlagen je Bereich und Art; Vorgaben = Muster aus 0.3.x (dort lagen Interpreten in „artists“, Sampler in „sampler“) */
    public const TEMPLATES = [
        'template_library' => ['library', false, 'Lib – Interpreten', 'artists/{buchstabe}/{ordner_interpret}/{album} ({jahr})/{nr} - {titel}.{ext}'],
        'template_library_compilation' => ['library', true, 'Lib – Sampler', 'sampler/{album_buchstabe}/{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}'],
        'template_blackhole' => ['blackhole', false, 'Blackhole – Interpreten', 'artists/{buchstabe}/{ordner_interpret}/{album} ({jahr})/{nr} - {titel}.{ext}'],
        'template_blackhole_compilation' => ['blackhole', true, 'Blackhole – Sampler', 'sampler/{album_buchstabe}/{album} ({jahr})/{nr} - {interpret} - {titel}.{ext}'],
    ];

    /** weitere Einstellungen zum Bilden der Namen: Schlüssel => [Bezeichnung, Vorgabe, Hinweis] */
    public const OPTIONS = [
        'replace_colon' => ['Ersatz für „:“', '-', 'Doppelpunkt ist in Datei- und Ordnernamen nicht erlaubt. „A: B“ wird „A - B“.'],
        'replace_slash' => ['Ersatz für „/“ und „\\“', '', 'Würde sonst einen weiteren Ordner anlegen. Leer = entfernen („Time / Koma“ → „Time Koma“, zwischen Ziffern „5/2003“ → „5-2003“).'],
        'no_album' => ['Ohne-Album-Name', 'Kein Album', 'Ordner für Lieder ohne Album (dann ohne Jahr).'],
        'non_letter_folder' => ['Ordner für Nicht-Buchstaben', '0-9', 'Für {buchstabe}, wenn der Name mit Ziffer oder Zeichen beginnt.'],
    ];

    /** Höchstlänge eines Ordner- bzw. Dateinamens in Byte (Dateisysteme erlauben 255; Luft für „(2)“) */
    public const MAX_SEGMENT_BYTES = 200;

    /** @param  array<string, string>  $options  Werte zu OPTIONS (fehlende = Vorgabe) */
    public function __construct(private array $options = []) {}

    public static function fromSettings(): self
    {
        $settings = app(Settings::class);

        return new self(array_map(fn ($o) => (string) $settings->get($o, ''), array_combine(array_keys(self::OPTIONS), array_keys(self::OPTIONS))));
    }

    private function option(string $key): string
    {
        $value = $this->options[$key] ?? '';

        return $value !== '' ? $value : self::OPTIONS[$key][1];
    }

    /** Fehler in einer Vorlage (null = in Ordnung) */
    public static function problem(string $template): ?string
    {
        $template = trim($template);
        if ($template === '') {
            return 'Bitte eine Vorlage eingeben.';
        }
        preg_match_all('/\{([^}]*)\}/', $template, $m);
        $unknown = array_diff($m[1], array_keys(self::PLACEHOLDERS));
        if ($unknown) {
            return 'Unbekannter Platzhalter: {'.implode('}, {', $unknown).'}';
        }
        if (! str_ends_with($template, '.{ext}')) {
            return 'Die Vorlage muss mit „.{ext}“ enden.';
        }
        if (str_starts_with($template, '/') || in_array('..', explode('/', $template), true) || str_contains($template, '//')) {
            return 'Relativ zum Ordner, ohne „/“ am Anfang, „//“ oder „..“.';
        }
        if (! array_intersect($m[1], ['titel', 'nr'])) {
            return 'Ohne {titel} oder {nr} hätten alle Lieder eines Albums denselben Namen.';
        }

        return null;
    }

    /** Ist das Lied Teil eines Samplers? */
    public static function isCompilation(Track $track): bool
    {
        return (bool) $track->compilation || $track->albumartist === NameRules::VARIOUS;
    }

    /**
     * Werte der Platzhalter für ein Lied.
     *
     * @param  Collection<int, Track>|null  $album  alle Lieder des Albums (für Jahr und mehrere CDs)
     */
    public function values(Track $track, ?Collection $album = null): array
    {
        $album ??= collect([$track]);
        $albumName = trim((string) $track->album);
        if (! $this->usable($albumName)) {
            $albumName = '';
        }
        $folderArtist = trim((string) ($track->albumartist ?: $track->artist));
        $title = trim((string) $track->title);
        if (! $this->usable($title)) {
            $title = pathinfo($track->filename ?: $track->path, PATHINFO_FILENAME);
        }
        $shownAlbum = $albumName !== '' ? $albumName : $this->option('no_album');
        $discs = $album->pluck('disc')->filter()->unique();
        $multiDisc = $album->contains(fn ($t) => (int) $t->disc_total > 1) || $discs->count() > 1 || (int) $discs->first() > 1;

        return [
            'buchstabe' => $this->letter($folderArtist),
            'album_buchstabe' => $this->letter($shownAlbum),
            'ordner_interpret' => $folderArtist,
            'interpret' => trim((string) $track->artist),
            'album' => $shownAlbum,
            // ohne Album kein Jahr: der Ordner heißt dann einfach „Kein Album“
            'jahr' => $albumName !== '' ? self::albumYear($album->pluck('year')) : '',
            'nr' => self::number($track->track, $track->disc, $multiDisc),
            'titel' => $title,
            'ext' => strtolower(pathinfo($track->path, PATHINFO_EXTENSION)),
        ];
    }

    public function render(string $template, array $values): string
    {
        $segments = [];
        foreach (explode('/', trim($template)) as $segment) {
            $isFile = str_contains($segment, '{ext}');
            foreach ($values as $key => $value) {
                if ($key === 'ext') {
                    continue;
                }
                $value = $this->clean((string) $value);
                if ($value === '') {
                    // leere Platzhalter samt Klammern bzw. Trenner entfernen: „Album ()“, „ - Titel“
                    $q = preg_quote('{'.$key.'}', '/');
                    $segment = preg_replace(['/\s*[\(\[]'.$q.'[\)\]]/u', '/'.$q.'\s*-\s*/u', '/\s*-\s*'.$q.'/u'], '', $segment);
                }
                $segment = str_replace('{'.$key.'}', $value, $segment);
            }
            // Punkte am Ende des Namens fallen vor der Endung weg („Sea Cruise..flac“ → „Sea Cruise.flac“)
            $segment = str_replace('{ext}', $values['ext'] ?? '', preg_replace('/[\s.]*\.\{ext\}/u', '.{ext}', $segment));
            // führende Punkte machen Ordner/Dateien unsichtbar („.38 Special“), abschließende verbietet Windows/SMB
            $segment = trim(trim(preg_replace('/\s{2,}/u', ' ', $segment)), '. ');
            $segment = $this->limit($segment, $isFile ? (string) ($values['ext'] ?? '') : '');
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Zeichen ersetzen, die in Pfaden nicht erlaubt sind – „A: B“ → „A - B“, „/“ entfernt („5/2003“ → „5-2003“), „...“ → „…“
     * (sonst „Would You....flac“); dazu dieselbe Bereinigung wie in den Tags (TextCleanup).
     */
    public function clean(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        $colon = $this->option('replace_colon');
        $value = preg_replace('/\s*:\s+/u', $colon === '' ? ' ' : ' '.$colon.' ', $value);
        $value = str_replace(':', $colon, $value);
        $slash = $this->option('replace_slash');
        // entfernen – zwischen Ziffern („5/2003“, „24/7“) ein Bindestrich, sonst ginge die Bedeutung verloren
        $value = $slash === '' ? preg_replace(['/(?<=\d)\/(?=\d)/u', '/\s*[\/\\\\]\s*/u'], ['-', ' '], $value) : str_replace(['/', '\\'], $slash, $value);
        $value = str_replace(['?', '*', '<', '>', '|', '"', '...'], ['', '', '', '', '', "'", '…'], $value);
        $value = preg_replace('/[\x00-\x1F]/u', '', $value);

        return TextCleanup::clean($value);
    }

    private function usable(string $value): bool
    {
        return trim($this->clean($value), '. ') !== '';
    }

    private function letter(string $name): string
    {
        $first = strtoupper(mb_substr(Str::ascii(trim($name)), 0, 1));

        return preg_match('/^[A-Z]$/', $first) ? $first : $this->option('non_letter_folder');
    }

    /** Zu lange Namen an einer Zeichengrenze kürzen; die Dateiendung bleibt */
    private function limit(string $segment, string $ext): string
    {
        if (strlen($segment) <= self::MAX_SEGMENT_BYTES) {
            return $segment;
        }
        $suffix = $ext !== '' && str_ends_with($segment, '.'.$ext) ? '.'.$ext : '';
        $name = substr($segment, 0, strlen($segment) - strlen($suffix));

        return rtrim(mb_strcut($name, 0, self::MAX_SEGMENT_BYTES - strlen($suffix), 'UTF-8'), '. ').$suffix;
    }

    public static function number(mixed $track, mixed $disc, bool $multiDisc): string
    {
        $t = (int) preg_replace('/\D.*$/', '', trim((string) $track));
        if ($t <= 0) {
            return '';
        }
        $nr = sprintf('%02d', $t);

        return $multiDisc ? max((int) preg_replace('/\D.*$/', '', trim((string) $disc)), 1).'-'.$nr : $nr;
    }

    /**
     * Jahr für den Album-Ordner: das der Mehrheit der Lieder mit Jahr, sonst das neueste – ein Sampler erscheint
     * frühestens mit seinem jüngsten Lied. So landet ein Album immer in einem Ordner.
     */
    public static function albumYear(iterable $years): string
    {
        $counts = [];
        foreach ($years as $y) {
            if (preg_match('/(\d{4})/', (string) $y, $m)) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
        }
        if (! $counts) {
            return '';
        }
        arsort($counts);
        $top = (string) array_key_first($counts);
        $keys = array_map('strval', array_keys($counts));
        sort($keys);

        return array_sum($counts) < $counts[$top] * 2 ? $top : (string) end($keys);
    }

    /**
     * Beispiele aus dem Bestand: bis zu $limit Lieder aus verschiedenen Alben des Bereichs und der Art.
     *
     * @return list<array{from: string, to: string}>
     */
    public function examples(string $template, string $location, bool $compilation, int $limit = 3): array
    {
        $query = Track::where('location', $location)->where(fn ($q) => $compilation
            ? $q->where('compilation', true)->orWhere('albumartist', NameRules::VARIOUS)
            : $q->where(fn ($q) => $q->whereNull('compilation')->orWhere('compilation', false))
                ->where(fn ($q) => $q->whereNull('albumartist')->orWhere('albumartist', '!=', NameRules::VARIOUS)));
        $hashes = (clone $query)->whereNotNull('album')->select('album_hash')->distinct()->orderBy('album_hash')->limit($limit)->pluck('album_hash');
        $out = [];
        foreach ($hashes as $hash) {
            $album = Track::where('location', $location)->where('album_hash', $hash)->orderBy('disc')->orderBy('track')->get();
            $track = $album->first();
            $out[] = ['from' => $this->relative($track), 'to' => $this->render($template, $this->values($track, $album))];
        }

        return $out;
    }

    private function relative(Track $track): string
    {
        $root = Locations::root($track->location);

        return str_starts_with($track->path, $root.'/') ? substr($track->path, strlen($root) + 1) : $track->path;
    }
}
