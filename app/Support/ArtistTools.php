<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistSplit;
use App\Models\Setting;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Interpreten-Werkzeuge (zweite Leiste): Zähler und gemeinsame Zahlen. Die Zähler (Ähnlich, Kombis …) sind teuer zu
 * rechnen – sie werden nur neu gerechnet, wenn sich am Verzeichnis, an den Tags oder an Entscheidungen etwas geändert
 * hat (Stand aus wenigen günstigen Abfragen).
 */
class ArtistTools
{
    /** Werkzeuge in der zweiten Leiste ('' = Liste aller Interpreten) */
    public const TOOLS = ['' => 'Alle', 'eindeutig' => 'Eindeutig', 'wahrscheinlich' => 'Wahrscheinlich', 'schreibweise' => 'Schreibweise', 'aehnlich' => 'Ähnlich', 'kombis' => 'Kombis', 'verschieden' => 'Verschieden', 'sortiernamen' => 'Sortiernamen'];

    /** Unbestätigte Interpreten (Badge bei „Alle“) – günstig, daher nicht zwischengespeichert */
    public static function unconfirmed(): int
    {
        return Artist::where('confirmed', false)->count();
    }

    /** @return array<string, int> Werkzeug => Anzahl */
    public static function counts(): array
    {
        $state = implode('|', [implode(',', array_keys(self::TOOLS)), Artist::count(), Artist::max('updated_at'), ArtistAlias::count(), ArtistAlias::max('id'),
            DB::table('artist_distinct')->count(), ArtistSplit::count(), ArtistSplit::max('updated_at'), Track::count(), Track::max('updated_at'),
            DB::table('tag_changes')->count(), DB::table('tag_changes')->max('id'), Artist::max('looked_up_at'), DB::table('artist_sources')->max('updated_at'), DB::table('artist_recording_checks')->max('checked_at'), Setting::where('key', 'sort_articles')->value('value')]);

        return Cache::remember('artist-tools:'.md5($state), now()->addDay(), function () {
            $credits = app(ArtistCredits::class);
            $clear = app(ClearArtists::class)->counts();

            return [
                '' => Artist::count(),
                'eindeutig' => $clear['eindeutig'],
                'wahrscheinlich' => $clear['wahrscheinlich'],
                'schreibweise' => $clear['schreibweise'],
                'aehnlich' => count(app(ArtistMatcher::class)->groups()),
                'kombis' => count($credits->open()) + count($credits->titleSuggestions()),
                'verschieden' => DB::table('artist_distinct')->count(),
                'sortiernamen' => count(app(SortNames::class)->openByArtist()),
            ];
        });
    }

    /** Lieder und Alben je Künstler über track_artist (ein Lied zählt einmal, auch mit mehreren Rollen) */
    public static function stats(?array $ids = null): array
    {
        return DB::table('track_artist')->join('tracks', 'tracks.id', '=', 'track_artist.track_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('track_artist.artist_id', $ids))
            ->selectRaw('track_artist.artist_id, COUNT(DISTINCT track_artist.track_id) AS tracks, COUNT(DISTINCT tracks.album_hash) AS albums')
            ->groupBy('track_artist.artist_id')->get()->keyBy('artist_id')->all();
    }
}
