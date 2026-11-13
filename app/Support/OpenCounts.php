<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Offene Punkte je Bereich – die Badges in der Navigation (Interpreten, Alben, Genres, Sonstiges) und in den zweiten
 * Leisten zeigen nur, was noch zu bearbeiten ist (Ausgenommenes und Vorgemerktes zählt nicht). Die Prüfungen sind teuer:
 * gerechnet wird nur, wenn sich in der Datenbank etwas geändert hat (Stand aus einer günstigen Abfrage) oder nach einer
 * Aktion in der Oberfläche bzw. einem Hintergrundvorgang (touch – der Stand ist sekundengenau und sieht nicht alles),
 * spätestens nach einigen Minuten.
 */
class OpenCounts
{
    /** Tabelle => Spalte für den Stand (neben der Anzahl) */
    private const STATE = [
        'tracks' => 'updated_at', 'tag_changes' => 'id', 'track_artist' => null, 'artists' => 'updated_at', 'artist_aliases' => 'id',
        'artist_distinct' => 'created_at', 'artist_sources' => 'updated_at', 'artist_splits' => 'updated_at', 'artist_genre' => null,
        'artist_recording_checks' => 'checked_at', 'name_checks' => 'checked_at', 'name_rules' => 'updated_at',
        'genres' => 'updated_at', 'genre_synonyms' => 'id', 'genre_ignores' => 'id', 'genre_exemptions' => 'id',
        'duplicate_exemption_pairs' => 'created_at', 'album_exemptions' => 'id', 'album_tracklists' => 'updated_at', 'file_plans' => 'updated_at',
        'series_patterns' => 'updated_at', 'text_repairs' => 'updated_at', 'settings' => 'updated_at', 'cover_images' => 'created_at',
        'folder_images' => 'updated_at', 'fingerprint_scores' => null, 'lyrics_lookups' => 'fetched_at',
    ];

    private const TTL_MINUTES = 5;

    private const VERSION = 'open-counts:version';

    /** nach einer Änderung: beim nächsten Abruf neu rechnen */
    public static function touch(): void
    {
        Cache::forever(self::VERSION, (int) Cache::get(self::VERSION, 0) + 1);
    }

    private ?string $state = null;

    /** Sonstiges: Ansicht => offen */
    public function misc(): array
    {
        return $this->remember('misc', function () {
            $genres = app(GenreChecks::class);
            $counts = ['zeichen' => app(TextRepairs::class)->count(), 'jahr' => app(YearDates::class)->count(), 'replaygain' => app(ReplayGain::class)->count(),
                'duplikate' => app(Duplicates::class)->count(), 'fingerabdruck' => Fingerprints::pending(Fingerprints::LOCATIONS)->count(),
                'kaputt' => app(BrokenTracks::class)->count(), 'songtexte' => app(Lyrics::class)->count(), 'nicht_lesbar' => DB::table('tracks')->whereNotNull('error')->count()];
            foreach (['genre_fehlt' => 'missing', 'genre_abweichend' => 'mismatch', 'genre_uneinheitlich' => 'mixed'] as $view => $check) {
                $counts[$view] = $genres->count($check);
            }

            return $counts;
        });
    }

    /** Alben: Prüfung => offen („Alle“ ist keine Prüfung – ohne Zahl) */
    public function albums(): array
    {
        return $this->remember('albums', function () {
            $covers = app(AlbumCovers::class)->counts();

            return ['getrennt' => app(SplitAlbums::class)->count(), 'cover-verschieden' => $covers['different'], 'cover-klein' => $covers['small'],
                'sampler' => app(SamplerFlags::class)->count(), 'sortiernamen' => collect(app(SortNames::class)->open())->pluck('album_hash')->unique()->count(),
                'unvollstaendig' => app(IncompleteAlbums::class)->count(), 'serien' => app(AlbumSeries::class)->series()->filter(fn ($s) => $s['changes'])->count()];
        });
    }

    /** Genres: Reiter => offen */
    public function genres(): array
    {
        return $this->remember('genres', fn () => ['unmapped' => count(app(ArtistGenres::class)->unmapped(PHP_INT_MAX))]);
    }

    /** Interpreten: Werkzeug => offen („Alle“ = unbestätigt, „Verschieden“ sind getroffene Entscheidungen – ohne Zahl) */
    public function artists(): array
    {
        return array_diff_key(ArtistTools::counts(), ['' => 0, 'verschieden' => 0]);
    }

    /** Navigation: je Bereich die Summe der offenen Punkte */
    public function nav(): array
    {
        return ['artists' => array_sum($this->artists()), 'albums' => array_sum($this->albums()), 'genres' => array_sum($this->genres()), 'misc' => array_sum($this->misc())];
    }

    private function remember(string $area, Closure $compute): array
    {
        return Cache::remember('open-counts:'.$area.':'.Cache::get(self::VERSION, 0).':'.$this->state(), now()->addMinutes(self::TTL_MINUTES), $compute);
    }

    /** Stand der Datenbank: je Tabelle Anzahl und neuester Eintrag – eine Abfrage */
    private function state(): string
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $sql = implode(' UNION ALL ', array_map(fn ($table, $column) => 'SELECT COUNT(*) AS n, '.($column ? "MAX($column)" : 'NULL')." AS m FROM $table",
            array_keys(self::STATE), self::STATE));

        // nur für diesen Aufruf merken – die nächste Anfrage prüft neu
        return $this->state = md5(json_encode(DB::select($sql)));
    }
}
