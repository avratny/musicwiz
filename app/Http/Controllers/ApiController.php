<?php

namespace App\Http\Controllers;

use App\Jobs\ImportMusicBrainzAliases;
use App\Jobs\PrefetchUnknownArtists;
use App\Jobs\RefreshCleanup;
use App\Models\Album;
use App\Models\Artist;
use App\Models\HistoryEntry;
use App\Models\Operation;
use App\Models\PendingChange;
use App\Models\Track;
use App\Support\ArtistActions;
use App\Support\ArtistDirectory;
use App\Support\ArtistGenreSuggestions;
use App\Support\ArtistNames;
use App\Support\ArtistQuickMatch;
use App\Support\CleanupChecks;
use App\Support\CombinedSuggestions;
use App\Support\InboxWatch;
use App\Support\Lookup;
use App\Support\PendingChanges;
use App\Support\Scanner;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Schnittstelle zum Prüfen und Vormerken (z. B. für eine Analyse von außen): lesen und Änderungen vormerken bzw.
 * verwerfen – in die Dateien geschrieben wird nie über die Schnittstelle, nur per „Änderungen schreiben“ in der App.
 * Pfade relativ zum Musikordner. Übersicht aller Aufrufe: GET /api
 */
class ApiController extends Controller
{
    public function __construct(private Settings $settings) {}

    public function index(): JsonResponse
    {
        return $this->json([
            'app' => 'MusicWiz',
            'note' => 'Lesen und vormerken – geschrieben wird nur in der App („Änderungen schreiben“). Pfade relativ zu base.',
            'base' => $this->base(),
            'counts' => [
                'tracks' => Track::where('location', 'library')->count(),
                'albums' => Album::where('location', 'library')->count(),
                'inbox_tracks' => Track::where('location', 'inbox')->count(),
                'artists' => Artist::count(),
                'pending_tracks' => PendingChange::count(),
            ],
            'endpoints' => [
                'GET /api/stats' => 'Zahlen zum Bestand',
                'GET /api/checks' => 'Prüfungen mit Anzahl, Schritt und ob der Stand aktuell ist',
                'GET /api/checks/{key}?limit=500&offset=0&fresh=0' => 'Funde einer Prüfung (fresh=1: jetzt neu rechnen, kann dauern)',
                'POST /api/checks/{key}/stage' => '{"ids": ["a12", …]} oder {"all": true} – Behebung vormerken (nur Tags/Ablage)',
                'GET /api/pending' => 'alles Vorgemerkte wie der JSON-Export (inkl. geplanter Aktionen)',
                'POST /api/pending/stage' => '{"label": "…", "changes": [{"track_id": 1 | "path": "library/…", "tags": {"year": "1999"}}]}',
                'POST /api/pending/discard' => '{"album_ids": [..]} | {"track_ids": [..]} | {"all": true}',
                'GET /api/albums?q=&location=library&limit=50' => 'Alben suchen (Titel, Album-Interpret, Ordner)',
                'GET /api/albums/{id}' => 'Album mit allen Titeln, Tags und Vormerkungen',
                'GET /api/tracks?q=&limit=100' => 'Titel suchen (Interpret, Titel, Album, Pfad)',
                'GET /api/artists?q=&limit=100' => 'Künstlerverzeichnis mit Schreibweisen',
                'GET /api/artists/{id}' => 'ein Eintrag mit Schreibweisen, Titeln im Bestand und Mitgliedern laut MusicBrainz',
                'POST /api/artists' => '{"name", "mbid"?, "genre"?, "aliases"?: [..], "image_url"?} – anlegen bzw. ergänzen',
                'POST /api/artists/{id}' => '{"mbid"?, "discogs_id"?, "genre"?} – Felder setzen',
                'POST /api/artists/{id}/rename' => '{"name"} – umbenennen (alter Name wird Schreibweise)',
                'POST /api/artists/{id}/merge' => '{"ids": [..]} – „Ist derselbe wie …“',
                'POST /api/artists/{id}/aliases' => '{"alias"} – Schreibweise hinzufügen',
                'DELETE /api/artists/{id}/aliases' => '{"alias"} – Schreibweise entfernen (wird gemerkt)',
                'POST /api/artists/{id}/split' => '{"parts"?: [..]} – in Einzelkünstler trennen',
                'GET /api/quick?kind=sure&limit=100' => 'Schnell erfassen: offene Namen mit Treffer und Bildvorschlägen (kind: sure|name|unsure|pending)',
                'POST /api/quick' => '{"picks": [{"name", "mb_spelling"?: true, "image"?: url}]} – anlegen',
                'POST /api/quick/split' => '{"name", "parts"?: [..]} – offenen Kombi-Namen trennen',
                'GET /api/combined' => 'Kombi-Namen (&, und, Komma) mit Vorschlag',
                'POST /api/combined' => '{"names": [..]} oder {"all": true} – Vorschläge übernehmen',
                'GET /api/history?limit=20' => 'letzte Aktionen im Verlauf',
                'GET /api/history/{id}?errors=1&limit=2000' => 'eine Aktion mit allen Einträgen (errors=1: nur Fehler/Hinweise)',
                'GET /api/status' => 'laufende Vorgänge, Warteschlange, fehlgeschlagene Jobs, Speicherplatz, Ordnerrechte, PHP-Grenzen',
                'GET /api/logs?entries=50&level=warning' => 'Fehlerprotokoll (neueste zuerst; level: debug|info|warning|error)',
            ],
            'tag_keys' => Track::TAG_KEYS,
        ]);
    }

    public function stats(): JsonResponse
    {
        $lib = Track::where('location', 'library');

        return $this->json([
            'tracks' => (clone $lib)->count(),
            'albums' => Album::where('location', 'library')->count(),
            'compilations' => Album::where('location', 'library')->where('is_compilation', true)->count(),
            'formats' => (clone $lib)->selectRaw('ext, count(*) as n')->groupBy('ext')->pluck('n', 'ext'),
            'without_year' => (clone $lib)->where(fn ($q) => $q->whereNull('year')->orWhere('year', ''))->count(),
            'without_genre' => (clone $lib)->where(fn ($q) => $q->whereNull('genres')->orWhere('genres', '[]'))->count(),
            'without_cover' => (clone $lib)->where('cover_embedded', false)->count(),
            'inbox_tracks' => Track::where('location', 'inbox')->count(),
            'artists' => Artist::count(),
            'artists_with_mbid' => Artist::whereNotNull('mbid')->count(),
            'pending_tracks' => PendingChange::count(),
        ]);
    }

    public function checks(CleanupChecks $checks): JsonResponse
    {
        $states = $checks->countsWithState();
        $out = [];
        foreach (CleanupChecks::definitions() as $key => [$title, $description, $fix]) {
            $out[] = ['key' => $key, 'title' => $title, 'description' => $description, 'fix' => $fix, 'step' => CleanupChecks::stepOf($key),
                'count' => $states[$key]['count'], 'stale' => $states[$key]['stale']];
        }

        return $this->json(['checks' => $out]);
    }

    public function check(Request $request, CleanupChecks $checks, string $key): JsonResponse
    {
        abort_unless(isset(CleanupChecks::definitions()[$key]), 404, 'Unbekannte Prüfung');
        if ($request->boolean('fresh')) {
            [$list, $stale] = [$checks->findings($key), false];
        } else {
            [$list, $stale] = $checks->displayFindings($key);
            if ($stale) {
                Cache::put(CleanupChecks::FOCUS, $key, now()->addMinutes(30));
                RefreshCleanup::request(); // im Hintergrund neu rechnen, später erneut abfragen
            }
        }
        $limit = min(5000, max(1, $request->integer('limit', 500)));
        $offset = max(0, $request->integer('offset'));

        return $this->json([
            'key' => $key,
            'stale' => $stale,
            'total' => count($list),
            'offset' => $offset,
            'findings' => array_map(fn ($f) => $this->relPaths($f), array_slice($list, $offset, $limit)),
        ]);
    }

    public function stageFindings(Request $request, CleanupChecks $checks, PendingChanges $pending, string $key): JsonResponse
    {
        abort_unless(isset(CleanupChecks::definitions()[$key]), 404, 'Unbekannte Prüfung');
        $ids = (array) $request->input('ids', []);
        $findings = collect($checks->findings($key))->reject(fn ($f) => $f['dismissed'] ?? false);
        if (! $request->boolean('all')) {
            $findings = $findings->whereIn('id', $ids);
        }
        $label = CleanupChecks::definitions()[$key][0];
        $staged = $tracks = 0;
        $skipped = [];
        foreach ($findings as $f) {
            if (! $f['actions'] || ! PendingChanges::stageable($f['actions'])) {
                $skipped[] = ['id' => $f['id'], 'reason' => $f['actions'] ? 'nicht vormerkbar (Papierkorb, Messen …) – nur in der App' : 'keine Behebung'];

                continue;
            }
            $tracks += $pending->stage($f['actions'], $label);
            $staged++;
        }
        if ($staged) {
            RefreshCleanup::request();
        }
        Log::info('Schnittstelle: Behebungen vormerken', ['check' => $key, 'findings' => $staged, 'tracks' => $tracks]);

        return $this->json(['staged_findings' => $staged, 'staged_tracks' => $tracks, 'skipped' => $skipped,
            'missing' => array_values(array_diff($ids, $findings->pluck('id')->all()))]);
    }

    public function pending(PendingChanges $pending): JsonResponse
    {
        return $this->json($pending->export());
    }

    public function stage(Request $request, PendingChanges $pending): JsonResponse
    {
        $label = trim((string) $request->input('label')) ?: 'Schnittstelle';
        $staged = [];
        $errors = [];
        $dirs = [];
        foreach ((array) $request->input('changes', []) as $i => $c) {
            $track = ! empty($c['track_id']) ? Track::find($c['track_id']) : (! empty($c['path']) ? Track::where('path', $this->abs((string) $c['path']))->first() : null);
            $tags = (array) ($c['tags'] ?? []);
            $unknown = array_diff(array_keys($tags), Track::TAG_KEYS);
            if (! $track || $track->location !== 'library') {
                $errors[] = ['index' => $i, 'error' => 'Titel nicht gefunden (nur Lib)'];
            } elseif ($unknown || ! $tags) {
                $errors[] = ['index' => $i, 'error' => $tags ? 'unbekannte Felder: '.implode(', ', $unknown) : 'keine Tags'];
            } else {
                $pending->stageTrack($track, $tags, false, $label);
                $dirs[$track->dir] = true;
                $staged[] = $track->id;
            }
        }
        $pending->afterChange(array_keys($dirs));
        if ($staged) {
            RefreshCleanup::request();
        }
        Log::info('Schnittstelle: Tags vormerken', ['label' => $label, 'tracks' => $staged, 'errors' => count($errors)]);

        return $this->json(['staged' => count($staged), 'track_ids' => $staged, 'errors' => $errors], $errors && ! $staged ? 422 : 200);
    }

    public function discard(Request $request, PendingChanges $pending): JsonResponse
    {
        if ($request->boolean('all')) {
            $n = $pending->discard();
        } elseif ($request->filled('album_ids')) {
            $n = $pending->discard(array_map('intval', (array) $request->input('album_ids')));
        } else {
            $ids = PendingChange::whereIn('track_id', array_map('intval', (array) $request->input('track_ids', [])))->pluck('id')->all();
            $pending->discardIds($ids);
            $n = count($ids);
        }
        RefreshCleanup::request();
        Log::info('Schnittstelle: Vormerkungen verworfen', ['count' => $n, 'request' => $request->all()]);

        return $this->json(['discarded' => $n]);
    }

    public function albums(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $albums = Album::where('location', $request->input('location', 'library'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('title', 'like', "%$q%")->orWhere('albumartist', 'like', "%$q%")->orWhere('dir', 'like', "%$q%")))
            ->orderBy('dir')->limit(min(500, $request->integer('limit', 50)))->get();

        return $this->json(['albums' => $albums->map(fn (Album $a) => $this->albumData($a))->all()]);
    }

    public function album(Album $album): JsonResponse
    {
        $tracks = $album->tracks()->with('pendingChange')->orderBy('disc')->orderByRaw('CAST(track AS INTEGER)')->get();

        return $this->json($this->albumData($album) + ['tracks' => $tracks->map(fn (Track $t) => $this->trackData($t))->all()]);
    }

    public function tracks(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        abort_if($q === '', 422, 'Parameter q fehlt');
        $tracks = Track::with('pendingChange')->where('location', $request->input('location', 'library'))
            ->where(fn ($x) => $x->where('artist', 'like', "%$q%")->orWhere('title', 'like', "%$q%")->orWhere('album', 'like', "%$q%")->orWhere('path', 'like', "%$q%"))
            ->orderBy('path')->limit(min(1000, $request->integer('limit', 100)))->get();

        return $this->json(['tracks' => $tracks->map(fn (Track $t) => $this->trackData($t))->all()]);
    }

    public function artists(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $artists = Artist::with('aliases')
            ->when($q !== '', fn ($w) => $w->where('name', 'like', "%$q%")->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', "%$q%")))
            ->orderBy('name')->limit(min(2000, $request->integer('limit', 100)))->get();

        return $this->json(['artists' => $artists->map(fn (Artist $a) => [
            'id' => $a->id, 'name' => $a->name, 'mbid' => $a->mbid, 'discogs_id' => $a->discogs_id, 'default_genre' => $a->default_genre,
            'disambiguation' => $a->disambiguation, 'image' => (bool) $a->image, 'aliases' => $a->aliases->pluck('alias')->all(),
        ])->all()]);
    }

    public function history(Request $request): JsonResponse
    {
        $ops = Operation::latest('id')->limit(min(200, $request->integer('limit', 20)))->get();

        return $this->json(['operations' => $ops->map(fn (Operation $o) => [
            'id' => $o->id, 'type' => $o->type, 'description' => $o->description, 'status' => $o->status,
            'done' => $o->done, 'failed' => $o->failed, 'total' => $o->total, 'error' => $o->error,
            'created_at' => $o->created_at?->toIso8601String(), 'finished_at' => $o->finished_at?->toIso8601String(),
        ])->all()]);
    }

    // ---------------------------------------------------------------- Künstlerverzeichnis (ändert sofort, Tags nur vorgemerkt)

    public function artist(Artist $artist, Lookup $lookup): JsonResponse
    {
        $counts = app(ArtistDirectory::class)->namesInCollection();

        return $this->json([
            'id' => $artist->id, 'name' => $artist->name, 'mbid' => $artist->mbid, 'discogs_id' => $artist->discogs_id, 'default_genre' => $artist->default_genre,
            'image' => (bool) $artist->image, 'aliases' => $artist->aliases()->pluck('alias')->all(),
            'tracks' => collect([$artist->name, ...$artist->aliases()->pluck('alias')->all()])->mapWithKeys(fn ($n) => [$n => $counts[$n] ?? 0])->filter()->all(),
            'looks_collaboration' => ArtistNames::looksCollaboration($artist->name),
            'split_suggestion' => ArtistNames::suggestSplit($artist->name),
            'musicbrainz_members' => $artist->mbid ? rescue(fn () => $lookup->musicbrainzMembers($artist->mbid), [], report: false) : [],
        ]);
    }

    public function createArtist(Request $request, ArtistActions $actions): JsonResponse
    {
        return $this->change(fn () => $actions->create((string) $request->input('name'), $request->input('mbid'), $request->input('genre'),
            (array) $request->input('aliases', []), $request->input('image_url'), 'Schnittstelle: neuer Künstler'), 'Künstler anlegen', $request->all());
    }

    public function updateArtist(Request $request, Artist $artist): JsonResponse
    {
        return $this->change(function () use ($request, $artist) {
            foreach (['mbid' => 'mbid', 'discogs_id' => 'discogs_id', 'genre' => 'default_genre'] as $in => $col) {
                if ($request->has($in)) {
                    $artist->{$col} = trim((string) $request->input($in)) ?: null;
                }
            }
            $artist->save();

            return ['artist' => $artist];
        }, 'Künstler ändern', ['id' => $artist->id] + $request->all());
    }

    public function renameArtist(Request $request, Artist $artist, ArtistActions $actions): JsonResponse
    {
        return $this->change(fn () => $actions->rename($artist, (string) $request->input('name')), 'Künstler umbenennen', ['from' => $artist->name, 'to' => $request->input('name')]);
    }

    public function mergeArtists(Request $request, Artist $artist, ArtistActions $actions): JsonResponse
    {
        return $this->change(fn () => $actions->merge($artist, (array) $request->input('ids', [])), 'Künstler zusammenführen', ['into' => $artist->name, 'ids' => $request->input('ids')]);
    }

    public function addArtistAlias(Request $request, Artist $artist, ArtistActions $actions): JsonResponse
    {
        return $this->change(fn () => $actions->addAlias($artist, (string) $request->input('alias')), 'Schreibweise hinzufügen', ['artist' => $artist->name, 'alias' => $request->input('alias')]);
    }

    public function removeArtistAlias(Request $request, Artist $artist, ArtistActions $actions): JsonResponse
    {
        return $this->change(fn () => ['removed' => $actions->removeAlias($artist, (string) $request->input('alias'))], 'Schreibweise entfernen', ['artist' => $artist->name, 'alias' => $request->input('alias')]);
    }

    public function splitArtist(Request $request, Artist $artist, ArtistActions $actions): JsonResponse
    {
        $parts = (array) ($request->input('parts') ?: ArtistNames::suggestSplit($artist->name));

        return $this->change(fn () => $actions->splitInto($artist, $parts), 'Künstler trennen', ['artist' => $artist->name, 'parts' => $parts]);
    }

    public function quick(Request $request, ArtistQuickMatch $quick, Lookup $lookup): JsonResponse
    {
        $kind = (string) $request->input('kind', 'sure');
        $rows = array_values(array_filter($quick->rows(), fn ($r) => $r['kind'] === $kind));
        $page = array_slice($rows, max(0, $request->integer('offset')), min(500, $request->integer('limit', 100)));
        $images = $quick->images($page, $lookup);

        return $this->json(['kind' => $kind, 'total' => count($rows), 'rows' => array_map(fn ($r) => $r + ['images' => $images[$r['key']] ?? []], $page)]);
    }

    public function quickSave(Request $request, ArtistQuickMatch $quick, Lookup $lookup, ArtistGenreSuggestions $genres): JsonResponse
    {
        $wanted = collect((array) $request->input('picks', []))->keyBy('name');
        $picks = [];
        foreach ($quick->rows() as $r) {
            if (($p = $wanted[$r['name']] ?? null) && in_array($r['kind'], ['sure', 'name'], true) && $r['hit']) {
                $picks[] = ['name' => $r['name'], 'hit' => $r['hit'], 'mb_spelling' => $r['hit']['name'] !== $r['name'] && ($p['mb_spelling'] ?? true), 'image' => $p['image'] ?? null];
            }
        }
        $missing = array_values(array_diff($wanted->keys()->all(), array_column($picks, 'name')));

        return $this->change(fn () => ($picks ? $quick->save($picks, $lookup, $genres) : ['created' => 0, 'staged' => 0]) + ['taken' => array_column($picks, 'name'), 'not_offered' => $missing],
            'Schnell erfassen', ['names' => $wanted->keys()->all()]);
    }

    public function quickSplit(Request $request, ArtistQuickMatch $quick): JsonResponse
    {
        $name = (string) $request->input('name');
        $parts = array_values(array_filter(array_map('trim', (array) ($request->input('parts') ?: ArtistNames::suggestSplit($name)))));

        return $this->change(function () use ($quick, $name, $parts) {
            if (count($parts) < 2 || Artist::where('name', $name)->exists()) {
                throw new InvalidArgumentException(count($parts) < 2 ? 'Mindestens zwei Teile angeben.' : '„'.$name.'“ steht im Verzeichnis – dort trennen (POST /api/artists/{id}/split).');
            }

            return ['parts' => $parts, 'staged' => $quick->split($name, $parts)];
        }, 'Kombi trennen', ['name' => $name, 'parts' => $parts]);
    }

    public function combined(CleanupChecks $checks, CombinedSuggestions $suggest): JsonResponse
    {
        [$list, $stale] = $checks->displayFindings('combined_manual');
        $names = array_values(array_filter(array_column($list, 'new_artist')));
        $s = $suggest->for($names);

        return $this->json(['stale' => $stale, 'total' => count($names), 'names' => array_map(fn ($f) => ['name' => $f['new_artist'], 'detail' => $f['detail'],
            'suggestion' => $s[$f['new_artist']] ?? null], array_values(array_filter($list, fn ($f) => ! empty($f['new_artist']))))]);
    }

    public function combinedApply(Request $request, CleanupChecks $checks, ArtistActions $actions): JsonResponse
    {
        $names = $request->boolean('all') ? array_column($checks->displayFindings('combined_manual')[0], 'new_artist') : (array) $request->input('names', []);

        return $this->change(fn () => $actions->applyCombined($names), 'Kombi-Vorschläge übernehmen', ['names' => $request->boolean('all') ? 'alle' : $names]);
    }

    /** Änderung ausführen und protokollieren (wer über die Schnittstelle was geändert hat, steht im Protokoll). */
    private function change(callable $fn, string $what, array $context): JsonResponse
    {
        try {
            $result = $fn();
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        $result = array_map(fn ($v) => $v instanceof Artist ? ['id' => $v->id, 'name' => $v->name, 'mbid' => $v->mbid, 'default_genre' => $v->default_genre] : $v, (array) $result);
        Log::info('Schnittstelle: '.$what, ['request' => $context, 'result' => array_diff_key($result, ['artist' => 1])]);

        return $this->json(['ok' => true] + $result);
    }

    public function historyEntry(Request $request, Operation $operation): JsonResponse
    {
        $entries = HistoryEntry::where('operation_id', $operation->id)
            ->when($request->boolean('errors'), fn ($q) => $q->where(fn ($w) => $w->whereNotNull('error')->orWhereIn('kind', ['error', 'info'])))
            ->orderBy('id')->limit(min(20000, $request->integer('limit', 2000)))->get();

        return $this->json([
            'id' => $operation->id, 'type' => $operation->type, 'description' => $operation->description, 'status' => $operation->status,
            'done' => $operation->done, 'failed' => $operation->failed, 'total' => $operation->total, 'error' => $operation->error,
            'created_at' => $operation->created_at?->toIso8601String(), 'finished_at' => $operation->finished_at?->toIso8601String(),
            'kinds' => HistoryEntry::where('operation_id', $operation->id)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
            'entries' => $entries->map(fn (HistoryEntry $e) => array_filter([
                'kind' => $e->kind, 'old_path' => $this->rel($e->old_path), 'new_path' => $this->rel($e->new_path),
                'tags' => $e->new_tags ? array_keys((array) $e->new_tags) : null, 'cover_changed' => $e->cover_changed ?: null, 'error' => $e->error,
            ], fn ($v) => $v !== null))->all(),
        ]);
    }

    /** Was läuft, was hängt, wie steht es um Speicher und Rechte – für die Fehlersuche. */
    public function status(CleanupChecks $checks, Scanner $scanner, InboxWatch $watch): JsonResponse
    {
        $now = time();
        $queues = DB::table('jobs')->selectRaw('queue, count(*) as waiting, sum(case when reserved_at is not null then 1 else 0 end) as running, min(available_at) as oldest')
            ->groupBy('queue')->get()->map(fn ($q) => ['queue' => $q->queue, 'waiting' => (int) $q->waiting, 'running' => (int) $q->running,
                'oldest_waiting_seconds' => $q->oldest ? max(0, $now - (int) $q->oldest) : null])->all();
        $failed = DB::table('failed_jobs')->latest('id')->limit(10)->get()->map(fn ($f) => [
            'id' => $f->id, 'queue' => $f->queue, 'failed_at' => $f->failed_at,
            'job' => json_decode($f->payload, true)['displayName'] ?? null,
            'exception' => $this->redact(mb_substr(strtok((string) $f->exception, "\n") ?: '', 0, 500)),
        ])->all();
        $dirs = [];
        foreach (['base' => $this->settings->base(), 'inbox' => (string) $this->settings->get('inbox'), 'trash' => (string) $this->settings->get('trash'), 'data' => storage_path('app')] as $k => $dir) {
            $dirs[$k] = ['path' => $dir, 'exists' => is_dir($dir), 'readable' => is_readable($dir), 'writable' => is_writable($dir),
                'free_gb' => is_dir($dir) && ($free = @disk_free_space($dir)) !== false ? round($free / 1024 ** 3, 1) : null];
        }
        $db = (string) config('database.connections.sqlite.database');

        return $this->json([
            'now' => now()->toIso8601String(),
            'scan' => $scanner->status(),
            'operations' => Operation::whereIn('status', ['pending', 'running'])->orderBy('id')->get()->map(fn (Operation $o) => [
                'id' => $o->id, 'type' => $o->type, 'description' => $o->description, 'status' => $o->status, 'done' => $o->done, 'failed' => $o->failed,
                'total' => $o->total, 'planned' => $o->isPlanned(), 'created_at' => $o->created_at?->toIso8601String(), 'updated_at' => $o->updated_at?->toIso8601String(),
            ])->all(),
            'queues' => $queues,
            'failed_jobs' => ['count' => DB::table('failed_jobs')->count(), 'latest' => $failed],
            'cleanup' => ['fresh' => $checks->isFresh(), 'focus' => Cache::get(CleanupChecks::FOCUS), 'progress' => CleanupChecks::progress()],
            'artist_prefetch' => PrefetchUnknownArtists::status(),
            'musicbrainz_aliases' => Cache::get(ImportMusicBrainzAliases::STATUS_KEY),
            'inbox_watch' => ['enabled' => $watch->enabled(), 'interval' => $watch->interval(), 'alive' => Cache::get('inbox-watch:alive')],
            'pending_tracks' => PendingChange::count(),
            'dirs' => $dirs,
            'env' => [
                'php' => PHP_VERSION, 'laravel' => app()->version(), 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'),
                'scan_workers' => config('musicwiz.scan_workers'), 'queue' => config('queue.default'), 'cache' => config('cache.default'),
                'db_mb' => is_file($db) ? round(filesize($db) / 1024 ** 2, 1) : null, 'timezone' => config('app.timezone'),
            ],
        ]);
    }

    /** Fehlerprotokoll: neueste Einträge zuerst, mit gekürztem Stacktrace; Zugangsdaten aus den Einstellungen geschwärzt. */
    public function logs(Request $request): JsonResponse
    {
        $levels = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];
        $min = $levels[strtolower((string) $request->input('level', 'debug'))] ?? 0;
        $limit = min(500, max(1, $request->integer('entries', 50)));
        $files = array_merge(glob(storage_path('app/logs/*.log')) ?: [], glob(storage_path('logs/*.log')) ?: []);
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $entries = [];
        foreach ($files as $file) {
            // nur das Ende lesen (große Dateien)
            $size = filesize($file);
            $fh = fopen($file, 'r');
            fseek($fh, max(0, $size - 4 * 1024 * 1024));
            $text = stream_get_contents($fh);
            fclose($fh);
            $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T][^\]]*\] \w+\.\w+:)/m', (string) $text) ?: [];
            foreach (array_reverse($parts) as $part) {
                if (! preg_match('/^\[([^\]]+)\] (\w+)\.(\w+): (.*)$/s', trim($part), $m) || ($levels[strtolower($m[3])] ?? 0) < $min) {
                    continue;
                }
                $lines = explode("\n", $m[4]);
                $entries[] = ['time' => $m[1], 'level' => strtolower($m[3]), 'file' => basename($file),
                    'message' => $this->redact(mb_substr(array_shift($lines), 0, 2000)),
                    'trace' => array_map(fn ($l) => $this->redact(mb_substr($l, 0, 300)), array_slice(array_filter($lines, fn ($l) => trim($l) !== ''), 0, 15))];
                if (count($entries) >= $limit) {
                    break 2;
                }
            }
        }

        return $this->json(['files' => array_map(fn ($f) => ['name' => basename($f), 'kb' => round(filesize($f) / 1024), 'modified' => date('c', filemtime($f))], $files),
            'entries' => $entries]);
    }

    /** Zugangsdaten aus den Einstellungen nie über die Schnittstelle zeigen */
    private function redact(string $text): string
    {
        foreach (['discogs_token', 'fanart_key', 'acoustid_key'] as $k) {
            $secret = trim((string) $this->settings->get($k));
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }

    private function albumData(Album $a): array
    {
        return ['id' => $a->id, 'location' => $a->location, 'title' => $a->title, 'albumartist' => $a->albumartist, 'category' => $a->category,
            'compilation' => (bool) $a->is_compilation, 'dir' => $this->rel($a->dir), 'track_count' => $a->track_count, 'cover' => (bool) ($a->cover_file || $a->cover_embedded)];
    }

    private function trackData(Track $t): array
    {
        $pc = $t->pendingChange;

        return ['id' => $t->id, 'album_id' => $t->album_id, 'path' => $this->rel($t->path), 'tags' => $t->tags(),
            'duration' => $t->duration, 'bitrate' => $t->bitrate, 'cover_embedded' => (bool) $t->cover_embedded,
            'pending' => $pc ? ['tags_before' => $pc->tags_before, 'labels' => $pc->labels] : null];
    }

    private function base(): string
    {
        return rtrim($this->settings->base(), '/').'/';
    }

    private function rel(?string $path): ?string
    {
        return $path !== null && str_starts_with($path, $this->base()) ? substr($path, strlen($this->base())) : $path;
    }

    private function abs(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->base().ltrim($path, '/');
    }

    /** Pfade in Funden (Aktionen, Optionen) relativ machen. */
    private function relPaths(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->relPaths($v), $value);
        }

        return is_string($value) ? $this->rel($value) : $value;
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
