<?php

namespace App\Support;

use App\Models\GenreIgnore;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

/**
 * Zu breite Genre-Werte („Rock/Pop“) aus den Liedern entfernen – nur in der Datenbank, als System-Änderung: Bleibt ein
 * anderes Genre im Feld, nur das; sonst das Hauptgenre des Interpreten; hat er keins, bleibt das Genre leer.
 */
class GenreCleanup
{
    public function __construct(private GenreTree $tree) {}

    /** @return int Zahl der geänderten Lieder */
    public function apply(): int
    {
        $this->tree->forget();
        $values = GenreIgnore::pluck('value')->all();
        if (! $values) {
            return 0;
        }
        $like = fn (string $v) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v).'%';
        $tracks = Track::whereNotIn('location', Locations::IGNORED)->where(fn ($q) => array_map(fn ($v) => $q->orWhere('genre', 'like', $like($v)), $values))->get();
        $editor = app(TagEditor::class);
        $changed = 0;
        foreach ($tracks as $track) {
            $parts = GenreTree::split($track->genre);
            $removed = array_values(array_filter($parts, fn ($p) => $this->tree->isIgnored($p)));
            if (! $removed) {
                continue; // nur ähnlich geschrieben (LIKE), nicht wirklich betroffen
            }
            $keep = array_values(array_diff($parts, $removed));
            $main = $keep ? null : $this->artistMainGenre($track->id);
            $new = $keep ? implode('; ', $keep) : $main;
            $reason = 'Genre „'.implode('“, „', $removed).'“ zu breit – '.($keep ? 'entfernt' : ($main ? 'Hauptgenre des Interpreten' : 'entfernt (Interpret ohne Genre)'));
            if ($editor->update($track, ['genre' => $new], TagEditor::SYSTEM, $reason)) {
                $changed++;
            }
        }

        return $changed;
    }

    /** Hauptgenre (Ebene 1) mit dem meisten Gewicht über die Interpreten des Lieds */
    private function artistMainGenre(int $trackId): ?string
    {
        $artistIds = DB::table('track_artist')->where('track_id', $trackId)->where('role', 'artist')->pluck('artist_id');
        $weights = [];
        foreach (DB::table('artist_genre')->whereIn('artist_id', $artistIds)->get(['genre_id', 'weight']) as $r) {
            if ($root = $this->tree->root((int) $r->genre_id)) {
                $weights[$root] = ($weights[$root] ?? 0) + (int) $r->weight;
            }
        }
        if (! $weights) {
            return null;
        }
        arsort($weights);

        return $this->tree->path((int) array_key_first($weights));
    }
}
