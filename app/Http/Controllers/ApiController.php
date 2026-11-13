<?php

namespace App\Http\Controllers;

use App\Models\ActivityRecord;
use App\Models\Artist;
use App\Models\TagChange;
use App\Models\Track;
use App\Support\ApiUi;
use App\Support\Locations;
use App\Support\OpenCounts;
use App\Support\TagEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Schnittstelle für Analyse und Aktionen von außen (z. B. eine KI): lesen, Tags ändern (wie von Hand, mit Grund) und
 * über /api/ui jede Aktion der Oberfläche auslösen. Übersicht aller Aufrufe: GET /api
 */
class ApiController extends Controller
{
    /** lesbar: Umlaute und Pfade nicht maskieren */
    private function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function index(): JsonResponse
    {
        return $this->json([
            'app' => 'MusicWiz',
            'auth' => config('musicwiz.api_token') ? 'Alles außer dieser Übersicht braucht das Token (API_TOKEN): Header „Authorization: Bearer …“ oder „X-Api-Token: …“.' : 'ohne Token (API_TOKEN nicht gesetzt)',
            'note' => 'Tag-Änderungen landen nur in der Datenbank (wie in der App) – in die Dateien kommen sie erst über „Schreiben“.',
            'locations' => array_map(fn ($l) => Locations::root($l), array_combine(array_keys(Locations::LABELS), array_keys(Locations::LABELS))),
            'endpoints' => [
                'GET /api/status' => 'Anzahl je Bereich, Vorgänge (laufend und zuletzt), Warteschlangen, fehlgeschlagene Jobs, Speicherplatz',
                'GET /api/logs?entries=50&level=warning' => 'Fehlerprotokoll, neueste zuerst (level: debug|info|notice|warning|error)',
                'GET /api/tracks?location=&q=&album_hash=&changed=0|1&error=0|1&limit=100&offset=0' => 'Lieder suchen (q: Titel, Interpret, Album, Pfad)',
                'GET /api/tracks/{id}' => 'ein Lied mit allen Feldern, rohen Tags der Datei, offenen und geschriebenen Änderungen, Interpreten',
                'POST /api/tracks/{id}' => '{"tags": {"year": "1999", …}, "reason"?: "…"} – Felder ändern wie von Hand (Felder: siehe editable)',
                'POST /api/tracks/{id}/revert' => '{"fields": ["year", …]} – auf den Stand der Datei zurück',
                'GET /api/changes?track_id=&field=&source=manual|system&written=0|1&limit=200' => 'Änderungsprotokoll, neueste zuerst',
                'GET /api/artists?q=&limit=100' => 'Künstlerverzeichnis',
                'GET /api/artists/{id}' => 'ein Künstler mit Schreibweisen und Liedern',
                'GET|POST /api/sql?q=SELECT …&limit=500' => 'Nur-Lese-Abfrage (SELECT, WITH, SHOW, EXPLAIN, DESCRIBE; ein Befehl)',
                'POST /api/activity/{key}/stop' => 'Hintergrundvorgang anhalten (Schlüssel siehe status.activities)',
                'GET|POST /api/ui/{seite}' => '{"mount"?: {…}, "set"?: {"eigenschaft": wert}, "call"?: [{"method": "…", "params": […]}], "html"?: false, "text"?: true} – Seite aufbauen und Aktionen ausführen wie per Klick; GET mit ?mount[slug]=inbox. Antwort: data (Stand, u. a. message/error), returns, dispatches, text der Seite',
            ],
            'editable' => Track::EDITABLE,
            'ui' => array_map(fn ($c) => array_diff_key($c, ['class' => 0]), ApiUi::components()),
        ]);
    }

    public function status(): JsonResponse
    {
        $disk = [];
        foreach (array_keys(Locations::LABELS) as $location) {
            $root = Locations::root($location);
            $disk[$location] = ['path' => $root, 'exists' => is_dir($root), 'writable' => is_writable($root),
                'free_gb' => is_dir($root) ? round(disk_free_space($root) / 1e9, 1) : null];
        }

        return $this->json([
            'time' => now()->toIso8601String(),
            'counts' => Locations::counts() + ['artists' => Artist::count(), 'open_changes' => TagChange::count()],
            'activities' => ActivityRecord::orderByDesc('started_at')->get()->map(fn ($a) => [
                'key' => $a->key, 'label' => $a->label, 'running' => (bool) $a->running, 'done' => (int) $a->done, 'total' => (int) $a->total,
                'detail' => $a->detail, 'message' => $a->message, 'started_at' => $a->started_at?->toIso8601String(), 'finished_at' => $a->finished_at?->toIso8601String(),
            ]),
            'queues' => DB::table('jobs')->selectRaw('queue, COUNT(*) AS n, SUM(reserved_at IS NOT NULL) AS running')->groupBy('queue')->get(),
            'failed_jobs' => DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get()->map(fn ($j) => [
                'id' => $j->id, 'queue' => $j->queue, 'failed_at' => $j->failed_at,
                'job' => json_decode($j->payload, true)['displayName'] ?? null,
                'exception' => mb_substr($j->exception, 0, 2000),
            ]),
            'disk' => $disk,
            'php' => ['version' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')],
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $min = array_search(strtolower($request->string('level', 'debug')), $levels, true) ?: 0;
        $limit = min(500, max(1, $request->integer('entries', 50)));
        // Betrieb: Tagesdateien aller Container im Datenordner (app_file), Entwicklung: laravel.log – die neuesten zuerst
        $files = array_merge(glob(storage_path('app/logs/*.log')) ?: [], glob(storage_path('logs/laravel*.log')) ?: []);
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $chunks = [];
        foreach (array_slice($files, 0, 3) as $file) {
            // nur das Ende lesen – das Protokoll kann groß werden
            $handle = fopen($file, 'r');
            fseek($handle, max(0, filesize($file) - 4_000_000));
            $chunks[] = stream_get_contents($handle);
            fclose($handle);
        }
        $all = [];
        foreach ($chunks as $content) {
            array_push($all, ...preg_split('/^(?=\[\d{4}-\d\d-\d\d[ T])/m', $content));
        }
        // nach Zeit (am Anfang jedes Eintrags) – mehrere Dateien greifen ineinander
        usort($all, fn ($a, $b) => strcmp(substr($b, 0, 26), substr($a, 0, 26)));
        $entries = [];
        foreach ($all as $entry) {
            if (! preg_match('/^\[([^\]]+)\] \w+\.(\w+): /', $entry, $m)) {
                continue;
            }
            if (array_search(strtolower($m[2]), $levels, true) < $min) {
                continue;
            }
            $entries[] = ['time' => $m[1], 'level' => strtolower($m[2]), 'message' => mb_substr(trim(substr($entry, strlen($m[0]))), 0, 4000)];
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $this->json(['entries' => $entries]);
    }

    public function tracks(Request $request): JsonResponse
    {
        $query = Track::query()
            ->when($request->filled('location'), fn ($q) => $q->where('location', $request->string('location')))
            ->when($request->filled('album_hash'), fn ($q) => $q->where('album_hash', $request->string('album_hash')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')).'%';
                $q->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('artist', 'like', $like)
                    ->orWhere('album', 'like', $like)->orWhere('path', 'like', $like));
            })
            ->when($request->has('changed'), fn ($q) => $request->boolean('changed') ? $q->whereHas('changes') : $q->whereDoesntHave('changes'))
            ->when($request->has('error'), fn ($q) => $request->boolean('error') ? $q->whereNotNull('error') : $q->whereNull('error'));
        $total = (clone $query)->count();
        $limit = min(1000, max(1, $request->integer('limit', 100)));
        $tracks = $query->orderBy('album_dir')->orderBy('disc')->orderBy('track')->orderBy('path')
            ->offset(max(0, $request->integer('offset')))->limit($limit)->get();

        return $this->json(['total' => $total, 'tracks' => $tracks->map(fn (Track $t) => $t->makeHidden(['tags', 'fingerprint']))]);
    }

    public function track(Track $track): JsonResponse
    {
        return $this->json([
            'track' => $track,
            'quality' => $track->qualityLabel(),
            'open_changes' => $track->changes()->get(),
            'written_changes' => TagChange::history()->where('track_id', $track->id)->whereNotNull('written_at')->latest('id')->limit(200)->get(),
            'artists' => DB::table('track_artist')->join('artists', 'artists.id', '=', 'track_artist.artist_id')
                ->where('track_id', $track->id)->get(['artists.id', 'artists.name', 'track_artist.role']),
        ]);
    }

    public function updateTrack(Request $request, Track $track, TagEditor $editor): JsonResponse
    {
        $tags = (array) $request->input('tags', []);
        if ($unknown = array_keys(array_diff_key($tags, Track::EDITABLE))) {
            return $this->json(['error' => 'Nicht bearbeitbar: '.implode(', ', $unknown), 'editable' => array_keys(Track::EDITABLE)], 422);
        }
        $reason = trim((string) $request->input('reason')) ?: 'über die Schnittstelle';
        $changed = $editor->update($track, $tags, TagEditor::MANUAL, $reason);
        Log::info('Schnittstelle: Tags geändert', ['track' => $track->id, 'fields' => array_keys($changed)]);
        OpenCounts::touch();

        return $this->json(['changed' => $changed, 'track' => $track->fresh()->makeHidden('tags')]);
    }

    public function revertTrack(Request $request, Track $track, TagEditor $editor): JsonResponse
    {
        $reverted = $editor->revert($track, array_values((array) $request->input('fields', [])));
        OpenCounts::touch();

        return $this->json(['reverted' => $reverted, 'track' => $track->fresh()->makeHidden('tags')]);
    }

    public function changes(Request $request): JsonResponse
    {
        $query = ($request->has('written') ? TagChange::history() : TagChange::query())
            ->when($request->has('written'), fn ($q) => $request->boolean('written') ? $q->whereNotNull('written_at') : $q->whereNull('written_at'))
            ->when($request->filled('track_id'), fn ($q) => $q->where('track_id', $request->integer('track_id')))
            ->when($request->filled('field'), fn ($q) => $q->where('field', $request->string('field')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')));
        $total = (clone $query)->count();
        $changes = $query->with('track:id,path,title,artist,album')->latest('id')
            ->offset(max(0, $request->integer('offset')))->limit(min(2000, max(1, $request->integer('limit', 200))))->get();

        return $this->json(['total' => $total, 'changes' => $changes]);
    }

    public function artists(Request $request): JsonResponse
    {
        // Namen sind binär sortiert (Groß/klein zählt) – suchen ohne
        $like = '%'.mb_strtolower(trim((string) $request->input('q'))).'%';
        $artists = Artist::query()
            ->when($request->filled('q'), fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereHas('aliases', fn ($a) => $a->whereRaw('LOWER(alias) LIKE ?', [$like])))
            ->orderBy('name')->limit(min(1000, max(1, $request->integer('limit', 100))))->get();

        return $this->json(['artists' => $artists]);
    }

    public function artist(Artist $artist): JsonResponse
    {
        return $this->json([
            'artist' => $artist,
            'aliases' => $artist->aliases()->pluck('alias'),
            'tracks' => $artist->tracks()->limit(500)->get(['tracks.id', 'location', 'path', 'title', 'artist', 'album', 'albumartist']),
        ]);
    }

    /** Nur-Lese-Abfrage auf eigener Verbindung in einer READ-ONLY-Transaktion – Schreiben lehnt die Datenbank ab */
    public function sql(Request $request): JsonResponse
    {
        $sql = rtrim(trim((string) $request->input('q')), "; \n\t");
        if (! preg_match('/^(select|with|show|explain|describe|desc)\b/i', $sql) || str_contains($sql, ';')) {
            return $this->json(['error' => 'Nur ein Lese-Befehl (SELECT, WITH, SHOW, EXPLAIN, DESCRIBE).'], 422);
        }
        $limit = min(5000, max(1, $request->integer('limit', 500)));
        config(['database.connections.api_readonly' => config('database.connections.'.config('database.default'))]);
        $db = DB::connection('api_readonly');
        $rows = [];
        $truncated = false;
        $db->statement('START TRANSACTION READ ONLY');
        try {
            foreach ($db->cursor($sql) as $row) {
                if (count($rows) >= $limit) {
                    $truncated = true;
                    break;
                }
                $rows[] = $row;
            }
        } catch (Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } finally {
            $db->statement('ROLLBACK');
            DB::purge('api_readonly');
        }

        return $this->json(['rows' => $rows, 'count' => count($rows), 'truncated' => $truncated]);
    }

    public function ui(Request $request, ApiUi $ui, string $component): JsonResponse
    {
        try {
            $result = $ui->run($component, (array) $request->input('mount', []), (array) $request->input('set', []), (array) $request->input('call', []));
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage() ?: 'Nicht gefunden'], $e->getStatusCode());
        } catch (Throwable $e) {
            Log::error('Schnittstelle: Seite '.$component, ['exception' => $e]);

            return $this->json(['error' => $e->getMessage(), 'exception' => $e::class, 'at' => $e->getFile().':'.$e->getLine()], 500);
        }
        $html = $result['html'];
        unset($result['html']);

        return $this->json($result
            + ($request->boolean('text', true) ? ['text' => ApiUi::text($html)] : [])
            + ($request->boolean('html') ? ['html' => $html] : []));
    }
}
