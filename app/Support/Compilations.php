<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Support\Collection;

/**
 * Erkennt Sampler: Compilation-Flag oder eine Schreibweise von „Various Artists“ im Album-Interpreten („V.A.“, „Diverse“,
 * „Verschiedene Interpreten“ …). Geprüft wird nur der Album-Interpret, nie der Interpret eines Titels.
 * „Unknown Artist“ & Co. sind ein fehlendes Tag, kein Sampler.
 */
class Compilations
{
    /** Normalisierte Schreibweisen; Treffer nur bei vollständiger Gleichheit (kein „enthält“). */
    private const VARIANTS = [
        'variousartists', 'variousartist', 'various', 'variosartists', 'variusartists', 'variousartits', 'vairousartists',
        'variousperformers', 'variousinterprets', 'assortedartists', 'multipleartists', 'mixedartists',
        'verschiedeneinterpreten', 'verschiedenekunstler', 'verschiedenekuenstler', 'diverseinterpreten', 'diversekunstler',
        'diversekuenstler', 'divinterpreten', 'verschinterpreten', 'artistesdivers', 'artistesvaries', 'varioartistas',
        'variosartistas', 'artistasvarios', 'artistivari', 'autorivari', 'verschillendeartiesten', 'diverseartiesten',
        'blandadeartister', 'diverseartister', 'forskelligekunstnere', 'rozniwykonawcy', 'ruzniinterpreti', 'ruzniumelci',
        // kurze Kürzel
        'va', 'div', 'diverse', 'divers', 'varios', 'vari', 'aavv', 'compilation', 'compilations', 'sampler',
    ];

    /** Nicht-lateinische Schreibweisen, ohne Normalisierung verglichen. */
    private const NON_LATIN = ['ヴァリアス・アーティスト', 'オムニバス', 'Разные исполнители', 'Сборник'];

    /** Platzhalter für ein fehlendes Interpreten-Tag (normalisiert); „-Interpret-“ legte MediaMonkey an */
    private const PLACEHOLDERS = ['unknownartist', 'unknown', 'unbekannterinterpret', 'unbekannterkunstler', 'unbekannterkuenstler',
        'unbekannt', 'interpret', 'artist', 'noartist', 'keininterpret'];

    public function __construct(private Settings $settings) {}

    /** „V. A.“, „V/A“, „v-a“ → „va“; „Various Artists (Compilation)“ → „variousartists“. */
    public static function normalize(string $value): string
    {
        $s = ArtistNames::stripAccents(mb_strtolower(trim($value)));
        do {
            $before = $s;
            $s = preg_replace('/\s*[\(\[][^\(\)\[\]]*[\)\]]\s*$/u', '', $s) ?? $s;
        } while ($s !== $before && $s !== '');

        return preg_replace('/[.\-_\/\s]+/u', '', $s) ?? $s;
    }

    /** Album-Interpret ist eine Schreibweise von „Various Artists“ (auch der eingestellte Wert selbst). */
    public function isVariousArtists(?string $albumArtist): bool
    {
        $raw = trim((string) $albumArtist);
        if ($raw === '') {
            return false;
        }
        if (in_array($raw, self::NON_LATIN, true) || mb_strtolower($raw) === mb_strtolower((string) $this->settings->get('compilation_artist'))) {
            return true;
        }

        return in_array(self::normalize($raw), self::VARIANTS, true);
    }

    /** „Unknown Artist“, „[unknown]“, „Unbekannter Interpret“, „-Interpret-“: fehlendes Tag, kein Künstler – nur prüfen. */
    public static function isPlaceholder(?string $name): bool
    {
        $raw = mb_strtolower(trim((string) $name));

        return $raw === '[unknown]' || ($raw !== '' && in_array(self::normalize($raw), self::PLACEHOLDERS, true));
    }

    /**
     * Warum ist das Album ein Sampler? Flag bei mehr als der Hälfte der Titel (wie beim Scan) oder eine
     * „Various Artists“-Schreibweise als häufigster Album-Interpret. null = kein Sampler.
     *
     * @param  Collection<int, Track>|array  $tracks
     */
    public function reason(Collection|array $tracks): ?string
    {
        $tracks = collect($tracks);
        if ($tracks->isEmpty()) {
            return null;
        }
        if ($tracks->filter(fn ($t) => (bool) $t->compilation)->count() > $tracks->count() / 2) {
            return 'Compilation-Flag';
        }
        $aa = (string) $tracks->map(fn ($t) => trim((string) $t->albumartist))->filter()->countBy()->sortDesc()->keys()->first();

        return $this->isVariousArtists($aa) ? 'Album-Interpret „'.$aa.'“' : null;
    }
}
