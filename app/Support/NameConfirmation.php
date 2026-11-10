<?php

namespace App\Support;

use App\Jobs\PrefetchArtistLookup;
use App\Models\Artist;

/**
 * „Name passt“ im Künstlerverzeichnis: von Hand (bleibt immer) oder von der App in eindeutigen Fällen.
 *
 * Eindeutig – Kombi-Namen: mindestens zwei Dienste (Deezer, Spotify, MusicBrainz) tragen ihn als einen Act ein und keiner
 * widerspricht. Einzelnamen: der MusicBrainz-Treffer mit gleichem Namen hat eure Lieder (wie „eindeutig“ bei Schnell
 * erfassen) und ist derselbe wie die MusicBrainz-ID des Eintrags. Eine automatische Bestätigung nimmt die App zurück, wenn
 * sich später etwas widerspricht; fehlen nur Daten (Zwischenspeicher abgelaufen), bleibt sie.
 *
 * Gespeichert ohne Modell-Ereignisse: das Häkchen ist keine Änderung am Verzeichnis (sonst rechnete das Aufräumen neu).
 */
class NameConfirmation
{
    public function __construct(private CombiCheck $combi, private ArtistQuickMatch $quick) {}

    /** Zustände ohne „Name passt“ (rejected = von dir zurückgenommen: die App bestätigt dann nicht mehr automatisch) */
    public const OPEN = [null, 'rejected'];

    /** Von Hand setzen bzw. entfernen */
    public function setManual(Artist $artist, bool $confirmed): void
    {
        $this->store($artist, $confirmed ? 'manual' : 'rejected', $confirmed ? 'von dir bestätigt' : 'von dir zurückgenommen');
    }

    /** Einträge ohne „Name passt“ */
    public static function scopeOpen($query)
    {
        return $query->where(fn ($q) => $q->whereNull('name_confirmed')->orWhere('name_confirmed', 'rejected'));
    }

    /** Alle Einträge prüfen (liest nur Zwischenspeicher – schnell). @return int geänderte Einträge */
    public function evaluateAll(): int
    {
        $artists = Artist::where(fn ($q) => $q->whereNull('name_confirmed')->orWhere('name_confirmed', 'auto'))->get();
        $results = PrefetchArtistLookup::results($artists->pluck('name')->all());
        $combis = $this->combi->cachedMany($artists->pluck('name')->filter(fn ($n) => CombiCheck::applies($n))->values()->all());
        $changed = 0;
        foreach ($artists as $a) {
            $changed += $this->evaluate($a, $results[$a->name] ?? null, $combis[$a->name] ?? null, true) ? 1 : 0;
        }

        return $changed;
    }

    /** Einen Eintrag prüfen. @return bool geändert */
    public function evaluate(Artist $artist, ?array $prefetch = null, ?array $combi = null, bool $preloaded = false): bool
    {
        if (in_array($artist->name_confirmed, ['manual', 'rejected'], true)) {
            return false; // deine Entscheidung gilt
        }
        [$clear, $contradicted, $reason] = CombiCheck::applies($artist->name)
            ? $this->combiVerdict($preloaded ? $combi : $this->combi->cached($artist->name))
            : $this->singleVerdict($artist, $preloaded ? $prefetch : (PrefetchArtistLookup::results([$artist->name])[$artist->name] ?? null));
        if ($clear && $artist->name_confirmed !== 'auto') {
            $this->store($artist, 'auto', $reason);

            return true;
        }
        if ($contradicted && $artist->name_confirmed === 'auto') {
            $this->store($artist, null, null);

            return true;
        }

        return false;
    }

    /** @return array{0: bool, 1: bool, 2: ?string} [eindeutig, widersprochen, Begründung] */
    private function combiVerdict(?array $combi): array
    {
        if (! $combi) {
            return [false, false, null];
        }
        $evidence = collect($combi['evidence'] ?? []);
        $actSources = $evidence->where('vote', 'act')->pluck('source')->unique();
        $collab = $evidence->where('vote', 'collab')->isNotEmpty();
        if ($combi['verdict'] === 'act' && $actSources->count() >= 2 && ! $collab) {
            return [true, false, 'laut '.$actSources->implode(', ').' ein Act'];
        }

        return [false, $combi['verdict'] === 'collab' || $collab, null];
    }

    /** @return array{0: bool, 1: bool, 2: ?string} */
    private function singleVerdict(Artist $artist, ?array $prefetch): array
    {
        if (! $prefetch || ! $artist->mbid) {
            return [false, false, null];
        }
        [$kind, , $hit] = $this->quick->classify($artist->name, $prefetch);
        if ($kind === 'sure' && ($hit['mbid'] ?? null) === $artist->mbid) {
            return [true, false, 'MusicBrainz-Treffer mit euren Liedern („'.($hit['songs'][0] ?? '').'“)'];
        }

        // anderer MusicBrainz-Künstler hat eure Lieder: die automatische Bestätigung stimmt nicht mehr
        return [false, $kind === 'sure' && ($hit['mbid'] ?? null) !== $artist->mbid, null];
    }

    private function store(Artist $artist, ?string $state, ?string $reason): void
    {
        $values = ['name_confirmed' => $state, 'name_confirmed_reason' => $reason, 'name_confirmed_at' => $state ? now() : null];
        Artist::whereKey($artist->id)->toBase()->update($values); // ohne updated_at und Ereignisse
        $artist->forceFill($values);
    }
}
