<div class="flex h-full flex-col overflow-hidden" @if ($scan['running'] ?? false) wire:poll.2s @endif>
    <x-page-header title="Inbox" :subtitle="$albums->count().($albums->count() === 1 ? ' Album · ' : ' Alben · ').$inbox">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Suchen…" class="input w-64">
        <a href="{{ route('text-repair', ['scope' => 'inbox']) }}" wire:navigate class="btn-ghost" title="Kaputte Zeichen in den Tags (z. B. „KÃ¶nig“) vor dem Einordnen richten">Zeichen-Assistent</a>
        <button wire:click="startScan('inbox')" class="btn-primary" @disabled($scan['running'] ?? false)>
            <svg class="size-4 {{ ($scan['running'] ?? false) && $scan['location'] === 'inbox' ? 'animate-spin' : '' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v6h6M20 20v-6h-6M5.6 15a7 7 0 0 0 12.3 2M18.4 9A7 7 0 0 0 6.1 7"/></svg>
            Inbox scannen
        </button>
        @if (($scan['running'] ?? false) && ($scan['location'] ?? null) === 'inbox')
            <button wire:click="cancelScan" class="btn-ghost" @disabled(($scan['phase'] ?? '') === 'wird abgebrochen …') title="Bis dahin Gelesenes bleibt – der nächste Scan macht dort weiter">Abbrechen</button>
        @endif
    </x-page-header>

    <div class="min-h-0 flex-1 overflow-y-auto p-6">
        @include('livewire.partials.scan-notes', ['location' => 'inbox'])
        @if ($albums->isEmpty())
            <div class="grid h-full place-items-center text-center text-zinc-400">
                <div>
                    <p class="text-lg text-zinc-300">Keine Alben in der Inbox.</p>
                    <p class="mt-1 text-sm">Neue Musik wird erst nach einem Klick auf „Inbox scannen“ angezeigt.</p>
                </div>
            </div>
        @else
            <div class="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-5">
                @foreach ($albums as $album)
                    <a href="{{ route('inbox.album', $album) }}" wire:navigate wire:key="a{{ $album->id }}"
                       class="group rounded-xl border border-zinc-800 bg-zinc-900/60 p-3 transition hover:-translate-y-0.5 hover:border-zinc-700 hover:bg-zinc-900">
                        <x-cover :url="$album->coverUrl(400)" class="aspect-square w-full" />
                        <div class="mt-3 truncate font-medium text-zinc-100 group-hover:text-white" title="{{ $album->title }}">{{ $album->title }}</div>
                        <div class="truncate text-sm text-zinc-400" title="{{ $album->albumartist ?: ($album->is_compilation ? 'Various Artists' : '—') }}">{{ $album->albumartist ?: ($album->is_compilation ? 'Various Artists' : '—') }}</div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @if ($album->discCount() > 1)
                                @php $missing = $album->missingDiscs(); @endphp
                                <span class="badge-blue" title="{{ implode("\n", array_map('basename', $album->allDirs())) }}">CD {{ implode(', ', $album->discNumbers()) }}</span>
                                @if ($missing)<span class="badge-amber" title="Diese CD liegt nicht in der Inbox">CD {{ implode(', ', $missing) }} fehlt</span>@endif
                            @endif
                            @php $ex = $existing[$album->id] ?? null; @endphp
                            @if ($ex && $ex['album'] && $ex['duplicate'])
                                <span class="badge-amber" title="Im Bestand: {{ $ex['album']->dir }} – {{ $ex['songs'] }} von {{ $ex['total'] }} Liedern vorhanden">im Bestand</span>
                            @elseif ($ex && $ex['album'])
                                <span class="badge-blue" title="Gleiches Album im Bestand ({{ $ex['album']->dir }}), aber andere Lieder bzw. CDs – gehört vermutlich zusammen">Teil im Bestand</span>
                            @elseif ($ex && $ex['songs'])
                                <span class="{{ $ex['songs'] === $ex['total'] ? 'badge-amber' : 'badge-gray' }}" title="Diese Lieder gibt es schon im Bestand (in anderen Alben bzw. „Kein Album“)">{{ $ex['songs'] }}/{{ $ex['total'] }} im Bestand</span>
                            @endif
                            <span class="badge-gray">{{ $album->track_count }} Tracks</span>
                            @if ($album->year)<span class="badge-gray">{{ $album->year }}</span>@endif
                            @if ($album->category)<span class="badge-violet">{{ $album->category }}</span>@endif
                            @if (! $album->hasCover())<span class="badge-amber">kein Cover</span>@endif
                            @if ($renames[$album->id] ?? null)
                                <span class="badge-blue" title="Künstler umbenennen:&#10;{{ collect($renames[$album->id])->map(fn ($r, $from) => $from.' → '.$r['to'].' ('.$r['source'].')')->implode("\n") }}">
                                    umbenennen: {{ collect($renames[$album->id])->map(fn ($r) => $r['to'])->unique()->implode(', ') }}
                                </span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
