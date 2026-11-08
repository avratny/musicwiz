<?php

namespace App\Support;

use App\Models\HistoryEntry;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Baut Zielpfade aus Kategorie-Muster und Tags.
 * Platzhalter: {buchstabe} {album_buchstabe} {ordner_interpret} {album} {nr} {interpret} {jahr} {titel} {ext}
 * {buchstabe} richtet sich nach dem Ordner-Interpreten, {album_buchstabe} nach dem Album (für Sampler, deren Interpret
 * „Various Artists“ alles unter „V“ legen würde).
 */
class PathBuilder
{
    public const PLACEHOLDERS = ['buchstabe', 'album_buchstabe', 'ordner_interpret', 'album', 'nr', 'interpret', 'jahr', 'titel', 'ext'];

    public function __construct(private Settings $settings) {}

    /**
     * @param  array  $tags  Tags im Tag-Tool-Format
     * @param  bool  $multiDisc  Album hat mehrere CDs
     * @param  string  $sourcePath  bisheriger Pfad (für Endung und Ersatztitel)
     */
    public function target(array $category, array $tags, bool $multiDisc, string $sourcePath): string
    {
        $relative = $this->render($category['pattern'], $this->values($tags, $multiDisc, $sourcePath));

        return $this->settings->categoryRoot($category).'/'.$relative;
    }

    public function values(array $tags, bool $multiDisc, string $sourcePath): array
    {
        $album = trim((string) ($tags['album'] ?? ''));
        // Album aus lauter unzulässigen Zeichen (z. B. "..."): wie ohne Album, sonst fiele der Ordner ganz weg
        if (! $this->usable($album)) {
            $album = '';
        }
        $folderArtist = self::folderArtist($tags); // Ordner heißt immer genau wie der Künstler im Tag
        $title = trim((string) ($tags['title'] ?? ''));
        if (! $this->usable($title)) {
            $title = pathinfo($sourcePath, PATHINFO_FILENAME);
        }

        $albumName = $album !== '' ? $album : (string) $this->settings->get('no_album');

        return [
            'buchstabe' => $this->letter($folderArtist),
            'album_buchstabe' => $this->letter($albumName),
            'ordner_interpret' => $folderArtist,
            'album' => $albumName,
            'nr' => self::number($tags['track'] ?? '', $tags['disc'] ?? '', $multiDisc),
            'interpret' => trim((string) ($tags['artist'] ?? '')),
            // Ohne Album kein Jahr: der Ordner heißt dann einfach "Kein Album"
            'jahr' => $album !== '' ? self::year($tags['year'] ?? '') : '',
            'titel' => $title,
            'ext' => strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)),
        ];
    }

    /** Bleibt vom Wert nach dem Bereinigen etwas Brauchbares für einen Datei- bzw. Ordnernamen übrig? */
    private function usable(string $value): bool
    {
        return trim($this->clean($value), '. ') !== '';
    }

    /** Höchstlänge eines Ordner- bzw. Dateinamens in Byte (Dateisysteme erlauben 255; Luft für "(2)" und Zwischennamen) */
    public const MAX_SEGMENT_BYTES = 200;

    public function render(string $pattern, array $values): string
    {
        $segments = [];
        foreach (explode('/', $pattern) as $segment) {
            $isFile = str_contains($segment, '{ext}');
            foreach ($values as $key => $value) {
                if ($key === 'ext') {
                    continue;
                }
                $value = $this->clean((string) $value);
                if ($value === '') {
                    // leere Platzhalter samt Klammern bzw. Trenner entfernen
                    $q = preg_quote('{'.$key.'}', '/');
                    $segment = preg_replace(['/\s*[\(\[]'.$q.'[\)\]]/u', '/'.$q.'\s*-\s*/u', '/\s*-\s*'.$q.'/u'], '', $segment);
                }
                $segment = str_replace('{'.$key.'}', $value, $segment);
            }
            $segment = str_replace('{ext}', $values['ext'] ?? '', $segment);
            $segment = trim(preg_replace('/\s{2,}/u', ' ', $segment));
            // führende Punkte machen Ordner/Dateien unsichtbar (".38 Special"), abschließende verbietet Windows/SMB
            $segment = trim($segment, '. ');
            $segment = $this->limit($segment, $isFile ? (string) ($values['ext'] ?? '') : '');
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /** Zu lange Namen an einer Zeichengrenze kürzen; die Dateiendung bleibt erhalten. */
    private function limit(string $segment, string $ext): string
    {
        if (strlen($segment) <= self::MAX_SEGMENT_BYTES) {
            return $segment;
        }
        $suffix = $ext !== '' && str_ends_with($segment, '.'.$ext) ? '.'.$ext : '';
        $name = substr($segment, 0, strlen($segment) - strlen($suffix));

        return rtrim(mb_strcut($name, 0, self::MAX_SEGMENT_BYTES - strlen($suffix), 'UTF-8'), '. ').$suffix;
    }

    /** Zeichen ersetzen, die in Pfaden nicht erlaubt sind. */
    public function clean(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        $value = str_replace([':', '/', '\\'], [$this->settings->get('replace_colon'), $this->settings->get('replace_slash'), $this->settings->get('replace_slash')], $value);
        $value = str_replace(['?', '*', '<', '>', '|', '"'], ['', '', '', '', '', "'"], $value);
        $value = preg_replace('/[\x00-\x1F]/u', '', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /** @var array<string, string>|null Kleinschreibung => einheitliche Schreibweise des Ordners */
    private ?array $folderCasing = null;

    /**
     * Einheitliche Schreibweise eines Künstlers (Vorschlag für die Tags): Künstlerverzeichnis, sonst häufigste Schreibweise
     * im Bestand. Nötig, weil das Share Groß/klein nicht unterscheidet – "ATB" und "Atb" können nicht zwei Ordner sein.
     */
    public function folderName(string $name): string
    {
        if ($name === '') {
            return $name;
        }
        $check = app(ArtistDirectory::class)->check($name);
        if (in_array($check['status'], ['ok', 'variant'], true) || ($check['status'] === 'similar' && mb_strtolower($check['canonical']) === mb_strtolower($name))) {
            return $check['canonical'];
        }

        return $this->folderCasings()[mb_strtolower(FileOps::nfc($name))] ?? $name;
    }

    /** Anzeigetext mit einheitlicher Schreibweise: nur Namen, die sich bloß in Groß/klein bzw. Unicode-Form unterscheiden. */
    public function uniformSpelling(?string $text): string
    {
        $text = (string) $text;
        foreach (ArtistNames::split($text) as $name) {
            $wanted = $this->folderName($name);
            if ($wanted !== $name && mb_strtolower(FileOps::nfc($wanted)) === mb_strtolower(FileOps::nfc($name))) {
                $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $wanted, $text, 1);
            }
        }

        return $text;
    }

    /** @return array<string, string> */
    private function folderCasings(): array
    {
        if ($this->folderCasing !== null) {
            return $this->folderCasing;
        }
        if (! Schema::hasTable('tracks')) {
            return $this->folderCasing = [];
        }
        $version = Track::count().'|'.Track::max('id').'|'.Track::max('updated_at').'|'.HistoryEntry::max('id');

        return $this->folderCasing = Cache::remember('folder-casing:'.md5($version), now()->addHour(), function () {
            $counts = [];
            $query = Track::query()->where('location', 'library')->select(['artist', 'artists', 'albumartist', 'albumartists']);
            foreach ($query->cursor() as $t) {
                $n = FileOps::nfc(self::folderArtist($t->tags()));
                if ($n !== '') {
                    $counts[mb_strtolower($n)][$n] = ($counts[mb_strtolower($n)][$n] ?? 0) + 1;
                }
            }
            $out = [];
            foreach ($counts as $lower => $spellings) {
                if (count($spellings) > 1) {
                    // häufigste Schreibweise; bei Gleichstand die mit mehr Großbuchstaben am Wortanfang
                    uksort($spellings, fn ($a, $b) => [$spellings[$b], preg_match_all('/\b\p{Lu}/u', $b)] <=> [$spellings[$a], preg_match_all('/\b\p{Lu}/u', $a)]);
                    $out[$lower] = (string) array_key_first($spellings);
                }
            }

            return $out;
        });
    }

    public function letter(string $folderArtist): string
    {
        $first = mb_substr(ArtistNames::stripAccents(trim($folderArtist)), 0, 1);
        $first = strtoupper($first);

        return preg_match('/^[A-Z]$/', $first) ? $first : (string) $this->settings->get('non_letter_folder');
    }

    /** Ordner-Interpret: erster Eintrag aus ALBUMARTISTS, ersatzweise ARTISTS. */
    public static function folderArtist(array $tags): string
    {
        $candidates = [
            $tags['albumartists'] ?? [],
            ArtistNames::split($tags['albumartist'] ?? ''),
            $tags['artists'] ?? [],
            ArtistNames::split($tags['artist'] ?? ''),
        ];
        foreach ($candidates as $list) {
            foreach ((array) $list as $name) {
                if (trim((string) $name) !== '') {
                    return trim((string) $name);
                }
            }
        }

        return '';
    }

    public static function number(mixed $track, mixed $disc, bool $multiDisc): string
    {
        $t = (int) preg_replace('/\D.*$/', '', trim((string) $track));
        if ($t <= 0) {
            return '';
        }
        $nr = sprintf('%02d', $t);
        $d = (int) preg_replace('/\D.*$/', '', trim((string) $disc));

        return $multiDisc ? max($d, 1).'-'.$nr : $nr;
    }

    public static function year(mixed $year): string
    {
        return preg_match('/(\d{4})/', (string) $year, $m) ? $m[1] : '';
    }

    /** Mehrere CDs: Gesamtzahl im Tag > 1 oder verschiedene CD-Nummern im Album. */
    public static function isMultiDisc(array $tagSets): bool
    {
        $discs = [];
        foreach ($tagSets as $tags) {
            if ((int) ($tags['disctotal'] ?? 0) > 1) {
                return true;
            }
            $d = (int) ($tags['disc'] ?? 0);
            if ($d > 0) {
                $discs[$d] = true;
            }
        }

        return count($discs) > 1 || (count($discs) === 1 && array_key_first($discs) > 1);
    }
}
