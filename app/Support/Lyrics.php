<?php

namespace App\Support;

use App\Models\LyricsLookup;
use App\Models\Track;
use App\Support\Lookup\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Songtexte von LRCLIB (https://lrclib.net – frei, ohne Anmeldung). Je Lied wird erst genau gesucht (Interpret,
 * Titel, Album, Länge ±2 s – /api/get); gibt es das nicht, liefert die Suche Kandidaten zur Auswahl (/api/search).
 * Synchronisierte Texte (LRC) haben Vorrang vor reinem Text.
 * - Eindeutig (im Batch übernehmbar): genauer Treffer, und das Lied hat noch keinen Songtext – oder nur reinen und der
 *   Treffer ist synchronisiert.
 * - Zur Auswahl: Kandidaten aus der Suche, oder ein genauer Treffer, der einen vorhandenen Text anders ersetzen würde.
 * Abgefragt werden Lieder ohne Songtext oder mit reinem Text (Inbox und Lib); synchronisierte bleiben, wie sie sind.
 * Übernommen wird als Tag-Änderung (nur in der Datenbank, „Schreiben“ bringt sie in die Datei).
 */
class Lyrics
{
    private const API = 'https://lrclib.net/api/';

    /** Länge darf beim genauen Treffer so viele Sekunden abweichen (wie bei LRCLIB) */
    public const TOLERANCE = 2.0;

    /** „nichts gefunden“ bzw. „keiner passt“ erst nach so vielen Tagen erneut abfragen */
    public const RETRY_DAYS = 30;

    private const MAX_CANDIDATES = 8;

    public function __construct(private Client $client) {}

    public static function normalize(mixed $value): ?string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $text === '' ? null : $text;
    }

    /** synchronisiert = Zeilen mit LRC-Zeitstempel „[01:23.45]“ */
    public static function synced(?string $text): bool
    {
        return $text !== null && preg_match('/^\[\d{1,3}:\d{2}(?:[.:]\d{1,3})?\]/m', $text) === 1;
    }

    /** kurz für Listen: „synchronisiert, 42 Zeilen“ */
    public static function summary(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $lines = count(array_filter(explode("\n", preg_replace('/^\[[^\]]*\]\s*/m', '', $text)), fn ($l) => trim($l) !== ''));

        return (self::synced($text) ? 'synchronisiert' : 'reiner Text').', '.$lines.' '.($lines === 1 ? 'Zeile' : 'Zeilen');
    }

    /** Lieder, die abgefragt werden: ohne oder nur mit reinem Text, noch nie bzw. „nichts“ vor längerer Zeit ($force: alle) */
    public static function pending(bool $force = false): Builder
    {
        return Track::whereIn('location', SplitAlbums::LOCATIONS)->whereNull('error')
            ->where('title', '<>', '')->where('artist', '<>', '')->where('duration', '>=', BrokenTracks::MIN_DURATION)
            ->where(fn ($q) => $q->whereNull('lyrics')->orWhere('lyrics', 'not regexp', '(^|\n)\\[[0-9]{1,3}:[0-9]{2}'))
            ->when(! $force, fn ($q) => $q->whereNotIn('id', LyricsLookup::where(fn ($l) => $l->whereIn('status', ['exact', 'choice'])
                ->orWhere('fetched_at', '>', now()->subDays(self::RETRY_DAYS)))->select('track_id')));
    }

    /** ein Lied bei LRCLIB abfragen und das Ergebnis merken */
    public function fetch(Track $track): LyricsLookup
    {
        $exact = null;
        $response = $this->client->fresh(fn () => $this->client->get('lrclib', self::API.'get', array_filter([
            'artist_name' => $track->artist, 'track_name' => $track->title, 'album_name' => $track->album, 'duration' => (int) round((float) $track->duration),
        ], fn ($v) => $v !== null && $v !== '')));
        if ($response->successful() && is_array($response->json())) {
            $exact = self::candidate($response->json());
            if ($exact && abs($exact['duration'] - (float) $track->duration) > self::TOLERANCE) {
                $exact = null;
            }
        } elseif ($response->status() !== 404) {
            $response->throw();
        }
        $candidates = [];
        if (! $exact || $exact['instrumental']) {
            $search = $this->client->fresh(fn () => $this->client->get('lrclib', self::API.'search', ['track_name' => $track->title, 'artist_name' => $track->artist]));
            $search->throw();
            $candidates = collect($search->json() ?: [])->map(fn ($c) => is_array($c) ? self::candidate($c) : null)->filter()
                ->reject(fn ($c) => $c['instrumental'] || ($c['synced'] === null && $c['plain'] === null))
                // passende Länge zuerst, dann synchronisiert
                ->sortBy(fn ($c) => [abs($c['duration'] - (float) $track->duration) > self::TOLERANCE ? 1 : 0, $c['synced'] === null ? 1 : 0, abs($c['duration'] - (float) $track->duration)])
                ->take(self::MAX_CANDIDATES)->values()->all();
        }
        $status = match (true) {
            $exact && ! $exact['instrumental'] => 'exact',
            $candidates !== [] => 'choice',
            $exact && $exact['instrumental'] => 'instrumental',
            default => 'none',
        };

        return LyricsLookup::updateOrCreate(['track_id' => $track->id], ['status' => $status, 'exact' => $status === 'exact' ? $exact : null,
            'candidates' => $candidates ?: null, 'fetched_at' => now()]);
    }

    /** @return array{id: int, artist: string, title: string, album: string, duration: float, instrumental: bool, synced: ?string, plain: ?string}|null */
    private static function candidate(array $c): ?array
    {
        if (! isset($c['id'])) {
            return null;
        }

        return ['id' => (int) $c['id'], 'artist' => (string) ($c['artistName'] ?? ''), 'title' => (string) ($c['trackName'] ?? ''),
            'album' => (string) ($c['albumName'] ?? ''), 'duration' => (float) ($c['duration'] ?? 0), 'instrumental' => (bool) ($c['instrumental'] ?? false),
            'synced' => self::normalize($c['syncedLyrics'] ?? null), 'plain' => self::normalize($c['plainLyrics'] ?? null)];
    }

    /** bevorzugter Text eines Kandidaten: synchronisiert vor rein */
    public static function text(array $candidate): ?string
    {
        return $candidate['synced'] ?? $candidate['plain'];
    }

    /**
     * Befunde für Sonstiges › Songtexte: eindeutig (Batch), zur Auswahl, nicht gefunden.
     *
     * @return array{exact: Collection, choice: Collection, none: Collection}
     */
    public function findings(string $search = ''): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
        $lookups = LyricsLookup::with(['track' => fn ($q) => $q->select(['id', 'location', 'path', 'title', 'artist', 'album', 'duration', 'lyrics', 'track', 'error'])])
            ->whereHas('track', fn ($q) => $q->whereIn('location', SplitAlbums::LOCATIONS)
                ->when(trim($search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('artist', 'like', $like)->orWhere('album', 'like', $like))))
            ->get()->sortBy(fn ($l) => [$l->track->artist, $l->track->album, (int) $l->track->track, $l->track->title]);
        $out = ['exact' => collect(), 'choice' => collect(), 'none' => collect()];
        foreach ($lookups as $l) {
            $current = $l->track->lyrics;
            if ($l->status === 'exact') {
                $text = self::text($l->exact);
                if ($text === $current || self::synced($current)) {
                    continue;   // schon übernommen bzw. synchroner Text vorhanden – nichts zu tun
                }
                $batch = $current === null || self::synced($text);
                $out[$batch ? 'exact' : 'choice']->push(['lookup' => $l, 'track' => $l->track, 'candidates' => [$l->exact], 'exact' => true]);
            } elseif ($l->status === 'choice') {
                if ($current !== null && collect($l->candidates)->contains(fn ($c) => in_array($current, [$c['synced'], $c['plain']], true))) {
                    continue;
                }
                if (! self::synced($current)) {
                    $out['choice']->push(['lookup' => $l, 'track' => $l->track, 'candidates' => $l->candidates, 'exact' => false]);
                }
            } elseif (in_array($l->status, ['none', 'instrumental'], true) && $current === null) {
                $out['none']->push(['lookup' => $l, 'track' => $l->track, 'candidates' => [], 'exact' => false]);
            }
        }

        return $out;
    }

    /** offen: eindeutig und zur Auswahl */
    public function count(): int
    {
        $f = $this->findings();

        return $f['exact']->count() + $f['choice']->count();
    }

    /**
     * Einen Kandidaten übernehmen (Tag-Änderung). $plain: den reinen Text statt des synchronisierten.
     *
     * @throws InvalidArgumentException
     */
    public function take(int $trackId, int $candidateId, bool $plain = false): void
    {
        $lookup = LyricsLookup::with('track')->find($trackId) ?? throw new InvalidArgumentException('Keine Abfrage zu diesem Lied – bitte neu laden.');
        $candidate = collect([$lookup->exact, ...($lookup->candidates ?? [])])->filter()->firstWhere('id', $candidateId)
            ?? throw new InvalidArgumentException('Diesen Songtext gibt es nicht mehr – bitte neu laden.');
        $text = $plain ? ($candidate['plain'] ?? null) : self::text($candidate);
        if ($text === null) {
            throw new InvalidArgumentException('Kein Text vorhanden.');
        }
        app(TagEditor::class)->update($lookup->track, ['lyrics' => $text], TagEditor::MANUAL,
            'Songtext von LRCLIB ('.($plain || $candidate['synced'] === null ? 'rein' : 'synchronisiert').')');
    }

    /** alle eindeutigen übernehmen. @return int Lieder */
    public function takeExact(): int
    {
        $n = 0;
        foreach ($this->findings()['exact'] as $f) {
            $this->take($f['track']->id, $f['lookup']->exact['id']);
            $n++;
        }

        return $n;
    }

    /** „Keiner passt“: aus der Auswahl nehmen (wird nach RETRY_DAYS erneut abgefragt) */
    public function dismiss(int $trackId): void
    {
        LyricsLookup::whereKey($trackId)->update(['status' => 'dismissed', 'fetched_at' => now()]);
    }
}
