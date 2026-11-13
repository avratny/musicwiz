<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Lokale MusicBrainz-Daten: alle Interpreten aus den JSON-Dumps (Einstellungen › MusicBrainz), für die ganze App –
 * Suche über den Vergleichsschlüssel (ArtistMatcher::key) für Name und Schreibweisen, Mitglieder von Gruppen, ohne
 * Abfrage beim Dienst.
 * Aktualisiert wird in Zwischentabellen (_neu), die am Ende in einem Schritt die alten ersetzen – bis dahin gilt der
 * alte Stand. Das Lesen der Dumps übernimmt tools/mbimport.py (als Strom aus dem Netz, nichts auf der Platte).
 */
class MusicBrainzData
{
    public const ACTIVITY = 'musicbrainz';

    private const STOP = 'musicbrainz:stop';

    private const BATCH = 1000;

    /** Tabellen der lokalen Daten (beim Aktualisieren je eine Zwischentabelle _neu) */
    private const TABLES = ['mb_artists', 'mb_names', 'mb_members'];

    /** Treffer-Bewertung wie beim Abgleich: genau der Name, genau eine Schreibweise, Groß/klein, nur der Schlüssel */
    private const SCORES = ['name' => 100, 'alias' => 95, 'case' => 92, 'key' => 90];

    private ?bool $available = null;

    /** Tabellen anlegen ($suffix '' = in Gebrauch, '_neu' = Zwischentabellen); Indizes erst nach dem Laden (schneller) */
    public static function createTables(string $suffix, bool $indexes = true): void
    {
        self::createMembersTable($suffix);
        Schema::create('mb_artists'.$suffix, function (Blueprint $t) {
            $t->char('mbid', 36)->primary();
            $t->string('name', 512)->collation('utf8mb4_bin');
            $t->string('sort_name', 512)->nullable();
            $t->string('type', 32)->nullable();          // Person, Group, Orchestra, Choir, Character, Other
            $t->string('country', 8)->nullable();
            $t->string('disambiguation', 512)->nullable();
            $t->string('begin', 16)->nullable();
            $t->string('end', 16)->nullable();
            $t->string('genres', 512)->nullable();       // die häufigsten, mit „, “
            $t->json('links')->nullable();               // spotify, discogs, wikidata: IDs
            $t->unsignedInteger('releases')->default(0); // Release-Groups
        });
        Schema::create('mb_names'.$suffix, function (Blueprint $t) use ($suffix, $indexes) {
            $t->id();
            $t->char('mbid', 36);
            $t->string('name', 512)->collation('utf8mb4_bin');
            $t->string('name_key', 191);
            $t->boolean('alias')->default(false);
            $t->string('alias_type', 32)->nullable();     // Artist name, Legal name, Search hint …
            if ($indexes) {
                $t->index('name_key', 'mb_names'.$suffix.'_key');
                $t->index('mbid', 'mb_names'.$suffix.'_mbid');
            }
        });
    }

    /** Mitglieder von Gruppen („member of band“): Gruppe → Mitglied, ehemalig ja/nein */
    public static function createMembersTable(string $suffix): void
    {
        Schema::create('mb_members'.$suffix, function (Blueprint $t) use ($suffix) {
            $t->char('group_mbid', 36);
            $t->char('member_mbid', 36);
            $t->boolean('ended')->default(false);
            $t->primary(['group_mbid', 'member_mbid']);
            $t->index('member_mbid', 'mb_members'.$suffix.'_member');
        });
    }

    /**
     * Mitglieder einer Gruppe (aktuelle zuerst) – leer ohne lokale Daten.
     *
     * @return list<array{mbid: string, name: string, type: ?string, ended: bool}>
     */
    public function members(string $groupMbid): array
    {
        return $this->available() ? DB::table('mb_members')->join('mb_artists', 'mb_artists.mbid', '=', 'mb_members.member_mbid')
            ->where('group_mbid', $groupMbid)->orderBy('ended')->orderBy('mb_artists.name')
            ->get(['mb_artists.mbid', 'mb_artists.name', 'mb_artists.type', 'mb_members.ended'])
            ->map(fn ($r) => ['mbid' => $r->mbid, 'name' => $r->name, 'type' => $r->type, 'ended' => (bool) $r->ended])->all() : [];
    }

    /**
     * Gruppen, in denen der Interpret Mitglied ist (war).
     *
     * @return list<array{mbid: string, name: string, type: ?string, ended: bool}>
     */
    public function memberOf(string $mbid): array
    {
        return $this->available() ? DB::table('mb_members')->join('mb_artists', 'mb_artists.mbid', '=', 'mb_members.group_mbid')
            ->where('member_mbid', $mbid)->orderBy('ended')->orderBy('mb_artists.name')
            ->get(['mb_artists.mbid', 'mb_artists.name', 'mb_artists.type', 'mb_members.ended'])
            ->map(fn ($r) => ['mbid' => $r->mbid, 'name' => $r->name, 'type' => $r->type, 'ended' => (bool) $r->ended])->all() : [];
    }

    /** Sind lokale Daten da? */
    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('mb_artists') && DB::table('mb_artists')->exists();
    }

    /** Stand für die Einstellungen */
    public function status(): array
    {
        $settings = app(Settings::class);

        return [
            'dump' => $settings->get('musicbrainz_dump'),
            'timestamp' => $settings->get('musicbrainz_timestamp'),
            'imported_at' => $settings->get('musicbrainz_imported_at'),
            'artists' => (int) $settings->get('musicbrainz_artists', 0),
            'names' => (int) $settings->get('musicbrainz_names', 0),
        ];
    }

    /**
     * Interpreten, deren Name oder Schreibweise zum Namen passt (gleicher Vergleichsschlüssel) – bester zuerst, bei
     * Gleichstand der mit den meisten Veröffentlichungen. Format wie MusicBrainz::artist().
     *
     * @return list<array>
     */
    public function search(string $name, int $limit = 2): array
    {
        $key = mb_substr(ArtistMatcher::key($name), 0, 191);
        if ($key === '' || ! $this->available()) {
            return [];
        }
        $scores = [];
        foreach (DB::table('mb_names')->where('name_key', $key)->limit(500)->get(['mbid', 'name', 'alias']) as $r) {
            $score = $r->name === $name ? self::SCORES[$r->alias ? 'alias' : 'name']
                : (mb_strtolower($r->name) === mb_strtolower($name) ? self::SCORES['case'] : self::SCORES['key']);
            $scores[$r->mbid] = max($scores[$r->mbid] ?? 0, $score);
        }
        if (! $scores) {
            return [];
        }
        $artists = DB::table('mb_artists')->whereIn('mbid', array_keys($scores))->get()->keyBy('mbid');
        $ids = array_keys($scores);
        usort($ids, fn ($a, $b) => [$scores[$b], (int) ($artists[$b]->releases ?? 0)] <=> [$scores[$a], (int) ($artists[$a]->releases ?? 0)]);

        return array_values(array_filter(array_map(fn ($id) => isset($artists[$id]) ? $this->candidate($artists[$id], $scores[$id]) : null, array_slice($ids, 0, $limit))));
    }

    /** Wie viele Interpreten heißen genau so (Vergleichsschlüssel des Namens, ohne Schreibweisen) */
    public function namesakes(string $name): int
    {
        $key = mb_substr(ArtistMatcher::key($name), 0, 191);

        return $key === '' ? 0 : DB::table('mb_names')->where('name_key', $key)->where('alias', false)->distinct()->count('mbid');
    }

    /**
     * Welcher MusicBrainz-Eintrag ist das? Je Interpret (Name und Schreibweisen) – eindeutig oder gar nicht: zuerst der
     * Name eines Eintrags (Vergleichsschlüssel, also auch ohne Groß/klein: „Beam“ und „BEAM“ sind zwei Kandidaten),
     * sonst eine Schreibweise (MusicBrainz-Aliase: „Puff Daddy“ → Diddy). Gibt es auf einer Stufe mehrere, ist er
     * mehrdeutig (keine Zuordnung).
     * Eine schon gewählte ID (Abgleich) gilt immer.
     *
     * @param  array<int, array{mbid: ?string, spellings: list<string>}>  $artists
     * @return array<int, array{mbid: ?string, candidates: int}>
     */
    public function identify(array $artists): array
    {
        if (! $this->available()) {
            return [];
        }
        $out = [];
        $keysOf = [];
        foreach ($artists as $id => $a) {
            if ($a['mbid']) {
                $out[$id] = ['mbid' => $a['mbid'], 'candidates' => 1];

                continue;
            }
            $keysOf[$id] = array_values(array_unique(array_filter(array_map(fn ($n) => mb_substr(ArtistMatcher::key((string) $n), 0, 191), $a['spellings']))));
        }
        $rows = [];
        foreach (array_chunk(array_values(array_unique(array_merge([], ...array_values($keysOf)))), 1000) as $chunk) {
            foreach (DB::table('mb_names')->whereIn('name_key', $chunk)->get(['mbid', 'name', 'name_key', 'alias']) as $r) {
                $rows[$r->name_key][] = $r;
            }
        }
        foreach ($keysOf as $id => $keys) {
            $found = array_merge([], ...array_map(fn ($k) => $rows[$k] ?? [], $keys));
            $levels = [
                array_filter($found, fn ($r) => ! $r->alias),
                array_filter($found, fn ($r) => (bool) $r->alias),
            ];
            $mbid = null;
            foreach ($levels as $level) {
                $ids = array_unique(array_column($level, 'mbid'));
                if ($ids) {
                    $mbid = count($ids) === 1 ? reset($ids) : null;
                    break;
                }
            }
            $out[$id] = ['mbid' => $mbid, 'candidates' => count(array_unique(array_column($found, 'mbid')))];
        }

        return $out;
    }

    /**
     * Kurzinfo zu Einträgen (Name, Typ, Land, Zusatz, Beginn, Veröffentlichungen).
     *
     * @return array<string, object>
     */
    public function info(array $mbids): array
    {
        return $this->available() && $mbids ? DB::table('mb_artists')->whereIn('mbid', array_values(array_unique($mbids)))
            ->get(['mbid', 'name', 'type', 'country', 'disambiguation', 'begin', 'releases'])->keyBy('mbid')->all() : [];
    }

    /** Ein Interpret nach MusicBrainz-ID (oder null, wenn er lokal fehlt) */
    public function artist(string $mbid, int $score = 100): ?array
    {
        if (! $this->available() || ! ($row = DB::table('mb_artists')->where('mbid', $mbid)->first())) {
            return null;
        }

        return $this->candidate($row, $score);
    }

    /** Veröffentlichungen (Release-Groups) – null, wenn der Interpret lokal fehlt */
    public function releases(string $mbid): ?int
    {
        $n = $this->available() ? DB::table('mb_artists')->where('mbid', $mbid)->value('releases') : null;

        return $n === null ? null : (int) $n;
    }

    private function candidate(object $a, int $score): array
    {
        $links = json_decode((string) $a->links, true) ?: [];
        $aliases = DB::table('mb_names')->where('mbid', $a->mbid)->where('alias', true)->get(['name', 'alias_type']);

        return [
            'service' => 'musicbrainz',
            'external_id' => $a->mbid,
            'mbid' => $a->mbid,
            'name' => $a->name,
            'score' => $score,
            'data' => array_filter([
                'type' => $a->type,
                'country' => $a->country,
                'disambiguation' => $a->disambiguation,
                'sort_name' => $a->sort_name,
                'begin' => $a->begin,
                'end' => $a->end,
                'genres' => $a->genres ? explode(', ', $a->genres) : null,
                'url' => 'https://musicbrainz.org/artist/'.$a->mbid,
                'releases' => (int) $a->releases,
            ], fn ($v) => $v !== null && $v !== ''),
            'aliases' => $aliases->map(fn ($al) => ['alias' => $al->name, 'type' => $al->alias_type, 'locale' => null, 'primary' => false])->all(),
            'images' => [],
            'links' => ['spotify' => $links['spotify'] ?? [], 'discogs' => $links['discogs'] ?? []],
            'members' => in_array($a->type, ['Group', 'Orchestra', 'Choir'], true) ? $this->members($a->mbid) : [],
        ];
    }

    public static function stop(): void
    {
        Cache::put(self::STOP, true, now()->addHour());
    }

    /**
     * Neuesten Dump laden (außer er ist schon da und $force fehlt). Gibt eine Meldung zurück.
     *
     * @throws RuntimeException
     */
    public function update(bool $force = false): string
    {
        $base = rtrim((string) config('musicwiz.musicbrainz_dumps'), '/').'/';
        $latest = $this->latest($base);
        $settings = app(Settings::class);
        if (! $force && $settings->get('musicbrainz_dump') === $latest && $this->available()) {
            return 'MusicBrainz-Daten sind aktuell (Stand '.$latest.').';
        }

        Cache::forget(self::STOP);
        $this->dropStaging();
        self::createTables('_neu', indexes: false);
        $estimate = max(1, (int) $settings->get('musicbrainz_artists', 2600000));
        Activity::start(self::ACTIVITY, 'MusicBrainz-Daten laden', $estimate, 'Veröffentlichungen zählen …');

        $result = $this->load($base.$latest, $estimate);
        if ($result['stopped'] || ! $result['done']) {
            $this->dropStaging();
            if ($result['stopped']) {
                return 'Laden angehalten – es gilt weiter der bisherige Stand.';
            }
            throw new RuntimeException('MusicBrainz-Import abgebrochen: '.mb_substr($result['error'] ?: 'unvollständig', 0, 200));
        }

        Activity::progress(self::ACTIVITY, $result['artists'], $result['artists'], null, 'Suchindex anlegen …');
        $this->activate();
        $settings->set(['musicbrainz_dump' => $latest, 'musicbrainz_timestamp' => $result['timestamp'], 'musicbrainz_imported_at' => now()->toDateTimeString(),
            'musicbrainz_artists' => $result['artists'], 'musicbrainz_names' => $result['names']]);

        return 'MusicBrainz-Daten geladen: '.number_format($result['artists'], 0, ',', '.').' Interpreten (Stand '.$latest.').';
    }

    /** Name des neuesten Dumps (Datei LATEST) */
    private function latest(string $base): string
    {
        $context = stream_context_create(['http' => ['header' => 'User-Agent: MusicWiz/0.4', 'timeout' => 30]]);
        $latest = trim((string) @file_get_contents($base.'LATEST', false, $context));
        if ($latest === '') {
            throw new RuntimeException('MusicBrainz-Dumps nicht erreichbar ('.$base.')');
        }

        return $latest;
    }

    /**
     * Dumps über tools/mbimport.py lesen und paketweise in die Zwischentabellen schreiben.
     *
     * @return array{done: bool, stopped: bool, error: string, timestamp: ?string, artists: int, names: int}
     */
    private function load(string $dir, int $estimate): array
    {
        $process = new Process(['python3', base_path('tools/mbimport.py'), $dir.'/release-group.tar.xz', $dir.'/artist.tar.xz']);
        $process->setTimeout(null);
        $process->start();
        $batch = ['artists' => [], 'names' => [], 'members' => []];
        $result = ['done' => false, 'stopped' => false, 'error' => '', 'timestamp' => null, 'artists' => 0, 'names' => 0];
        $buffer = '';
        foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $msg = json_decode(substr($buffer, 0, $pos), true);
                $buffer = substr($buffer, $pos + 1);
                match ($msg['type'] ?? null) {
                    'artist' => $this->collect($msg, $batch, $result),
                    'progress' => $msg['stage'] === 'artist'
                        ? Activity::progress(self::ACTIVITY, $msg['done'], max($estimate, $msg['done']), null, 'Interpreten übernehmen')
                        : Activity::progress(self::ACTIVITY, 0, null, null, number_format($msg['done'], 0, ',', '.').' Veröffentlichungen gezählt'),
                    'timestamp' => $result['timestamp'] = $msg['value'],
                    'done' => $result['done'] = true,
                    default => null,
                };
                if (($msg['type'] ?? null) === 'progress' && Cache::get(self::STOP)) {
                    $process->stop(5);
                    break 2;
                }
            }
        }
        $process->wait();
        $result['stopped'] = (bool) Cache::pull(self::STOP);
        $result['error'] = trim($process->getErrorOutput());
        if ($result['done'] && ! $result['stopped']) {
            $this->flush($batch, $result);
        }

        return $result;
    }

    /** Einen Interpreten in den Puffer; volle Pakete schreiben */
    private function collect(array $msg, array &$batch, array &$result): void
    {
        [$artist, $names] = $this->rows($msg);
        $batch['artists'][] = $artist;
        array_push($batch['names'], ...$names);
        // Mitgliedschaften stehen bei Gruppe und Person – doppelte fallen über den Schlüssel weg
        foreach ($msg['members'] ?? [] as [$group, $member, $ended]) {
            $batch['members'][$group.$member] = ['group_mbid' => $group, 'member_mbid' => $member, 'ended' => $ended];
        }
        if (count($batch['artists']) >= self::BATCH) {
            $this->flush($batch, $result);
        }
    }

    private function flush(array &$batch, array &$result): void
    {
        DB::table('mb_artists_neu')->insertOrIgnore($batch['artists']);
        foreach (array_chunk($batch['names'], self::BATCH) as $chunk) {
            DB::table('mb_names_neu')->insert($chunk);
        }
        foreach (array_chunk(array_values($batch['members']), self::BATCH) as $chunk) {
            DB::table('mb_members_neu')->insertOrIgnore($chunk);
        }
        $result['artists'] += count($batch['artists']);
        $result['names'] += count($batch['names']);
        $batch = ['artists' => [], 'names' => [], 'members' => []];
    }

    /** Suchindex anlegen, dann die Zwischentabellen in einem Schritt an die Stelle der alten setzen */
    private function activate(): void
    {
        Schema::table('mb_names_neu', function (Blueprint $t) {
            $t->index('name_key', 'mb_names_key');
            $t->index('mbid', 'mb_names_mbid');
        });
        DB::statement('RENAME TABLE '.implode(', ', array_map(fn ($t) => $t.' TO '.$t.'_alt, '.$t.'_neu TO '.$t, self::TABLES)));
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table.'_alt');
        }
        $this->available = null;
    }

    private function dropStaging(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table.'_neu');
        }
    }

    /** Zeile für mb_artists und die Namen (Name + Schreibweisen) mit Vergleichsschlüssel */
    private function rows(array $a): array
    {
        $cut = fn ($v, $n) => $v === null || $v === '' ? null : mb_substr((string) $v, 0, $n);
        $artist = [
            'mbid' => $a['id'], 'name' => mb_substr($a['name'], 0, 512), 'sort_name' => $cut($a['sort'], 512), 'type' => $cut($a['kind'], 32),
            'country' => $cut($a['country'], 8), 'disambiguation' => $cut($a['disambiguation'], 512), 'begin' => $cut($a['begin'], 16), 'end' => $cut($a['end'], 16),
            'genres' => $cut(implode(', ', $a['genres']), 512), 'links' => $a['links'] ? json_encode($a['links']) : null, 'releases' => (int) $a['releases'],
        ];
        $names = [];
        $seen = [];
        foreach ([[$a['name'], null, false], ...array_map(fn ($al) => [$al[0], $al[1], true], $a['aliases'])] as [$name, $type, $alias]) {
            $key = mb_substr(ArtistMatcher::key((string) $name), 0, 191);
            if ($key === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $names[] = ['mbid' => $a['id'], 'name' => mb_substr((string) $name, 0, 512), 'name_key' => $key, 'alias' => $alias, 'alias_type' => $cut($type, 32)];
        }

        return [$artist, $names];
    }
}
