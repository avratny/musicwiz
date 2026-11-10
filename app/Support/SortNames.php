<?php

namespace App\Support;

use App\Models\Artist;

/**
 * Sortiernamen für ARTISTSORT/ALBUMARTISTSORT: „The Beatles“ → „Beatles, The“ (Artikel laut Einstellung), oder der eigene
 * Sortiername aus dem Künstlerverzeichnis. Personen werden nie umgedreht („Udo Jürgens“ bleibt). Die Ordner bleiben davon
 * unberührt.
 */
class SortNames
{
    /** Artikel zum Anklicken in den Einstellungen (weitere lassen sich ins Feld schreiben) */
    public const SUGGESTED_ARTICLES = ['The', 'Die', 'A', 'An', 'Der', 'Das', 'Les', 'Le', 'La', 'Los', 'Las', 'El', 'Il'];

    /** @var array<string, string>|null Name im Anzeigetext => eigener Sortiername */
    private ?array $overrides = null;

    public function __construct(private Settings $settings) {}

    /** @return list<string> Artikel aus der Einstellung, klein geschrieben */
    public function articles(): array
    {
        $list = preg_split('/\s*,\s*/u', mb_strtolower(trim((string) $this->settings->get('sort_articles', ''))));

        return array_values(array_filter($list, fn ($a) => $a !== ''));
    }

    /** Sortiername nach den Artikel-Regeln (ohne eigenen Eintrag im Verzeichnis); gleich dem Namen, wenn nichts umzustellen ist. */
    public function byRule(string $name): string
    {
        $name = trim($name);
        $parts = preg_split('/\s+/u', $name, 2);
        if (count($parts) === 2 && in_array(mb_strtolower($parts[0]), $this->articles(), true)) {
            return $parts[1].', '.$parts[0];
        }

        return $name;
    }

    /** Sortiername eines einzelnen Künstlers: eigener Eintrag im Verzeichnis, sonst nach den Artikel-Regeln. */
    public function forName(string $name): string
    {
        $name = trim($name);

        return $this->overrides()[$name] ?? $this->byRule($name);
    }

    /**
     * Wert für ARTISTSORT bzw. ALBUMARTISTSORT zu einem Anzeigetext („The Beatles feat. Liz“ → „Beatles, The feat. Liz“).
     * Leer, wenn er dem Anzeigetext gleicht – dann braucht es das Feld nicht (ein vorhandenes, z. B. „Jürgens, Udo“ von
     * Picard oder MediaMonkey, wird entfernt).
     */
    public function forText(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $out = $text;
        foreach (ArtistNames::split($text) as $name) {
            $sort = $this->forName($name);
            if ($sort !== $name) {
                $out = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', str_replace(['\\', '$'], ['\\\\', '\\$'], $sort), $out, 1);
            }
        }

        return $out === $text ? '' : $out;
    }

    /** @return array{artistsort: string, albumartistsort: string} */
    public function tags(array $tags): array
    {
        return ['artistsort' => $this->forText($tags['artist'] ?? ''), 'albumartistsort' => $this->forText($tags['albumartist'] ?? '')];
    }

    /** @return array<string, string> */
    private function overrides(): array
    {
        if ($this->overrides === null) {
            $this->overrides = [];
            foreach (Artist::whereNotNull('sort_name')->where('sort_name', '!=', '')->get(['name', 'base_name', 'sort_name']) as $a) {
                $this->overrides[$a->name] = $a->sort_name;
                // gleichnamige Künstler stehen im Anzeigetext ohne Zusatz („Adam“ statt „Adam [DE]“)
                if ($a->base_name) {
                    $this->overrides[$a->base_name] ??= $a->sort_name;
                }
            }
        }

        return $this->overrides;
    }
}
