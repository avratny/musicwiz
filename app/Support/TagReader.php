<?php

namespace App\Support;

use getID3;
use getid3_lib;

/**
 * Liest Dateien: Technik und Cover per getID3, Tag-Felder für MP3/FLAC über das Tag-Tool
 * (damit Lesen und Schreiben dieselben Felder sehen, z. B. TXXX:ARTISTS und den Kommentar ohne Beschreibung).
 */
class TagReader
{
    public function __construct(private TagTool $tool) {}

    /**
     * @param  list<string>  $paths
     * @return array<string, array> Pfad => Track-Attribute
     */
    public function readMany(array $paths): array
    {
        $out = $this->readPass($paths);
        // Lesefehler (Share kurz weg, Datei gesperrt): nach kurzer Pause einmal neu versuchen
        $failed = array_keys(array_filter($out, fn ($a) => $a['read_failed']));
        if ($failed) {
            usleep((int) config('musicwiz.read_retry_ms', 500) * 1000);
            $out = array_replace($out, $this->readPass($failed));
        }

        return $out;
    }

    /** @return array<string, array> */
    private function readPass(array $paths): array
    {
        $writable = array_values(array_filter($paths, fn ($p) => TagTool::canWrite($p)));
        $toolResults = [];
        try {
            $toolResults = $this->tool->read($writable);
        } catch (\Throwable $e) {
            report($e);
        }

        $out = [];
        foreach ($paths as $path) {
            $out[$path] = $this->readOne($path, $toolResults[$path] ?? null);
        }

        return $out;
    }

    public function readOne(string $path, ?array $toolResult = null): array
    {
        // MP3/FLAC: Tag-Tool liefert Tags und Technik aus einem Lesevorgang – getID3 (zweites Öffnen) nur als Ersatz
        if (($toolResult['ok'] ?? false) && isset($toolResult['info'])) {
            return $this->fromTool($path, $toolResult);
        }
        $getID3 = new getID3;
        $getID3->option_save_attachments = true;
        $getID3->option_md5_data = false;
        $info = @$getID3->analyze($path);
        getid3_lib::CopyTagsToComments($info);
        $c = $info['comments'] ?? [];

        $attrs = [
            'duration' => isset($info['playtime_seconds']) ? (float) $info['playtime_seconds'] : null,
            'bitrate' => isset($info['bitrate']) ? (int) round($info['bitrate']) : null,
            'format' => $info['fileformat'] ?? null,
            'cover_embedded' => false,
            'cover_size' => null,
            'writable' => TagTool::canWrite($path),
            'error' => isset($info['error']) ? implode('; ', array_slice($info['error'], 0, 2)) : null,
        ];
        foreach ($c['picture'] ?? [] as $pic) {
            $attrs['cover_embedded'] = true;
            $w = $pic['image_width'] ?? null;
            $h = $pic['image_height'] ?? null;
            if ((! $w || ! $h) && ! empty($pic['data'])) {
                [$w, $h] = @getimagesizefromstring($pic['data']) ?: [null, null];
            }
            $attrs['cover_size'] = $w && $h ? min($w, $h) : null;
            break;
        }

        // MP3/FLAC ohne Ergebnis vom Tag-Tool (Fehler bzw. Tag-Tool ganz ausgefallen) gilt als nicht gelesen:
        // die getID3-Tags sehen andere Felder (z. B. Kommentar = Kategorie) und dürfen den Index nicht überschreiben
        $toolFailed = TagTool::canWrite($path) && ! ($toolResult['ok'] ?? false);
        if ($toolResult && ($toolResult['ok'] ?? false)) {
            $tags = $toolResult['tags'];
            $attrs['error'] = null;
        } else {
            $tags = $this->fromGetID3($c);
            if ($toolFailed) {
                $attrs['error'] = $toolResult['error'] ?? 'Tags nicht lesbar (Tag-Tool nicht erreichbar)';
            }
        }

        $noAudio = ($attrs['duration'] ?? 0) < 0.1;
        if ($noAudio) {
            $attrs['error'] = trim('Keine Audiodaten – Datei ist defekt oder unvollständig. '.($attrs['error'] ?? ''));
        }
        // nur intern: Scanner behält bei einem Lesefehler die bisherigen Werte (siehe Scanner::store)
        $attrs['read_failed'] = $toolFailed || $noAudio;

        return $attrs + self::normalize($tags);
    }

    public static function normalize(array $tags): array
    {
        $str = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $list = fn ($v) => array_values(array_filter(array_map(fn ($x) => trim((string) $x), (array) $v), fn ($x) => $x !== ''));

        return [
            'title' => $str($tags['title'] ?? ''),
            'artist' => $str($tags['artist'] ?? ''),
            'artists' => $list($tags['artists'] ?? []),
            'album' => $str($tags['album'] ?? ''),
            'albumartist' => $str($tags['albumartist'] ?? ''),
            'albumartists' => $list($tags['albumartists'] ?? []),
            'track' => $str($tags['track'] ?? ''),
            'tracktotal' => $str($tags['tracktotal'] ?? ''),
            'disc' => $str($tags['disc'] ?? ''),
            'disctotal' => $str($tags['disctotal'] ?? ''),
            'year' => $str($tags['year'] ?? ''),
            'genres' => $list($tags['genres'] ?? []),
            'comment' => $str($tags['comment'] ?? ''),
            'category' => $str($tags['category'] ?? ''),
            'artistsort' => $str($tags['artistsort'] ?? ''),
            'albumartistsort' => $str($tags['albumartistsort'] ?? ''),
            'compilation' => (bool) ($tags['compilation'] ?? false),
            // Herkunft (z. B. „YouTube“ bei Downloads) – nur gelesen, nie geschrieben
            'source' => $str($tags['source'] ?? ''),
            // Bewertung 1–5 Sterne (MP3: POPM, FLAC: RATING), ohne = null
            'rating' => max(0, min(5, (int) ($tags['rating'] ?? 0))) ?: null,
            // nur gelesen für die Prüfungen „ReplayGain fehlt“ / „Songtext fehlt“ (Text selbst steht nicht im Index)
            'replaygain' => $str($tags['rg_track_gain'] ?? ''),
            'replaygain_album' => $str($tags['rg_album_gain'] ?? ''),
            'has_lyrics' => trim((string) ($tags['lyrics'] ?? '')) !== '',
            // nur für „Album uneinheitlich“: danach trennt Navidrome Alben (im Editor nicht bearbeitet)
            'originaldate' => $str($tags['originaldate'] ?? ''),
            'releasedate' => $str($tags['releasedate'] ?? ''),
            'mb_albumid' => $str($tags['mb_albumid'] ?? ''),
        ];
    }

    private function fromTool(string $path, array $toolResult): array
    {
        $info = $toolResult['info'];
        $attrs = [
            'duration' => isset($info['duration']) ? (float) $info['duration'] : null,
            'bitrate' => isset($info['bitrate']) ? (int) $info['bitrate'] : null,
            'format' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'cover_embedded' => (bool) ($info['cover_embedded'] ?? false),
            'cover_size' => isset($info['cover_size']) ? (int) $info['cover_size'] : null,
            'writable' => true,
            'error' => null,
        ];
        $noAudio = ($attrs['duration'] ?? 0) < 0.1;
        if ($noAudio) {
            $attrs['error'] = 'Keine Audiodaten – Datei ist defekt oder unvollständig.';
        }
        // nur intern: Scanner behält bei einem Lesefehler die bisherigen Werte (siehe Scanner::store)
        $attrs['read_failed'] = $noAudio;

        return $attrs + self::normalize($toolResult['tags']);
    }

    /** Ersatz für Formate, die das Tag-Tool nicht kennt (z. B. WMA). */
    private function fromGetID3(array $c): array
    {
        $first = fn ($k) => isset($c[$k]) ? (string) (is_array($c[$k]) ? reset($c[$k]) : $c[$k]) : '';
        [$track, $tracktotal] = array_pad(explode('/', $first('track_number') ?: $first('tracknumber') ?: $first('track')), 2, '');
        [$disc, $disctotal] = array_pad(explode('/', $first('part_of_a_set') ?: $first('discnumber')), 2, '');

        return [
            'title' => $first('title'),
            'artist' => $first('artist'),
            'album' => $first('album'),
            'albumartist' => $first('albumartist') ?: $first('band') ?: $first('album_artist'),
            'track' => $track,
            'tracktotal' => $tracktotal ?: $first('totaltracks'),
            'disc' => $disc,
            'disctotal' => $disctotal ?: $first('totaldiscs'),
            'year' => $first('year') ?: $first('date'),
            'genres' => (array) ($c['genre'] ?? []),
            'comment' => $first('comment'),
            'compilation' => in_array($first('part_of_a_compilation') ?: $first('compilation'), ['1', 'true'], true),
        ];
    }
}
