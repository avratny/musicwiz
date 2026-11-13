<?php

namespace App\Support\Lookup;

use App\Support\ArtistCredits;
use App\Support\ArtistMatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Namensprüfung für Kombis: fragt MusicBrainz, Spotify, Discogs, TheAudioDB und Last.fm nach einem Namen (nur die
 * Suche, ohne Bilder) und merkt sich je Dienst den besten passenden Treffer mit Veröffentlichungen (MusicBrainz,
 * Discogs), Beliebtheit (Spotify), Hörern (Last.fm), gleichnamigen Interpreten und – bei Gruppen – den Mitgliedern
 * (MusicBrainz). Mitglieder werden über ihre MusicBrainz-ID geprüft (genau dieser Interpret, keine Verwechslung mit
 * Gleichnamigen). Unabhängig vom Verzeichnis; die Antworten der Dienste teilt sie sich mit dem Abgleich (Client).
 * Gefragt wird nur im Hintergrund (CheckComboNames) – Lesen (results, group) fragt nie einen Dienst.
 */
class NameCheck
{
    public const SERVICES = ['musicbrainz' => 'MusicBrainz', 'spotify' => 'Spotify', 'discogs' => 'Discogs', 'audiodb' => 'TheAudioDB', 'lastfm' => 'Last.fm'];

    /** Gegenstand einer Prüfung: ein Name oder ein Mitglied (MusicBrainz-ID) */
    public const NAME = 'name';

    public const MBID = 'mbid';

    private const GROUPS = ['Group', 'Orchestra', 'Choir'];

    private const GOOD = ['exakt', 'gross_klein', 'zeichen'];

    public function __construct(private MusicBrainz $musicbrainz, private Spotify $spotify, private Discogs $discogs, private AudioDb $audiodb, private LastFm $lastfm) {}

    /**
     * Was zu prüfen ist: Namen der offenen Kombis (ganz und die vorgeschlagenen Teile) und die Mitglieder von Gruppen –
     * noch nicht, vor längerer Zeit, nicht bei jedem Dienst oder mit Fehler geprüft. Schon in diesem Durchlauf Gefragtes
     * ($asked, Schlüssel von key()) kommt nicht noch einmal dran – sonst liefe er bei einem gestörten Dienst endlos.
     *
     * @return list<array{type: string, subject: string}>
     */
    public static function pending(array $asked = []): array
    {
        $wanted = [];
        foreach (self::subjects() as $w) {
            $wanted[self::key($w['type'], $w['subject'])] = $w;
        }
        $done = [];
        foreach ([self::NAME, self::MBID] as $type) {
            $subjects = array_column(array_filter($wanted, fn ($w) => $w['type'] === $type), 'subject');
            foreach (array_chunk($subjects, 1000) as $chunk) {
                $rows = DB::table('name_checks')->where('subject_type', $type)->whereIn('subject', $chunk)
                    ->where('checked_at', '>', now()->subDays((int) config('musicwiz.lookup_refresh_days')))->get(['subject', 'service', 'status']);
                foreach ($rows->groupBy('subject') as $subject => $list) {
                    // fertig: jeder Dienst hat geantwortet (neue Dienste werden nachgefragt), ohne Fehler
                    if (! $list->contains('status', 'fehler') && $list->pluck('service')->unique()->count() >= count(self::SERVICES)) {
                        $done[self::key($type, (string) $subject)] = true;
                    }
                }
            }
        }

        return array_values(array_diff_key($wanted, $done, array_flip($asked)));
    }

    /** Schlüssel für pending()/$asked */
    public static function key(string $type, string $subject): string
    {
        return $type.':'.$subject;
    }

    /** Einen Namen bzw. ein Mitglied bei allen Diensten prüfen und speichern. @return array<string, string> Dienst => Status */
    public function check(string $type, string $subject): array
    {
        $status = [];
        foreach (array_keys(self::SERVICES) as $service) {
            $row = ['status' => 'nicht_gefunden', 'found' => null, 'match' => null, 'type' => null, 'releases' => null, 'popularity' => null,
                'listeners' => null, 'namesakes' => null, 'mbid' => null, 'members' => null, 'url' => null, 'message' => null];
            try {
                $hit = $type === self::MBID ? $this->member($service, $subject) : $this->search($service, $subject);
                if ($hit === false) {
                    $row['status'] = 'uebersprungen';
                } elseif ($hit) {
                    $row = ['status' => 'ok'] + $hit + $row;   // übrige Spalten leeren (alte Werte nicht stehen lassen)
                }
            } catch (Throwable $e) {
                $row['status'] = 'fehler';
                $row['message'] = mb_substr($e->getMessage(), 0, 255);
            }
            $row['members'] = $row['members'] === null ? null : json_encode($row['members'], JSON_UNESCAPED_UNICODE);
            DB::table('name_checks')->updateOrInsert(['subject_type' => $type, 'subject' => $subject, 'service' => $service], $row + ['checked_at' => now()]);
            $status[$service] = $row['status'];
        }

        return $status;
    }

    /**
     * Ergebnisse je Name: Name => Dienst => Treffer.
     *
     * @return array<string, array<string, array>>
     */
    public static function results(array $names): array
    {
        return self::read(self::NAME, $names);
    }

    /**
     * Ergebnisse je Mitglied: MusicBrainz-ID => Dienst => Treffer.
     *
     * @return array<string, array<string, array>>
     */
    public static function memberResults(array $mbids): array
    {
        return self::read(self::MBID, $mbids);
    }

    /**
     * Gruppe mit Mitgliedern, wenn MusicBrainz den ganzen Namen der Kombi als Gruppe führt – die Teile werden den
     * Mitgliedern zugeordnet (Vergleichsschlüssel: „Beam“ = „BEAM“). Nur aus Gespeichertem, ohne Abfrage.
     *
     * @return array{mbid: string, members: list<array>, parts: array<string, array{mbid: string, name: string, ended: bool}>}|null
     */
    public static function group(array $combo, array $checks): ?array
    {
        $mb = $checks[$combo['name']]['musicbrainz'] ?? null;
        if (! $mb || $mb['status'] !== 'ok' || ! $mb['mbid'] || ! $mb['members'] || ! in_array($mb['type'], self::GROUPS, true) || ! in_array($mb['match'], self::GOOD, true)) {
            return null;
        }
        $byKey = [];
        foreach ($mb['members'] as $m) {
            $byKey[ArtistMatcher::key($m['name'])] ??= $m;
        }
        $parts = [];
        foreach ($combo['parts'] as $part) {
            if ($m = $byKey[ArtistMatcher::key($part)] ?? null) {
                $parts[$part] = $m;
            }
        }

        return ['mbid' => $mb['mbid'], 'members' => $mb['members'], 'parts' => $parts];
    }

    /** Prüfungen, die keine offene Kombi mehr braucht (entschieden, umbenannt …), entfernen – nach einem Durchlauf */
    public static function prune(): int
    {
        $keep = [];
        foreach (self::subjects() as $w) {
            $keep[$w['type']][] = $w['subject'];
        }
        $n = 0;
        foreach ([self::NAME, self::MBID] as $type) {
            $n += DB::table('name_checks')->where('subject_type', $type)->when($keep[$type] ?? [], fn ($q, $s) => $q->whereNotIn('subject', $s))->delete();
        }

        return $n;
    }

    /** Alles, was die offenen Kombis brauchen: ganzer Name, Teile und – bei Gruppen – die Mitglieder */
    private static function subjects(): array
    {
        $out = [];
        $combos = app(ArtistCredits::class)->open();
        foreach ($combos as $combo) {
            foreach ([$combo['name'], ...$combo['parts']] as $name) {
                $out[] = ['type' => self::NAME, 'subject' => (string) $name];
            }
        }
        $checks = self::results(array_column($out, 'subject'));
        foreach ($combos as $combo) {
            foreach (self::group($combo, $checks)['parts'] ?? [] as $member) {
                $out[] = ['type' => self::MBID, 'subject' => $member['mbid']];
            }
        }

        return $out;
    }

    private static function read(string $type, array $subjects): array
    {
        $out = [];
        $int = fn ($v) => $v === null ? null : (int) $v;
        foreach (array_chunk(array_values(array_unique(array_map('strval', $subjects))), 1000) as $chunk) {
            foreach (DB::table('name_checks')->where('subject_type', $type)->whereIn('subject', $chunk)->get() as $r) {
                $out[$r->subject][$r->service] = ['status' => $r->status, 'found' => $r->found, 'match' => $r->match, 'type' => $r->type,
                    'releases' => $int($r->releases), 'popularity' => $int($r->popularity), 'listeners' => $int($r->listeners), 'namesakes' => $int($r->namesakes),
                    'mbid' => $r->mbid, 'members' => $r->members ? json_decode($r->members, true) : null, 'url' => $r->url, 'message' => $r->message];
            }
        }

        return $out;
    }

    /** Mitglied über seine MusicBrainz-ID: genau dieser Interpret bei jedem Dienst (über die Verweise bei MusicBrainz) */
    private function member(string $service, string $mbid): array|false
    {
        $mb = $this->musicbrainz->artist($mbid);
        if (! $mb) {
            return [];
        }
        $hit = fn (array $c, array $extra = []) => ['found' => mb_substr((string) $c['name'], 0, 255), 'match' => 'exakt',
            'url' => isset($c['data']['url']) ? mb_substr($c['data']['url'], 0, 255) : null] + $extra;
        $spotify = $mb['links']['spotify'][0] ?? null;
        $discogs = $mb['links']['discogs'][0] ?? null;

        // ohne Verweis bei MusicBrainz: übersprungen (nicht „nicht gefunden“ – nach Namen wäre es womöglich ein anderer)
        return match ($service) {
            'musicbrainz' => ['type' => $mb['data']['type'] ?? null, 'releases' => $this->musicbrainz->releaseCount($mbid), 'mbid' => $mbid] + $hit($mb),
            'spotify' => ! $this->spotify->available() || ! $spotify ? false
                : (($c = $this->spotify->byIds([$spotify])[0] ?? null) ? $hit($c, ['popularity' => $c['data']['popularity'] ?? null]) : []),
            'discogs' => ! $this->discogs->available() || ! $discogs ? false
                : (($c = $this->discogs->artist($discogs)) ? $hit($c, ['releases' => $this->discogs->releaseCount($discogs)]) : []),
            'audiodb' => ($c = $this->audiodb->byMbid($mbid)) ? $hit($c) : [],
            'lastfm' => ! $this->lastfm->available() ? false : (($c = $this->lastfm->byMbid($mbid)) ? $hit($c, ['listeners' => $c['data']['listeners'] ?? null]) : []),
        };
    }

    /** Bester passender Treffer mit Veröffentlichungen, Beliebtheit, Hörern …, [] = keiner, false = Dienst ohne Zugang */
    private function search(string $service, string $name): array|false
    {
        $candidates = match ($service) {
            'musicbrainz' => array_map(fn ($c) => ['name' => $c['name'], 'aliases' => array_column($c['aliases'] ?? [], 'alias'), 'type' => $c['data']['type'] ?? null,
                'url' => $c['data']['url'] ?? null, 'id' => $c['external_id']], $this->musicbrainz->search($name, 1)),
            'spotify' => $this->spotify->available() ? array_map(fn ($c) => ['name' => $c['name'], 'url' => $c['data']['url'] ?? null,
                'popularity' => $c['data']['popularity'] ?? null], $this->spotify->search($name)) : false,
            'discogs' => $this->discogs->available() ? array_map(fn ($h) => ['name' => $h['name'], 'url' => 'https://www.discogs.com/artist/'.$h['id'], 'id' => $h['id']],
                $this->discogs->search($name)) : false,
            'audiodb' => array_map(fn ($c) => ['name' => $c['name'], 'url' => 'https://www.theaudiodb.com/artist/'.$c['external_id']], $this->audiodb->search($name)),
            'lastfm' => $this->lastfm->available() ? array_map(fn ($c) => ['name' => $c['name'], 'url' => $c['data']['url'] ?? null, 'listeners' => $c['data']['listeners'] ?? null],
                array_filter([$this->lastfm->byName($name)])) : false,
        };
        if ($candidates === false) {
            return false;
        }
        $best = null;
        foreach ($candidates as $c) {
            $info = ArtistLookup::matchInfo($c['name'], $c['aliases'] ?? [], [$name]);
            if ($info['match'] !== null && ($best === null || $info['score'] > $best['score'])) {
                $best = $info + $c;
            }
        }
        if (! $best) {
            return [];
        }
        $out = ['found' => mb_substr($best['name'], 0, 255), 'match' => $best['match'], 'type' => $best['type'] ?? null,
            'popularity' => $best['popularity'] ?? null, 'listeners' => $best['listeners'] ?? null, 'url' => $best['url'] ? mb_substr($best['url'], 0, 255) : null];
        if ($service === 'musicbrainz') {
            // wie viele heißen genau so (ab 2: Treffer anderer Dienste womöglich von jemand anderem) – und bei Gruppen die Mitglieder
            $out += ['mbid' => $best['id'], 'releases' => $this->musicbrainz->releaseCount($best['id']), 'namesakes' => $this->musicbrainz->namesakes($name),
                'members' => in_array($best['type'] ?? null, self::GROUPS, true) ? $this->musicbrainz->members($best['id']) : null];
        } elseif ($service === 'discogs') {
            $out['releases'] = $this->discogs->releaseCount($best['id']);
        }

        return $out;
    }
}
