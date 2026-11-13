<?php

namespace App\Support\Lookup;

use Throwable;

/**
 * Cover-Vorschläge aus dem Netz für ein Album (ohne Schlüssel): Cover Art Archive (über die MusicBrainz-Album-ID bzw.
 * die MusicBrainz-Suche), Deezer und iTunes. Je Treffer Vorschau, Bild zum Übernehmen, Titel und Größe (soweit der Dienst
 * sie nennt; max = größte mögliche Seite, null = unbekannt). Fällt ein Dienst aus, kommen die übrigen trotzdem – mit Hinweis.
 */
class CoverSuggestions
{
    private const PER_SERVICE = 4;

    public function __construct(private Client $client) {}

    /** @return array{items: list<array>, errors: list<string>} */
    public function search(string $album, string $artist, ?string $mbAlbumId = null): array
    {
        $items = [];
        $errors = [];
        foreach (['Cover Art Archive' => fn () => $this->coverArtArchive($album, $artist, $mbAlbumId), 'Deezer' => fn () => $this->deezer($album, $artist),
            'iTunes' => fn () => $this->itunes($album, $artist)] as $service => $fn) {
            try {
                array_push($items, ...$fn());
            } catch (Throwable $e) {
                $errors[] = $service.': '.$e->getMessage();
            }
        }
        // passender Albumname zuerst (Dienste liefern auch Ähnliches), sonst Reihenfolge der Dienste
        $norm = fn ($s) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $s));
        usort($items, fn ($a, $b) => ($norm($b['title']) === $norm($album)) <=> ($norm($a['title']) === $norm($album)));

        return ['items' => $items, 'errors' => $errors];
    }

    private function coverArtArchive(string $album, string $artist, ?string $mbAlbumId): array
    {
        $releases = [];
        if ($mbAlbumId) {
            $releases[] = ['id' => $mbAlbumId, 'title' => $album, 'date' => null];
        } else {
            $query = 'release:"'.addcslashes($album, '"\\').'"'.($artist !== '' ? ' AND artist:"'.addcslashes($artist, '"\\').'"' : '');
            $r = $this->client->get('musicbrainz', 'https://musicbrainz.org/ws/2/release', ['query' => $query, 'limit' => 3, 'fmt' => 'json']);
            foreach ($r->json('releases') ?? [] as $rel) {
                $releases[] = ['id' => $rel['id'], 'title' => $rel['title'] ?? $album, 'date' => $rel['date'] ?? null];
            }
        }
        $out = [];
        foreach (array_slice($releases, 0, 3) as $rel) {
            $r = $this->client->get('coverartarchive', 'https://coverartarchive.org/release/'.$rel['id']);
            foreach ($r->successful() ? ($r->json('images') ?? []) : [] as $img) {
                if (! ($img['front'] ?? false)) {
                    continue;
                }
                $t = $img['thumbnails'] ?? [];
                $out[] = ['service' => 'Cover Art Archive', 'title' => $rel['title'], 'artist' => $artist, 'year' => $rel['date'] ? substr($rel['date'], 0, 4) : null,
                    'preview' => $t['500'] ?? $t['large'] ?? $img['image'], 'url' => $t['1200'] ?? $img['image'], 'size' => isset($t['1200']) ? 'bis 1200 px' : 'Original', 'max' => isset($t['1200']) ? 1200 : null];
            }
        }

        return array_slice($out, 0, self::PER_SERVICE);
    }

    private function deezer(string $album, string $artist): array
    {
        $q = ($artist !== '' ? 'artist:"'.$artist.'" ' : '').'album:"'.$album.'"';
        $r = $this->client->get('deezer', 'https://api.deezer.com/search/album', ['q' => $q, 'limit' => self::PER_SERVICE]);
        if (! $r->successful()) {
            throw new \RuntimeException('antwortet nicht ('.$r->status().')');
        }

        return array_values(array_filter(array_map(fn ($a) => empty($a['cover_xl']) ? null : ['service' => 'Deezer', 'title' => $a['title'] ?? '',
            'artist' => $a['artist']['name'] ?? '', 'year' => null, 'preview' => $a['cover_medium'] ?? $a['cover_xl'], 'url' => $a['cover_xl'], 'size' => '1000×1000 px', 'max' => 1000],
            $r->json('data') ?? [])));
    }

    private function itunes(string $album, string $artist): array
    {
        $r = $this->client->get('itunes', 'https://itunes.apple.com/search', ['term' => trim($artist.' '.$album), 'entity' => 'album', 'limit' => self::PER_SERVICE, 'country' => 'DE']);
        if (! $r->successful()) {
            throw new \RuntimeException('antwortet nicht ('.$r->status().')');
        }

        // artworkUrl100 lässt sich größer anfordern (Apple liefert bis zur Originalgröße)
        return array_values(array_filter(array_map(fn ($a) => empty($a['artworkUrl100']) ? null : ['service' => 'iTunes', 'title' => $a['collectionName'] ?? '',
            'artist' => $a['artistName'] ?? '', 'year' => isset($a['releaseDate']) ? substr($a['releaseDate'], 0, 4) : null,
            'preview' => str_replace('100x100bb', '300x300bb', $a['artworkUrl100']), 'url' => str_replace('100x100bb', '1200x1200bb', $a['artworkUrl100']), 'size' => 'bis 1200 px', 'max' => 1200],
            $r->json('results') ?? [])));
    }
}
