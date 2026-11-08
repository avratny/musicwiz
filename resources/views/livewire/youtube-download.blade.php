@php $rel = fn ($p) => str_starts_with((string) $p, $base.'/') ? substr($p, strlen($base) + 1) : $p; @endphp
<div class="flex h-full flex-col overflow-hidden" @if ($active) wire:poll.2s @endif>
    <x-page-header title="YouTube-Download" subtitle="Lädt den Ton als MP3 (beste VBR-Stufe, mit Tags und Vorschaubild als Cover) in die Inbox." />

    <div class="min-h-0 flex-1 overflow-y-auto p-6">
        <form wire:submit="download" class="card space-y-4 p-5">
            <div>
                <label class="label" for="urls">Links (einer pro Zeile – Video, YouTube Music oder Playlist)</label>
                <textarea id="urls" wire:model="urls" rows="4" class="input font-mono text-sm" placeholder="https://www.youtube.com/watch?v=…"></textarea>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <label class="flex items-center gap-2 text-zinc-300">
                    <input type="checkbox" wire:model="playlist" class="rounded border-zinc-600 bg-zinc-800">
                    Bei Video-Links mit Playlist die ganze Playlist laden
                </label>
                <span class="text-zinc-500">→ {{ $inbox }}/{{ \App\Support\YouTube::FOLDER }}/…</span>
                <button type="submit" class="btn-primary btn-sm ml-auto">Herunterladen</button>
            </div>
            @if ($message)
                <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-200">{{ $message }}</div>
            @endif
        </form>

        {{-- Grenzen gegen Drosselung/Sperre --}}
        <details class="card mt-4 p-5 text-sm" @if ($errors->has('limits.*')) open @endif>
            <summary class="cursor-pointer font-medium text-zinc-200">
                Grenzen
                <span class="ml-2 font-normal text-zinc-500">
                    heute {{ $today }}{{ $limits['daily_limit'] ? ' von '.$limits['daily_limit'] : '' }} Titel geladen
                </span>
            </summary>
            <form wire:submit="saveLimits" class="mt-4 space-y-3">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block">
                        <span class="label">Pause zwischen Titeln (s)</span>
                        <span class="flex items-center gap-2">
                            <input type="number" min="0" wire:model="limits.sleep_min" class="input-sm"> –
                            <input type="number" min="0" wire:model="limits.sleep_max" class="input-sm">
                        </span>
                    </label>
                    <label class="block">
                        <span class="label">Pause zwischen Anfragen (s)</span>
                        <input type="number" min="0" step="0.5" wire:model="limits.sleep_requests" class="input-sm">
                    </label>
                    <label class="block">
                        <span class="label">Bandbreite (MB/s)</span>
                        <input type="number" min="0" step="0.5" wire:model="limits.rate_mb" class="input-sm">
                    </label>
                    <label class="block">
                        <span class="label">Titel pro Tag</span>
                        <input type="number" min="0" wire:model="limits.daily_limit" class="input-sm">
                    </label>
                </div>
                @foreach ($errors->get('limits.*') as $messages)
                    <p class="text-xs text-red-300">{{ implode(' ', $messages) }}</p>
                @endforeach
                <div class="flex flex-wrap items-center gap-3">
                    <p class="flex-1 text-xs text-zinc-500">
                        0 = aus. Die Pause zwischen Titeln wird zufällig zwischen den beiden Werten gewählt.
                        Ist die Tagesgrenze erreicht, geht der Rest kurz nach Mitternacht automatisch weiter.
                    </p>
                    @if ($limitsSaved)
                        <span class="text-xs text-emerald-300">Gespeichert</span>
                    @endif
                    <button type="submit" class="btn-primary btn-sm">Speichern</button>
                </div>
            </form>
        </details>

        @if ($deletable)
            <div class="mt-6 flex items-center text-sm">
                <span class="flex-1 font-medium text-zinc-300">Verlauf</span>
                <button wire:click="clearHistory"
                        wire:confirm="Alle abgeschlossenen und geplanten Downloads aus dem Verlauf löschen? Die Dateien bleiben in der Inbox, rückgängig machen geht danach aber nicht mehr."
                        class="btn-ghost btn-sm">Verlauf löschen</button>
            </div>
        @endif

        <div class="mt-2 space-y-2">
            @foreach ($ops as $op)
                @php $p = $progress[$op->id] ?? null; @endphp
                <div wire:key="dl{{ $op->id }}" class="card px-4 py-3">
                    <div class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium" title="{{ $op->description }}">{{ $op->description }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ $op->created_at->format('d.m.Y H:i') }} · {{ $op->entries->count() }} Datei(en)
                                @if ($op->isPlanned())
                                    · Tagesgrenze erreicht, geht am {{ \Illuminate\Support\Carbon::parse($op->payload['options']['not_before'])->timezone(config('app.timezone'))->format('d.m. \u\m H:i') }} weiter
                                @endif
                            </div>
                        </div>
                        <span @class([
                            'badge-gray' => $op->isPlanned(),
                            'badge-blue' => $op->isActive() && ! $op->isPlanned(),
                            'badge-green' => $op->status === 'done',
                            'badge-red' => $op->status === 'failed',
                            'badge-gray' => $op->status === 'undone',
                            'badge-amber' => $op->status === 'partly_undone',
                        ])>{{ $op->statusLabel() }}</span>
                        @if ($op->status === 'failed' && ! in_array($op->id, $retried))
                            <button wire:click="retry({{ $op->id }})" wire:loading.attr="disabled" class="btn-ghost btn-sm">Erneut versuchen</button>
                        @endif
                        @if ($op->isPlanned())
                            <button wire:click="startNow({{ $op->id }})" class="btn-ghost btn-sm">Jetzt starten</button>
                        @endif
                        @if (! $op->isActive() || $op->isPlanned())
                            <button wire:click="remove({{ $op->id }})"
                                    wire:confirm="Diesen Download aus dem Verlauf löschen? Die Dateien bleiben, rückgängig machen geht danach nicht mehr."
                                    class="text-zinc-500 hover:text-red-300" title="Aus dem Verlauf löschen">✕</button>
                        @endif
                    </div>
                    @if ($op->isActive() && ! $op->isPlanned() && $p)
                        <div class="mt-2 text-xs text-zinc-400">
                            {{ $p['count'] ? ($p['index'] ?? '?').'/'.$p['count'].' · ' : '' }}{{ $p['title'] }}
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-800">
                            <div class="h-full bg-gradient-to-r from-blue-500 to-violet-500" style="width: {{ min(100, $p['percent'] ?? 0) }}%"></div>
                        </div>
                    @endif
                    @if ($op->entries->isNotEmpty())
                        <ul class="mt-2 space-y-0.5 font-mono text-xs text-zinc-400">
                            @foreach ($op->entries->take(30) as $e)
                                <li class="truncate" title="{{ $e->new_path }}">{{ $rel($e->new_path) }}</li>
                            @endforeach
                            @if ($op->entries->count() > 30)
                                <li class="text-zinc-600">… und {{ $op->entries->count() - 30 }} weitere</li>
                            @endif
                        </ul>
                    @endif
                    @if ($op->error)
                        <div class="mt-2 text-xs whitespace-pre-line text-red-300">{{ $op->error }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3 text-xs text-zinc-500">
            <span>yt-dlp {{ $version ?: 'nicht gefunden' }}</span>
            <button wire:click="updateTool" wire:loading.attr="disabled" class="btn-ghost btn-sm">yt-dlp aktualisieren</button>
            <span>Schlägt ein Download fehl, hilft oft eine neuere yt-dlp-Version. Rückgängig machen (Verlauf) legt die Dateien in den Papierkorb.</span>
        </div>
        @if ($updateOutput)
            <pre class="mt-2 rounded-lg bg-zinc-900 p-3 text-xs whitespace-pre-wrap text-zinc-400">{{ $updateOutput }}</pre>
        @endif
    </div>
</div>
