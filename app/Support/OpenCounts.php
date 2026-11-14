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
 * spätestens nach einigen Minuten. Im Betrieb wartet keine Seite darauf: gezeigt wird der letzte Stand, neu gerechnet
 * nach der Antwort und je Bereich nur einmal gleichzeitig (bei großer Bibliothek dauert das Minuten). Ändert sich nur
 * die Datenbank (z. B. während des Abgleichs), höchstens jede Minute.
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

    /** nur der Datenbank-Stand hat sich geändert: frühestens nach so vielen Sekunden neu rechnen */
    private const STATE_SECONDS = 60;

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
        return array_diff_key($this->artistTools(), ['' => 0, 'verschieden' => 0]);
    }

    /** Interpreten: alle Werkzeuge samt „Alle“ und „Verschieden“ (zweite Leiste) */
    public function artistTools(): array
    {
        return $this->remember('artist-tools', fn () => ArtistTools::counts());
    }

    /** Navigation: je Bereich die Summe der offenen Punkte */
    public function nav(): array
    {
        return ['artists' => array_sum($this->artists()), 'albums' => array_sum($this->albums()), 'genres' => array_sum($this->genres()), 'misc' => array_sum($this->misc())];
    }

    private function remember(string $area, Closure $compute): array
    {
        $version = (int) Cache::get(self::VERSION, 0);
        $state = $this->state();
        if (! config('musicwiz.counts_in_background')) {
            return Cache::remember('open-counts:'.$area.':'.$version.':'.$state, now()->addMinutes(self::TTL_MINUTES), $compute);
        }

        $key = 'open-counts:'.$area;
        $lock = Cache::lock($key.':lock', 900);
        $refresh = function () use ($key, $compute, $version, $state, $lock) {
            try {
                set_time_limit(0);
                $at = now()->timestamp;
                Cache::forever($key, ['version' => $version, 'state' => $state, 'at' => $at, 'counts' => $compute()]);
            } finally {
                $lock->release();
            }
        };

        $cached = Cache::get($key);
        if (! $cached) {
            // noch nie gerechnet: einmal warten (gleichzeitige Aufrufe warten auf denselben Durchgang)
            $lock->block(900, function () use ($key, $refresh) {
                Cache::has($key) ? null : $refresh();
            });

            return Cache::get($key)['counts'] ?? [];
        }
        $age = now()->timestamp - $cached['at'];
        $stale = $cached['version'] !== $version || $age >= self::TTL_MINUTES * 60 || ($cached['state'] !== $state && $age >= self::STATE_SECONDS);
        if ($stale && $lock->get()) {
            defer($refresh, 'open-counts:'.$area);
        }

        return $cached['counts'];
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
