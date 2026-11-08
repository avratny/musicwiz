<?php

namespace App\Livewire;

use App\Jobs\RunOperation;
use App\Models\HistoryEntry;
use App\Models\Operation;
use App\Support\OperationRunner;
use App\Support\Settings;
use App\Support\YouTube;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app'), Title('YouTube-Download')]
class YouTubeDownload extends Component
{
    public string $urls = '';

    /** Bei Links mit Video und Playlist die ganze Playlist laden */
    public bool $playlist = false;

    public ?string $message = null;

    public ?string $updateOutput = null;

    /** Grenzen gegen Drosselung/Sperre (siehe YouTube::limits) */
    public array $limits = [];

    public bool $limitsSaved = false;

    public function mount(YouTube $youtube): void
    {
        $this->limits = $youtube->limits();
    }

    public function saveLimits(Settings $settings, YouTube $youtube): void
    {
        $this->validate([
            'limits.sleep_min' => 'required|integer|min:0|max:600',
            'limits.sleep_max' => 'required|integer|min:0|max:600|gte:limits.sleep_min',
            'limits.sleep_requests' => 'required|numeric|min:0|max:60',
            'limits.rate_mb' => 'required|numeric|min:0|max:1000',
            'limits.daily_limit' => 'required|integer|min:0|max:10000',
        ], [], [
            'limits.sleep_min' => 'Pause min.', 'limits.sleep_max' => 'Pause max.', 'limits.sleep_requests' => 'Pause zwischen Anfragen',
            'limits.rate_mb' => 'Bandbreite', 'limits.daily_limit' => 'Titel pro Tag',
        ]);
        $settings->set([
            'yt_sleep_min' => (int) $this->limits['sleep_min'],
            'yt_sleep_max' => (int) $this->limits['sleep_max'],
            'yt_sleep_requests' => (float) $this->limits['sleep_requests'],
            'yt_rate_mb' => (float) $this->limits['rate_mb'],
            'yt_daily_limit' => (int) $this->limits['daily_limit'],
        ]);
        $this->limits = $youtube->limits();
        $this->limitsSaved = true;
    }

    public function download(Settings $settings): void
    {
        $this->message = null;
        $lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s+/', $this->urls)))));
        $invalid = array_filter($lines, fn ($u) => ! YouTube::isUrl($u));
        if (! $lines || $invalid) {
            $this->message = $lines ? '⚠ Kein YouTube-Link: '.implode(', ', $invalid) : '⚠ Bitte mindestens einen Link eingeben.';

            return;
        }
        if ($settings->inbox() === '') {
            $this->message = '⚠ Kein Inbox-Ordner eingerichtet (Einstellungen).';

            return;
        }
        $dir = $settings->inbox().'/'.YouTube::FOLDER;
        Storage::makeDirectory('youtube');
        // Archiv je Link: Fortsetzungen und neue Versuche überspringen schon geladene Titel ohne erneute Anfrage
        $actions = array_map(fn ($u) => ['type' => 'download', 'url' => $u, 'dir' => $dir, 'playlist' => $this->playlist,
            'archive' => Storage::path('youtube/archive-'.Str::random(16).'.txt')], $lines);
        OperationRunner::dispatch('download', 'YouTube: '.(count($lines) === 1 ? $lines[0] : count($lines).' Links').' herunterladen', $actions);
        $this->urls = '';
        $this->dispatch('activity-changed');
    }

    /** Fehlgeschlagene Links eines Downloads noch einmal laden (bei Playlists werden vorhandene Titel übersprungen). */
    public function retry(int $id): void
    {
        $op = Operation::where('type', 'download')->where('status', 'failed')->find($id);
        if (! $op || self::retriedIds()->contains($id)) {
            return;
        }
        $failed = $op->entries()->where('kind', 'error')->pluck('old_path')->filter()->all();
        $actions = array_values(array_filter($op->payload['actions'], fn ($a) => in_array($a['url'], $failed, true)));
        OperationRunner::dispatch('download', 'Erneut: '.preg_replace('/^(Erneut: )+/', '', $op->description), $actions ?: $op->payload['actions'], ['retry_of' => $op->id]);
        $this->dispatch('activity-changed');
    }

    /** IDs der Downloads, die schon erneut versucht wurden. */
    private static function retriedIds()
    {
        return Operation::where('type', 'download')->whereNotNull('payload->options->retry_of')->pluck('payload')
            ->map(fn ($p) => $p['options']['retry_of']);
    }

    /** Eingeplante Fortsetzung sofort starten (zählt trotzdem gegen die Tagesgrenze). */
    public function startNow(int $id): void
    {
        $op = Operation::where('type', 'download')->find($id);
        if (! $op?->isPlanned()) {
            return;
        }
        $payload = $op->payload;
        unset($payload['options']['not_before']);
        $op->update(['payload' => $payload]);
        // der verzögerte Job findet die Aktion später nicht mehr wartend vor und tut nichts
        RunOperation::dispatch($op->id)->onQueue('downloads');
        $this->dispatch('activity-changed');
    }

    /** Einen Download aus dem Verlauf löschen (die Dateien bleiben). */
    public function remove(int $id): void
    {
        $this->deleteOps(Operation::where('type', 'download')->whereKey($id)->get());
    }

    /** Alle abgeschlossenen bzw. eingeplanten Downloads aus dem Verlauf löschen (die Dateien bleiben). */
    public function clearHistory(): void
    {
        $this->deleteOps(Operation::where('type', 'download')->get());
    }

    private function deleteOps($ops): void
    {
        // laufende Downloads bleiben, eingeplante dürfen weg (ihr verzögerter Job findet dann nichts mehr)
        $ops = $ops->filter(fn (Operation $op) => ! $op->isActive() || $op->isPlanned());
        if ($ops->isEmpty()) {
            return;
        }
        $ids = $ops->pluck('id')->all();
        $all = [...$ids, ...Operation::whereIn('undo_of', $ids)->pluck('id')->all()];
        HistoryEntry::whereIn('operation_id', $all)->delete();
        Operation::whereIn('id', $all)->delete();

        // Archive, die kein verbliebener Download mehr braucht
        $archives = fn ($list) => collect($list)->flatMap(fn ($op) => array_column($op->payload['actions'] ?? [], 'archive'))->filter()->unique();
        $stillUsed = $archives(Operation::where('type', 'download')->get())->all();
        foreach ($archives($ops)->diff($stillUsed) as $file) {
            @unlink($file);
        }
        foreach ($ids as $id) {
            Cache::forget('download-progress:'.$id);
        }
        $this->dispatch('activity-changed');
    }

    public function updateTool(YouTube $youtube): void
    {
        $this->updateOutput = $youtube->update();
        Cache::forget('ytdlp-version');
    }

    #[On('activity-changed')]
    public function refreshList(): void {}

    public function render(Settings $settings, YouTube $youtube)
    {
        $ops = Operation::where('type', 'download')->with(['entries' => fn ($q) => $q->where('kind', 'download')])
            ->latest('id')->limit(20)->get();
        $progress = [];
        foreach ($ops->filter->isActive() as $op) {
            $progress[$op->id] = Cache::get('download-progress:'.$op->id);
        }

        return view('livewire.youtube-download', [
            'ops' => $ops,
            'progress' => $progress,
            'active' => $ops->contains(fn ($op) => $op->isActive() && ! $op->isPlanned()),
            'today' => YouTube::countToday(),
            'deletable' => $ops->contains(fn ($op) => ! $op->isActive() || $op->isPlanned()),
            'retried' => self::retriedIds()->all(),
            'inbox' => $settings->inbox(),
            'version' => Cache::remember('ytdlp-version', 600, fn () => $youtube->version() ?? ''),
            'base' => $settings->base(),
        ]);
    }
}
