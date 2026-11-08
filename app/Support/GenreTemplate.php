<?php

namespace App\Support;

use App\Models\Genre;
use App\Models\GenreMapping;

/**
 * Vorlage der Genre-Liste: flach, nur Electronic mit Unterstilen (siehe Planung).
 * Abgeglichen mit den Hauptgenres von Discogs/MusicBrainz und dem Bestand (Dance, Schlager, Austropop, 50er, Klassik-Crossover).
 */
class GenreTemplate
{
    public const LIST = [
        'Electronic' => ['Trance', 'House', 'Techno', 'Eurodance', 'Dance / Pop-Dance', 'Euro Disco / Hi-NRG', 'Italo Disco', 'EDM / Big Room',
            'Hard Dance / Hardstyle', 'Hardcore', 'Drum & Bass', 'Breakbeat / Big Beat', 'Electro / Synthpop', 'EBM / Dark Electro',
            'Chill / Ambient / Lounge', 'Downtempo / Trip-Hop'],
        'Pop' => [], 'Rock' => [], 'Rock \'n\' Roll / Rockabilly' => [], 'Hard Rock' => [], 'Alternative / Indie' => [], 'Metal' => [], 'Punk' => [],
        'Deutschrock' => [], 'NDW / Deutschpop' => [], 'Austropop' => [], 'Schlager' => [], 'Liedermacher' => [], 'Chanson' => [],
        'Volksmusik' => [], 'Blasmusik / Marsch' => [], 'Hip-Hop' => [], 'R&B / Soul' => [], 'Funk / Disco' => [], 'Gospel' => [],
        'Reggae' => [], 'Latin' => [], 'Weltmusik' => [], 'Jazz' => [], 'Swing / Big Band' => [], 'Blues' => [], 'Country / Folk' => [],
        'Klassik' => [], 'Klassik-Crossover' => [], 'Easy Listening' => [], 'New Age / Instrumental' => [], 'Soundtrack' => [], 'Musical' => [],
        'Weihnachten' => [], 'Kinder' => [], 'Comedy' => [], 'Hörbuch / Hörspiel' => [],
    ];

    /** Häufige Werte aus Tags, MusicBrainz und Discogs → Listen-Genre (Startwerte der Zuordnung). */
    public const MAPPINGS = [
        'Rock & Roll' => 'Rock \'n\' Roll / Rockabilly', 'Rock And Roll' => 'Rock \'n\' Roll / Rockabilly', 'Rock\'n\'Roll' => 'Rock \'n\' Roll / Rockabilly',
        'Rock \'n\' Roll' => 'Rock \'n\' Roll / Rockabilly', 'Rockabilly' => 'Rock \'n\' Roll / Rockabilly', 'Doo Wop' => 'Rock \'n\' Roll / Rockabilly',
        'Oldies' => 'Rock \'n\' Roll / Rockabilly',
        'Hard Rock' => 'Hard Rock', 'Classic Rock' => 'Rock', 'Pop Rock' => 'Rock', 'Glam Rock' => 'Rock', 'Soft Rock' => 'Rock',
        'Heavy Metal' => 'Metal', 'Indie Rock' => 'Alternative / Indie', 'Alternative Rock' => 'Alternative / Indie', 'Grunge' => 'Alternative / Indie',
        'Euro House' => 'House', 'Progressive House' => 'House', 'Deep House' => 'House', 'Electro House' => 'EDM / Big Room', 'Big Room' => 'EDM / Big Room',
        'Euro-Dance' => 'Eurodance', 'Euro Dance' => 'Eurodance', 'Eurodance' => 'Eurodance', 'Dance' => 'Dance / Pop-Dance', 'Dance-Pop' => 'Dance / Pop-Dance',
        'Italo-Disco' => 'Italo Disco', 'Italo Disco' => 'Italo Disco', 'Euro-Disco' => 'Euro Disco / Hi-NRG', 'Euro Disco' => 'Euro Disco / Hi-NRG', 'Hi NRG' => 'Euro Disco / Hi-NRG',
        'Synth-pop' => 'Electro / Synthpop', 'Synthpop' => 'Electro / Synthpop', 'Hardstyle' => 'Hard Dance / Hardstyle', 'Happy Hardcore' => 'Hardcore',
        'Progressive Trance' => 'Trance', 'Vocal Trance' => 'Trance', 'Trance' => 'Trance', 'Techno' => 'Techno', 'Ambient' => 'Chill / Ambient / Lounge',
        'Trip Hop' => 'Downtempo / Trip-Hop', 'Drum n Bass' => 'Drum & Bass', 'Big Beat' => 'Breakbeat / Big Beat',
        'Schlager' => 'Schlager', 'Volkstümliche Musik' => 'Volksmusik', 'Folk, World, & Country' => 'Country / Folk', 'Country' => 'Country / Folk', 'Folk' => 'Country / Folk',
        'Brass & Military' => 'Blasmusik / Marsch', 'Marches' => 'Blasmusik / Marsch', 'Polka' => 'Blasmusik / Marsch',
        'Funk / Soul' => 'R&B / Soul', 'Soul' => 'R&B / Soul', 'Rhythm & Blues' => 'R&B / Soul', 'Disco' => 'Funk / Disco', 'Funk' => 'Funk / Disco',
        'Hip Hop' => 'Hip-Hop', 'Rap' => 'Hip-Hop', 'Latin' => 'Latin', 'Latin Pop' => 'Latin', 'Salsa' => 'Latin', 'Reggaeton' => 'Latin', 'Ska' => 'Reggae',
        'Swing' => 'Swing / Big Band', 'Big Band' => 'Swing / Big Band', 'Classical' => 'Klassik', 'Opera' => 'Klassik', 'Classical Crossover' => 'Klassik-Crossover',
        'Easy Listening' => 'Easy Listening', 'Stage & Screen' => 'Soundtrack', 'Score' => 'Soundtrack', 'Children\'s' => 'Kinder', 'Christmas' => 'Weihnachten',
        'Non-Music' => 'Hörbuch / Hörspiel', 'Audiobook' => 'Hörbuch / Hörspiel', 'Spoken Word' => 'Hörbuch / Hörspiel', 'World' => 'Weltmusik', 'Chanson' => 'Chanson',
        'Singer/Songwriter' => 'Liedermacher', 'Neue Deutsche Welle' => 'NDW / Deutschpop', 'Krautrock' => 'Deutschrock',
    ];

    /** Fehlende Genres ergänzen (nichts umbenennen oder löschen) und die Vorlagen-Reihenfolge herstellen. */
    public static function addMissing(): int
    {
        $added = 0;
        $pos = 0;
        foreach (self::LIST as $main => $children) {
            $g = Genre::whereNull('parent_id')->where('name', $main)->first();
            if (! $g) {
                $g = Genre::create(['name' => $main, 'position' => $pos]);
                $added++;
            } else {
                $g->update(['position' => $pos]);
            }
            $pos++;
            foreach ($children as $i => $child) {
                $c = Genre::where('name', $child)->first();
                if (! $c) {
                    Genre::create(['name' => $child, 'parent_id' => $g->id, 'position' => $i]);
                    $added++;
                } elseif ($c->parent_id === $g->id) {
                    $c->update(['position' => $i]);
                }
            }
        }
        // eigene Genres dahinter
        foreach (Genre::whereNull('parent_id')->whereNotIn('name', array_keys(self::LIST))->orderBy('position')->get() as $own) {
            $own->update(['position' => $pos++]);
        }

        return $added;
    }

    /** Start-Zuordnungen anlegen, ohne vorhandene zu ändern. */
    public static function addMappings(): int
    {
        $listed = Genre::pluck('name')->all();
        $n = 0;
        foreach (self::MAPPINGS as $source => $target) {
            if (in_array($target, $listed, true) && ! in_array($source, $listed, true) && ! GenreMapping::where('source', $source)->exists()) {
                GenreMapping::create(['source' => $source, 'target' => $target]);
                $n++;
            }
        }

        return $n;
    }

    public static function tree(): array
    {
        return array_map(fn ($name, $children) => ['name' => $name, 'children' => $children], array_keys(self::LIST), self::LIST);
    }
}
