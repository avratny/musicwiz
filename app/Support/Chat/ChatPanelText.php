<?php

namespace App\Support\Chat;

use App\Models\Artist;
use App\Models\ArtistSplit;

/** Ein Satz zu einem Änderungsvorschlag des Assistenten (die Details stehen darunter aufklappbar). */
class ChatPanelText
{
    public static function summary(string $tool, array $in): string
    {
        $artist = fn () => Artist::find((int) ($in['id'] ?? 0))?->name ?? '#'.($in['id'] ?? '?');
        $list = fn ($v) => implode(' · ', array_map('strval', (array) $v));

        return match ($tool) {
            'stage_findings' => ! empty($in['all']) ? 'Alle Funde von „'.$in['key'].'“ vormerken' : count((array) ($in['ids'] ?? [])).' Fund(e) von „'.$in['key'].'“ vormerken',
            'stage_tags' => count((array) ($in['changes'] ?? [])).' Titel: Tags vormerken'.(! empty($in['label']) ? ' – '.$in['label'] : ''),
            'discard_pending' => ! empty($in['all']) ? 'Alle Vormerkungen verwerfen' : 'Vormerkungen verwerfen ('.count((array) ($in['album_ids'] ?? [])).' Alben, '.count((array) ($in['track_ids'] ?? [])).' Titel)',
            'artist_create' => '„'.($in['name'] ?? '?').'“ anlegen'.(! empty($in['mbid']) ? ' mit MusicBrainz-ID' : '').(! empty($in['aliases']) ? ', Schreibweisen: '.$list($in['aliases']) : '').(! empty($in['image_url']) ? ', mit Bild' : ''),
            'artist_update' => $artist().': '.implode(', ', array_merge(array_map(fn ($k) => $k.' = '.$in[$k], array_values(array_intersect(['mbid', 'discogs_id'], array_keys($in)))), isset($in['genres']) ? ['Genres = '.$list($in['genres'])] : [],
                array_key_exists('confirmed', $in) ? [$in['confirmed'] ? '„Name passt“ bestätigen' : '„Name passt“ zurücknehmen'] : [])),
            'artist_rename' => $artist().' → „'.($in['name'] ?? '?').'“',
            'artist_merge' => count((array) ($in['ids'] ?? [])).' Eintrag/Einträge gehen in „'.$artist().'“ auf',
            'artist_alias_add' => $artist().': Schreibweise „'.($in['alias'] ?? '').'“ hinzufügen',
            'artist_alias_remove' => $artist().': Schreibweise „'.($in['alias'] ?? '').'“ entfernen',
            'artist_split' => $artist().' trennen'.(! empty($in['parts']) ? ' → '.$list($in['parts']) : ''),
            'quick_create' => count((array) ($in['picks'] ?? [])).' Künstler anlegen: '.$list(array_column((array) ($in['picks'] ?? []), 'name')),
            'quick_split' => '„'.($in['name'] ?? '?').'“ trennen'.(! empty($in['parts']) ? ' → '.$list($in['parts']) : ''),
            'combined_apply' => ! empty($in['all']) ? 'Alle Kombi-Vorschläge übernehmen' : 'Kombi-Vorschläge übernehmen: '.$list($in['names'] ?? []),
            'combi_start' => 'Kombi-Prüfung aller Kombi-Namen im Hintergrund starten',
            'split_remove' => ($s = ArtistSplit::find((int) ($in['id'] ?? 0))) ? '„'.$s->name.'“ nicht mehr trennen ('.$list($s->parts).')' : 'Trennung aufheben',
            default => ChatTools::label($tool),
        };
    }
}
